<?php

namespace App\Domain\Games;

/**
 * Where coilbox fetches a game from, `lib/games/download.ts`. Three kinds:
 * a rapid tag, a url with the filename to save it as, and a GitHub repo with
 * an optional part of the release file's name. A game holds an ordered list,
 * best source first, because the sources are not interchangeable.
 *
 * A download is an array `['kind' => ..., 'value' => ..., 'asset'?, 'filename'?]`
 * with the optional keys absent rather than null, matching the JSON contract.
 */
final class Download
{
    public const KINDS = ['rapid', 'url', 'github'];

    private const MAX_VALUE = 512;

    private const MAX_DETAIL = 256;

    public const MAX_DOWNLOADS = 12;

    private const RAPID = '/^[^\s:]+:[^\s:]+$/u';

    private const REPO = '/^[\w.-]+\/[\w.-]+$/';

    private const FILENAME = '/^[^\s\/\\\\]+$/u';

    /**
     * Read one source. A blank value clears it whatever the kind says. A detail
     * belonging to another kind is dropped, because the edit form keeps a row's
     * boxes as the kind is switched.
     *
     * @return array{ok: true, download: array|null}|array{ok: false, message: string}
     */
    public static function parseDownload(string $kind, string $value, string $asset = '', string $filename = ''): array
    {
        $trimmed = Js::trim($value);
        if ($trimmed === '') {
            return ['ok' => true, 'download' => null];
        }
        $length = Js::length($trimmed);
        if ($length > self::MAX_VALUE) {
            return self::refuse('A download is at most '.self::MAX_VALUE.' characters. That one is '.$length.'.');
        }

        if ($kind === 'rapid') {
            if (! preg_match(self::RAPID, $trimmed)) {
                return self::refuse('A rapid tag looks like "metalfactions:stable", a name then a colon then a branch.');
            }

            return ['ok' => true, 'download' => ['kind' => $kind, 'value' => $trimmed]];
        }

        if ($kind === 'url') {
            $scheme = self::urlScheme($trimmed);
            if ($scheme === null) {
                return self::refuse('That is not a web address. It should start with "https://".');
            }
            // Anything else, javascript: above all, would become an href on a public page.
            if ($scheme !== 'https' && $scheme !== 'http') {
                return self::refuse('A download address has to be http or https.');
            }
            $saveAs = Js::trim($filename);
            if ($saveAs === '') {
                return self::refuse('An address needs the filename to save it as, like "metal_factions.sdz".');
            }
            if (Js::length($saveAs) > self::MAX_DETAIL || ! preg_match(self::FILENAME, $saveAs)) {
                return self::refuse('A filename is one name with no slashes or spaces, like "metal_factions.sdz".');
            }

            return ['ok' => true, 'download' => ['kind' => $kind, 'value' => $trimmed, 'filename' => $saveAs]];
        }

        if ($kind === 'github') {
            if (! preg_match(self::REPO, $trimmed)) {
                return self::refuse('A GitHub repo looks like "owner/repo", not a full address.');
            }
            $part = Js::trim($asset);
            $partLength = Js::length($part);
            if ($partLength > self::MAX_DETAIL) {
                return self::refuse('A release file is at most '.self::MAX_DETAIL.' characters. That one is '.$partLength.'.');
            }

            return ['ok' => true, 'download' => $part === ''
                ? ['kind' => $kind, 'value' => $trimmed]
                : ['kind' => $kind, 'value' => $trimmed, 'asset' => $part]];
        }

        return self::refuse('Pick how the game is downloaded.');
    }

    /**
     * The whole list as the edit form sends it, one JSON array in the order to
     * try. Empty rows drop out. Anything else wrong refuses the save naming the row.
     *
     * @return array{ok: true, downloads: list<array>}|array{ok: false, message: string}
     */
    public static function parseDownloads(string $payload): array
    {
        $rows = json_decode($payload === '' ? '[]' : $payload, true);
        if (json_last_error() !== JSON_ERROR_NONE || ! is_array($rows) || ! array_is_list($rows)) {
            return ['ok' => false, 'message' => 'The download list did not arrive. Try saving again.'];
        }
        if (count($rows) > self::MAX_DOWNLOADS) {
            return ['ok' => false, 'message' => 'A game holds at most '.self::MAX_DOWNLOADS.' download sources.'];
        }

        $downloads = [];
        foreach ($rows as $index => $row) {
            if (! is_array($row)) {
                continue;
            }
            $parsed = self::parseDownload(self::text($row['kind'] ?? null), self::text($row['value'] ?? null), self::text($row['asset'] ?? null), self::text($row['filename'] ?? null));
            if (! $parsed['ok']) {
                return ['ok' => false, 'message' => 'Source '.($index + 1).': '.$parsed['message']];
            }
            if ($parsed['download'] !== null) {
                $downloads[] = $parsed['download'];
            }
        }

        return ['ok' => true, 'downloads' => $downloads];
    }

    /**
     * The list a read handed back, with anything it cannot understand left out.
     * Deliberately not the writer's rules: a stored row the form would refuse
     * today still shows up rather than vanishing.
     *
     * @return list<array>
     */
    public static function readDownloads(mixed $value): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            return [];
        }
        $downloads = [];
        foreach ($value as $row) {
            if (! is_array($row)) {
                continue;
            }
            $held = Js::trim(self::text($row['value'] ?? null));
            $kind = self::text($row['kind'] ?? null);
            if ($held === '' || ! in_array($kind, self::KINDS, true)) {
                continue;
            }
            $download = ['kind' => $kind, 'value' => $held];
            $asset = Js::trim(self::text($row['asset'] ?? null));
            if ($asset !== '') {
                $download['asset'] = $asset;
            }
            $filename = Js::trim(self::text($row['filename'] ?? null));
            if ($filename !== '') {
                $download['filename'] = $filename;
            }
            $downloads[] = $download;
        }

        return $downloads;
    }

    /** Where the download control points. A rapid tag has no address. */
    public static function downloadHref(array $download): ?string
    {
        return match ($download['kind']) {
            'url' => $download['value'],
            'github' => 'https://github.com/'.$download['value'].'/releases',
            default => null,
        };
    }

    private static function refuse(string $message): array
    {
        return ['ok' => false, 'message' => $message];
    }

    private static function text(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    /**
     * The scheme `new URL()` would read, lower cased, or null where it would
     * throw. A WHATWG URL needs a scheme, and a special scheme like http needs
     * a host.
     */
    private static function urlScheme(string $value): ?string
    {
        if (! preg_match('/^([a-zA-Z][a-zA-Z0-9+.-]*):(.*)$/s', $value, $m)) {
            return null;
        }
        $scheme = strtolower($m[1]);
        if (in_array($scheme, ['http', 'https', 'ws', 'wss', 'ftp'], true)) {
            $rest = ltrim($m[2], '/\\');
            $host = preg_split('/[\/\\\\?#]/', $rest)[0] ?? '';
            $host = str_contains($host, '@') ? substr($host, strrpos($host, '@') + 1) : $host;
            $host = preg_replace('/:\d*$/', '', $host);
            if ($host === '' || $host === null || preg_match('/[\s<>^|%]/', $host)) {
                return null;
            }
        }

        return $scheme;
    }
}
