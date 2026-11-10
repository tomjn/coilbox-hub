<?php

namespace App\Domain\Games;

use App\Supabase\Client;
use App\Supabase\SupabaseException;

/**
 * The database half of a game facts submission, `lib/games/submit.ts`: the
 * digest the hub works out for itself, and the one call that decides and
 * writes the whole game. The decision is `public.submit_game_facts`, because
 * one request touches five tables and a retirement pass that needs the whole
 * unit list.
 */
final class Submit
{
    /**
     * The digest over one unit's facts. The name is inside the hash. Build
     * options and morph targets are sorted here by code unit, never by locale,
     * so a developer's machine and the server compute one digest.
     */
    public static function unitDigest(array $unit): string
    {
        $buildOptions = Js::sortCodeUnits(array_values(array_unique($unit['build_options'])));
        $morphTargets = $unit['morph_targets'];
        usort($morphTargets, fn ($a, $b) => self::compareInto((string) self::into($a), (string) self::into($b)));

        $canonical = Js::canonicalJson([
            'name' => $unit['name'],
            'fullName' => $unit['full_name'],
            'factionKey' => $unit['faction_key'],
            'buildOptions' => $buildOptions,
            'stats' => self::asObject($unit['stats']),
            'morphTargets' => $morphTargets,
        ]);

        return hash('sha256', $canonical);
    }

    /**
     * The parsed facts with each unit's digest filled in, in the shape
     * `submit_game_facts` reads.
     *
     * @param  array  $submission  {@see GameFacts::parse()}'s submission without `downloads`
     */
    public static function buildGameSubmission(array $submission): array
    {
        $submission['units'] = array_map(
            fn (array $unit) => ['unit' => self::wireUnit($unit), 'facts_digest' => self::unitDigest($unit)],
            $submission['units'],
        );

        return $submission;
    }

    /**
     * The whole game, decided and written in one call. Answers come back in
     * request order, factions first, so a caller zips against its own request.
     *
     * @return array{ok: true, results: list<array>}|array{ok: false}
     */
    public static function submitGameFacts(Client $supabase, array $submission, string $submittedBy): array
    {
        try {
            $data = $supabase->rpc('submit_game_facts', [
                'p_submission' => $submission,
                'p_submitted_by' => $submittedBy,
            ]);
        } catch (SupabaseException) {
            return ['ok' => false];
        }
        if (! is_array($data)) {
            return ['ok' => false];
        }

        $written = [];
        foreach ($data as $row) {
            $written[$row['kind'].':'.$row['name']] = $row;
        }

        $results = [];
        $push = function (string $kind, string $name) use ($written, &$results) {
            $row = $written[$kind.':'.$name] ?? null;
            if ($row !== null) {
                $result = ['kind' => $kind, 'name' => $name, 'outcome' => $row['outcome']];
                if (($row['said'] ?? null) !== null && $row['said'] !== '') {
                    $result['said'] = $row['said'];
                }
                $results[] = $result;
            }
        };
        foreach ($submission['factions'] ?? [] as $faction) {
            $push('faction', $faction['key']);
        }
        foreach ($submission['units'] as $unit) {
            $push('unit', $unit['unit']['name']);
        }

        return ['ok' => true, 'results' => $results];
    }

    /**
     * Download sources the client offered, held for a person to accept rather
     * than written. A separate call, because `submit_game_facts` replaces what
     * it covers. Answers how many offers are newly held, or null on failure,
     * which the caller shrugs at: the next sweep offers again.
     */
    public static function offerGameDownloadSources(Client $supabase, string $shortname, array $downloads, string $offeredBy): ?int
    {
        try {
            $data = $supabase->rpc('offer_game_download_sources', [
                'p_shortname' => $shortname,
                'p_sources' => $downloads,
                'p_offered_by' => $offeredBy,
            ]);
        } catch (SupabaseException) {
            return null;
        }

        return is_int($data) ? $data : 0;
    }

    /** Stats always travel as a JSON object, even an empty one. */
    private static function wireUnit(array $unit): array
    {
        $unit['stats'] = self::asObject($unit['stats']);

        return $unit;
    }

    private static function asObject(mixed $value): object
    {
        return is_object($value) ? $value : (object) $value;
    }

    private static function into(mixed $target): mixed
    {
        return is_object($target) ? ($target->into ?? null) : ($target['into'] ?? null);
    }

    private static function compareInto(string $into, string $other): int
    {
        return Js::compareCodeUnits($into, $other);
    }
}
