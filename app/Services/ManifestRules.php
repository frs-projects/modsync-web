<?php

namespace App\Services;

use App\Models\ModsyncFile;

/**
 * The client's rules for paths and download hosts (ManifestCodec, PathSandbox and HostAllowlist
 * in the mod), so the panel never publishes a manifest the client refuses.
 */
final class ManifestRules
{
    public const int MAX_PATH_LENGTH = 512;

    public const int MAX_URLS = 16;

    public const int MAX_URL_LENGTH = 2048;

    public const int MAX_FILES = 10_000;

    /**
     * A path the client may write: `<root>/<segment>[/<segment>…]`, forward slashes, no `.`/`..`,
     * none of Windows' illegal characters, endings or device names.
     */
    public static function isPath(string $path): bool
    {
        if ($path === '' || strlen($path) > self::MAX_PATH_LENGTH) {
            return false;
        }

        $segments = explode('/', $path);

        if (count($segments) < 2 || ! in_array($segments[0], ModsyncFile::ROOTS, true)) {
            return false;
        }

        foreach ($segments as $segment) {
            if (! self::isSegment($segment)) {
                return false;
            }
        }

        return true;
    }

    public static function isSegment(string $segment): bool
    {
        return $segment !== ''
            && $segment !== '.'
            && $segment !== '..'
            && preg_match('/[\x00-\x1F\x7F\\\\\/:*?"<>|]/', $segment) !== 1
            && preg_match('/^\s|[\s.]$/u', $segment) !== 1
            && preg_match('/^(CON|PRN|AUX|NUL|COM[0-9]|LPT[0-9])(\..*)?$/i', $segment) !== 1;
    }

    /**
     * A folder files can be put in: a root, optionally with subfolders (`config/tacz`).
     */
    public static function isFolder(string $folder): bool
    {
        return self::isPath("{$folder}/x");
    }

    /**
     * Whether the client downloads from this URL without the player approving its host: an
     * allowlisted host, or the host of the HTTPS manifest that lists it.
     */
    public static function isTrustedUrl(string $url, ?string $manifestUrl = null): bool
    {
        $host = self::host($url);

        if (! str_starts_with($url, 'https://') || $host === null) {
            return false;
        }

        if ($manifestUrl !== null && str_starts_with($manifestUrl, 'https://') && $host === self::host($manifestUrl)) {
            return true;
        }

        return collect(config('modsync.trusted_hosts'))
            ->contains(fn (string $trusted): bool => $host === $trusted || str_ends_with($host, ".{$trusted}"));
    }

    public static function host(string $url): ?string
    {
        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) ? strtolower($host) : null;
    }
}
