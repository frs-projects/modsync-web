<?php

namespace App\Hosts;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Throwable;

/**
 * The transport of a host client: retries on connection errors and 5xx, and turns every other
 * failure into a HostException.
 */
trait SendsRequests
{
    /**
     * The host's name in errors.
     */
    abstract protected function hostName(): string;

    /**
     * The base request: URL, headers.
     */
    abstract protected function request(): PendingRequest;

    /**
     * @param  array<string, mixed>  $query
     */
    private function get(string $path, array $query = [], bool $allowMissing = false): Response
    {
        return $this->send(fn (PendingRequest $http): Response => $http->get($path, $query), $allowMissing);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function post(string $path, array $body): Response
    {
        return $this->send(fn (PendingRequest $http): Response => $http->post($path, $body));
    }

    /**
     * @param  callable(PendingRequest): Response  $call
     */
    private function send(callable $call, bool $allowMissing = false): Response
    {
        try {
            $response = $call($this->request()
                ->acceptJson()
                ->timeout(15)
                ->retry(2, 500, fn (Throwable $exception): bool => $exception instanceof ConnectionException
                    || ($exception instanceof RequestException && $exception->response->serverError()), throw: false));
        } catch (ConnectionException $exception) {
            throw new HostException("{$this->hostName()}: {$exception->getMessage()}", previous: $exception);
        }

        if ($response->successful() || ($allowMissing && $response->notFound())) {
            return $response;
        }

        throw HostException::fromResponse($this->hostName(), $response);
    }
}
