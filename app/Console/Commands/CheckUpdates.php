<?php

namespace App\Console\Commands;

use App\Models\ModsyncPack;
use App\Services\UpdateChecker;
use Illuminate\Console\Command;

/**
 * Checks every pack's Modrinth and CurseForge files for newer versions (scheduled daily).
 */
class CheckUpdates extends Command
{
    protected $signature = 'modsync:check-updates {pack? : Only this pack (key)}';

    protected $description = 'Check ModSync pack files for newer versions on Modrinth and CurseForge';

    public function handle(UpdateChecker $checker): int
    {
        $packs = ModsyncPack::query()->when($this->argument('pack'), fn ($query, string $key) => $query->where('key', $key))->orderBy('key')->get();

        if ($packs->isEmpty()) {
            $this->components->warn('No packs to check.');

            return self::SUCCESS;
        }

        $failed = false;

        foreach ($packs as $pack) {
            $result = $checker->check($pack);
            $this->components->info("{$pack->key}: {$result['checked']} checked, {$result['updates']} with updates.");

            foreach ($result['errors'] as $error) {
                $this->components->error("{$pack->key}: {$error}");
                $failed = true;
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
