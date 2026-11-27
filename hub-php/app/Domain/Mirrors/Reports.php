<?php

namespace App\Domain\Mirrors;

use App\Domain\Replays\ReplayUploads;
use App\Domain\Replays\TrackUploads;
use App\Supabase\Supabase;
use App\Supabase\SupabaseException;
use App\Support\HubCache;
use Illuminate\Support\Facades\Log;

/** Handles one signed mirror report from open to recorded (PROTOCOL.md section 7). */
final class Reports
{
    /** @return array{status: int, body: array<string, mixed>} */
    public static function handle(string $body): array
    {
        $opened = Report::open($body);
        if (is_string($opened)) {
            return self::error(400, $opened);
        }

        try {
            $admin = Supabase::admin();

            // The mirror is looked up before the payload is trusted, because its key decides who signed.
            $mirror = $admin->from('mirror')->select('id,base_url,public_key,enabled')->eq('id', $opened['mirror'])->maybeSingle();
            if ($mirror === null || ($mirror['enabled'] ?? false) !== true) {
                return self::error(403, 'unknown_mirror');
            }
            if (! Report::verify($opened['payloadBytes'], $opened['sig'], (string) $mirror['public_key'])) {
                return self::error(403, 'bad_signature');
            }

            $payload = json_decode($opened['payloadBytes'], true);
            if (! is_array($payload) || ! Report::validate($payload)) {
                return self::error(400, 'bad_request');
            }

            $result = $admin->rpc('record_mirror_report', ['p_mirror' => $opened['mirror'], 'p_payload' => $payload]);
            $outcome = is_array($result) ? ($result['outcome'] ?? null) : null;
            if ($outcome === 'stale') {
                return self::error(409, 'stale');
            }
            if ($outcome === 'unknown_mirror') {
                return self::error(403, 'unknown_mirror');
            }
            if ($outcome !== 'recorded') {
                return self::error(503, 'unavailable');
            }

            // Replays and their tracks are only ever in MariaDB, whichever database holds the mirrors.
            // The next sync report carries the same hashes, so a failure here waits for that.
            try {
                ReplayUploads::received($opened['mirror'], $payload['held']);
                TrackUploads::received($opened['mirror'], $payload['held']);
            } catch (\Throwable $e) {
                Log::warning('Marking replays and tracks received after a report failed: '.$e->getMessage());
            }

            $check = is_array($result['check'] ?? null) ? $result['check'] : null;
            $healthy = HealthCheck::check((string) $mirror['base_url'], $check, in_array('engine', $payload['kinds'] ?? [], true));
            $healthChanged = $admin->rpc('set_mirror_health', ['p_mirror' => $opened['mirror'], 'p_healthy' => $healthy]) === true;

            // Map and game pages are cached for a week. Their download lists
            // name the healthy mirrors holding each archive, so drop them when
            // that changed, and only then. Only game entries with download
            // lists, so unit pages stay cached.
            if (($result['changed'] ?? false) === true || $healthChanged) {
                HubCache::invalidate([HubCache::MAPS, HubCache::DOWNLOADS]);
            }

            // The report is already recorded, so a catalogue that cannot be rebuilt must not fail it.
            try {
                (new CatalogueStore($admin, HubKeys::fromConfig()))->refresh();
            } catch (\Throwable $e) {
                Log::warning('Catalogue refresh after a report failed: '.$e->getMessage());
            }
        } catch (SupabaseException) {
            return self::error(503, 'unavailable');
        }

        return ['status' => 200, 'body' => ['ok' => true]];
    }

    /** @return array{status: int, body: array<string, mixed>} */
    private static function error(int $status, string $code): array
    {
        return ['status' => $status, 'body' => ['error' => $code]];
    }
}
