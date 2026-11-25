<?php

namespace App\Domain\Mirrors;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/** Asks a mirror whether it really serves what it reports. */
final class HealthCheck
{
    /**
     * With a candidate, sends a HEAD for that archive and compares Content-Length with its size.
     * Without one, fetches listing.json. Redirects are never followed.
     * A mirror that says it takes engines must also serve its engines/ folder, so one upgraded without it
     * gets no uploads or download links.
     *
     * @param  array{filename: string, kind: string, size: int}|null  $candidate
     */
    public static function check(string $baseUrl, ?array $candidate, bool $takesEngines = false): bool
    {
        $base = rtrim($baseUrl, '/');

        try {
            if ($takesEngines && Http::withoutRedirecting()->get($base.'/engines/')->status() !== 200) {
                return false;
            }

            if ($candidate === null) {
                return Http::withoutRedirecting()->get($base.'/listing.json')->status() === 200;
            }

            $folder = ['game' => 'games', 'engine' => 'engines'][$candidate['kind']] ?? 'maps';
            $response = Http::withoutRedirecting()->head($base.'/'.$folder.'/'.rawurlencode($candidate['filename']));

            return $response->status() === 200
                && $response->header('Content-Length') === (string) $candidate['size'];
        } catch (ConnectionException) {
            return false;
        }
    }
}
