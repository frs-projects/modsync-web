<?php

namespace App\Hosts;

use Illuminate\Http\Client\Response;
use RuntimeException;

/**
 * A Modrinth or CurseForge call that failed; the message names the host and its answer.
 */
class HostException extends RuntimeException
{
    public static function fromResponse(string $host, Response $response): self
    {
        $message = $response->json('description') ?? $response->json('error') ?? $response->reason();

        return new self(sprintf('%s %d: %s', $host, $response->status(), is_string($message) ? $message : $response->reason()), $response->status());
    }
}
