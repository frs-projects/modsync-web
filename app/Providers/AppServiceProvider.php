<?php

namespace App\Providers;

use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Mod jars run past Livewire's default upload limit.
     */
    public function boot(): void
    {
        $proxies = config('app.trusted_proxies');

        if (filled($proxies)) {
            TrustProxies::at($proxies === '*' ? '*' : array_map('trim', explode(',', $proxies)));
        }

        config(['livewire.temporary_file_upload.rules' => ['required', 'file', 'max:'.max(12288, (int) config('modsync.max_upload_kb'))]]);
    }
}
