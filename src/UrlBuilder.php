<?php
declare(strict_types=1);

namespace BBC\ProgrammesMorphLibrary;

class UrlBuilder
{
    /** @var string */
    private $endpoint;

    function __construct(string $endpoint)
    {
        $this->endpoint = $endpoint;
    }

    public function buildUrl(string $template, string $id, array $params)
    {
        return $this->endpoint . $template . '/' . $id . '/' . implode($params, '/');
    }
}