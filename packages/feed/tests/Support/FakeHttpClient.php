<?php

declare(strict_types=1);

namespace JmaXml\Feed\Tests\Support;

use LogicException;
use Override;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * A PSR-18 client that answers from queued responses per URL and records every request.
 *
 * A request to a URL with nothing queued throws a LogicException, which FeedClient does not catch, so the test fails.
 *
 * @author shimomo
 */
final class FakeHttpClient implements ClientInterface
{
    /**
     * @var array<string, list<ResponseInterface|ClientExceptionInterface>>
     */
    private array $queues = [];

    /**
     * @var list<RequestInterface>
     */
    private array $requests = [];

    public function queue(string $url, ResponseInterface|ClientExceptionInterface ...$responses): void
    {
        $this->queues[$url] = [...($this->queues[$url] ?? []), ...array_values($responses)];
    }

    #[Override]
    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;
        $url = (string) $request->getUri();
        $queue = $this->queues[$url] ?? [];
        $next = array_shift($queue) ?? throw new LogicException('Unexpected request to ' . $url);
        $this->queues[$url] = $queue;

        if ($next instanceof ClientExceptionInterface) {
            throw $next;
        }

        return $next;
    }

    /**
     * @return list<RequestInterface>
     */
    public function requests(): array
    {
        return $this->requests;
    }

    /**
     * @return list<RequestInterface>
     */
    public function requestsTo(string $url): array
    {
        return array_values(array_filter($this->requests, static fn(RequestInterface $request): bool => (string) $request->getUri() === $url));
    }
}
