<?php

namespace App\Http\Controllers;

use App\Models\ModsyncPack;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ModsyncController
{
    /**
     * A pack's live release, with an ETag so a client polling it on every join only downloads
     * it when it changed.
     */
    public function manifest(Request $request, string $pack): Response
    {
        $release = ModsyncPack::query()->where('key', $pack)->with('liveRelease')->first()?->liveRelease ?? abort(404);

        $response = response($release->manifest, 200, ['Content-Type' => 'application/json'])
            ->setEtag($release->content_hash)
            ->setPublic()
            ->setMaxAge(0)
            ->header('X-Modsync-Release', $release->version);

        $response->isNotModified($request);

        return $response;
    }

    /**
     * An uploaded file, by the SHA-512 it is stored under. It outlives the pack file that added
     * it while a release lists it, so older releases keep downloading.
     */
    public function file(string $hash, string $name): StreamedResponse
    {
        $disk = Storage::disk(config('modsync.disk'));
        $path = "modsync/uploads/{$hash}";

        abort_unless($disk->exists($path), 404);

        return $disk->download($path, $name, ['Content-Type' => 'application/octet-stream']);
    }
}
