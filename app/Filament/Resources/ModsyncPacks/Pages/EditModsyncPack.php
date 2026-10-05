<?php

namespace App\Filament\Resources\ModsyncPacks\Pages;

use App\Filament\Resources\ModsyncPacks\ModsyncPackResource;
use App\Models\ModsyncPack;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\Log;

/**
 * @property ModsyncPack $record
 */
class EditModsyncPack extends EditRecord
{
    protected static string $resource = ModsyncPackResource::class;

    public function getSubheading(): string
    {
        return __('packs.subheading', ['url' => $this->record->manifestUrl()]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('manifest')
                ->label(__('packs.open_manifest'))
                ->icon('heroicon-o-arrow-top-right-on-square')
                ->color('gray')
                ->url(fn (): string => $this->record->manifestUrl(), shouldOpenInNewTab: true),
            DeleteAction::make()
                ->modalDescription(__('packs.delete_confirm'))
                ->before(fn (ModsyncPack $record) => Log::notice('modsync.pack_deleted', ['key' => $record->key, 'files' => $record->files()->count()])),
        ];
    }
}
