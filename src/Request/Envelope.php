<?php
declare(strict_types=1);

namespace BBC\ProgrammesMorphLibrary\Request;

use BBC\ProgrammesMorphLibrary\UrlBuilder;
use Psr\Cache\CacheItemInterface;

class Envelope
{
    /** @var string */
    private $template;

    /** @var string */
    private $id;

    /** @var string[] */
    private $parameters;

    /** @var string[] */
    private $queryParameters;

    /** @var string */
    private $ttl;

    /** @var string */
    private $nullTtl;

    /** @var CacheItemInterface */
    private $cacheItem;

    /** @var string */
    private $url;

    public function __construct(
        UrlBuilder $urlBuilder,
        string $template,
        string $id,
        array $parameters,
        array $queryParameters,
        int $timeout,
        string $ttl,
        string $nullTtl
    ) {
        if ($timeout > 0) {
            $queryParameters = array_merge(['timeout' => $timeout], $queryParameters);
        }

        $this->url = $urlBuilder->buildUrl($template, $parameters, $queryParameters);

        $this->template = $template;
        $this->id = $id;
        $this->parameters = $parameters;
        $this->queryParameters = $queryParameters;
        $this->ttl = $ttl;
        $this->nullTtl = $nullTtl;
    }

    public function getTemplate(): string
    {
        return $this->template;
    }

    public function getId(): string
    {
        return $this->id;
    }

    /** @return string[] */
    public function getParameters(): array
    {
        return $this->parameters;
    }

    /** @return string[] */
    public function getQueryParameters(): array
    {
        return $this->queryParameters;
    }

    public function getTtl(): string
    {
        return $this->ttl;
    }

    public function getNullTtl(): string
    {
        return $this->nullTtl;
    }

    public function getCacheItem(): CacheItemInterface
    {
        return $this->cacheItem;
    }

    public function getUrl(): string
    {
        return $this->url;
    }
}