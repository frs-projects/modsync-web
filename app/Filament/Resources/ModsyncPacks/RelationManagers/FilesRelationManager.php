<?php

namespace App\Filament\Resources\ModsyncPacks\RelationManagers;

use App\Hosts\HostException;
use App\Hosts\Hosts;
use App\Hosts\ModHost;
use App\Hosts\RemoteVersion;
use App\Models\FilePolicy;
use App\Models\FileSide;
use App\Models\FileSource;
use App\Models\ModsyncFile;
use App\Models\ModsyncPack;
use App\Models\ProjectType;
use App\Services\ManifestImporter;
use App\Services\ManifestRules;
use App\Services\PackFileException;
use App\Services\PackFiles;
use App\Services\UpdateChecker;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Enums\Width;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Number;

/**
 * A pack's files: add from Modrinth/CurseForge (live search), upload, URL or a ModSync export;
 * check for updates and move files to other versions..
 */
class FilesRelationManager extends RelationManager
{
    protected static string $relationship = 'files';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('files.title');
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->defaultSort('path')
            ->paginated([25, 50, 100, 'all'])
            ->defaultPaginationPageOption(50)
            ->columns([
                ImageColumn::make('icon_url')
                    ->label('')
                    ->imageSize(28)
                    ->extraImgAttributes(['loading' => 'lazy', 'referrerpolicy' => 'no-referrer']),
                TextColumn::make('name')
                    ->label(__('files.name'))
                    ->description(fn (ModsyncFile $record): string => $record->path)
                    ->url(fn (ModsyncFile $record): ?string => $record->page_url, shouldOpenInNewTab: true)
                    ->searchable(['name', 'path'])
                    ->sortable(['path']),
                TextColumn::make('version_name')
                    ->label(__('files.version'))
                    ->placeholder('—')
                    ->description(fn (ModsyncFile $record): ?string => $record->hasUpdate()
                        ? __('files.update_to', ['version' => $record->latest_version_name])
                        : null),
                TextColumn::make('status')
                    ->label(__('files.status'))
                    ->state(fn (ModsyncFile $record): string => self::status($record))
                    ->formatStateUsing(fn (string $state): string => __("files.statuses.{$state}"))
                    ->tooltip(fn (ModsyncFile $record): ?string => $record->hash_error)
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'error' => 'danger',
                        'hashing', 'update' => 'warning',
                        default => 'success',
                    }),
                TextColumn::make('source')
                    ->label(__('files.source'))
                    ->badge(),
                TextColumn::make('policy')
                    ->label(__('files.policy'))
                    ->badge(),
                TextColumn::make('side')
                    ->label(__('files.side')),
                TextColumn::make('size')
                    ->label(__('files.size'))
                    ->formatStateUsing(fn (?int $state): string => $state === null ? '—' : Number::fileSize($state))
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('source')->label(__('files.source'))->options(FileSource::class),
                SelectFilter::make('policy')->label(__('files.policy'))->options(FilePolicy::class),
                Filter::make('updates')->label(__('files.only_updates'))->query(fn (Builder $query) => $query->withUpdate()),
            ])
            ->headerActions([
                ...array_map(fn (FileSource $source): Action => $this->addFromHostAction($source), [FileSource::Modrinth, FileSource::CurseForge]),
                ActionGroup::make([
                    $this->uploadAction(),
                    $this->addUrlAction(),
                    $this->importAction(),
                ])->label(__('files.more'))->icon('heroicon-o-plus')->button()->color('gray'),
                $this->checkUpdatesAction(),
            ])
            ->recordActions([
                $this->changeVersionAction(),
                $this->editAction(),
                Action::make('delete')
                    ->label(__('files.delete'))
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->iconButton()
                    ->requiresConfirmation()
                    ->action(fn (ModsyncFile $record) => $record->delete()),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('updateSelected')
                        ->label(__('files.update_selected'))
                        ->icon('heroicon-o-arrow-up-circle')
                        ->requiresConfirmation()
                        ->action(fn (Collection $records) => $this->updateFiles($records->filter(fn (ModsyncFile $file): bool => $file->hasUpdate()))),
                    BulkAction::make('setPolicy')
                        ->label(__('files.set_policy'))
                        ->icon('heroicon-o-shield-check')
                        ->schema([
                            Select::make('policy')->label(__('files.policy'))->options(FilePolicy::class)->required(),
                            Select::make('side')->label(__('files.side'))->options(FileSide::class)->placeholder(__('files.keep')),
                        ])
                        ->action(fn (Collection $records, array $data) => $records->each(fn (ModsyncFile $file) => $file->update(array_filter([
                            'policy' => $data['policy'],
                            'side' => $data['side'] ?? null,
                        ])))),
                    BulkAction::make('deleteSelected')
                        ->label(__('files.delete_selected'))
                        ->icon('heroicon-o-trash')
                        ->color('danger')
                        ->requiresConfirmation()
                        ->action(fn (Collection $records) => $records->each(fn (ModsyncFile $file) => $file->delete())),
                ]),
            ])
            ->emptyStateHeading(__('files.empty'))
            ->emptyStateDescription(__('files.empty_help'));
    }

    private function addFromHostAction(FileSource $source): Action
    {
        $host = app(Hosts::class)->for($source);

        return Action::make("add_{$source->value}")
            ->label(__('files.add_from', ['host' => $source->getLabel()]))
            ->icon('heroicon-o-magnifying-glass')
            ->visible(fn (): bool => $host->isAvailable())
            ->modalHeading(__('files.add_from', ['host' => $source->getLabel()]))
            ->modalDescription(fn (): string => __('files.search_help', [
                'loader' => $this->pack()->loader->getLabel(),
                'minecraft' => $this->pack()->minecraft_version,
            ]))
            ->modalWidth(Width::TwoExtraLarge)
            ->modalSubmitActionLabel(__('files.add'))
            ->schema([
                ToggleButtons::make('type')
                    ->label(__('files.type'))
                    ->options(ProjectType::class)
                    ->default(ProjectType::Mod->value)
                    ->inline()
                    ->required()
                    ->live()
                    ->afterStateUpdated(function (Set $set): void {
                        $set('project', null);
                        $set('version', null);
                    }),
                Select::make('project')
                    ->label(__('files.project'))
                    ->placeholder(__('files.project_placeholder'))
                    ->searchable()
                    ->searchDebounce(400)
                    ->searchingMessage(__('files.searching'))
                    ->noSearchResultsMessage(__('files.no_results'))
                    ->allowHtml()
                    ->required()
                    ->live()
                    ->getSearchResultsUsing(fn (?string $search, Get $get): array => $this->searchResults($host, (string) $search, self::type($get)))
                    ->getOptionLabelUsing(fn (?string $value): ?string => $value === null ? null : e(self::projectTitle($host, $value)))
                    ->afterStateUpdated(fn (Set $set, Get $get, ?string $state) => $set('version', $state === null ? null : array_key_first($this->versionOptions($host, $state, self::type($get))))),
                Select::make('version')
                    ->label(__('files.version'))
                    ->options(fn (Get $get): array => filled($get('project')) ? $this->versionOptions($host, $get('project'), self::type($get)) : [])
                    ->helperText(fn (Get $get): ?string => filled($get('project')) && $this->versionOptions($host, $get('project'), self::type($get)) === []
                        ? __('files.no_versions')
                        : null)
                    ->required()
                    ->visible(fn (Get $get): bool => filled($get('project'))),
                ...self::policyFields(defaultsHelp: true),
            ])
            ->action(function (array $data, Action $action) use ($source): void {
                $file = $this->attempt(fn (): ModsyncFile => app(PackFiles::class)->addFromHost(
                    $this->pack(),
                    $source,
                    $data['project'],
                    $data['version'],
                    self::type($data['type']),
                    array_filter(['policy' => $data['policy'] ?? null, 'side' => $data['side'] ?? null]),
                ), $action);

                Notification::make()
                    ->success()
                    ->title(__('files.added', ['name' => $file->name]))
                    ->body($file->sha512 === null ? __('files.hashing_soon') : null)
                    ->send();
            });
    }

    private function uploadAction(): Action
    {
        return Action::make('upload')
            ->label(__('files.upload'))
            ->icon('heroicon-o-arrow-up-tray')
            ->modalDescription(__('files.upload_help'))
            ->schema([
                FileUpload::make('upload')
                    ->label(__('files.file'))
                    ->disk(config('modsync.disk'))
                    ->directory('modsync/incoming')
                    ->visibility('private')
                    ->storeFileNamesIn('upload_name')
                    ->maxSize(config('modsync.max_upload_kb'))
                    ->required(),
                self::folderField(),
                TextInput::make('name')->label(__('files.name'))->helperText(__('files.name_help'))->maxLength(255),
                ...self::policyFields(defaultsHelp: true),
            ])
            ->action(function (array $data, Action $action): void {
                $file = $this->attempt(fn (): ModsyncFile => app(PackFiles::class)->addUpload(
                    $this->pack(),
                    $data['upload'],
                    $data['upload_name'] ?? basename($data['upload']),
                    $data['folder'],
                    array_filter(['name' => $data['name'] ?? null, 'policy' => $data['policy'] ?? null, 'side' => $data['side'] ?? null]),
                ), $action);

                Notification::make()->success()->title(__('files.added', ['name' => $file->name]))->send();
            });
    }

    private function addUrlAction(): Action
    {
        return Action::make('addUrl')
            ->label(__('files.add_url'))
            ->icon('heroicon-o-link')
            ->modalDescription(__('files.add_url_help'))
            ->schema([
                TextInput::make('url')
                    ->label(__('files.url'))
                    ->url()
                    ->startsWith(['https://'])
                    ->maxLength(ManifestRules::MAX_URL_LENGTH)
                    ->required()
                    ->live(onBlur: true)
                    ->afterStateUpdated(fn (Set $set, ?string $state) => $set('file_name', $state === null ? null : rawurldecode(basename((string) parse_url($state, PHP_URL_PATH))))),
                self::folderField(),
                TextInput::make('file_name')->label(__('files.file_name'))->required()->maxLength(255),
                TextInput::make('name')->label(__('files.name'))->helperText(__('files.name_help'))->maxLength(255),
                ...self::policyFields(defaultsHelp: true),
            ])
            ->action(function (array $data, Action $action): void {
                $file = $this->attempt(fn (): ModsyncFile => app(PackFiles::class)->addUrl(
                    $this->pack(),
                    $data['url'],
                    $data['file_name'],
                    $data['folder'],
                    array_filter(['name' => $data['name'] ?? null, 'policy' => $data['policy'] ?? null, 'side' => $data['side'] ?? null]),
                ), $action);

                Notification::make()->success()->title(__('files.added', ['name' => $file->name]))->body(__('files.hashing_soon'))->send();
            });
    }

    private function importAction(): Action
    {
        return Action::make('import')
            ->label(__('files.import'))
            ->icon('heroicon-o-document-arrow-up')
            ->modalDescription(__('files.import_help'))
            ->schema([
                FileUpload::make('manifest')
                    ->label(__('files.manifest'))
                    ->disk(config('modsync.disk'))
                    ->directory('modsync/incoming')
                    ->visibility('private')
                    ->acceptedFileTypes(['application/json', 'text/plain'])
                    ->maxSize(10240)
                    ->required(),
                Toggle::make('remove_missing')
                    ->label(__('files.remove_missing'))
                    ->helperText(__('files.remove_missing_help')),
            ])
            ->action(function (array $data, Action $action): void {
                $disk = Storage::disk(config('modsync.disk'));
                $json = (string) $disk->get($data['manifest']);
                $disk->delete($data['manifest']);

                $result = $this->attempt(fn () => app(ManifestImporter::class)->import($this->pack(), $json, (bool) ($data['remove_missing'] ?? false)), $action);

                Log::info('modsync.pack_imported', [
                    'key' => $this->pack()->key,
                    'created' => $result->created,
                    'updated' => $result->updated,
                    'removed' => $result->removed,
                    'skipped' => count($result->skipped),
                ]);

                Notification::make()
                    ->title(__('files.imported', ['created' => $result->created, 'updated' => $result->updated, 'removed' => $result->removed]))
                    ->body(implode("\n", array_slice([
                        ...array_map(fn (string $skipped): string => __('files.import_skipped', ['entry' => $skipped]), $result->skipped),
                        ...$result->warnings,
                    ], 0, 15)) ?: null)
                    ->color($result->skipped === [] && $result->warnings === [] ? 'success' : 'warning')
                    ->persistent($result->skipped !== [] || $result->warnings !== [])
                    ->send();
            });
    }

    private function checkUpdatesAction(): Action
    {
        return Action::make('checkUpdates')
            ->label(__('files.check_updates'))
            ->icon('heroicon-o-arrow-path')
            ->color('gray')
            ->action(function (): void {
                $result = app(UpdateChecker::class)->check($this->pack());

                Notification::make()
                    ->title(__('files.checked', ['checked' => $result['checked'], 'updates' => $result['updates']]))
                    ->body(implode("\n", $result['errors']) ?: null)
                    ->color($result['errors'] === [] ? 'success' : 'warning')
                    ->send();
            });
    }

    private function changeVersionAction(): Action
    {
        return Action::make('changeVersion')
            ->label(fn (ModsyncFile $record): string => $record->hasUpdate() ? __('files.update') : __('files.change_version'))
            ->icon('heroicon-o-arrow-up-circle')
            ->color(fn (ModsyncFile $record): string => $record->hasUpdate() ? 'warning' : 'gray')
            ->iconButton()
            ->visible(fn (ModsyncFile $record): bool => $record->source->isHost() && $record->project_id !== null && app(Hosts::class)->for($record->source)->isAvailable())
            ->modalHeading(fn (ModsyncFile $record): string => __('files.change_version_heading', ['name' => $record->name]))
            ->fillForm(fn (ModsyncFile $record): array => ['version' => $record->hasUpdate() ? $record->latest_version_id : $record->version_id])
            ->schema(fn (ModsyncFile $record): array => [
                Select::make('version')
                    ->label(__('files.version'))
                    ->options(fn (): array => $this->versionOptions(app(Hosts::class)->for($record->source), (string) $record->project_id, $record->projectType(), $record->version_id))
                    ->required(),
            ])
            ->action(function (ModsyncFile $record, array $data, Action $action): void {
                $file = $this->attempt(fn (): ModsyncFile => app(PackFiles::class)->update($record, $data['version']), $action);

                Notification::make()->success()->title(__('files.updated', ['name' => $file->name, 'version' => $file->version_name]))->send();
            });
    }

    private function editAction(): Action
    {
        return Action::make('edit')
            ->label(__('files.edit'))
            ->icon('heroicon-o-pencil-square')
            ->iconButton()
            ->fillForm(fn (ModsyncFile $record): array => $record->only(['name', 'description', 'policy', 'side']))
            ->schema([
                TextInput::make('name')->label(__('files.name'))->required()->maxLength(255),
                Textarea::make('description')->label(__('files.description'))->helperText(__('files.description_help'))->maxLength(4096)->rows(2),
                ...self::policyFields(defaultsHelp: false),
            ])
            ->action(fn (ModsyncFile $record, array $data) => $record->update($data));
    }

    /**
     * @param  \Illuminate\Support\Collection<int, ModsyncFile>  $files
     */
    private function updateFiles(\Illuminate\Support\Collection $files): void
    {
        $failed = [];

        foreach ($files as $file) {
            try {
                app(PackFiles::class)->update($file);
            } catch (PackFileException|HostException $exception) {
                $failed[] = "{$file->name}: {$exception->getMessage()}";
            }
        }

        Notification::make()
            ->title(__('files.updated_many', ['count' => $files->count() - count($failed)]))
            ->body(implode("\n", $failed) ?: null)
            ->color($failed === [] ? 'success' : 'warning')
            ->send();
    }

    /**
     * Runs a file change; a host or file error becomes a notification and stops the action.
     *
     * @template T
     *
     * @param  callable(): T  $change
     * @return T
     */
    private function attempt(callable $change, Action $action): mixed
    {
        try {
            return $change();
        } catch (PackFileException|HostException $exception) {
            Notification::make()->danger()->title(__('files.failed'))->body($exception->getMessage())->send();

            $action->halt();
        }
    }

    /**
     * Search hits as HTML option labels; their titles are kept for the selected option's label.
     *
     * @return array<string, string>
     */
    private function searchResults(ModHost $host, string $search, ProjectType $type): array
    {
        if (mb_strlen(trim($search)) < 2) {
            return [];
        }

        try {
            $projects = $host->search(trim($search), $type, $this->pack());
        } catch (HostException $exception) {
            Notification::make()->danger()->title(__('files.search_failed'))->body($exception->getMessage())->send();

            return [];
        }

        $installed = $this->pack()->files()->where('source', $host->source())->pluck('project_id')->all();
        $options = [];

        foreach ($projects as $project) {
            Cache::put(self::titleKey($host, $project->id), $project->title, now()->addHour());
            $options[$project->id] = view('filament.project-option', [
                'project' => $project,
                'installed' => in_array($project->id, $installed, true),
            ])->render();
        }

        return $options;
    }

    /**
     * @return array<string, string> version id => label, newest first
     */
    private function versionOptions(ModHost $host, string $projectId, ProjectType $type, ?string $current = null): array
    {
        $pack = $this->pack();
        $key = "modsync.versions.{$host->source()->value}.{$projectId}.{$type->value}.{$pack->loader->value}.{$pack->minecraft_version}";

        try {
            // Only strings are cached: the cache unserializes no classes (cache.serializable_classes).
            $options = Cache::remember($key, now()->addMinutes(5), fn (): array => array_column(array_map(fn (RemoteVersion $version): array => [
                'id' => $version->id,
                'label' => implode(' · ', array_filter([
                    $version->name,
                    $version->releaseType !== 'release' ? __("files.release_types.{$version->releaseType}") : null,
                    $version->publishedAt !== null ? substr($version->publishedAt, 0, 10) : null,
                    $version->url === null ? __('files.no_download') : null,
                ])),
            ], $host->versions($projectId, $type, $pack)), 'label', 'id'));
        } catch (HostException $exception) {
            Notification::make()->danger()->title(__('files.search_failed'))->body($exception->getMessage())->send();

            return [];
        }

        if ($current !== null && isset($options[$current])) {
            $options[$current] .= ' · '.__('files.current');
        }

        return $options;
    }

    private static function projectTitle(ModHost $host, string $projectId): string
    {
        return Cache::remember(self::titleKey($host, $projectId), now()->addHour(), function () use ($host, $projectId): string {
            try {
                return $host->project($projectId)->title ?? $projectId;
            } catch (HostException) {
                return $projectId;
            }
        });
    }

    private static function titleKey(ModHost $host, string $projectId): string
    {
        return "modsync.project.{$host->source()->value}.{$projectId}";
    }

    /**
     * @return list<Select>
     */
    private static function policyFields(bool $defaultsHelp): array
    {
        return [
            Select::make('policy')
                ->label(__('files.policy'))
                ->helperText($defaultsHelp ? __('files.policy_default_help') : __('files.policy_help'))
                ->options(FilePolicy::class)
                ->placeholder(__('files.by_type'))
                ->required(! $defaultsHelp),
            Select::make('side')
                ->label(__('files.side'))
                ->helperText($defaultsHelp ? __('files.side_default_help') : null)
                ->options(FileSide::class)
                ->placeholder(__('files.by_type'))
                ->required(! $defaultsHelp),
        ];
    }

    private static function folderField(): TextInput
    {
        return TextInput::make('folder')
            ->label(__('files.folder'))
            ->helperText(__('files.folder_help', ['roots' => implode(', ', ModsyncFile::ROOTS)]))
            ->datalist(ModsyncFile::ROOTS)
            ->default('mods')
            ->required()
            ->rule(fn (): \Closure => function (string $attribute, mixed $value, \Closure $fail): void {
                if (! is_string($value) || ! ManifestRules::isFolder($value)) {
                    $fail(__('files.errors.bad_folder'));
                }
            });
    }

    private static function type(Get|string|ProjectType|null $value): ProjectType
    {
        $value = $value instanceof Get ? $value('type') : $value;

        return $value instanceof ProjectType ? $value : (ProjectType::tryFrom((string) $value) ?? ProjectType::Mod);
    }

    private static function status(ModsyncFile $file): string
    {
        return match (true) {
            $file->hash_error !== null => 'error',
            $file->sha512 === null && $file->policy !== FilePolicy::Forbid => 'hashing',
            $file->hasUpdate() => 'update',
            default => 'ok',
        };
    }

    private function pack(): ModsyncPack
    {
        /** @var ModsyncPack */
        return $this->getOwnerRecord();
    }
}
