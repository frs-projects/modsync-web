<?php

use App\Filament\Resources\ModsyncPacks\Pages\EditModsyncPack;
use App\Filament\Resources\ModsyncPacks\RelationManagers\FilesRelationManager;
use App\Models\FilePolicy;
use App\Models\FileSide;
use App\Models\FileSource;
use App\Models\ModsyncFile;
use App\Models\ModsyncPack;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\FakeHosts;

beforeEach(function () {
    // Serialize like Redis in production, which unserializes no classes (cache.serializable_classes).
    config(['cache.stores.array.serialize' => true]);
    Cache::forgetDriver('array');
    Filament::setCurrentPanel('admin');
    $this->pack = ModsyncPack::factory()->create(['key' => 'main']);
});

function filesManager(ModsyncPack $pack): Testable
{
    return Livewire::test(FilesRelationManager::class, ['ownerRecord' => $pack, 'pageClass' => EditModsyncPack::class]);
}

function actAsPackManager(): void
{
    test()->actingAs(User::factory()->create());
}

it('adds a mod from Modrinth with its hashes, and follows Modrinth on the side', function () {
    actAsPackManager();
    FakeHosts::fake([
        FakeHosts::MODRINTH.'/project/AANobbMI' => Http::response(FakeHosts::modrinthProject('AANobbMI', 'Sodium', ['server_side' => 'unsupported'])),
        FakeHosts::MODRINTH.'/version/v1' => Http::response(FakeHosts::modrinthVersion('v1', 'AANobbMI', 'sodium-0.6.jar', '0.6')),
        FakeHosts::MODRINTH.'/project/AANobbMI/version?*' => Http::response([FakeHosts::modrinthVersion('v1', 'AANobbMI', 'sodium-0.6.jar', '0.6')]),
    ]);

    filesManager($this->pack)
        ->callTableAction('add_modrinth', data: ['type' => 'mod', 'project' => 'AANobbMI', 'version' => 'v1'])
        ->assertHasNoTableActionErrors();

    expect(ModsyncFile::query()->sole()->only(['source', 'project_id', 'version_id', 'name', 'version_name', 'path', 'sha512', 'urls', 'policy', 'side']))->toBe([
        'source' => FileSource::Modrinth,
        'project_id' => 'AANobbMI',
        'version_id' => 'v1',
        'name' => 'Sodium',
        'version_name' => '0.6',
        'path' => 'mods/sodium-0.6.jar',
        'sha512' => hash('sha512', 'sodium-0.6.jar'),
        'urls' => ['https://cdn.modrinth.com/data/AANobbMI/versions/v1/sodium-0.6.jar'],
        'policy' => FilePolicy::Require,
        'side' => FileSide::Client,
    ]);
});

it('does not add a project the pack already has', function () {
    actAsPackManager();
    ModsyncFile::factory()->for($this->pack, 'pack')->create(['project_id' => 'AANobbMI']);
    FakeHosts::fake([
        FakeHosts::MODRINTH.'/project/AANobbMI' => Http::response(FakeHosts::modrinthProject('AANobbMI', 'Sodium')),
        FakeHosts::MODRINTH.'/version/v2' => Http::response(FakeHosts::modrinthVersion('v2', 'AANobbMI', 'sodium-0.7.jar')),
        FakeHosts::MODRINTH.'/project/AANobbMI/version?*' => Http::response([FakeHosts::modrinthVersion('v2', 'AANobbMI', 'sodium-0.7.jar')]),
    ]);

    filesManager($this->pack)
        ->callTableAction('add_modrinth', data: ['type' => 'mod', 'project' => 'AANobbMI', 'version' => 'v2'])
        ->assertNotified('That did not work');

    expect(ModsyncFile::query()->count())->toBe(1);
});

