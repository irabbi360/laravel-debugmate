<?php

namespace Irabbi360\LaravelDebugMate\Tests\Concerns;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Irabbi360\LaravelDebugMate\Services\ApiClient;

/**
 * An ApiClient backed by canned responses that records every request it sends.
 */
trait RecordsApiRequests
{
    /** @var list<array{request: \Psr\Http\Message\RequestInterface}> */
    private array $history = [];

    /**
     * @param  list<Response>  $responses
     */
    private function client(array $responses): ApiClient
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->history));

        return new ApiClient('https://debugmate.test', 'dm_test_key', new Client([
            'handler' => $stack,
            'base_uri' => 'https://debugmate.test',
        ]));
    }

    /**
     * @return list<string>
     */
    private function sentPaths(): array
    {
        return array_map(fn (array $entry) => $entry['request']->getUri()->getPath(), $this->history);
    }

    private function sentBody(int $index): array
    {
        return json_decode((string) $this->history[$index]['request']->getBody(), true);
    }
}
