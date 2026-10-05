<?php

namespace App\Services;

use App\Hosts\HostException;
use App\Hosts\Hosts;
use App\Hosts\RemoteVersion;
use App\Jobs\HashFile;
use App\Models\FilePolicy;
use App\Models\FileSide;
use App\Models\FileSource;
use App\Models\ModsyncFile;
use App\Models\ModsyncPack;
use App\Models\ProjectType;
use Illuminate\Support\Facades\Storage;

/**
 * Adds files to packs and moves them to other versions. Model changes are audited by the models.
 */
class PackFiles
{
    public function __construct(private readonly Hosts $hosts, private readonly FileHasher $hasher) {}

    /**
     * Adds a version of a Modrinth or CurseForge project. Policy and side default by type, and
     * the side follows Modrinth's client/server support where it says.
     *
     * @param  array{policy?: FilePolicy|string|null, side?: FileSide|string|null}  $attributes
     *
     * @throws PackFileException|HostException
     */
    public function addFromHost(ModsyncPack $pack, FileSource $source, string $projectId, string $versionId, ProjectType $type, array $attributes = []): ModsyncFile
    {
        $host = $this->hosts->for($source);
        $project = $host->project($projectId) ?? throw new PackFileException(__('files.errors.project_missing'));
        $version = $host->version($projectId, $versionId) ?? throw new PackFileException(__('files.errors.version_missing'));

        if ($pack->files()->where('source', $source)->where('project_id', $project->id)->exists()) {
            throw new PackFileException(__('files.errors.already_added', ['name' => $project->title]));
        }

        $file = new ModsyncFile([
            'source' => $source,
            'project_id' => $project->id,
            'name' => $project->title,
            'description' => $project->summary,
            'page_url' => $project->pageUrl,
            'icon_url' => $project->iconUrl,
            'policy' => $attributes['policy'] ?? $type->defaultPolicy(),
            'side' => $attributes['side'] ?? $project->side ?? $type->defaultSide(),
        ]);
        $file->pack()->associate($pack);

        return $this->applyVersion($file, $version, $type->root());
    }

    /**
     * Moves a file to another version of its project: the update check's latest by default.
     *
     * @throws PackFileException|HostException
     */
    public function update(ModsyncFile $file, ?string $versionId = null): ModsyncFile
    {
        $versionId ??= $file->latest_version_id ?? throw new PackFileException(__('files.errors.no_update'));

        $version = $this->hosts->for($file->source)->version((string) $file->project_id, $versionId)
            ?? throw new PackFileException(__('files.errors.version_missing'));

        return $this->applyVersion($file, $version, dirname($file->path));
    }

    /**
     * Adds a file kept by the panel. The upload is stored by its SHA-512 and served at
     * /files.
     *
     * @param  string  $localPath  The uploaded file on the modsync disk; moved away.
     * @param  array{name?: string|null, description?: string|null, policy?: FilePolicy|string|null, side?: FileSide|string|null}  $attributes
     *
     * @throws PackFileException
     */
    public function addUpload(ModsyncPack $pack, string $localPath, string $fileName, string $folder, array $attributes = []): ModsyncFile
    {
        $disk = Storage::disk(config('modsync.disk'));

        try {
            $path = $this->path($pack, $folder, $fileName);
        } catch (PackFileException $exception) {
            $disk->delete($localPath);

            throw $exception;
        }

        $hashes = $this->hasher->hashLocal($disk->path($localPath));
        $stored = "modsync/uploads/{$hashes['sha512']}";

        if ($disk->exists($stored)) {
            $disk->delete($localPath);
        } else {
            $disk->move($localPath, $stored);
        }

        return $pack->files()->create([
            ...$hashes,
            ...$this->defaults($folder, $attributes, $fileName),
            'source' => FileSource::Upload,
            'path' => $path,
            'upload_path' => $stored,
        ]);
    }

    /**
     * Adds a file from any HTTPS URL; it is downloaded once to hash it.
     *
     * @param  array{name?: string|null, description?: string|null, policy?: FilePolicy|string|null, side?: FileSide|string|null}  $attributes
     *
     * @throws PackFileException
     */
    public function addUrl(ModsyncPack $pack, string $url, string $fileName, string $folder, array $attributes = []): ModsyncFile
    {
        $file = $pack->files()->create([
            ...$this->defaults($folder, $attributes, $fileName),
            'source' => FileSource::Url,
            'path' => $this->path($pack, $folder, $fileName),
            'urls' => [$url],
        ]);

        HashFile::dispatch($file);

        return $file;
    }

    /**
     * @param  array{name?: string|null, description?: string|null, policy?: FilePolicy|string|null, side?: FileSide|string|null}  $attributes
     * @return array<string, mixed>
     */
    private function defaults(string $folder, array $attributes, string $fileName): array
    {
        $type = ProjectType::fromPath("{$folder}/");

        return [
            'name' => filled($attributes['name'] ?? null) ? $attributes['name'] : $fileName,
            'description' => $attributes['description'] ?? null,
            'policy' => $attributes['policy'] ?? $type->defaultPolicy(),
            'side' => $attributes['side'] ?? $type->defaultSide(),
        ];
    }

    /**
     * @throws PackFileException|HostException
     */
    private function applyVersion(ModsyncFile $file, RemoteVersion $version, string $folder): ModsyncFile
    {
        if ($version->url === null) {
            throw new PackFileException(__('files.errors.no_third_party', ['name' => $file->name]));
        }

        $path = $this->path($file->pack, $folder, $version->fileName, $file);

        // A version another pack already has needs no second download.
        $known = $version->sha512 === null
            ? ModsyncFile::query()->where('source', $file->source)->where('version_id', $version->id)->whereNotNull('sha512')->first()
            : null;

        $file->fill([
            'version_id' => $version->id,
            'version_name' => $version->name,
            'path' => $path,
            'size' => $known->size ?? $version->size,
            'sha512' => $known->sha512 ?? $version->sha512,
            'sha1' => $known->sha1 ?? $version->sha1,
            'urls' => [$version->url],
            'hash_error' => null,
        ])->save();

        if ($file->sha512 === null) {
            HashFile::dispatch($file);
        }

        return $file;
    }

    /**
     * @throws PackFileException
     */
    private function path(ModsyncPack $pack, string $folder, string $fileName, ?ModsyncFile $except = null): string
    {
        $path = "{$folder}/{$fileName}";

        if (! ManifestRules::isSegment($fileName) || ! ManifestRules::isPath($path)) {
            throw new PackFileException(__('files.errors.bad_path', ['path' => $path]));
        }

        if ($pack->files()->where('path', $path)->when($except?->exists, fn ($query) => $query->whereKeyNot($except->getKey()))->exists()) {
            throw new PackFileException(__('files.errors.path_taken', ['path' => $path]));
        }

        return $path;
    }
}
