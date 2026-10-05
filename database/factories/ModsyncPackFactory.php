<?php

namespace Database\Factories;

use App\Models\Loader;
use App\Models\ModsyncPack;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ModsyncPack>
 */
class ModsyncPackFactory extends Factory
{
    protected $model = ModsyncPack::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'key' => fake()->unique()->slug(2),
            'name' => fake()->words(2, true),
            'minecraft_version' => '1.21.1',
            'loader' => Loader::NeoForge,
        ];
    }
}
