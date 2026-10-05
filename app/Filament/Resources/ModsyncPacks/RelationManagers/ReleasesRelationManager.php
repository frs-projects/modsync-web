<?php

namespace App\Filament\Resources\ModsyncPacks\RelationManagers;

use App\Models\ModsyncPack;
use App\Models\ModsyncRelease;
use App\Models\User;
use App\Services\PackReleases;
use App\Services\ReleaseFailed;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Enums\Width;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;

/**
 * A pack's releases: publish the files as a new one, or make an older one live again..
 */
class ReleasesRelationManager extends RelationManager
{
    protected static string $relationship = 'releases';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('releases.title');
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('version')
            ->defaultSort('id', 'desc')
            ->modifyQueryUsing(fn ($query) => $query->with('publisher'))
            ->columns([
                TextColumn::make('version')
                    ->label(__('releases.version'))
                    ->description(fn (ModsyncRelease $record): string => substr($record->content_hash, 0, 12))
                    ->badge(fn (ModsyncRelease $record): bool => $this->isLive($record))
                    ->color(fn (ModsyncRelease $record): ?string => $this->isLive($record) ? 'success' : null)
                    ->icon(fn (ModsyncRelease $record): ?string => $this->isLive($record) ? 'heroicon-o-signal' : null)
                    ->tooltip(fn (ModsyncRelease $record): ?string => $this->isLive($record) ? __('releases.live') : null),
                TextColumn::make('game')
                    ->label(__('releases.game'))
                    ->state(fn (ModsyncRelease $record): string => "{$record->loader->getLabel()} {$record->minecraft_version}"),
                TextColumn::make('files_count')
                    ->label(__('releases.files')),
                TextColumn::make('publisher.name')
                    ->label(__('releases.published_by'))
                    ->placeholder('—'),
                TextColumn::make('created_at')
                    ->label(__('releases.published_at'))
                    ->since()
                    ->dateTimeTooltip(),
            ])
            ->headerActions([
                $this->publishAction(),
            ])
            ->recordActions([
                Action::make('manifest')
                    ->label(__('releases.view_manifest'))
                    ->icon('heroicon-o-code-bracket')
                    ->color('gray')
                    ->modalHeading(fn (ModsyncRelease $record): string => "{$this->pack()->key} {$record->version}")
                    ->modalContent(fn (ModsyncRelease $record): HtmlString => new HtmlString('<pre style="overflow-x: auto; font-size: 0.75rem; line-height: 1rem">'.e($record->manifest).'</pre>'))
                    ->modalWidth(Width::FourExtraLarge)
                    ->modalSubmitAction(false),
                Action::make('makeLive')
                    ->label(__('releases.make_live'))
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('warning')
                    ->hidden(fn (ModsyncRelease $record): bool => $this->isLive($record))
                    ->requiresConfirmation()
                    ->modalDescription(fn (ModsyncRelease $record): string => __('releases.make_live_confirm', ['version' => $record->version]))
                    ->action(function (ModsyncRelease $record): void {
                        app(PackReleases::class)->makeLive($record);
                        $this->pack()->refresh();

                        Notification::make()->success()->title(__('releases.made_live', ['version' => $record->version]))->send();
                    }),
            ])
            ->emptyStateHeading(__('releases.empty'))
            ->emptyStateDescription(__('releases.empty_help'));
    }

    private function publishAction(): Action
    {
        return Action::make('publish')
            ->label(__('releases.publish'))
            ->icon('heroicon-o-cloud-arrow-up')
            ->modalHeading(fn (): string => __('releases.publish_heading', ['pack' => $this->pack()->name]))
            ->modalDescription(function (): HtmlString {
                $check = app(PackReleases::class)->check($this->pack());
                $lines = $check->passes()
                    ? [e(__('releases.publish_help')), ...array_map(fn (string $warning): string => '⚠ '.e($warning), $check->warnings)]
                    : [e(__('releases.publish_blocked')), ...array_map(fn (string $error): string => '✕ '.e($error), $check->errors)];

                return new HtmlString(implode('<br>', $lines));
            })
            ->schema([
                TextInput::make('version')
                    ->label(__('releases.version'))
                    ->helperText(__('releases.version_help'))
                    ->default(fn (): string => app(PackReleases::class)->nextVersion($this->pack()))
                    ->required()
                    ->maxLength(64),
            ])
            ->action(function (array $data, Action $action): void {
                $user = Filament::auth()->user();

                try {
                    $release = app(PackReleases::class)->publish($this->pack(), trim($data['version']), $user instanceof User ? $user : null);
                } catch (ReleaseFailed $exception) {
                    Notification::make()->danger()->title(__('releases.publish_failed'))->body(implode("\n", $exception->errors))->send();

                    $action->halt();

                    return;
                }

                Notification::make()->success()->title(__('releases.published', ['version' => $release->version]))->send();
            });
    }

    private function isLive(ModsyncRelease $release): bool
    {
        return $this->pack()->live_release_id === $release->getKey();
    }

    private function pack(): ModsyncPack
    {
        /** @var ModsyncPack */
        return $this->getOwnerRecord();
    }
}
