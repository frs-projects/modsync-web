<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One published version of a pack: its manifest as players get it, frozen. Editing the pack's
 * files changes nothing here; the pack's live release is what players download.
 *
 * @property int $id
 * @property int $pack_id
 * @property string $version
 * @property string $minecraft_version
 * @property Loader $loader
 * @property string $manifest
 * @property string $content_hash
 * @property list<string> $uploads
 * @property int $files_count
 * @property list<string> $warnings
 * @property int|null $published_by
 */
#[Fillable(['version', 'minecraft_version', 'loader', 'manifest', 'content_hash', 'uploads', 'files_count', 'warnings', 'published_by'])]
class ModsyncRelease extends Model
{
    /**
     * @return BelongsTo<ModsyncPack, $this>
     */
    public function pack(): BelongsTo
    {
        return $this->belongsTo(ModsyncPack::class, 'pack_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }

    public function isLive(): bool
    {
        return $this->pack->live_release_id === $this->getKey();
    }

    protected function casts(): array
    {
        return [
            'loader' => Loader::class,
            'uploads' => 'array',
            'warnings' => 'array',
            'files_count' => 'integer',
        ];
    }
}
