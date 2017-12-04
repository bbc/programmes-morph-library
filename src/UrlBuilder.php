<?php
declare(strict_types=1);

namespace BBC\ProgrammesMorphLibrary;

class UrlBuilder
{
    /** @var string */
    private $endpoint;

    function __construct(string $endpoint)
    {
        $this->endpoint = rtrim($endpoint, '/');
    }

    public function buildUrl(string $template, array $params, array $queryParams)
    {
        return $this->endpoint .
            '/view/' .
            rawurldecode($template) .
            $this->buildParameters($params) .
            $this->buildQueryParameters($queryParams);
    }

    private function buildParameters(array $parameters): string
    {
        $path = '';

        foreach ($parameters as $key => $value) {
            $path .= '/' . rawurldecode((string) $key) . '/' . rawurldecode((string) $value);
        }

        return $path;
    }

    private function buildQueryParameters(array $queryParameters): string
    {
        $query = http_build_query($queryParameters);
        return empty($query) ? '' : '?' . $query;
    }
}