it('adds a CurseForge file and hashes its download, checking the published SHA-1', function () {
    actAsPackManager();
    FakeHosts::enableCurseForge();
    FakeHosts::fake([
        FakeHosts::CURSEFORGE.'/mods/238222' => Http::response(['data' => FakeHosts::curseForgeMod(238222, 'JEI')]),
        FakeHosts::CURSEFORGE.'/mods/238222/files/5101234' => Http::response(['data' => FakeHosts::curseForgeFile(5101234, 238222, 'jei.jar', 'jei contents')]),
        FakeHosts::CURSEFORGE.'/mods/238222/files?*' => Http::response(['data' => [FakeHosts::curseForgeFile(5101234, 238222, 'jei.jar', 'jei contents')]]),
        FakeHosts::curseForgeUrl(5101234, 'jei.jar') => Http::response('jei contents'),
    ]);

    filesManager($this->pack)->callTableAction('add_curseforge', data: ['type' => 'mod', 'project' => '238222', 'version' => '5101234']);

    expect(ModsyncFile::query()->sole()->only(['source', 'name', 'path', 'sha512', 'size', 'hash_error']))->toBe([
        'source' => FileSource::CurseForge,
        'name' => 'JEI',
        'path' => 'mods/jei.jar',
        'sha512' => hash('sha512', 'jei contents'),
        'size' => 12,
        'hash_error' => null,
    ]);
});

it('keeps a CurseForge download that does not match its SHA-1 unhashed', function () {
    actAsPackManager();
    FakeHosts::enableCurseForge();
    FakeHosts::fake([
        FakeHosts::CURSEFORGE.'/mods/238222' => Http::response(['data' => FakeHosts::curseForgeMod(238222, 'JEI')]),
        FakeHosts::CURSEFORGE.'/mods/238222/files/5101234' => Http::response(['data' => FakeHosts::curseForgeFile(5101234, 238222, 'jei.jar', 'jei contents')]),
        FakeHosts::CURSEFORGE.'/mods/238222/files?*' => Http::response(['data' => [FakeHosts::curseForgeFile(5101234, 238222, 'jei.jar', 'jei contents')]]),
        FakeHosts::curseForgeUrl(5101234, 'jei.jar') => Http::response('tampered'),
    ]);

    filesManager($this->pack)->callTableAction('add_curseforge', data: ['type' => 'mod', 'project' => '238222', 'version' => '5101234']);

    expect(ModsyncFile::query()->sole()->only(['sha512', 'hash_error']))->toBe([
        'sha512' => null,
        'hash_error' => 'The downloaded file does not match the SHA-1 the host published.',
    ]);
});

it('refuses CurseForge files whose author disallows third-party downloads', function () {
    actAsPackManager();
    FakeHosts::enableCurseForge();
    FakeHosts::fake([
        FakeHosts::CURSEFORGE.'/mods/238222' => Http::response(['data' => FakeHosts::curseForgeMod(238222, 'JEI')]),
        FakeHosts::CURSEFORGE.'/mods/238222/files/5101234' => Http::response(['data' => FakeHosts::curseForgeFile(5101234, 238222, 'jei.jar', 'x', downloadable: false)]),
        FakeHosts::CURSEFORGE.'/mods/238222/files?*' => Http::response(['data' => [FakeHosts::curseForgeFile(5101234, 238222, 'jei.jar', 'x', downloadable: false)]]),
    ]);

    filesManager($this->pack)
        ->callTableAction('add_curseforge', data: ['type' => 'mod', 'project' => '238222', 'version' => '5101234'])
        ->assertNotified('That did not work');

    expect(ModsyncFile::query()->count())->toBe(0);
});

it('hides CurseForge without an API key', function () {
    actAsPackManager();

    filesManager($this->pack)->assertTableActionHidden('add_curseforge');
});

it('finds updates and moves a file to the newest version', function () {
    actAsPackManager();
    $file = ModsyncFile::factory()->for($this->pack, 'pack')->create(['project_id' => 'AANobbMI', 'version_id' => 'v1', 'path' => 'mods/sodium-0.6.jar']);
    FakeHosts::fake([
        FakeHosts::MODRINTH.'/version_files/update' => Http::response([$file->sha512 => FakeHosts::modrinthVersion('v2', 'AANobbMI', 'sodium-0.7.jar', '0.7')]),
        FakeHosts::MODRINTH.'/version/v2' => Http::response(FakeHosts::modrinthVersion('v2', 'AANobbMI', 'sodium-0.7.jar', '0.7')),
        FakeHosts::MODRINTH.'/project/AANobbMI/version?*' => Http::response([FakeHosts::modrinthVersion('v2', 'AANobbMI', 'sodium-0.7.jar', '0.7'), FakeHosts::modrinthVersion('v1', 'AANobbMI', 'sodium-0.6.jar', '0.6')]),
    ]);

    filesManager($this->pack)
        ->callTableAction('checkUpdates')
        ->assertNotified('1 files checked, 1 with updates.');

    Http::assertSent(fn ($request) => $request->url() === FakeHosts::MODRINTH.'/version_files/update'
        && $request['loaders'] === ['neoforge'] && $request['game_versions'] === ['1.21.1']);
    expect($file->fresh()->hasUpdate())->toBeTrue();

    filesManager($this->pack)->callTableAction('changeVersion', $file, data: ['version' => 'v2']);

    expect($file->fresh()->only(['version_id', 'version_name', 'path', 'sha512']))->toBe([
        'version_id' => 'v2',
        'version_name' => '0.7',
        'path' => 'mods/sodium-0.7.jar',
        'sha512' => hash('sha512', 'sodium-0.7.jar'),
    ])->and($file->fresh()->hasUpdate())->toBeFalse();
});

