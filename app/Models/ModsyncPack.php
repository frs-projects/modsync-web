<?php

namespace App\Models;

use Database\Factories\ModsyncPackFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One modpack the ModSync client syncs against. Its files and game are the draft of the next
 * release; players get the live release. The key is the manifest's `packId` and part of its
 * public URL.
 *
 * @property int $id
 * @property string $key
 * @property string $name
 * @property string $minecraft_version
 * @property Loader $loader
 * @property string $unlisted_policy
 * @property int|null $live_release_id
 * @property-read ModsyncRelease|null $liveRelease
 */
#[Fillable(['key', 'name', 'minecraft_version', 'loader', 'unlisted_policy'])]
class ModsyncPack extends Model
{
    /** @use HasFactory<ModsyncPackFactory> */
    use HasFactory;

    /**
     * The client's packId charset (ManifestCodec::validatePackId), lower case only: it becomes
     * a directory name on the player's machine and part of the manifest URL.
     */
    public const string KEY_PATTERN = '/^[a-z0-9][a-z0-9._-]{0,63}$/';

    public const array UNLISTED_POLICIES = ['quarantine', 'keep'];

    protected $attributes = [
        'unlisted_policy' => 'quarantine',
    ];

    /**
     * @return HasMany<ModsyncFile, $this>
     */
    public function files(): HasMany
    {
        return $this->hasMany(ModsyncFile::class, 'pack_id');
    }

    /**
     * @return HasMany<ModsyncRelease, $this>
     */
    public function releases(): HasMany
    {
        return $this->hasMany(ModsyncRelease::class, 'pack_id');
    }

    /**
     * The release players get.
     *
     * @return BelongsTo<ModsyncRelease, $this>
     */
    public function liveRelease(): BelongsTo
    {
        return $this->belongsTo(ModsyncRelease::class, 'live_release_id');
    }

    /**
     * Where players' clients fetch the published manifest.
     */
    public function manifestUrl(): string
    {
        return route('modsync.manifest', ['pack' => $this->key]);
    }

    protected function casts(): array
    {
        return [
            'loader' => Loader::class,
        ];
    }

    protected static function newFactory(): ModsyncPackFactory
    {
        return ModsyncPackFactory::new();
    }
}
