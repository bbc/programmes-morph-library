<?php
declare(strict_types=1);

namespace Tests\BBC\ProgrammesMorphLibrary;

use BBC\ProgrammesMorphLibrary\MorphClient;
use GuzzleHttp\Client;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class MorphClientTest extends TestCase
{
    public function test()
    {
        $client = new MorphClient(
            $this->createMock(LoggerInterface::class),
            $this->createMock(Client::class),
            'https://morph.api.bbci.co.uk'
        );

        $resp = $client->getView('template', 'id', ['param1' => 'val1'], ['queryParam1' => 'val1']);
        $this->assertEquals(['head'], $resp->getHead());
        $this->assertEquals('https://morph.api.bbci.co.uk/view/template/param1/val1?queryParam1=val1', $resp->getBody());
        $this->assertEquals(['bodyLast'], $resp->getFooter());
    }
}