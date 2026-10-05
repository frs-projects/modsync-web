<?php

namespace App\Hosts;

/**
 * One downloadable version of a project: its primary file. Update checks may only know the id
 * and name; the rest is fetched before a file is changed.
 */
final readonly class RemoteVersion
{
    /**
     * @param  string|null  $url  Null when the author does not allow downloads outside the host (CurseForge).
     * @param  string|null  $sha512  Null on CurseForge, which only publishes SHA-1: the panel downloads the file to hash it.
     * @param  string  $releaseType  `release`, `beta` or `alpha`.
     */
    public function __construct(
        public string $id,
        public string $projectId,
        public string $name,
        public string $fileName,
        public ?int $size = null,
        public ?string $url = null,
        public ?string $sha512 = null,
        public ?string $sha1 = null,
        public string $releaseType = 'release',
        public ?string $publishedAt = null,
    ) {}
}
