<?php

use App\Hosts\CurseForge;
use App\Hosts\Modrinth;
use App\Models\Loader;
use App\Models\ModsyncFile;
use App\Models\ModsyncPack;
use App\Models\ProjectType;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeHosts;

it('filters Modrinth searches by game version, and mods also by loader', function (ProjectType $type, array $facets) {
    $pack = ModsyncPack::factory()->create(['loader' => Loader::Forge, 'minecraft_version' => '1.20.1']);
    FakeHosts::fake([FakeHosts::MODRINTH.'/search?*' => Http::response(['hits' => [
        ['project_id' => 'AANobbMI', ...FakeHosts::modrinthProject('AANobbMI', 'Sodium')],
    ]])]);

    $results = app(Modrinth::class)->search('sod', $type, $pack);

    expect($results[0]->title)->toBe('Sodium');
    Http::assertSent(fn ($request) => json_decode($request['facets'], true) === $facets);
})->with([
    'mods' => [ProjectType::Mod, [['project_type:mod'], ['versions:1.20.1'], ['categories:forge']]],
    'shaders' => [ProjectType::Shader, [['project_type:shader'], ['versions:1.20.1']]],
]);

it('picks the newest CurseForge release for the pack over newer betas and other loaders', function () {
    FakeHosts::enableCurseForge();
    $pack = ModsyncPack::factory()->create(['loader' => Loader::NeoForge, 'minecraft_version' => '1.21.1']);
    $file = ModsyncFile::factory()->for($pack, 'pack')->create(['source' => 'curseforge', 'project_id' => '238222', 'version_id' => '100']);
    FakeHosts::fake([FakeHosts::CURSEFORGE.'/mods' => Http::response(['data' => [FakeHosts::curseForgeMod(238222, 'JEI', ['latestFilesIndexes' => [
        ['gameVersion' => '1.21.1', 'fileId' => 300, 'filename' => 'jei-beta.jar', 'releaseType' => 2, 'modLoader' => 6],
        ['gameVersion' => '1.21.1', 'fileId' => 200, 'filename' => 'jei-2.jar', 'releaseType' => 1, 'modLoader' => 6],
        ['gameVersion' => '1.21.1', 'fileId' => 400, 'filename' => 'jei-forge.jar', 'releaseType' => 1, 'modLoader' => 1],
        ['gameVersion' => '1.20.1', 'fileId' => 500, 'filename' => 'jei-old-mc.jar', 'releaseType' => 1, 'modLoader' => 6],
    ]])]])]);

    $latest = app(CurseForge::class)->latest([$file], $pack);

    expect($latest[$file->id]->id)->toBe('200')
        ->and($latest[$file->id]->name)->toBe('jei-2.jar');
});
