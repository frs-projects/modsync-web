<?php

namespace App\Hosts;

use App\Models\FileSource;
use InvalidArgumentException;

/**
 * The hosts files can come from, by source.
 */
class Hosts
{
    public function __construct(private readonly Modrinth $modrinth, private readonly CurseForge $curseForge) {}

    public function for(FileSource $source): ModHost
    {
        return match ($source) {
            FileSource::Modrinth => $this->modrinth,
            FileSource::CurseForge => $this->curseForge,
            default => throw new InvalidArgumentException("{$source->value} is not a host."),
        };
    }

    /**
     * @return list<ModHost>
     */
    public function available(): array
    {
        return array_values(array_filter([$this->modrinth, $this->curseForge], fn (ModHost $host): bool => $host->isAvailable()));
    }
}
