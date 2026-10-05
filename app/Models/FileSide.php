<?php

namespace App\Models;

use Filament\Support\Contracts\HasLabel;

/**
 * Where a file belongs (the manifest's `side`).
 */
enum FileSide: string implements HasLabel
{
    case Both = 'both';
    case Client = 'client';
    case Server = 'server';

    public function getLabel(): string
    {
        return __("files.sides.{$this->value}");
    }
}
