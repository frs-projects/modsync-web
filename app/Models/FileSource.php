<?php

namespace App\Models;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Where a file comes from. Modrinth and CurseForge files can be checked for updates; uploads
 * are served by the panel; a URL is downloaded once to hash it.
 */
enum FileSource: string implements HasColor, HasLabel
{
    case Modrinth = 'modrinth';
    case CurseForge = 'curseforge';
    case Upload = 'upload';
    case Url = 'url';

    public function getLabel(): string
    {
        return __("files.sources.{$this->value}");
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Modrinth => 'success',
            self::CurseForge => 'warning',
            self::Upload => 'info',
            self::Url => 'gray',
        };
    }

    public function isHost(): bool
    {
        return $this === self::Modrinth || $this === self::CurseForge;
    }
}
