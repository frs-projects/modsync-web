<?php

namespace App\Hosts;

use App\Models\FileSide;
use App\Models\FileSource;
use App\Models\ModsyncFile;
use App\Models\ModsyncPack;
use App\Models\ProjectType;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Modrinth's API v2 (docs.modrinth.com). No key needed, but every call names this app. Versions
 * carry SHA-512, the client's integrity hash, so Modrinth files need no download.
 */
class Modrinth implements ModHost
{
    use SendsRequests;

    /**
     * Modrinth answers far more per call, but a smaller batch fails smaller.
     */
    private const int BATCH = 100;

    public function source(): FileSource
    {
        return FileSource::Modrinth;
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function search(string $query, ProjectType $type, ModsyncPack $pack): array
    {
        $facets = [["project_type:{$this->projectType($type)}"], ["versions:{$pack->minecraft_version}"]];

        if ($type === ProjectType::Mod) {
            $facets[] = ["categories:{$pack->loader->value}"];
        }

        $hits = $this->get('/search', [
            'query' => $query,
            'facets' => json_encode($facets),
            'limit' => 20,
        ])->json('hits') ?? [];

        return array_map(fn (array $hit): RemoteProject => $this->toProject($hit, $hit['project_id']), $hits);
    }

    public function project(string $projectId): ?RemoteProject
    {
        $response = $this->get("/project/{$projectId}", allowMissing: true);

        return $response->notFound() ? null : $this->toProject($response->json(), $projectId);
    }

    public function versions(string $projectId, ProjectType $type, ModsyncPack $pack): array
    {
        $query = ['game_versions' => json_encode([$pack->minecraft_version])];

        if ($type === ProjectType::Mod) {
            $query['loaders'] = json_encode([$pack->loader->value]);
        }

        $response = $this->get("/project/{$projectId}/version", $query, allowMissing: true);

        if ($response->notFound()) {
            return [];
        }

        return array_values(array_filter(array_map(fn (array $version): ?RemoteVersion => $this->toVersion($version), $response->json() ?? [])));
    }

    public function version(string $projectId, string $versionId): ?RemoteVersion
    {
        $response = $this->get("/version/{$versionId}", allowMissing: true);

        return $response->notFound() ? null : $this->toVersion($response->json());
    }

    public function latest(array $files, ModsyncPack $pack): array
    {
        $latest = [];

        // Only mods are filtered by loader: resource packs and shaders have their own "loaders".
        foreach (collect($files)->filter(fn (ModsyncFile $file): bool => $file->sha512 !== null)->groupBy(fn (ModsyncFile $file): bool => $file->projectType() === ProjectType::Mod) as $isMod => $group) {
            $byHash = $group->groupBy('sha512');

            foreach ($byHash->keys()->chunk(self::BATCH) as $hashes) {
                $body = ['hashes' => $hashes->values()->all(), 'algorithm' => 'sha512', 'game_versions' => [$pack->minecraft_version]];

                if ($isMod) {
                    $body['loaders'] = [$pack->loader->value];
                }

                foreach ($this->post('/version_files/update', $body)->json() ?? [] as $hash => $version) {
                    if (is_array($version) && ($remote = $this->toVersion($version)) !== null) {
                        foreach ($byHash[$hash] ?? [] as $file) {
                            $latest[$file->getKey()] = $remote;
                        }
                    }
                }
            }
        }

        return $latest;
    }

    /**
     * The versions files with these SHA-512 hashes belong to, for adopting an imported manifest.
     *
     * @param  list<string>  $hashes
     * @return array<string, RemoteVersion> hash => version
     */
    public function versionsByHash(array $hashes): array
    {
        $found = [];

        foreach (array_chunk(array_values(array_unique($hashes)), self::BATCH) as $batch) {
            foreach ($this->post('/version_files', ['hashes' => $batch, 'algorithm' => 'sha512'])->json() ?? [] as $hash => $version) {
                if (is_array($version) && ($remote = $this->toVersion($version, $hash)) !== null) {
                    $found[$hash] = $remote;
                }
            }
        }

        return $found;
    }

    /**
     * @param  list<string>  $ids
     * @return array<string, RemoteProject> id => project
     */
    public function projects(array $ids): array
    {
        $found = [];

        foreach (array_chunk(array_values(array_unique($ids)), self::BATCH) as $batch) {
            foreach ($this->get('/projects', ['ids' => json_encode($batch)])->json() ?? [] as $project) {
                $found[$project['id']] = $this->toProject($project, $project['id']);
            }
        }

        return $found;
    }

    /**
     * @param  array<string, mixed>  $project  A search hit or a project.
     */
    private function toProject(array $project, string $id): RemoteProject
    {
        $type = $project['project_type'] ?? 'mod';

        return new RemoteProject(
            id: $id,
            title: (string) ($project['title'] ?? $id),
            author: $project['author'] ?? null,
            summary: $project['description'] ?? null,
            iconUrl: $project['icon_url'] ?? null,
            pageUrl: isset($project['slug']) ? "https://modrinth.com/{$type}/{$project['slug']}" : null,
            downloads: (int) ($project['downloads'] ?? 0),
            side: match (true) {
                ($project['server_side'] ?? null) === 'unsupported' => FileSide::Client,
                ($project['client_side'] ?? null) === 'unsupported' => FileSide::Server,
                default => null,
            },
        );
    }

    /**
     * The version's file: the one with the hash asked about, else the primary, else the first.
     * A version can carry sources and javadoc jars alongside the mod.
     *
     * @param  array<string, mixed>  $version
     */
    private function toVersion(array $version, ?string $sha512 = null): ?RemoteVersion
    {
        $files = collect($version['files'] ?? []);
        $file = ($sha512 !== null ? $files->first(fn (array $file): bool => ($file['hashes']['sha512'] ?? null) === $sha512) : null)
            ?? $files->firstWhere('primary', true)
            ?? $files->first();

        if ($file === null) {
            return null;
        }

        return new RemoteVersion(
            id: (string) $version['id'],
            projectId: (string) $version['project_id'],
            name: (string) ($version['version_number'] ?? $version['name'] ?? $version['id']),
            fileName: (string) $file['filename'],
            size: isset($file['size']) ? (int) $file['size'] : null,
            url: $file['url'] ?? null,
            sha512: $file['hashes']['sha512'] ?? null,
            sha1: $file['hashes']['sha1'] ?? null,
            releaseType: (string) ($version['version_type'] ?? 'release'),
            publishedAt: $version['date_published'] ?? null,
        );
    }

    private function projectType(ProjectType $type): string
    {
        return match ($type) {
            ProjectType::Mod => 'mod',
            ProjectType::ResourcePack => 'resourcepack',
            ProjectType::Shader => 'shader',
        };
    }

    protected function hostName(): string
    {
        return 'Modrinth';
    }

    protected function request(): PendingRequest
    {
        return Http::baseUrl(config('modsync.modrinth.url'))->withUserAgent(config('modsync.user_agent'));
    }
}
