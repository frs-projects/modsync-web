<?php

namespace App\Services;

use App\Hosts\CurseForge;
use App\Hosts\HostException;
use App\Hosts\Modrinth;
use App\Models\FilePolicy;
use App\Models\FileSide;
use App\Models\FileSource;
use App\Models\ModsyncFile;
use App\Models\ModsyncPack;
use App\Models\ProjectType;
use Illuminate\Support\Facades\DB;

/**
 * Reads a ModSync manifest — usually the output of `/modsync export resolve mods` — into a pack.
 * Entries are matched by path. Files Modrinth knows by their SHA-512 become Modrinth files (so
 * they can be updated); `curseforge:` entries keep their mod and, from the CDN URL, their file.
 * Entries without a URL cannot be published, so they are skipped: upload those files instead.
 */
class ManifestImporter
{
    public function __construct(private readonly Modrinth $modrinth, private readonly CurseForge $curseForge) {}

    /**
     * @param  bool  $removeMissing  Delete the pack's files the manifest does not list.
     *
     * @throws PackFileException when the document is not a manifest.
     */
    public function import(ModsyncPack $pack, string $json, bool $removeMissing = false): ImportResult
    {
        $document = json_decode($json, true);

        if (! is_array($document) || array_is_list($document) || ! is_array($document['files'] ?? null)) {
            throw new PackFileException(__('files.import_errors.not_manifest'));
        }

        if (($document['formatVersion'] ?? null) !== 1) {
            throw new PackFileException(__('files.import_errors.format'));
        }

        if (count($document['files']) > ManifestRules::MAX_FILES) {
            throw new PackFileException(__('files.import_errors.too_many', ['max' => ManifestRules::MAX_FILES]));
        }

        $result = new ImportResult;
        $entries = [];

        foreach (array_values($document['files']) as $index => $entry) {
            $parsed = is_array($entry) ? $this->entry($entry) : __('files.import_errors.not_object');

            if (is_string($parsed)) {
                $result->skipped[] = (is_array($entry) && is_string($entry['path'] ?? null) ? $entry['path'] : "files[{$index}]").": {$parsed}";
            } else {
                $entries[$parsed['path']] = $parsed;
            }
        }

        $entries = $this->adoptModrinth($entries, $result);
        $entries = $this->nameCurseForge($entries, $result);

        DB::transaction(function () use ($pack, $entries, $removeMissing, $result): void {
            $existing = $pack->files()->get()->keyBy('path');

            foreach ($entries as $path => $attributes) {
                /** @var ModsyncFile|null $file */
                $file = $existing[$path] ?? null;

                if ($file === null) {
                    $pack->files()->create($attributes);
                    $result->created++;
                } elseif ($file->source === FileSource::Upload && $file->sha512 === $attributes['sha512']) {
                    // The panel already serves this exact file.
                    continue;
                } elseif ($file->fill($attributes)->isDirty()) {
                    $file->save();
                    $result->updated++;
                }
            }

            if ($removeMissing) {
                foreach ($existing->except(array_keys($entries)) as $file) {
                    $file->delete();
                    $result->removed++;
                }
            }
        });

        return $result;
    }

    /**
     * One manifest entry as file attributes, or why it is skipped.
     *
     * @param  array<string, mixed>  $entry
     * @return array<string, mixed>|string
     */
    private function entry(array $entry): array|string
    {
        $path = is_string($entry['path'] ?? null) ? str_replace('\\', '/', $entry['path']) : null;

        if ($path === null || ! ManifestRules::isPath($path)) {
            return __('files.import_errors.bad_path');
        }

        $policy = is_string($entry['policy'] ?? null)
            ? FilePolicy::tryFrom(strtolower($entry['policy']))
            : (($entry['required'] ?? true) === false ? FilePolicy::Optional : FilePolicy::Require);
        $side = FileSide::tryFrom(strtolower((string) ($entry['side'] ?? 'both')));

        if ($policy === null || $side === null) {
            return __('files.import_errors.bad_policy');
        }

        $sha512 = $this->hex($entry['hashes']['sha512'] ?? null, 128);
        $sha1 = $this->hex($entry['hashes']['sha1'] ?? null, 40);

        if ($sha512 === null && $policy !== FilePolicy::Forbid) {
            return __('files.import_errors.no_hash');
        }

        $urls = collect([$entry['url'] ?? null, ...(is_array($entry['urls'] ?? null) ? $entry['urls'] : [])])
            ->filter(fn (mixed $url): bool => is_string($url) && str_starts_with($url, 'https://') && strlen($url) <= ManifestRules::MAX_URL_LENGTH)
            ->unique()
            ->take(ManifestRules::MAX_URLS)
            ->values()
            ->all();

        if ($urls === [] && $policy !== FilePolicy::Forbid) {
            return __('files.import_errors.no_url');
        }

        [$source, $projectId] = match (true) {
            is_string($entry['id'] ?? null) && preg_match('/^curseforge:(\d+)$/', $entry['id'], $match) === 1 => [FileSource::CurseForge, $match[1]],
            is_string($entry['id'] ?? null) && preg_match('/^modrinth:([A-Za-z0-9]+)$/', $entry['id'], $match) === 1 => [FileSource::Modrinth, $match[1]],
            default => [FileSource::Url, null],
        };

        return [
            'source' => $source,
            'project_id' => $projectId,
            'version_id' => $source === FileSource::CurseForge ? collect($urls)->map(CurseForge::fileIdFromUrl(...))->filter()->first() : null,
            'name' => is_string($entry['label'] ?? null) && trim($entry['label']) !== '' ? mb_substr(trim($entry['label']), 0, 255) : basename($path),
            'version_name' => null,
            'description' => is_string($entry['desc'] ?? null) ? mb_substr($entry['desc'], 0, 4096) : null,
            'path' => $path,
            'size' => is_int($entry['size'] ?? null) && $entry['size'] >= 0 ? $entry['size'] : null,
            'sha512' => $sha512,
            'sha1' => $sha1,
            'urls' => $urls,
            'policy' => $policy,
            'side' => $side,
        ];
    }

