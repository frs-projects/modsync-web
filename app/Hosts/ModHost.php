<?php

namespace App\Hosts;

use App\Models\FileSource;
use App\Models\ModsyncFile;
use App\Models\ModsyncPack;
use App\Models\ProjectType;

/**
 * A site files can be added from and checked for updates on. Versions are filtered by the
 * pack's Minecraft version, and mods also by its loader.
 */
interface ModHost
{
    public function source(): FileSource;

    public function isAvailable(): bool;

    /**
     * @return list<RemoteProject>
     *
     * @throws HostException
     */
    public function search(string $query, ProjectType $type, ModsyncPack $pack): array;

    /**
     * @throws HostException
     */
    public function project(string $projectId): ?RemoteProject;

    /**
     * Versions for the pack, newest first.
     *
     * @return list<RemoteVersion>
     *
     * @throws HostException
     */
    public function versions(string $projectId, ProjectType $type, ModsyncPack $pack): array;

    /**
     * @throws HostException
     */
    public function version(string $projectId, string $versionId): ?RemoteVersion;

    /**
     * The newest version for the pack of each file that has one.
     *
     * @param  list<ModsyncFile>  $files  Files of this host in the pack.
     * @return array<int, RemoteVersion> file id => version
     *
     * @throws HostException
     */
    public function latest(array $files, ModsyncPack $pack): array;
}
