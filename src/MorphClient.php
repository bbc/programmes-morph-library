<?php
declare(strict_types=1);

namespace BBC\ProgrammesMorphLibrary;

use BBC\ProgrammesMorphLibrary\Entity\MorphView;
use BBC\ProgrammesMorphLibrary\Exception\MorphErrorException;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Promise\PromiseInterface;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use Throwable;
use Psr\Cache\InvalidArgumentException;

class MorphClient
{
    /** @var LoggerInterface */
    private $logger;

    /** @var Client */
    private $client;

    /** @var UrlBuilder */
    private $urlBuilder;

    /** @var int */
    private $timeout;

    /** @var CacheItemPoolInterface */
    private $cache;

    /** @var string */
    private $standardTTL;

    /** @var string */
    private $notFoundTTL;

    /** @var int */
    private $maxRetries;

    /** @var bool */
    private $flushCacheItems = false;

    public function __construct(
        ClientInterface $client,
        CacheItemPoolInterface $cache,
        LoggerInterface $logger,
        string $endpoint,
        int $standardTTL,
        int $notFoundTTL,
        int $timeout = 3,
        int $maxRetries = 1
    ) {
        $this->logger = $logger;
        $this->client = $client;
        $this->urlBuilder = new UrlBuilder($endpoint);
        $this->cache = $cache;
        $this->standardTTL = $standardTTL;
        $this->notFoundTTL = $notFoundTTL;
        $this->timeout = $timeout;
        $this->maxRetries = $maxRetries;
    }

    /** @throws MorphErrorException|InvalidArgumentException */
    public function makeCachedViewRequest(
        string $template,
        string $id,
        array $parameters,
        array $queryParameters
    ): MorphView {
        if ($this->timeout > 0) {
            $queryParameters = array_merge(['timeout' => $this->timeout], $queryParameters);
        }

        $url = $this->urlBuilder->buildUrl($template, $parameters, $queryParameters);

        $this->logger->info("[MORPH] Fetching $url");

        $cacheItem = $this->fetchCacheItem($url);
        if ($cacheItem->isHit()) {
            $this->logger->info("[MORPH] Got $url from cache");
            return $cacheItem->get();
        }

        try {
            $response = $this->client->request('GET', $url);
        } catch(RequestException $e) {
            return $this->handleRequestException($e, $cacheItem, $url);
        }

        return $this->handleResponse($response, $cacheItem, $id, $url);
    }

    /** @throws MorphErrorException|InvalidArgumentException */
    public function makeCachedViewPromise(
        string $template,
        string $id,
        array $parameters,
        array $queryParameters
    ): PromiseInterface {
        if ($this->timeout > 0) {
            $queryParameters = array_merge(['timeout' => $this->timeout], $queryParameters);
        }

        $url = $this->urlBuilder->buildUrl($template, $parameters, $queryParameters);

        $this->logger->info("[MORPH] Asynchronously fetching $url");

        $cacheItem = $this->fetchCacheItem($url);
        if ($cacheItem->isHit()) {
            $this->logger->info("[MORPH] Got $url from cache");
            return new FulfilledPromise($cacheItem->get());
        }

        try {
            $requestPromise = $this->client->requestAsync('GET', $url);
        } catch (RequestException $e) {
            return $this->handleRequestException($e, $cacheItem, $url);
        }

        return $requestPromise->then(
            // Success callback
            function ($response) use ($cacheItem, $id, $url) {
                return $this->handleResponse($response, $cacheItem, $id, $url);
            },
            // Error callback
            function ($reason) use ($cacheItem, $url) {
                return $this->handleAsyncError($reason, $cacheItem, $url);
            }
        );
    }

    /** @throws InvalidArgumentException */
    private function fetchCacheItem(string $url): CacheItemInterface
    {
        $cacheKey = 'morph' . '.' . md5($url);
        if ($this->flushCacheItems) {
            $this->cache->deleteItem($cacheKey);
        }

        return $this->cache->getItem($cacheKey);
    }

    /** @throws MorphErrorException */
    private function handle202(string $url, CacheItemInterface $cacheItem)
    {
        for ($retry = 0; $retry < $this->maxRetries; $retry++) {
            try {
                $response = $this->client->request('GET', $url);
                if ($response->getStatusCode() === 200) {
                    return $response;
                }
            } catch(RequestException $e) {
                return $this->handleRequestException($e, $cacheItem, $url);
            }
        }

        $message = "[MORPH] Error communicating with Morph API. Response code was 202 after $this->maxRetries tries.";
        $this->logger->error($message);
        throw new MorphErrorException($message);
    }

    /** @throws MorphErrorException */
    private function handleResponse(
        ResponseInterface $response,
        CacheItemInterface $cacheItem,
        string $id,
        string $url
    ): MorphView {
        if ($response->getStatusCode() === 202) {
            $response = $this->handle202($url, $cacheItem);
        }

        $json = json_decode($response->getBody()->getContents());
        $result = new MorphView(
            $id,
            $json->head ?? [],
            $json->bodyInline ?? '',
            $json->bodyLast && is_array($json->bodyLast) ? $json->bodyLast : []
        );

        $cacheItem->set($result);
        $cacheItem->expiresAfter($this->protectLifetimeFromStampede($this->standardTTL));
        $this->cache->save($cacheItem);

        return $result;
    }

    /** @throws MorphErrorException */
    private function handleRequestException(RequestException $e, CacheItemInterface $cacheItem, string $url): null
    {
        if (!$e->getResponse() || $e->getResponse()->getStatusCode() != 404) {
            $this->logger->error("[MORPH] Error communicating with API on $url. Response code was " . $e->getCode());
            throw new MorphErrorException($e->getMessage(), $e->getCode(), $e);
        }

        // 404 results get cached
        $cacheItem->set(null);
        $cacheItem->expiresAfter($this->protectLifetimeFromStampede($this->notFoundTTL));
        $this->cache->save($cacheItem);

        return null;
    }

    /** @throws MorphErrorException */
    private function handleAsyncError($reason, CacheItemInterface $cacheItem, string $url)
    {
        if ($reason instanceof RequestException) {
            return $this->handleRequestException($reason, $cacheItem, $url);
        }

        if ($reason instanceof Throwable) {
            throw new MorphErrorException($reason->getMessage());
        }

        $msg = "[MORPH] An unknown issue occurred handling a guzzle error whose reason was not an exception. URL: $url";

        $this->logger->error($msg);
        throw new MorphErrorException($msg);
    }

    private function protectLifetimeFromStampede(int $ttl): int
    {
        $ten = floor($ttl / 10);
        $modifier = rand(0, $ten);
        $modifier = min($modifier, 120);
        return $ttl + $modifier;
    }

    private function setFlushCacheItems(bool $flushCacheItems): void
    {
        $this->flushCacheItems = $flushCacheItems;
    }
}