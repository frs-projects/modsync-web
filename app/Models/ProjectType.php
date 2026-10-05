<?php

namespace App\Models;

use Filament\Support\Contracts\HasLabel;

/**
 * What kind of project a file comes from, which decides its folder and its defaults (the same
 * defaults as `/modsync export`: shader and resource packs are optional and client-only).
 */
enum ProjectType: string implements HasLabel
{
    case Mod = 'mod';
    case ResourcePack = 'resourcepack';
    case Shader = 'shader';

    public function getLabel(): string
    {
        return __("files.types.{$this->value}");
    }

    public function root(): string
    {
        return match ($this) {
            self::Mod => 'mods',
            self::ResourcePack => 'resourcepacks',
            self::Shader => 'shaderpacks',
        };
    }

    public static function fromPath(string $path): self
    {
        return match (strstr($path, '/', true)) {
            'resourcepacks' => self::ResourcePack,
            'shaderpacks' => self::Shader,
            default => self::Mod,
        };
    }

    public function defaultPolicy(): FilePolicy
    {
        return $this === self::Mod ? FilePolicy::Require : FilePolicy::Optional;
    }

    public function defaultSide(): FileSide
    {
        return $this === self::Mod ? FileSide::Both : FileSide::Client;
    }

    /**
     * CurseForge's `classId` for Minecraft.
     */
    public function curseForgeClass(): int
    {
        return match ($this) {
            self::Mod => 6,
            self::ResourcePack => 12,
            self::Shader => 6552,
        };
    }
}
