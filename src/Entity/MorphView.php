<?php
declare(strict_types=1);

namespace BBC\ProgrammesMorphLibrary\Entity;

class MorphView
{
    /** @var array */
    private $head;

    /** @var string */
    private $body;

    /** @var array */
    private $footer;

    public function __construct(array $head, string $body, array $footer)
    {
        $this->head = $head;
        $this->body = $body;
        $this->footer = $footer;
    }

    public function getHead(): array
    {
        return $this->head;
    }

    public function getBody(): string
    {
        return $this->body;
    }

    public function getFooter(): array
    {
        return $this->footer;
    }
}