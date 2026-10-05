# modsync-web

A small web app that builds, publishes and serves manifests for the
[ModSync](https://github.com/frs-projects/ModSync) Minecraft mod. Editing manifest JSON by hand is
replaced by an admin panel:

- **Packs.** One manifest URL each, for one Minecraft version and loader (Forge, NeoForge, Fabric,
  Quilt). A pack also sets what the client does with files the pack does not list: `quarantine`
  (the pack is the whole mod list) or `keep`.
- **Files.** You can add files in four ways:
  - **Modrinth:** live search, filtered by the pack's version and loader. No download is needed,
    because Modrinth publishes SHA-512.
  - **CurseForge:** live search, with an API key. The file is downloaded once to compute its
    SHA-512 (CurseForge only publishes SHA-1, which is checked).
  - **Upload:** for jars and configs that are on no mod site. Uploads are served by this app.
  - **URL:** any HTTPS download, such as a GitHub release. The file is hashed once.
- **Import.** Seed or refresh a pack from a `/modsync export resolve` manifest.
- **Update checks.** Run them from the panel, or let the daily job do it. Files can then be moved
  to the newest version one by one or in bulk.
- **Releases.** Publishing freezes the files into a manifest version. Players always get the
  *live* release, so editing files changes nothing until you publish again. You can roll back to
  any earlier release.

Publishing is refused when the client would reject the manifest: an empty pack, files still
being hashed, a missing URL, or a version that already exists. It warns when a file downloads
from a host the client does not trust by default.

## What players download

| URL | What |
|---|---|
| `GET /manifests/{pack}.json` | The pack's live release, with an ETag. An unchanged manifest answers `304`, and `X-Modsync-Release` names the version. |
| `GET /files/{sha512}/{name}` | An uploaded file, by its hash. It stays available while any release lists it. |

Both are public, like any modpack download. Point the client at the manifest in
`config/modsync-servers.json`, which you ship with your pack:

```json
{
  "servers": {
    "play.example.net": "https://packs.example.net/manifests/main.json"
  }
}
```

Serve the app over **HTTPS**. The client then trusts uploads from the manifest's own host, so
players do not have to add it to `approvedHosts`.

The admin panel is at `/admin`. Every user can do everything, so only create accounts for people
who may publish what players install.

## Deploying with Docker Compose

Requirements: Docker with Compose, and a reverse proxy that terminates TLS (Caddy, Traefik,
nginx, …). The image is published as `ghcr.io/frs-projects/modsync-web`, so you only need
[compose.yaml](compose.yaml):

```sh
mkdir modsync && cd modsync
curl -fsSLO https://raw.githubusercontent.com/frs-projects/modsync-web/main/compose.yaml
```

Create a `.env` next to it:

```sh
APP_KEY=base64:...                       # docker run --rm php:8.4-cli php -r 'echo "base64:".base64_encode(random_bytes(32)), PHP_EOL;'
APP_URL=https://packs.example.net
DB_PASSWORD=a-long-random-password
TRUSTED_PROXIES=*                        # or your proxy's address
MODSYNC_USER_AGENT="my-packs (admin@example.net)"   # Modrinth asks for contact details
MODSYNC_CURSEFORGE_KEY=                  # optional, from console.curseforge.com
IMAGE_TAG=latest                         # or a release, e.g. 1.0.0 or 1
```

Then start the stack and create the first user:

```sh
docker compose up -d
docker compose exec app php artisan make:filament-user
```

The app listens on `127.0.0.1:8080` (change it with `APP_BIND` / `APP_PORT`). Point your reverse
proxy at it. The stack runs:

| Service | Role |
|---|---|
| `app` | Web server (FrankenPHP); runs migrations on start |
| `worker` | Queue worker: hashes CurseForge and URL downloads |
| `scheduler` | Runs `modsync:check-updates` daily at 05:00 |
| `db` | MariaDB |

Uploaded files live in the `storage` volume, so back it up together with the database.

To update, run `docker compose pull && docker compose up -d`. To build the image yourself
instead, clone the repository and run `docker compose up -d --build`.

### Configuration

| Variable | Default | Meaning |
|---|---|---|
| `APP_URL` | — | Public URL. Queued jobs and the CLI use it to build links. |
| `TRUSTED_PROXIES` | — | `*` or a comma-separated list of proxy addresses. Without it, uploads are linked with the scheme the app sees (`http`). |
| `MODSYNC_CURSEFORGE_KEY` | — | CurseForge API key. Without one, CurseForge is hidden. |
| `MODSYNC_USER_AGENT` | `modsync-web (APP_URL)` | Sent to Modrinth and CurseForge. |
| `MODSYNC_MAX_UPLOAD_KB` | `262144` | Largest upload, in kilobytes. Raise PHP's `upload_max_filesize` and your proxy's body limit to match. |
| `MODSYNC_DISK` | `local` | Laravel filesystem disk for uploads. It must not be public. |

Any standard Laravel setting (database, cache, mail, …) can be set through the environment too.

## Running without Docker

You need PHP 8.3+ with `intl`, `zip`, `pdo_sqlite` (or `pdo_mysql` / `pdo_pgsql`) and Composer.
No Node build is needed.

```sh
composer run setup                     # .env, key, SQLite database, migrations
php artisan make:filament-user
php artisan serve                      # http://localhost:8000/admin
php artisan queue:work                 # in a second terminal: hashes downloads
```

In production, also run `php artisan schedule:work` (or a cron entry for `schedule:run`) for the
daily update check.

## Development

```sh
vendor/bin/pest --compact              # tests (in-memory SQLite)
vendor/bin/pint                        # formatting
```

### Publishing the image

`docker/publish.sh` builds the image for amd64 and arm64 and pushes it to GHCR. It tags
`:latest` and the short commit, and on a release tag (`v1.2.3`) also `:1.2.3`, `:1.2` and `:1`.
The script header covers logging in, QEMU for the foreign platform, and `--dirty` builds.

```sh
git tag v1.0.0 && git push --tags
docker/publish.sh
```

## License

[AGPL-3.0](LICENSE). If you run a modified version as a service, you must offer its source to
the people who use it.
