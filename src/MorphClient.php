<?php
declare(strict_types=1);

namespace BBC\ProgrammesMorphLibrary;

use BBC\ProgrammesCachingLibrary\CacheInterface;
use BBC\ProgrammesMorphLibrary\Entity\MorphView;
use BBC\ProgrammesMorphLibrary\Exception\MorphErrorException;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Promise\RejectedPromise;
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

    /** @var int */
    private $maxRetries;

    /** @var string[] */
    private $options = [
        'throwExceptions' => false,
    ];

    public function __construct(
        ClientInterface $client,
        CacheInterface $cache,
        LoggerInterface $logger,
        string $endpoint,
        array $options = [],
        int $timeout = 3,
        int $maxRetries = 1
    ) {
        $this->logger = $logger;
        $this->client = $client;
        $this->urlBuilder = new UrlBuilder($endpoint);
        $this->cache = $cache;
        $this->timeout = $timeout;
        $this->maxRetries = $maxRetries;
        $this->options = array_merge($this->options, $options);
    }

    /** @throws MorphErrorException|InvalidArgumentException */
    public function makeCachedViewRequest(
        string $template,
        string $id,
        array $parameters,
        array $queryParameters,
        $ttl = CacheInterface::NORMAL,
        $nullTtl = CacheInterface::SHORT
    ): ?MorphView {
        $requestEnvelope = new Envelope(
            $this->urlBuilder,
            $template,
            $id,
            $parameters,
            $queryParameters,
            $this->timeout,
            $ttl,
            $nullTtl
        );

        // Generate key
        $key = $this->cache->keyHelper(__CLASS__, __FUNCTION__, md5($requestEnvelope->getUrl()));

        $this->logger->info('[MORPH] Fetching ' . $requestEnvelope->getUrl());

        // Possibly get item from cache
        $cacheItem = $this->cache->getItem($key);
        if ($cacheItem->isHit()) {
            $this->logger->info('[MORPH] Got ' . $requestEnvelope->getUrl() . ' from cache');
            return $cacheItem->get();
        }

        // Make request
        try {
            $response = $this->client->request('GET', $requestEnvelope->getUrl());
        } catch(RequestException $e) {
            return $this->handleRequestException($e, $cacheItem, $requestEnvelope);
        }

        return $this->handleResponse($response, $cacheItem, $requestEnvelope);
    }

    /** @throws MorphErrorException|InvalidArgumentException */
    public function makeCachedViewPromise(
        string $template,
        string $id,
        array $parameters,
        array $queryParameters,
        $ttl = CacheInterface::NORMAL,
        $nullTtl = CacheInterface::SHORT
    ): PromiseInterface {
        $requestEnvelope = new Envelope(
            $this->urlBuilder,
            $template,
            $id,
            $parameters,
            $queryParameters,
            $this->timeout,
            $ttl,
            $nullTtl
        );

        // Generate key
        $key = $this->cache->keyHelper(__CLASS__, __FUNCTION__, md5($requestEnvelope->getUrl()));

        $this->logger->info('[MORPH] Asynchronously fetching ' . $requestEnvelope->getUrl());

        // Possibly get item from cache
        $cacheItem = $this->cache->getItem($key);
        if ($cacheItem->isHit()) {
            $this->logger->info('[MORPH] Got ' . $requestEnvelope->getUrl() . ' from cache');
            return new FulfilledPromise($cacheItem->get());
        }

        // Create promise
        $requestPromise = $this->client->requestAsync('GET', $requestEnvelope->getUrl());

        return $requestPromise->then(
            // Success callback
            function ($response) use ($cacheItem, $requestEnvelope) {
                return $this->handleResponse($response, $cacheItem, $requestEnvelope);
            },
            // Error callback
            function ($reason) use ($cacheItem, $requestEnvelope) {
                return $this->handleAsyncError($reason, $cacheItem, $requestEnvelope);
            }
        );
    }

    public function setFlushCacheItems(bool $flushCacheItems): void
    {
        $this->cache->setFlushCacheItems($flushCacheItems);
    }

    /** @throws MorphErrorException */
    private function handle202(Envelope $requestEnvelope, CacheItemInterface $cacheItem): ?ResponseInterface
    {
        // Retry until we get a response or until we hit the maximum number of retries
        for ($retry = 0; $retry < $this->maxRetries; $retry++) {
            try {
                $response = $this->client->request('GET', $requestEnvelope->getUrl());
                if ($response->getStatusCode() === 200) {
                    return $response;
                }
            } catch(RequestException $e) {
                return $this->handleRequestException($e, $cacheItem, $requestEnvelope);
            }
        }

        $message = "[MORPH] Error communicating with Morph API. Response code was 202 after $this->maxRetries tries. URL: " . $requestEnvelope->getUrl();
        $this->logger->error($message);
        if ($this->options['throwExceptions']) {
            throw new MorphErrorException($message, 202);
        }

        return null;
    }

    /** @throws MorphErrorException */
    private function handleResponse(
        ResponseInterface $response,
        CacheItemInterface $cacheItem,
        Envelope $requestEnvelope
    ): ?MorphView {
        // Morph throws 202 in certain cases, e.g. when a request is being made for the first time
        // See https://confluence.dev.bbc.co.uk/display/morph/Integrating+with+Morph for more info
        if ($response->getStatusCode() === 202) {
            $response = $this->handle202($requestEnvelope, $cacheItem);
        }

        if (!$response) {
            return null;
        }

        $json = json_decode($response->getBody()->getContents());
        $result = new MorphView(
            $requestEnvelope->getId(),
            $json->head ?? [],
            $json->bodyInline ?? '',
            $json->bodyLast && is_array($json->bodyLast) ? $json->bodyLast : []
        );

        $this->cache->setItem($cacheItem, $result, $requestEnvelope->getTtl());
        return $result;
    }

    /** @throws MorphErrorException */
    private function handleRequestException(
        RequestException $e,
        CacheItemInterface $cacheItem,
        Envelope $requestEnvelope
    ) {
        // 404 results get cached
        if ($e->getResponse() && $e->getResponse()->getStatusCode() === 404) {
            $this->cache->setItem($cacheItem, null, $requestEnvelope->getNullTtl());
        }

        $this->logger->error('[MORPH] Error communicating with API on ' . $requestEnvelope->getUrl() . '. Response code was ' . $e->getCode());
        if ($this->options['throwExceptions']) {
            throw new MorphErrorException($e->getMessage(), $e->getCode(), $e);
        }

        return null;
    }

    /** @throws MorphErrorException */
    private function handleAsyncError($reason, CacheItemInterface $cacheItem, Envelope $requestEnvelope)
    {
        if ($reason instanceof RequestException) {
            return $this->handleRequestException($reason, $cacheItem, $requestEnvelope);
        }

        if ($reason instanceof Throwable && $this->options['throwExceptions']) {
            throw new MorphErrorException($reason->getMessage());
        }

        $msg = '[MORPH] An unknown issue occurred handling a Guzzle error whose reason was not an exception. URL: ' . $requestEnvelope->getUrl();
        $this->logger->error($msg);

        if ($this->options['throwExceptions']) {
            throw new MorphErrorException($msg);
        }

        return null;
    }
}