<?php

namespace App\Services;

use App\Models\FilePolicy;
use App\Models\FileSource;
use App\Models\ModsyncFile;
use App\Models\ModsyncPack;
use App\Models\ModsyncRelease;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Freezes a pack's draft into a release (ModSync manifest format v1) and chooses which release
 * players get. Publishing one pack never touches another.
 */
class PackReleases
{
    public function check(ModsyncPack $pack): ReleaseCheck
    {
        $files = $this->files($pack);
        $errors = [];
        $warnings = [];

        if ($files->isEmpty()) {
            $errors[] = __('releases.errors.empty');
        }

        foreach ($files as $file) {
            array_push($errors, ...$this->fileErrors($file));
        }

        $untrusted = $files->flatMap(fn (ModsyncFile $file): array => $file->downloadUrls())
            ->reject(fn (string $url): bool => ManifestRules::isTrustedUrl($url, $pack->manifestUrl()))
            ->map(fn (string $url): string => (string) ManifestRules::host($url))
            ->unique()
            ->values();

        if ($untrusted->isNotEmpty()) {
            $warnings[] = __('releases.warnings.untrusted_hosts', ['hosts' => $untrusted->implode(', ')]);
        }

        return new ReleaseCheck($errors, $warnings);
    }

    /**
     * Releases the draft and makes it live.
     *
     * @throws ReleaseFailed
     */
    public function publish(ModsyncPack $pack, string $version, ?User $publisher = null): ModsyncRelease
    {
        $check = $this->check($pack);

        if (! $check->passes()) {
            throw new ReleaseFailed($check->errors);
        }

        if ($pack->releases()->where('version', $version)->exists()) {
            throw new ReleaseFailed([__('releases.errors.version_taken', ['version' => $version])]);
        }

        $files = $this->files($pack);
        $json = json_encode($this->manifest($pack, $version), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";

        $release = DB::transaction(function () use ($pack, $version, $publisher, $files, $json, $check): ModsyncRelease {
            $release = $pack->releases()->create([
                'version' => $version,
                'minecraft_version' => $pack->minecraft_version,
                'loader' => $pack->loader,
                'manifest' => $json,
                'content_hash' => hash('sha256', $json),
                'uploads' => $files->where('source', FileSource::Upload)->pluck('upload_path')->filter()->unique()->values()->all(),
                'files_count' => $files->count(),
                'warnings' => $check->warnings,
                'published_by' => $publisher?->getKey(),
            ]);

            $pack->liveRelease()->associate($release)->save();

            return $release;
        });

        Log::notice('modsync.release_published', ['publisher' => $publisher?->email, 'key' => $pack->key, 'version' => $version, 'files' => $release->files_count]);

        return $release;
    }

    /**
     * Makes an earlier (or later) release the one players get.
     */
    public function makeLive(ModsyncRelease $release): void
    {
        $pack = $release->pack;
        $previous = $pack->liveRelease?->version;

        $pack->liveRelease()->associate($release)->save();

        Log::notice('modsync.release_made_live', ['key' => $pack->key, 'version' => $release->version, 'previous' => $previous]);
    }

    /**
     * A version to offer for the next release: the live one with its last number raised.
     */
    public function nextVersion(ModsyncPack $pack): string
    {
        $current = $pack->liveRelease?->version ?? $pack->releases()->latest('id')->value('version');

        if ($current === null) {
            return '1.0.0';
        }

        return preg_match('/^(.*?)(\d+)(\D*)$/', $current, $match) === 1
            ? $match[1].((int) $match[2] + 1).$match[3]
            : $current;
    }

    /**
     * @return array<string, mixed>
     */
    public function manifest(ModsyncPack $pack, string $version): array
    {
        return [
            'formatVersion' => 1,
            'packId' => $pack->key,
            'packName' => $pack->name,
            'packVersion' => $version,
            'unlistedPolicy' => $pack->unlisted_policy,
            'files' => $this->files($pack)->map(fn (ModsyncFile $file): array => array_filter([
                'id' => $file->manifestId(),
                'label' => $file->name,
                'desc' => $file->description,
                'path' => $file->path,
                'size' => $file->size,
                'hashes' => array_filter(['sha512' => $file->sha512, 'sha1' => $file->sha1]) ?: null,
                'urls' => $file->downloadUrls() ?: null,
                'policy' => $file->policy->value,
                'side' => $file->side->value,
            ], fn (mixed $value): bool => $value !== null && $value !== ''))->values()->all(),
        ];
    }

    /**
     * @return Collection<int, ModsyncFile>
     */
    private function files(ModsyncPack $pack): Collection
    {
        return $pack->files()->orderBy('path')->get();
    }

    /**
     * What would make the client reject the manifest or fail to download the file.
     *
     * @return list<string>
     */
    private function fileErrors(ModsyncFile $file): array
    {
        $errors = [];

        if (! ManifestRules::isPath($file->path)) {
            $errors[] = __('releases.errors.bad_path', ['file' => $file->path]);
        }

        if ($file->policy === FilePolicy::Forbid) {
            return $errors;
        }

        if ($file->sha512 === null) {
            $errors[] = $file->hash_error !== null
                ? __('releases.errors.hash_failed', ['file' => $file->path, 'error' => $file->hash_error])
                : __('releases.errors.not_hashed', ['file' => $file->path]);
        }

        if ($file->downloadUrls() === []) {
            $errors[] = __('releases.errors.no_url', ['file' => $file->path]);
        }

        return $errors;
    }
}
