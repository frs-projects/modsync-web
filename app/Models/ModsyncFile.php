<?php

namespace App\Models;

use Database\Factories\ModsyncFileFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * One file of a pack: an entry of its manifest. `project_id`/`version_id` are the Modrinth
 * project and version, or the CurseForge mod and file; `latest_*` is what the last update check
 * found. Uploads are stored by their SHA-512, so packs sharing a file share the copy.
 *
 * @property int $id
 * @property int $pack_id
 * @property FileSource $source
 * @property string|null $project_id
 * @property string|null $version_id
 * @property string $name
 * @property string|null $version_name
 * @property string|null $description
 * @property string $path
 * @property int|null $size
 * @property string|null $sha512
 * @property string|null $sha1
 * @property list<string> $urls
 * @property string|null $upload_path
 * @property FilePolicy $policy
 * @property FileSide $side
 * @property string|null $page_url
 * @property string|null $icon_url
 * @property string|null $hash_error
 * @property string|null $latest_version_id
 * @property string|null $latest_version_name
 * @property Carbon|null $checked_at
 * @property-read ModsyncPack $pack
 */
#[Fillable([
    'pack_id', 'source', 'project_id', 'version_id', 'name', 'version_name', 'description', 'path',
    'size', 'sha512', 'sha1', 'urls', 'upload_path', 'policy', 'side', 'page_url', 'icon_url',
    'hash_error', 'latest_version_id', 'latest_version_name', 'checked_at',
])]
class ModsyncFile extends Model
{
    /** @use HasFactory<ModsyncFileFactory> */
    use HasFactory;

    /**
     * Folders the client may write to (PathSandbox::DEFAULT_ROOTS in the mod).
     */
    public const array ROOTS = ['mods', 'config', 'defaultconfigs', 'kubejs', 'resourcepacks', 'scripts', 'shaderpacks'];

    protected $attributes = [
        'urls' => '[]',
    ];

    protected static function booted(): void
    {
        static::deleted(function (ModsyncFile $file): void {
            if ($file->upload_path !== null && ! static::query()->where('upload_path', $file->upload_path)->exists()
                && ! ModsyncRelease::query()->whereJsonContains('uploads', $file->upload_path)->exists()) {
                Storage::disk(config('modsync.disk'))->delete($file->upload_path);
            }
        });
    }

    /**
     * @return BelongsTo<ModsyncPack, $this>
     */
    public function pack(): BelongsTo
    {
        return $this->belongsTo(ModsyncPack::class, 'pack_id');
    }

    /**
     * The manifest's `id`: the project on its host, so the client can tell a mod across versions.
     */
    public function manifestId(): ?string
    {
        return $this->source->isHost() && $this->project_id !== null ? "{$this->source->value}:{$this->project_id}" : null;
    }

    /**
     * Mirrors the client downloads from: the stored URLs, or the panel's copy of an upload.
     *
     * @return list<string>
     */
    public function downloadUrls(): array
    {
        if ($this->source === FileSource::Upload) {
            return $this->sha512 === null ? [] : [route('modsync.file', [
                'hash' => $this->sha512,
                'name' => $this->fileName(),
            ])];
        }

        return array_values($this->urls ?? []);
    }

    public function fileName(): string
    {
        return basename($this->path);
    }

    public function projectType(): ProjectType
    {
        return ProjectType::fromPath($this->path);
    }

    public function hasUpdate(): bool
    {
        return $this->latest_version_id !== null && $this->latest_version_id !== $this->version_id;
    }

    /**
     * Files whose last update check found another version (hasUpdate() in SQL).
     *
     * @param  Builder<self>  $query
     */
    public function scopeWithUpdate(Builder $query): void
    {
        $query->whereNotNull('latest_version_id')
            ->where(fn (Builder $query) => $query->whereNull('version_id')->orWhereColumn('latest_version_id', '!=', 'version_id'));
    }

    protected function casts(): array
    {
        return [
            'source' => FileSource::class,
            'policy' => FilePolicy::class,
            'side' => FileSide::class,
            'urls' => 'array',
            'size' => 'integer',
            'checked_at' => 'datetime',
        ];
    }

    protected static function newFactory(): ModsyncFileFactory
    {
        return ModsyncFileFactory::new();
    }
}
