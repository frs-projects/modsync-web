<?php

namespace Tests\Support;

use Illuminate\Support\Facades\Http;

/**
 * Modrinth and CurseForge payloads, shaped like their APIs' answers, for Http::fake.
 */
final class FakeHosts
{
    public const string MODRINTH = 'https://api.modrinth.com/v2';

    public const string CURSEFORGE = 'https://api.curseforge.com/v1';

    /**
     * Fake exactly these endpoints; anything else fails.
     *
     * @param  array<string, mixed>  $routes
     */
    public static function fake(array $routes): void
    {
        Http::preventStrayRequests();
        Http::fake($routes);
    }

    public static function enableCurseForge(): void
    {
        config(['modsync.curseforge.key' => 'test-key']);
    }

    /**
     * @return array<string, mixed>
     */
    public static function modrinthProject(string $id, string $title, array $overrides = []): array
    {
        return [
            'id' => $id,
            'slug' => strtolower($title),
            'title' => $title,
            'description' => "{$title} does things.",
            'project_type' => 'mod',
            'icon_url' => "https://cdn.modrinth.com/data/{$id}/icon.png",
            'client_side' => 'required',
            'server_side' => 'required',
            'downloads' => 1000,
            ...$overrides,
        ];
    }

    /**
     * A version with one primary jar, whose hashes derive from its file name.
     *
     * @return array<string, mixed>
     */
    public static function modrinthVersion(string $id, string $projectId, string $fileName, string $number = '1.0.0'): array
    {
        return [
            'id' => $id,
            'project_id' => $projectId,
            'name' => "Release {$number}",
            'version_number' => $number,
            'version_type' => 'release',
            'date_published' => '2026-09-01T10:00:00Z',
            'files' => [[
                'url' => "https://cdn.modrinth.com/data/{$projectId}/versions/{$id}/{$fileName}",
                'filename' => $fileName,
                'primary' => true,
                'size' => 1234,
                'hashes' => ['sha512' => hash('sha512', $fileName), 'sha1' => sha1($fileName)],
            ]],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function curseForgeMod(int $id, string $name, array $overrides = []): array
    {
        return [
            'id' => $id,
            'name' => $name,
            'summary' => "{$name} does things.",
            'downloadCount' => 5000,
            'logo' => ['thumbnailUrl' => "https://media.forgecdn.net/avatars/{$id}.png"],
            'authors' => [['name' => 'someone']],
            'links' => ['websiteUrl' => "https://www.curseforge.com/minecraft/mc-mods/{$id}"],
            ...$overrides,
        ];
    }

    /**
     * A file whose SHA-1 is that of $contents (what the CDN serves).
     *
     * @return array<string, mixed>
     */
    public static function curseForgeFile(int $id, int $modId, string $fileName, string $contents, bool $downloadable = true): array
    {
        return [
            'id' => $id,
            'modId' => $modId,
            'displayName' => $fileName,
            'fileName' => $fileName,
            'fileLength' => strlen($contents),
            'downloadUrl' => $downloadable ? self::curseForgeUrl($id, $fileName) : null,
            'hashes' => [['value' => sha1($contents), 'algo' => 1], ['value' => md5($contents), 'algo' => 2]],
            'releaseType' => 1,
            'fileDate' => '2026-09-01T10:00:00Z',
        ];
    }

    public static function curseForgeUrl(int $fileId, string $fileName): string
    {
        return sprintf('https://edge.forgecdn.net/files/%d/%d/%s', intdiv($fileId, 1000), $fileId % 1000, $fileName);
    }
}
