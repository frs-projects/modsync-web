<?php

namespace App\Services;

use App\Hosts\HostException;
use App\Hosts\Hosts;
use App\Models\ModsyncFile;
use App\Models\ModsyncPack;

/**
 * Asks Modrinth and CurseForge for the newest version of each of a pack's files, for the pack's
 * Minecraft version and loader. Only records what it found; updating is a separate step.
 */
class UpdateChecker
{
    public function __construct(private readonly Hosts $hosts) {}

    /**
     * @return array{checked: int, updates: int, errors: list<string>}
     */
    public function check(ModsyncPack $pack): array
    {
        $result = ['checked' => 0, 'updates' => 0, 'errors' => []];

        foreach ($this->hosts->available() as $host) {
            $files = $pack->files()->where('source', $host->source())->get();

            if ($files->isEmpty()) {
                continue;
            }

            try {
                $latest = $host->latest($files->all(), $pack);
            } catch (HostException $exception) {
                $result['errors'][] = $exception->getMessage();

                continue;
            }

            foreach ($files as $file) {
                /** @var ModsyncFile $file */
                $version = $latest[$file->getKey()] ?? null;
                $file->forceFill([
                    'latest_version_id' => $version?->id,
                    'latest_version_name' => $version?->name,
                    'checked_at' => now(),
                ])->save();

                $result['checked']++;
                $result['updates'] += $file->hasUpdate() ? 1 : 0;
            }
        }

        return $result;
    }
}
