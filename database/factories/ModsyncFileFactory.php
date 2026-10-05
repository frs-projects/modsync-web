<?php

namespace Database\Factories;

use App\Models\FilePolicy;
use App\Models\FileSide;
use App\Models\FileSource;
use App\Models\ModsyncFile;
use App\Models\ModsyncPack;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ModsyncFile>
 */
class ModsyncFileFactory extends Factory
{
    protected $model = ModsyncFile::class;

    /**
     * A Modrinth mod with its hashes and CDN URL.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->slug(2);

        return [
            'pack_id' => ModsyncPack::factory(),
            'source' => FileSource::Modrinth,
            'project_id' => fake()->regexify('[A-Za-z0-9]{8}'),
            'version_id' => fake()->regexify('[A-Za-z0-9]{8}'),
            'name' => ucwords(str_replace('-', ' ', $name)),
            'version_name' => '1.0.0',
            'path' => "mods/{$name}-1.0.0.jar",
            'size' => fake()->numberBetween(10_000, 5_000_000),
            'sha512' => hash('sha512', $name),
            'sha1' => sha1($name),
            'urls' => ["https://cdn.modrinth.com/data/abc/versions/def/{$name}-1.0.0.jar"],
            'policy' => FilePolicy::Require,
            'side' => FileSide::Both,
        ];
    }

    /**
     * A file whose hash the panel has not computed yet.
     */
    public function unhashed(): static
    {
        return $this->state(['source' => FileSource::CurseForge, 'sha512' => null, 'size' => null]);
    }
}
