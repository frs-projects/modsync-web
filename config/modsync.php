<?php

/*
| ModSync manifests and the files they list.
*/

return [

    'modrinth' => [
        'url' => env('MODSYNC_MODRINTH_URL', 'https://api.modrinth.com/v2'),
    ],

    // CurseForge needs an API key (console.curseforge.com). Without one, CurseForge is hidden.
    'curseforge' => [
        'url' => env('MODSYNC_CURSEFORGE_URL', 'https://api.curseforge.com/v1'),
        'key' => env('MODSYNC_CURSEFORGE_KEY'),
    ],

    // Modrinth asks every client to identify itself.
    'user_agent' => env('MODSYNC_USER_AGENT') ?: 'modsync-web ('.env('APP_URL', 'http://localhost').')',

    // Disk uploaded files are kept on (never public: served through /files).
    'disk' => env('MODSYNC_DISK', 'local'),

    // Largest uploaded file, in kilobytes.
    'max_upload_kb' => (int) env('MODSYNC_MAX_UPLOAD_KB', 262144),

    // Hosts the ModSync client downloads from without the player adding them to approvedHosts
    // (HostAllowlist in the mod, suffix-matched). Files elsewhere get a build warning.
    'trusted_hosts' => ['modrinth.com', 'curseforge.com', 'forgecdn.net', 'githubusercontent.com', 'github.com'],

];
