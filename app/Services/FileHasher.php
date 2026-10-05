<?php

namespace App\Services;

use App\Hosts\HostException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Computes what the client verifies a file against (SHA-512, plus SHA-1 and the size), for files
 * whose host does not publish a SHA-512. Remote files are streamed, never stored.
 */
class FileHasher
{
    /**
     * Larger than any mod or pack, small enough to stop a URL that never ends.
     */
    public const int MAX_BYTES = 2 * 1024 * 1024 * 1024;

    /**
     * @return array{sha512: string, sha1: string, size: int}
     *
     * @throws HostException
     */
    public function hashUrl(string $url): array
    {
        try {
            $response = Http::withUserAgent(config('modsync.user_agent'))
                ->timeout(600)
                ->withOptions(['stream' => true])
                ->get($url);
        } catch (ConnectionException $exception) {
            throw new HostException(__('files.errors.download', ['error' => $exception->getMessage()]), previous: $exception);
        }

        if (! $response->successful()) {
            throw new HostException(__('files.errors.download', ['error' => "HTTP {$response->status()}"]));
        }

        $body = $response->toPsrResponse()->getBody();
        $sha512 = hash_init('sha512');
        $sha1 = hash_init('sha1');
        $size = 0;

        while (! $body->eof()) {
            $chunk = $body->read(1024 * 1024);
            $size += strlen($chunk);

            if ($size > self::MAX_BYTES) {
                throw new HostException(__('files.errors.too_large'));
            }

            hash_update($sha512, $chunk);
            hash_update($sha1, $chunk);
        }

        return ['sha512' => hash_final($sha512), 'sha1' => hash_final($sha1), 'size' => $size];
    }

    /**
     * @return array{sha512: string, sha1: string, size: int}
     */
    public function hashLocal(string $path): array
    {
        return ['sha512' => hash_file('sha512', $path), 'sha1' => hash_file('sha1', $path), 'size' => (int) filesize($path)];
    }
}