    /**
     * Files Modrinth has, by SHA-512, become Modrinth files with their project and version.
     *
     * @param  array<string, array<string, mixed>>  $entries
     * @return array<string, array<string, mixed>>
     */
    private function adoptModrinth(array $entries, ImportResult $result): array
    {
        $hashes = collect($entries)
            ->filter(fn (array $entry): bool => $entry['sha512'] !== null && $entry['source'] !== FileSource::CurseForge && $this->isProjectFile($entry['path']))
            ->pluck('sha512')
            ->all();

        if ($hashes === []) {
            return $entries;
        }

        try {
            $versions = $this->modrinth->versionsByHash($hashes);
            $projects = $this->modrinth->projects(array_map(fn ($version): string => $version->projectId, array_values($versions)));
        } catch (HostException $exception) {
            $result->warnings[] = $exception->getMessage();

            return $entries;
        }

        foreach ($entries as $path => $entry) {
            $version = $versions[$entry['sha512']] ?? null;

            if ($version === null || $entry['source'] === FileSource::CurseForge) {
                continue;
            }

            $project = $projects[$version->projectId] ?? null;
            $entries[$path] = [
                ...$entry,
                'source' => FileSource::Modrinth,
                'project_id' => $version->projectId,
                'version_id' => $version->id,
                'version_name' => $version->name,
                'name' => $entry['name'] === basename($path) && $project !== null ? $project->title : $entry['name'],
                'description' => $entry['description'] ?? $project?->summary,
                'page_url' => $project?->pageUrl,
                'icon_url' => $project?->iconUrl,
                'urls' => $entry['urls'] !== [] ? $entry['urls'] : array_filter([$version->url]),
            ];
        }

        return $entries;
    }

    /**
     * CurseForge files get their project's name, icon and page, when a key is configured.
     *
     * @param  array<string, array<string, mixed>>  $entries
     * @return array<string, array<string, mixed>>
     */
    private function nameCurseForge(array $entries, ImportResult $result): array
    {
        $ids = collect($entries)->where('source', FileSource::CurseForge)->pluck('project_id')->all();

        if ($ids === [] || ! $this->curseForge->isAvailable()) {
            return $entries;
        }

        try {
            $projects = $this->curseForge->projects($ids);
        } catch (HostException $exception) {
            $result->warnings[] = $exception->getMessage();

            return $entries;
        }

        foreach ($entries as $path => $entry) {
            $project = $entry['source'] === FileSource::CurseForge ? ($projects[$entry['project_id']] ?? null) : null;

            if ($project !== null) {
                $entries[$path] = [
                    ...$entry,
                    'name' => $entry['name'] === basename($path) ? $project->title : $entry['name'],
                    'version_name' => basename($path),
                    'page_url' => $project->pageUrl,
                    'icon_url' => $project->iconUrl,
                ];
            }
        }

        return $entries;
    }

    private function isProjectFile(string $path): bool
    {
        return in_array(strstr($path, '/', true), array_map(fn (ProjectType $type): string => $type->root(), ProjectType::cases()), true);
    }

    private function hex(mixed $value, int $length): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = strtolower(trim($value));

        return strlen($value) === $length && ctype_xdigit($value) ? $value : null;
    }
}
