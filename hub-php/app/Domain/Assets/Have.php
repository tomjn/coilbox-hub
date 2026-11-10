<?php

namespace App\Domain\Assets;

use App\Supabase\Client;
use App\Supabase\SupabaseException;

/**
 * Identity keys and the batch have check, `lib/assets/have.ts` in the Next app.
 *
 * Given a list of identities, what `source_hash` the hub holds for each.
 * Answered entirely from `public.asset`.
 */
final class Have
{
    /**
     * Not `hash`, which is over the encoded bytes and differs between encoder
     * builds. `moderation` and `bytes_missing_at` decide whether a row counts.
     */
    private const HAVE_COLUMNS = 'game, unit_name, map_name, faction_key, variant, source_hash, moderation, bytes_missing_at';

    /**
     * How many identities go into one PostgREST request. The filter travels in
     * the query string, so the ceiling is URL length: 50 groups is near 5 kB,
     * under the 8 kB request line most proxies allow.
     */
    public const KEYS_PER_QUERY = 50;

    /**
     * Whether a row means the hub holds the picture. Not when the store lost the
     * bytes, so the client still holding the file offers it again. Except when
     * rejected, because an upload cannot undo a rejection.
     *
     * @param  array{moderation: string, bytes_missing_at: string|null}  $row
     */
    public static function heldForHave(array $row): bool
    {
        return $row['bytes_missing_at'] === null || $row['moderation'] === 'rejected';
    }

    /**
     * One string per identity. Separated by a null character, which no column
     * can contain, so two different identities cannot collide.
     *
     * @param  array<string, string>  $identity
     */
    public static function identityKey(array $identity): string
    {
        return match ($identity['keyedOn']) {
            'unit' => implode("\0", ['unit', $identity['game'], $identity['unitName'], $identity['variant']]),
            'faction' => implode("\0", ['faction', $identity['game'], $identity['factionKey'], $identity['variant']]),
            'map' => implode("\0", ['map', $identity['mapName'], $identity['variant']]),
        };
    }

    /**
     * A value as a PostgREST filter operand. Always quoted, with backslashes
     * and quotes escaped, because a map name is free text and a bare comma or
     * bracket would end the filter early.
     */
    public static function operand(string $value): string
    {
        return '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $value).'"';
    }

    /**
     * The filter for one identity, as a PostgREST `and(...)` group. One group
     * per key rather than three `in` lists, which would ask for the cross
     * product of everything named.
     *
     * @param  array<string, string>  $identity
     */
    public static function identityFilter(array $identity): string
    {
        return match ($identity['keyedOn']) {
            'unit' => 'and(game.eq.'.self::operand($identity['game']).',unit_name.eq.'.self::operand($identity['unitName']).',variant.eq.'.self::operand($identity['variant']).')',
            'faction' => 'and(game.eq.'.self::operand($identity['game']).',faction_key.eq.'.self::operand($identity['factionKey']).',variant.eq.'.self::operand($identity['variant']).')',
            'map' => 'and(map_name.eq.'.self::operand($identity['mapName']).',variant.eq.'.self::operand($identity['variant']).')',
        };
    }

    /**
     * @template T
     *
     * @param  list<T>  $identities
     * @return list<list<T>>
     */
    public static function queryChunks(array $identities, int $size = self::KEYS_PER_QUERY): array
    {
        return array_chunk($identities, $size);
    }

    /**
     * Which identity a row answers for, read back from its columns. A select
     * list without `faction_key` can only return unit and map rows.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, string>
     */
    public static function rowIdentity(array $row): array
    {
        if (($row['map_name'] ?? null) !== null) {
            return ['keyedOn' => 'map', 'mapName' => (string) $row['map_name'], 'variant' => (string) $row['variant']];
        }

        if (($row['faction_key'] ?? null) !== null) {
            return [
                'keyedOn' => 'faction',
                'game' => (string) ($row['game'] ?? ''),
                'factionKey' => (string) $row['faction_key'],
                'variant' => (string) $row['variant'],
            ];
        }

        return [
            'keyedOn' => 'unit',
            'game' => (string) ($row['game'] ?? ''),
            'unitName' => (string) ($row['unit_name'] ?? ''),
            'variant' => (string) $row['variant'],
        ];
    }

    /**
     * The `source_hash` held for each identity, keyed by {@see identityKey()},
     * or null when a chunk failed. An identity with no row is absent.
     *
     * Every moderation state counts, so this wants the admin client.
     *
     * @param  list<array<string, string>>  $identities
     * @return array<string, string>|null
     */
    public static function fetchSourceHashes(Client $supabase, array $identities): ?array
    {
        $hashes = [];
        foreach (self::queryChunks($identities) as $chunk) {
            try {
                $rows = $supabase->from('asset')
                    ->select(self::HAVE_COLUMNS)
                    ->or(implode(',', array_map(self::identityFilter(...), $chunk)))
                    ->get();
            } catch (SupabaseException) {
                return null;
            }
            foreach ($rows as $row) {
                if (! self::heldForHave(['moderation' => (string) $row['moderation'], 'bytes_missing_at' => $row['bytes_missing_at'] ?? null])) {
                    continue;
                }
                $hashes[self::identityKey(self::rowIdentity($row))] = (string) $row['source_hash'];
            }
        }

        return $hashes;
    }
}
