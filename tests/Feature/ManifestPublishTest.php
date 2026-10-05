<?php

use App\Filament\Resources\ModsyncPacks\Pages\EditModsyncPack;
use App\Filament\Resources\ModsyncPacks\RelationManagers\ReleasesRelationManager;
use App\Models\FilePolicy;
use App\Models\FileSide;
use App\Models\FileSource;
use App\Models\Loader;
use App\Models\ModsyncFile;
use App\Models\ModsyncPack;
use App\Models\ModsyncRelease;
use App\Models\User;
use App\Services\PackReleases;
use App\Services\ReleaseFailed;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('local');
});

function publish(ModsyncPack $pack, string $version = '1.0.0'): ModsyncRelease
{
    return app(PackReleases::class)->publish($pack, $version);
}

function releasesManager(ModsyncPack $pack): Testable
{
    return Livewire::test(ReleasesRelationManager::class, ['ownerRecord' => $pack, 'pageClass' => EditModsyncPack::class]);
}

it('publishes a pack as a format v1 manifest the client accepts', function () {
    $pack = ModsyncPack::factory()->create(['key' => 'main', 'name' => 'TACZ:BG', 'loader' => Loader::Forge, 'minecraft_version' => '1.20.1']);
    $mod = ModsyncFile::factory()->for($pack, 'pack')->create(['project_id' => 'AANobbMI', 'name' => 'Sodium', 'path' => 'mods/sodium.jar']);
    ModsyncFile::factory()->for($pack, 'pack')->create([
        'source' => FileSource::Url, 'project_id' => null, 'name' => 'Old mod', 'path' => 'mods/old.jar', 'policy' => FilePolicy::Forbid,
        'sha512' => null, 'sha1' => null, 'size' => null, 'urls' => [],
    ]);
    ModsyncFile::factory()->for(ModsyncPack::factory()->state(['key' => 'other']), 'pack')->create();

    $release = publish($pack, '2.1.0');

    expect($release->only(['version', 'minecraft_version', 'loader', 'files_count']))->toBe(['version' => '2.1.0', 'minecraft_version' => '1.20.1', 'loader' => Loader::Forge, 'files_count' => 2]);

    $manifest = $this->getJson('/manifests/main.json')
        ->assertOk()
        ->assertHeader('X-Modsync-Release', '2.1.0')
        ->json();

    expect($manifest)->toBe([
        'formatVersion' => 1,
        'packId' => 'main',
        'packName' => 'TACZ:BG',
        'packVersion' => '2.1.0',
        'unlistedPolicy' => 'quarantine',
        'files' => [
            ['label' => 'Old mod', 'path' => 'mods/old.jar', 'policy' => 'forbid', 'side' => 'both'],
            [
                'id' => 'modrinth:AANobbMI',
                'label' => 'Sodium',
                'path' => 'mods/sodium.jar',
                'size' => $mod->size,
                'hashes' => ['sha512' => $mod->sha512, 'sha1' => $mod->sha1],
                'urls' => $mod->urls,
                'policy' => 'require',
                'side' => 'both',
            ],
        ],
    ]);

    $this->get('/manifests/other.json')->assertNotFound();
});

it('answers an unchanged manifest with 304', function () {
    $pack = ModsyncPack::factory()->create(['key' => 'main']);
    ModsyncFile::factory()->for($pack, 'pack')->create();
    publish($pack);

    $etag = $this->get('/manifests/main.json')->headers->get('ETag');

    $this->get('/manifests/main.json', ['If-None-Match' => $etag])->assertStatus(304);
});

it('serves nothing before the first release or for an unknown pack', function () {
    $pack = ModsyncPack::factory()->create(['key' => 'main']);
    ModsyncFile::factory()->for($pack, 'pack')->create();

    $this->get('/manifests/main.json')->assertNotFound();

    publish($pack);

    $this->get('/manifests/nope.json')->assertNotFound();
});

it('keeps serving the live release until the next one, and can go back to an older one', function () {
    $pack = ModsyncPack::factory()->create(['key' => 'main']);
    ModsyncFile::factory()->for($pack, 'pack')->create(['path' => 'mods/a.jar']);
    $first = publish($pack, '1.0.0');

    ModsyncFile::factory()->for($pack, 'pack')->create(['path' => 'mods/b.jar']);

    expect(collect($this->getJson('/manifests/main.json')->json('files'))->pluck('path')->all())->toBe(['mods/a.jar']);

    publish($pack, '1.0.1');

    expect(collect($this->getJson('/manifests/main.json')->json('files'))->pluck('path')->all())->toBe(['mods/a.jar', 'mods/b.jar']);

    app(PackReleases::class)->makeLive($first);

    $this->getJson('/manifests/main.json')->assertHeader('X-Modsync-Release', '1.0.0')->assertJsonPath('packVersion', '1.0.0');
});

