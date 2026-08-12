<?php

declare(strict_types=1);

namespace Pictum;

use LogicException;
use Pictum\Exception\SvgRequestFailed;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;

final readonly class PictumAsset
{
    public function __construct(
        public string $url,
        private ?string $svgUrl,
        private ClientInterface $httpClient,
        private RequestFactoryInterface $requestFactory,
    ) {}

    public function svg(): string
    {
        if ($this->svgUrl === null) {
            throw new LogicException('This Pictum asset does not support SVG.');
        }

        $request = $this->requestFactory
            ->createRequest('GET', $this->svgUrl)
            ->withHeader('Accept', 'image/svg+xml');
        $response = $this->httpClient->sendRequest($request);
        $statusCode = $response->getStatusCode();

        if ($statusCode < 200 || $statusCode >= 300) {
            throw new SvgRequestFailed($statusCode, $response->getReasonPhrase());
        }

        $body = $response->getBody();

        if ($body->isSeekable()) {
            $body->rewind();
        }

        return $body->getContents();
    }
}
