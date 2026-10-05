<?php

namespace App\Models;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Whether a player may decline a file (the manifest's `policy`). `Forbid` names a file to move
 * out of the player's game directory.
 */
enum FilePolicy: string implements HasColor, HasLabel
{
    case Require = 'require';
    case Recommend = 'recommend';
    case Optional = 'optional';
    case Forbid = 'forbid';

    public function getLabel(): string
    {
        return __("files.policies.{$this->value}");
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Require => 'primary',
            self::Recommend => 'info',
            self::Optional => 'gray',
            self::Forbid => 'danger',
        };
    }
}
