<?php

namespace App\Domain\Mirrors;

use App\Domain\Engines\EngineFilename;
use App\Domain\Engines\Engines;

/**
 * The facts a Coilbox client sends when it asks to upload an archive. Reads the
 * decoded JSON body and answers with the normalised facts, or a short message
 * saying what is wrong.
 */
final class UploadFacts
{
    /**
     * @return array{sha256: string, size: int, filename: string, kind: string, internal_name: string, version: string, shortname: string|null, author: string|null, description: string|null, platform: string}|string
     */
    public static function parse(mixed $json): array|string
    {
        if (! is_array($json) || array_is_list($json)) {
            return 'Send the archive facts as a JSON object.';
        }

        $sha256 = $json['sha256'] ?? null;
        if (! is_string($sha256) || preg_match('/\A[0-9a-f]{64}\z/', $sha256) !== 1) {
            return 'sha256 must be 64 lowercase hex characters.';
        }

        $size = $json['size'] ?? null;
        if (! is_int($size) || $size < 1) {
            return 'size must be a whole number of bytes, at least 1.';
        }

        $kind = $json['kind'] ?? null;
        if ($kind !== 'map' && $kind !== 'game' && $kind !== 'engine') {
            return 'kind must be "map", "game" or "engine".';
        }

        if ($kind === 'engine') {
            return self::parseEngine($json, $sha256, $size);
        }

        $filename = $json['filename'] ?? null;
        if (! is_string($filename) || ! ArchiveFilename::isValid($filename)) {
            return 'filename must be a plain archive name ending in .sd7 or .sdz.';
        }

        $internalName = $json['internal_name'] ?? null;
        $internalName = is_string($internalName) ? trim($internalName) : null;
        if ($internalName === null || $internalName === '' || mb_strlen($internalName) > 256) {
            return 'internal_name must be 1 to 256 characters.';
        }

        $version = $json['version'] ?? null;
        if (! is_string($version) || mb_strlen($version) > 256) {
            return 'version must be text of at most 256 characters.';
        }

        $shortname = $json['shortname'] ?? null;
        if ($kind === 'game') {
            if (! is_string($shortname) || mb_strlen($shortname) < 1 || mb_strlen($shortname) > 64) {
                return 'shortname is required for a game, 1 to 64 characters.';
            }
        } elseif ($shortname !== null && (! is_string($shortname) || $shortname === '' || mb_strlen($shortname) > 64)) {
            return 'shortname must be null or 1 to 64 characters.';
        }

        $author = $json['author'] ?? null;
        if ($author !== null && (! is_string($author) || mb_strlen($author) > 256)) {
            return 'author must be null or text of at most 256 characters.';
        }

        $description = $json['description'] ?? null;
        if ($description !== null && (! is_string($description) || mb_strlen($description) > 4000)) {
            return 'description must be null or text of at most 4000 characters.';
        }

        return [
            'sha256' => $sha256,
            'size' => $size,
            'filename' => $filename,
            'kind' => $kind,
            'internal_name' => $internalName,
            'version' => $version,
            'shortname' => $shortname,
            'author' => $author,
            'description' => $description,
            'platform' => '',
        ];
    }

    /**
     * An engine's version and platform come from its filename, and the request
     * must say the same. The note is the description.
     *
     * @param  array<string, mixed>  $json
     * @return array{sha256: string, size: int, filename: string, kind: string, internal_name: string, version: string, shortname: string|null, author: string|null, description: string|null, platform: string}|string
     */
    private static function parseEngine(array $json, string $sha256, int $size): array|string
    {
        $filename = $json['filename'] ?? null;
        $parts = is_string($filename) ? EngineFilename::parse($filename) : null;
        if ($parts === null) {
            return 'filename must look like recoil_<version>_<platform>.7z, with a platform of amd64-linux, amd64-windows or arm64-macos.';
        }

        if (($json['version'] ?? null) !== $parts['version']) {
            return 'version must match the version in the filename.';
        }

        if (($json['platform'] ?? null) !== $parts['platform']) {
            return 'platform must match the platform in the filename.';
        }

        $note = $json['note'] ?? null;
        $note = is_string($note) ? trim($note) : '';
        if ($note === '' || mb_strlen($note) > Engines::NOTE_MAX) {
            return 'note is required, 1 to '.Engines::NOTE_MAX.' characters.';
        }

        return [
            'sha256' => $sha256,
            'size' => $size,
            'filename' => $filename,
            'kind' => 'engine',
            'internal_name' => 'recoil',
            'version' => $parts['version'],
            'shortname' => null,
            'author' => null,
            'description' => $note,
            'platform' => $parts['platform'],
        ];
    }
}
