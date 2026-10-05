<?php

namespace App\Hosts;

use App\Models\FileSource;
use App\Models\ModsyncFile;
use App\Models\ModsyncPack;
use App\Models\ProjectType;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * CurseForge's API v1 (docs.curseforge.com), with the panel's key. CurseForge publishes SHA-1
 * and MD5 only, so its files are downloaded once to compute the client's SHA-512 (HashFile).
 * Authors can opt out of downloads outside CurseForge; those files have no URL and must be
 * uploaded instead.
 */
class CurseForge implements ModHost
{
    use SendsRequests;

    private const int MINECRAFT = 432;

    private const int BATCH = 100;

    private const array RELEASE_TYPES = [1 => 'release', 2 => 'beta', 3 => 'alpha'];

    public function source(): FileSource
    {
        return FileSource::CurseForge;
    }

    public function isAvailable(): bool
    {
        return filled(config('modsync.curseforge.key'));
    }

    public function search(string $query, ProjectType $type, ModsyncPack $pack): array
    {
        $mods = $this->get('/mods/search', array_filter([
            'gameId' => self::MINECRAFT,
            'classId' => $type->curseForgeClass(),
            'searchFilter' => $query,
            'gameVersion' => $pack->minecraft_version,
            'modLoaderType' => $type === ProjectType::Mod ? $pack->loader->curseForgeType() : null,
            'sortField' => 2,
            'sortOrder' => 'desc',
            'pageSize' => 20,
        ]))->json('data') ?? [];

        return array_map(fn (array $mod): RemoteProject => $this->toProject($mod), $mods);
    }

    public function project(string $projectId): ?RemoteProject
    {
        $response = $this->get("/mods/{$projectId}", allowMissing: true);

        return $response->notFound() ? null : $this->toProject($response->json('data'));
    }

    public function versions(string $projectId, ProjectType $type, ModsyncPack $pack): array
    {
        $response = $this->get("/mods/{$projectId}/files", array_filter([
            'gameVersion' => $pack->minecraft_version,
            'modLoaderType' => $type === ProjectType::Mod ? $pack->loader->curseForgeType() : null,
            'pageSize' => 50,
        ]), allowMissing: true);

        if ($response->notFound()) {
            return [];
        }

        return collect($response->json('data') ?? [])
            ->sortByDesc('fileDate')
            ->map(fn (array $file): RemoteVersion => $this->toVersion($file))
            ->values()
            ->all();
    }

    public function version(string $projectId, string $versionId): ?RemoteVersion
    {
        $response = $this->get("/mods/{$projectId}/files/{$versionId}", allowMissing: true);

        return $response->notFound() ? null : $this->toVersion($response->json('data'));
    }

    /**
     * From each mod's `latestFilesIndexes`: the newest release for the pack's game version (and
     * loader, for mods), or the newest beta/alpha when there is no release.
     */
    public function latest(array $files, ModsyncPack $pack): array
    {
        $byProject = collect($files)->filter(fn (ModsyncFile $file): bool => $file->project_id !== null)->groupBy('project_id');
        $latest = [];

        foreach ($byProject->keys()->chunk(self::BATCH) as $ids) {
            $mods = $this->post('/mods', ['modIds' => $ids->map(fn (string $id): int => (int) $id)->values()->all()])->json('data') ?? [];

            foreach ($mods as $mod) {
                foreach ($byProject[(string) $mod['id']] ?? [] as $file) {
                    $isMod = $file->projectType() === ProjectType::Mod;
                    $candidates = collect($mod['latestFilesIndexes'] ?? [])->filter(fn (array $index): bool => $index['gameVersion'] === $pack->minecraft_version
                        && (! $isMod || ($index['modLoader'] ?? null) === $pack->loader->curseForgeType()));
                    $releases = $candidates->where('releaseType', 1);
                    $index = ($releases->isNotEmpty() ? $releases : $candidates)->sortByDesc('fileId')->first();

                    if ($index !== null) {
                        $latest[$file->getKey()] = new RemoteVersion(
                            id: (string) $index['fileId'],
                            projectId: (string) $mod['id'],
                            name: (string) $index['filename'],
                            fileName: (string) $index['filename'],
                            releaseType: self::RELEASE_TYPES[$index['releaseType']] ?? 'release',
                        );
                    }
                }
            }
        }

        return $latest;
    }

    /**
     * @param  list<string>  $ids
     * @return array<string, RemoteProject> id => project
     */
    public function projects(array $ids): array
    {
        $found = [];

        foreach (array_chunk(array_values(array_unique($ids)), self::BATCH) as $batch) {
            foreach ($this->post('/mods', ['modIds' => array_map('intval', $batch)])->json('data') ?? [] as $mod) {
                $found[(string) $mod['id']] = $this->toProject($mod);
            }
        }

        return $found;
    }

    /**
     * The file id in a CDN URL: `https://edge.forgecdn.net/files/4567/89/name.jar` is file 4567089.
     */
    public static function fileIdFromUrl(string $url): ?string
    {
        if (preg_match('#^https://[^/]*forgecdn\.net/files/(\d+)/(\d+)/#', $url, $match) !== 1) {
            return null;
        }

        return (string) ((int) $match[1] * 1000 + (int) $match[2]);
    }

    /**
     * @param  array<string, mixed>  $mod
     */
    private function toProject(array $mod): RemoteProject
    {
        return new RemoteProject(
            id: (string) $mod['id'],
            title: (string) $mod['name'],
            author: $mod['authors'][0]['name'] ?? null,
            summary: $mod['summary'] ?? null,
            iconUrl: $mod['logo']['thumbnailUrl'] ?? null,
            pageUrl: $mod['links']['websiteUrl'] ?? null,
            downloads: (int) ($mod['downloadCount'] ?? 0),
        );
    }

    /**
     * @param  array<string, mixed>  $file
     */
    private function toVersion(array $file): RemoteVersion
    {
        $sha1 = collect($file['hashes'] ?? [])->firstWhere('algo', 1)['value'] ?? null;

        return new RemoteVersion(
            id: (string) $file['id'],
            projectId: (string) $file['modId'],
            name: (string) ($file['displayName'] ?? $file['fileName']),
            fileName: (string) $file['fileName'],
            size: isset($file['fileLength']) ? (int) $file['fileLength'] : null,
            url: $file['downloadUrl'] ?? null,
            sha1: $sha1 !== null ? strtolower($sha1) : null,
            releaseType: self::RELEASE_TYPES[$file['releaseType'] ?? 1] ?? 'release',
            publishedAt: $file['fileDate'] ?? null,
        );
    }

    protected function hostName(): string
    {
        return 'CurseForge';
    }

    protected function request(): PendingRequest
    {
        return Http::baseUrl(config('modsync.curseforge.url'))
            ->withUserAgent(config('modsync.user_agent'))
            ->withHeaders(['x-api-key' => (string) config('modsync.curseforge.key')]);
    }
}
