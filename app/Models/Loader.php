<?php

namespace App\Models;

use Filament\Support\Contracts\HasLabel;

/**
 * The mod loader a pack is for. Mod versions on Modrinth and CurseForge are filtered by it.
 */
enum Loader: string implements HasLabel
{
    case Forge = 'forge';
    case NeoForge = 'neoforge';
    case Fabric = 'fabric';
    case Quilt = 'quilt';

    public function getLabel(): string
    {
        return match ($this) {
            self::Forge => 'Forge',
            self::NeoForge => 'NeoForge',
            self::Fabric => 'Fabric',
            self::Quilt => 'Quilt',
        };
    }

    /**
     * CurseForge's `modLoaderType`.
     */
    public function curseForgeType(): int
    {
        return match ($this) {
            self::Forge => 1,
            self::Fabric => 4,
            self::Quilt => 5,
            self::NeoForge => 6,
        };
    }
}