it('refuses to publish files the client could not install', function () {
    $pack = ModsyncPack::factory()->create(['key' => 'main']);
    ModsyncFile::factory()->for($pack, 'pack')->unhashed()->create(['path' => 'mods/pending.jar']);
    ModsyncFile::factory()->for($pack, 'pack')->unhashed()->create(['path' => 'mods/broken.jar', 'hash_error' => 'Download failed: HTTP 404']);
    ModsyncFile::factory()->for($pack, 'pack')->create(['path' => 'mods/nowhere.jar', 'source' => FileSource::Url, 'urls' => []]);

    expect(fn () => publish($pack))->toThrow(function (ReleaseFailed $exception) {
        expect($exception->errors)->toBe([
            'mods/broken.jar: could not be hashed: Download failed: HTTP 404',
            'mods/nowhere.jar: has no download URL.',
            'mods/pending.jar: still being hashed. Try again in a minute.',
        ]);
    });
    expect($pack->releases()->exists())->toBeFalse();
});

it('refuses an empty pack and a version that was already released', function () {
    $pack = ModsyncPack::factory()->create(['key' => 'main']);

    expect(fn () => publish($pack))->toThrow(ReleaseFailed::class, 'The pack has no files.');

    ModsyncFile::factory()->for($pack, 'pack')->create();
    publish($pack, '1.0.0');

    expect(fn () => publish($pack, '1.0.0'))->toThrow(ReleaseFailed::class, 'There already is a release 1.0.0.');
});

it('warns when players must approve a download host', function () {
    $pack = ModsyncPack::factory()->create(['key' => 'main']);
    ModsyncFile::factory()->for($pack, 'pack')->create(['source' => FileSource::Url, 'urls' => ['https://files.example.com/a.jar']]);
    ModsyncFile::factory()->for($pack, 'pack')->create(['source' => FileSource::Url, 'urls' => ['https://github.com/o/r/releases/download/v1/b.jar']]);

    expect(publish($pack)->warnings)->toBe([
        'Files download from files.example.com, which the client only allows once a player adds it to approvedHosts in modsync.json.',
    ]);
});

it('trusts uploads served from the manifest host over HTTPS', function (string $root, array $warnings) {
    URL::forceRootUrl($root);
    URL::forceScheme(parse_url($root, PHP_URL_SCHEME));
    $pack = ModsyncPack::factory()->create(['key' => 'main']);
    ModsyncFile::factory()->for($pack, 'pack')->create(['source' => FileSource::Upload, 'urls' => [], 'upload_path' => 'modsync/uploads/x']);

    expect(publish($pack)->warnings)->toBe($warnings);
})->with([
    'https' => ['https://packs.example.net', []],
    'http' => ['http://packs.example.net', ['Files download from packs.example.net, which the client only allows once a player adds it to approvedHosts in modsync.json.']],
]);

it('serves an uploaded file by its hash for as long as a release lists it', function () {
    Storage::disk('local')->put('modsync/uploads/'.hash('sha512', 'jar contents'), 'jar contents');
    $pack = ModsyncPack::factory()->create(['key' => 'main']);
    $file = ModsyncFile::factory()->for($pack, 'pack')->create([
        'source' => FileSource::Upload, 'path' => 'mods/own-mod.jar', 'urls' => [], 'upload_path' => 'modsync/uploads/'.hash('sha512', 'jar contents'),
        'sha512' => hash('sha512', 'jar contents'), 'side' => FileSide::Client,
    ]);
    publish($pack);

    $url = $this->getJson('/manifests/main.json')->json('files.0.urls.0');

    expect($url)->toBe(url('/files/'.$file->sha512.'/own-mod.jar'));
    expect($this->get($url)->assertOk()->streamedContent())->toBe('jar contents');
    $this->get('/files/'.str_repeat('0', 128).'/own-mod.jar')->assertNotFound();

    $file->delete();

    $this->get($url)->assertOk();
});

it('releases the draft from the pack page and go back to an older release', function () {
    Filament::setCurrentPanel('admin');
    $pack = ModsyncPack::factory()->create(['key' => 'main']);
    ModsyncFile::factory()->for($pack, 'pack')->create();
    $first = publish($pack, '1.0.9');

    test()->actingAs(User::factory()->create());

    releasesManager($pack)
        ->mountTableAction('publish')
        ->assertTableActionDataSet(['version' => '1.0.10'])
        ->callMountedTableAction()
        ->assertHasNoTableActionErrors();

    expect($pack->refresh()->liveRelease->version)->toBe('1.0.10');

    releasesManager($pack)->callTableAction('makeLive', $first);

    expect($pack->refresh()->live_release_id)->toBe($first->id);
});

it('deletes a pack with its releases', function () {
    $pack = ModsyncPack::factory()->create(['key' => 'main']);
    ModsyncFile::factory()->for($pack, 'pack')->create();
    publish($pack);

    $pack->delete();

    expect(ModsyncRelease::query()->exists())->toBeFalse();
    $this->get('/manifests/main.json')->assertNotFound();
});
