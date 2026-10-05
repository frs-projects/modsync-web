<?php

namespace App\Jobs;

use App\Hosts\HostException;
use App\Models\ModsyncFile;
use App\Services\FileHasher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;

/**
 * Downloads a file the panel has no SHA-512 for (CurseForge, a URL) and stores its hashes and
 * size. A SHA-1 the host published must match. Failures are kept on the file for the panel.
 */
class HashFile implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public ModsyncFile $file) {}

    public function handle(FileHasher $hasher): void
    {
        $file = $this->file->fresh();

        if ($file === null || $file->sha512 !== null) {
            return;
        }

        $url = $file->downloadUrls()[0] ?? null;

        if ($url === null) {
            $file->update(['hash_error' => __('files.errors.no_url')]);

            return;
        }

        try {
            $hashes = $hasher->hashUrl($url);
        } catch (HostException $exception) {
            $file->update(['hash_error' => Str::limit($exception->getMessage(), 250)]);

            if ($this->attempts() < $this->tries) {
                $this->release(60 * $this->attempts());
            }

            return;
        }

        if ($file->sha1 !== null && $file->sha1 !== $hashes['sha1']) {
            $file->update(['hash_error' => __('files.errors.sha1_mismatch')]);

            return;
        }

        $file->update([...$hashes, 'hash_error' => null]);
    }
}