it('stores an upload by its hash', function () {
    actAsPackManager();
    Storage::fake('local');

    filesManager($this->pack)
        ->callTableAction('upload', data: [
            'upload' => UploadedFile::fake()->createWithContent('tacz-config.toml', 'damage = 2'),
            'folder' => 'config/tacz',
        ])
        ->assertHasNoTableActionErrors();

    $file = ModsyncFile::query()->sole();

    expect($file->only(['source', 'path', 'name', 'policy', 'side', 'upload_path']))->toBe([
        'source' => FileSource::Upload,
        'path' => 'config/tacz/tacz-config.toml',
        'name' => 'tacz-config.toml',
        'policy' => FilePolicy::Require,
        'side' => FileSide::Both,
        'upload_path' => 'modsync/uploads/'.hash('sha512', 'damage = 2'),
    ]);
    Storage::disk('local')->assertExists($file->upload_path);
});

it('rejects a folder the client may not write to', function () {
    actAsPackManager();
    Storage::fake('local');

    filesManager($this->pack)
        ->callTableAction('upload', data: ['upload' => UploadedFile::fake()->createWithContent('a.jar', 'x'), 'folder' => 'saves'])
        ->assertHasTableActionErrors(['folder']);

    expect(ModsyncFile::query()->count())->toBe(0);
});

it('imports a /modsync export, adopting files Modrinth knows', function () {
    actAsPackManager();
    Storage::fake('local');
    FakeHosts::fake([
        FakeHosts::MODRINTH.'/version_files' => Http::response([hash('sha512', 'sodium.jar') => FakeHosts::modrinthVersion('v1', 'AANobbMI', 'sodium.jar', '0.6')]),
        FakeHosts::MODRINTH.'/projects*' => Http::response([FakeHosts::modrinthProject('AANobbMI', 'Sodium')]),
    ]);
    $export = json_encode(['formatVersion' => 1, 'packId' => 'export', 'files' => [
        ['path' => 'mods/sodium.jar', 'size' => 10, 'hashes' => ['sha512' => hash('sha512', 'sodium.jar')], 'urls' => ['https://cdn.modrinth.com/x/sodium.jar'], 'policy' => 'require', 'side' => 'both'],
        ['id' => 'curseforge:238222', 'label' => 'JEI', 'path' => 'mods/jei.jar', 'hashes' => ['sha512' => hash('sha512', 'jei.jar')], 'urls' => [FakeHosts::curseForgeUrl(5101234, 'jei.jar')]],
        ['path' => 'mods/private.jar', 'hashes' => ['sha512' => hash('sha512', 'private.jar')]],
    ]]);

    filesManager($this->pack)
        ->callTableAction('import', data: ['manifest' => UploadedFile::fake()->createWithContent('export.json', $export)])
        ->assertNotified('Imported: 2 added, 0 changed, 0 removed.');

    expect(ModsyncFile::query()->orderBy('path')->get()->map->only(['path', 'source', 'project_id', 'version_id', 'name'])->all())->toBe([
        ['path' => 'mods/jei.jar', 'source' => FileSource::CurseForge, 'project_id' => '238222', 'version_id' => '5101234', 'name' => 'JEI'],
        ['path' => 'mods/sodium.jar', 'source' => FileSource::Modrinth, 'project_id' => 'AANobbMI', 'version_id' => 'v1', 'name' => 'Sodium'],
    ]);
});
