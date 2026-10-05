<?php

use App\Http\Controllers\ModsyncController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/admin');

/*
| What the ModSync client downloads. Open to anyone, like a modpack download: the manifest lists
| public mods, and an uploaded file is only found with its full hash.
*/
Route::controller(ModsyncController::class)->name('modsync.')->group(function (): void {
    Route::get('/manifests/{pack}.json', 'manifest')->where('pack', '[a-z0-9][a-z0-9._-]{0,63}')->name('manifest');
    Route::get('/files/{hash}/{name}', 'file')->where('hash', '[0-9a-f]{128}')->name('file');
});
