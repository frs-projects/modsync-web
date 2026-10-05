<?php

return [
    'label' => 'pack',
    'plural' => 'packs',
    'navigation' => 'Modpacks',
    'subheading' => 'Players\' clients sync against :url (the live release). Changes to the files reach them once you publish a release.',
    'key' => 'Key',
    'key_help' => 'The manifest\'s packId and part of its URL. Lower-case letters, digits, ".", "_" and "-". Cannot be changed later.',
    'name' => 'Name',
    'live_version' => 'Live release',
    'unpublished' => 'Not published',
    'minecraft_version' => 'Minecraft version',
    'minecraft_version_help' => 'Versions on Modrinth and CurseForge are filtered by it, e.g. 1.21.1.',
    'loader' => 'Loader',
    'loader_help' => 'Mods on Modrinth and CurseForge are filtered by it.',
    'unlisted_policy' => 'Files not in the pack',
    'unlisted_policy_help' => 'What the client does with files in the folders the pack manages that the pack does not list.',
    'unlisted_policies' => [
        'quarantine' => 'Move aside (the pack is the whole mod list)',
        'keep' => 'Keep',
    ],
    'game' => 'Game',
    'files' => 'Files',
    'updates' => 'Updates',
    'manifest_url' => 'Manifest URL',
    'open_manifest' => 'Live manifest',
    'delete_confirm' => 'The pack, its files and its releases are deleted. Players\' clients can no longer sync it.',
];
