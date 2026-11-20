<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Transport;

/**
 * In-memory Transport for tests: scripted answers, recorded requests, no network.
 */
final class FakeTransport implements Transport
{
    /** @var list<Response|TransportFailure> */
    private array $queue = [];

    /** @var (callable(Request, ConnectionProfile): (Response|TransportFailure|null))|null */
    private $handler = null;

    /** @var list<array{request: Request, profile: ConnectionProfile}> */
    private array $recorded = [];

    private Response|TransportFailure $default;

    public function __construct()
    {
        $this->default = new Response(200);
    }

    /** Queue answers consumed in order, one per request. */
    public function push(Response|TransportFailure ...$answers): self
    {
        array_push($this->queue, ...array_values($answers));

        return $this;
    }

    /**
     * Answer through a callback; returning null falls back to the queue, then to the default answer.
     *
     * @param  callable(Request, ConnectionProfile): (Response|TransportFailure|null)  $handler
     */
    public function respondUsing(callable $handler): self
    {
        $this->handler = $handler;

        return $this;
    }

    public function defaultTo(Response|TransportFailure $answer): self
    {
        $this->default = $answer;

        return $this;
    }

    public function send(iterable $requests, ConnectionProfile $profile): \Generator
    {
        foreach ($requests as $request) {
            $this->recorded[] = ['request' => $request, 'profile' => $profile];

            $answer = $this->handler !== null ? ($this->handler)($request, $profile) : null;
            $answer ??= array_shift($this->queue) ?? $this->default;

            yield $request->id => $answer instanceof Response
                ? Exchange::responded($request, $answer)
                : Exchange::failed($request, $answer);
        }
    }

    public function close(?string $poolKey = null): void {}

    /** @return list<Request> */
    public function requests(): array
    {
        return array_map(static fn (array $entry): Request => $entry['request'], $this->recorded);
    }

    /** @return list<array{request: Request, profile: ConnectionProfile}> */
    public function recorded(): array
    {
        return $this->recorded;
    }

    public function reset(): void
    {
        $this->recorded = [];
        $this->queue = [];
    }
}
