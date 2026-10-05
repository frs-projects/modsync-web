<?php

use App\Filament\Resources\ModsyncPacks\Pages\CreateModsyncPack;
use App\Models\Loader;
use App\Models\ModsyncPack;
use App\Models\User;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    Filament::setCurrentPanel('admin');
});

it('sends guests to the login page', function () {
    $this->get('/admin/packs')->assertRedirect('/admin/login');
});

it('shows signed-in users the pack pages', function () {
    $pack = ModsyncPack::factory()->create(['key' => 'main']);
    $this->actingAs(User::factory()->create());

    $this->get('/admin/packs')->assertOk()->assertSee('/manifests/main.json');
    $this->get("/admin/packs/{$pack->id}/edit")->assertOk();
    $this->get('/admin/packs/create')->assertOk();
});

it('creates a pack', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test(CreateModsyncPack::class)
        ->fillForm(['key' => 'main', 'name' => 'Main', 'unlisted_policy' => 'keep', 'minecraft_version' => '1.21.1', 'loader' => Loader::NeoForge->value])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(ModsyncPack::query()->sole()->only(['key', 'unlisted_policy', 'loader']))->toBe(['key' => 'main', 'unlisted_policy' => 'keep', 'loader' => Loader::NeoForge]);
});
