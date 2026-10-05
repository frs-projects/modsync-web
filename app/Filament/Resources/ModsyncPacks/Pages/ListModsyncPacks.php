<?php

namespace App\Filament\Resources\ModsyncPacks\Pages;

use App\Filament\Resources\ModsyncPacks\ModsyncPackResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListModsyncPacks extends ListRecords
{
    protected static string $resource = ModsyncPackResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
