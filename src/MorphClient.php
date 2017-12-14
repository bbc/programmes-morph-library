<?php
declare(strict_types=1);

namespace BBC\ProgrammesMorphLibrary;

use BBC\ProgrammesMorphLibrary\Entity\MorphView;
use BBC\ProgrammesMorphLibrary\Exception\MorphErrorException;
use Closure;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\TransferStats;
use Psr\Log\LoggerInterface;
use SplObserver;
use SplSubject;
use stdClass;

class MorphClient implements SplSubject
{
    /** @var PromiseInterface[] */
    private $promises = [];

    /** @var SplObserver[] */
    private $subscribers = [];

    /** @var LoggerInterface */
    private $logger;

    /** @var Closure */
    private $logRequests;

    /** @var Client */
    private $httpClient;

    /** @var int */
    private $maxRetries = 1;

    /** @var UrlBuilder */
    private $urlBuilder;

    /** @var float */
    private $timeout = 2.8;

    public function __construct(LoggerInterface $logger, Client $httpClient, string $endpoint)
    {
        $this->logger = $logger;
        $this->httpClient = $httpClient;

        $this->logRequests = function (TransferStats $stats) {
            $this->stats = $stats;
            $this->notify();
        };

        $this->urlBuilder = new UrlBuilder($endpoint);
    }

    /** @throws MorphErrorException */
    public function getView(string $template, string $id, array $parameters = [], array $queryParameters = []): MorphView
    {
        if ($this->timeout > 0) {
            $queryParameters = array_merge(['timeout' => $this->timeout], $queryParameters);
        }

        $url = $this->urlBuilder->buildUrl($template, $parameters, $queryParameters);
        $response = $this->queryUrl($url);

        return new MorphView(
            $id,
            $response->head ?? [],
            $response->bodyInline ?? '',
            $response->bodyLast && is_array($response->bodyLast) ? $response->bodyLast : []
        );
    }

    public function queueView(string $template, array $parameters = [], array $queryParameters = []): void
    {
        if ($this->timeout > 0) {
            $queryParameters = array_merge(['timeout' => $this->timeout], $queryParameters);
        }

        $url = $this->urlBuilder->buildUrl($template, $parameters, $queryParameters);
        $this->queueUrl($url);
    }

    public function attach(SplObserver $observer)
    {
        $this->subscribers[] = $observer;
    }

    public function detach(SplObserver $observer)
    {
        $key = array_search($observer, $this->subscribers, true);
        if ($key) {
            unset($this->subscribers[$key]);
        }
    }

    public function notify()
    {
        foreach ($this->subscribers as $value) {
            $value->update($this);
        }
    }

    public function setMaxRetries(int $maxRetries): void
    {
        $this->maxRetries = $maxRetries;
    }

    public function setTimeout(int $timeout): void
    {
        $this->timeout = $timeout;
    }

    /** @throws MorphErrorException */
    private function queryUrl(string $url, int $retries = 0): stdClass
    {
        try {
            if (isset($this->promises[$url])) {
                $response = $this->promises[$url]->wait(true);
                unset($this->promises[$url]);
            } else {
                $this->logger->info('Fetching ' . $url);
                $response = $this->httpClient->get($url, [
                    'verify' => false,
                    'on_stats' => $this->logRequests,
                ]);
            }

            // Morph throws 202 in certain cases, e.g. when a request is being made for the first time
            // See https://confluence.dev.bbc.co.uk/display/morph/Integrating+with+Morph for more info
            if ($response->getStatusCode() === 202) {
                if (++$retries > $this->maxRetries) {
                    $message = "Error communicating with Morph API. Response code was 202 after " . $retries . " retries.";
                    $this->logger->alert($message);
                    throw new MorphErrorException($message, 202);
                }

                return $this->queryUrl($url, $retries);
            }

            return json_decode($response->getBody()->getContents());
        } catch (RequestException $e) {
            if ($e->getResponse() && $e->getResponse()->getStatusCode() == 404) {
                // empty response
                return json_decode('');
            }

            if (++$retries > $this->maxRetries) {
                $this->logger->alert("Error communicating with Morph API. Response code was " . $e->getCode());
                throw new MorphErrorException($e->getMessage(), $e->getCode(), $e);
            }

            // If at first you don't succeed...
            $this->logger->notice('The requested url ' . $url . ' gave a response of: ' . $e->getCode() . '. Retrying');
            return $this->queryUrl($url, $retries);
        }
    }

    private function queueUrl(string $url): void
    {
        $this->logger->info('Queueing ' . $url);
        $this->promises[$url] = $this->httpClient->getAsync($url, [
            'verify' => false,
            'on_stats' => $this->logRequests,
        ]);
    }
}