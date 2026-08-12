<?php

declare(strict_types=1);

namespace Pictum\Tests\Support;

use GuzzleHttp\Psr7\Response;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Throwable;

final class MockHttpClient implements ClientInterface
{
    /** @var list<RequestInterface> */
    public array $requests = [];

    /** @var list<ResponseInterface|Throwable> */
    private array $responses;

    public function __construct(ResponseInterface|Throwable ...$responses)
    {
        $this->responses = array_values($responses);
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;
        $response = array_shift($this->responses) ?? new Response(200, [], '<svg></svg>');

        if ($response instanceof ResponseInterface) {
            return $response;
        }

        throw $response;
    }

    public function request(int $index): RequestInterface
    {
        return $this->requests[$index] ?? throw new RuntimeException("No request exists at index {$index}.");
    }
}
