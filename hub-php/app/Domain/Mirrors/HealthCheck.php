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
     *
     * @param  array{filename: string, kind: string, size: int}|null  $candidate
     */
    public static function check(string $baseUrl, ?array $candidate): bool
    {
        $base = rtrim($baseUrl, '/');

        try {
            if ($candidate === null) {
                return Http::withoutRedirecting()->get($base.'/listing.json')->status() === 200;
            }

            $folder = $candidate['kind'] === 'game' ? 'games' : 'maps';
            $response = Http::withoutRedirecting()->head($base.'/'.$folder.'/'.rawurlencode($candidate['filename']));

            return $response->status() === 200
                && $response->header('Content-Length') === (string) $candidate['size'];
        } catch (ConnectionException) {
            return false;
        }
    }
}
