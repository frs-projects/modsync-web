<?php

namespace App\Hosts;

use App\Models\FileSide;

/**
 * A project (mod, resource pack, shader) on Modrinth or CurseForge.
 */
final readonly class RemoteProject
{
    /**
     * @param  FileSide|null  $side  Where the host says the project runs, when it says (Modrinth).
     */
    public function __construct(
        public string $id,
        public string $title,
        public ?string $author = null,
        public ?string $summary = null,
        public ?string $iconUrl = null,
        public ?string $pageUrl = null,
        public int $downloads = 0,
        public ?FileSide $side = null,
    ) {}
}
