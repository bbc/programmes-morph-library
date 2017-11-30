<?php
declare(strict_types=1);

namespace BBC\ProgrammesMorphLibrary;

use BBC\ProgrammesMorphLibrary\Entity\MorphView;
use Closure;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\TransferStats;
use Psr\Log\LoggerInterface;
use SplObserver;
use SplSubject;

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
    private $maxRetries = 3;

    /** @var UrlBuilder */
    private $urlBuilder;

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

    public function getView(string $template, string $id, array $parameters): MorphView
    {
        //$response = $this->queryUrl();
        $url = $this->urlBuilder->buildUrl($template, $id, $parameters);
        $response = (object) [
            'head' => ['head'],
            'bodyInline' => $url,
            'bodyLast' => ['bodyLast'],
        ];

        return new MorphView(
            $response->head ?? [],
            $response->bodyInline,
            $response->bodyLast && is_array($response->bodyLast) ? $response->bodyLast : []
        );
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

    private function queryUrl(string $url, int $retries = 0)
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

            return json_decode($response->getBody()->getContents());
        } catch (RequestException $e) {
            $response = $e->getResponse();
            $statusCode = 0;

            if ($response) {
                $statusCode = $response->getStatusCode();
            }

            if ($statusCode == 404) {
                return null;
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

    private function queueUrl(string $url, string $method = 'get'): void
    {
        $this->logger->info('Queueing ' . $url);
        $this->promises[$url] = $this->httpClient->getAsync($url, [
            'verify' => false,
            'on_stats' => $this->logRequests,
        ]);
    }
}