<?php

namespace App\Filament\Resources\ModsyncPacks;

use App\Filament\Resources\ModsyncPacks\Pages\CreateModsyncPack;
use App\Filament\Resources\ModsyncPacks\Pages\EditModsyncPack;
use App\Filament\Resources\ModsyncPacks\Pages\ListModsyncPacks;
use App\Filament\Resources\ModsyncPacks\RelationManagers\FilesRelationManager;
use App\Filament\Resources\ModsyncPacks\RelationManagers\ReleasesRelationManager;
use App\Models\Loader;
use App\Models\ModsyncPack;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Modpacks for the ModSync client: what each is for, its files (the
 * draft of the next release) and its releases.
 */
class ModsyncPackResource extends Resource
{
    protected static ?string $model = ModsyncPack::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPuzzlePiece;

    protected static ?string $slug = 'packs';

    protected static ?string $recordTitleAttribute = 'name';

    public static function getModelLabel(): string
    {
        return __('packs.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('packs.plural');
    }

    public static function getNavigationLabel(): string
    {
        return __('packs.navigation');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make()
                    ->columns(2)
                    ->schema([
                        TextInput::make('key')
                            ->label(__('packs.key'))
                            ->helperText(__('packs.key_help'))
                            ->required()
                            ->regex(ModsyncPack::KEY_PATTERN)
                            ->unique(ignoreRecord: true)
                            ->disabledOn('edit'),
                        TextInput::make('name')
                            ->label(__('packs.name'))
                            ->required()
                            ->maxLength(255),
                        Select::make('unlisted_policy')
                            ->label(__('packs.unlisted_policy'))
                            ->helperText(__('packs.unlisted_policy_help'))
                            ->options(array_combine(ModsyncPack::UNLISTED_POLICIES, array_map(fn (string $policy): string => __("packs.unlisted_policies.{$policy}"), ModsyncPack::UNLISTED_POLICIES)))
                            ->default('quarantine')
                            ->required()
                            ->selectablePlaceholder(false),
                        TextInput::make('minecraft_version')
                            ->label(__('packs.minecraft_version'))
                            ->helperText(__('packs.minecraft_version_help'))
                            ->required()
                            ->regex('/^\d+\.\d+(\.\d+)?$/')
                            ->maxLength(32),
                        Select::make('loader')
                            ->label(__('packs.loader'))
                            ->helperText(__('packs.loader_help'))
                            ->options(Loader::class)
                            ->required(),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('liveRelease')->withCount([
                'files',
                'files as updates_count' => fn (Builder $files) => $files->withUpdate(),
            ]))
            ->defaultSort('key')
            ->columns([
                TextColumn::make('name')
                    ->label(__('packs.name'))
                    ->description(fn (ModsyncPack $record): string => $record->key)
                    ->searchable(['name', 'key'])
                    ->sortable(),
                TextColumn::make('game')
                    ->label(__('packs.game'))
                    ->state(fn (ModsyncPack $record): string => "{$record->loader->getLabel()} {$record->minecraft_version}"),
                TextColumn::make('liveRelease.version')
                    ->label(__('packs.live_version'))
                    ->placeholder(__('packs.unpublished')),
                TextColumn::make('files_count')
                    ->label(__('packs.files'))
                    ->sortable(),
                TextColumn::make('updates_count')
                    ->label(__('packs.updates'))
                    ->badge()
                    ->color(fn (int $state): string => $state > 0 ? 'warning' : 'gray'),
                TextColumn::make('manifest_url')
                    ->label(__('packs.manifest_url'))
                    ->state(fn (ModsyncPack $record): string => $record->manifestUrl())
                    ->copyable()
                    ->fontFamily('mono')
                    ->size('xs'),
            ])
            ->recordActions([
                EditAction::make(),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            FilesRelationManager::class,
            ReleasesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListModsyncPacks::route('/'),
            'create' => CreateModsyncPack::route('/create'),
            'edit' => EditModsyncPack::route('/{record}/edit'),
        ];
    }
}
