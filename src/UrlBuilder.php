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

    public function buildUrl(string $route, string $template, array $params, array $queryParams)
    {
        if (!in_array($route, ['data', 'view])) {
            throw new Exception('Route must be one of: data, view);
        }
        return $this->endpoint .
            '/$route/' .
            rawurldecode($template) .
            $this->buildParameters($params) .
            $this->buildQueryParameters($queryParams);
    }

    private function buildParameters(array $parameters): string
    {
        $path = '';

        foreach ($parameters as $key => $value) {
            $path .= '/' . rawurlencode((string) $key) . '/' . rawurlencode((string) $value);
        }

        return $path;
    }

    private function buildQueryParameters(array $queryParameters): string
    {
        $query = http_build_query($queryParameters);
        return empty($query) ? '' : '?' . $query;
    }
}
