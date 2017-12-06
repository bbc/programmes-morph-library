<?php
declare(strict_types=1);

namespace BBC\ProgrammesMorphLibrary\Entity;

class MorphView
{
    /** @var string */
    private const ID_PLACEHOLDER = '631d8fd30fda2d841172372fcc103897';

    /** @var array */
    private $head;

    /** @var string */
    private $body;

    /** @var array */
    private $bodyLast;

    public function __construct(string $id, array $head, string $body, array $bodyLast)
    {
        $this->head = $head;
        // Replace the id placeholder with proper id
        $this->body = str_replace(self::ID_PLACEHOLDER, $id, $body);
        $this->bodyLast = $bodyLast;
    }

    public function getHead(): array
    {
        return $this->head;
    }

    public function getBody(): string
    {
        return $this->body;
    }

    public function getBodyLast(): array
    {
        return $this->bodyLast;
    }
}