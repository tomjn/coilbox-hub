<?php

namespace App\Domain\Engines;

/**
 * Reads the version and platform from an engine archive name such as
 * recoil_2026.09.03_arm64-macos.7z. The 64 character limit on the version is
 * a choice of this hub.
 */
final class EngineFilename
{
    public const PLATFORMS = ['amd64-linux', 'amd64-windows', 'arm64-macos'];

    /**
     * @return array{version: string, platform: string}|null
     */
    public static function parse(string $filename): ?array
    {
        $pattern = '/\Arecoil_([0-9A-Za-z][0-9A-Za-z.+-]{0,63})_('.implode('|', self::PLATFORMS).')\.7z\z/';
        if (preg_match($pattern, $filename, $m) !== 1) {
            return null;
        }

        return ['version' => $m[1], 'platform' => $m[2]];
    }
}
