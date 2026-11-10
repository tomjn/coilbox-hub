<?php

namespace App\Domain\Maps;

use App\Supabase\Client;
use App\Supabase\SupabaseException;

/**
 * The facts the hub holds and may publish for each map, `lib/maps/lookup.ts`.
 *
 * Two questions. `public.map_facts` answers what the hub holds. The licence
 * rows in `public.asset_licence` answer whether it may say so. The catalog
 * makes no licence decision of its own: a map whose resolved licence row
 * permits nothing at all is taken down whole, pictures and facts together.
 * A map with no row and no blanket row is withheld, so removing the blanket row
 * makes the catalog go quiet rather than publish on nobody's say so.
 *
 * Only the secret key may read `asset_licence`, so these take the admin client.
 */
final class Lookup
{
    /**
     * Keyed on the name as stored. Absent for a name the hub does not hold or
     * may not publish. Null when a read fails, never an empty answer.
     *
     * @param  list<string>  $mapNames
     * @return array<string, mixed>|null
     */
    public static function facts(Client $supabase, array $mapNames): ?array
    {
        try {
            $held = $supabase->rpc('map_facts', ['p_names' => $mapNames]);
        } catch (SupabaseException) {
            return null;
        }
        $licences = self::readLicences($supabase, $mapNames);
        if (! is_array($held) || $licences === null) {
            return null;
        }

        [$perMap, $blanket] = self::splitLicences($licences);
        $facts = [];
        foreach ($held as $row) {
            $name = (string) $row['map_name'];
            if (self::permitsAnything($perMap[$name] ?? $blanket)) {
                $facts[$name] = self::restoreObjects($row['facts']);
            }
        }

        return $facts;
    }

    /**
     * The Supabase client decodes JSON objects as PHP arrays, so an empty
     * `appearance` or `meta` object would go back out as `[]`. Those two are
     * always objects, so an empty one is put back.
     *
     * @param  array<string, mixed>  $facts
     * @return array<string, mixed>
     */
    private static function restoreObjects(array $facts): array
    {
        if (($facts['appearance'] ?? null) === []) {
            $facts['appearance'] = new \stdClass;
        }
        foreach (['start', 'metal', 'geo'] as $kind) {
            foreach ($facts['points'][$kind] ?? [] as $i => $point) {
                if (($point['meta'] ?? null) === []) {
                    $facts['points'][$kind][$i]['meta'] = new \stdClass;
                }
            }
        }

        return $facts;
    }

    /**
     * Which of these names have a page, by the same rule. Null when the licence
     * read fails.
     *
     * @param  list<string>  $mapNames
     * @return list<string>|null
     */
    public static function publishedNames(Client $supabase, array $mapNames): ?array
    {
        $licences = self::readLicences($supabase, $mapNames);
        if ($licences === null) {
            return null;
        }
        [$perMap, $blanket] = self::splitLicences($licences);

        return array_values(array_filter($mapNames, fn (string $name) => self::permitsAnything($perMap[$name] ?? $blanket)));
    }

    /**
     * The listing's rule, as how the per map rows differ from the blanket row:
     * `except` these names when the blanket row is open, `only` these when it is
     * closed. Null when the read fails or comes back short of the table.
     *
     * @return array{kind: string, names: list<string>}|null
     */
    public static function listingRule(Client $supabase): ?array
    {
        try {
            $result = $supabase->from('asset_licence')->select('*', 'exact')->or('all_maps.is.true,map_name.not.is.null')->getWithCount();
        } catch (SupabaseException) {
            return null;
        }
        if ($result['count'] !== null && count($result['rows']) < $result['count']) {
            return null;
        }

        [$perMap, $blanket] = self::splitLicences($result['rows']);
        $open = self::permitsAnything($blanket);
        $names = [];
        foreach ($perMap as $name => $row) {
            if (self::permitsAnything($row) !== $open) {
                $names[] = (string) $name;
            }
        }

        return ['kind' => $open ? 'except' : 'only', 'names' => $names];
    }

    /** @param array{kind: string, names: list<string>} $rule */
    public static function ruleListsMap(array $rule, string $mapName): bool
    {
        return in_array($mapName, $rule['names'], true) === ($rule['kind'] === 'only');
    }

    /**
     * Narrow a listing query on `map_name` to the maps with a page.
     *
     * @template Q of \App\Supabase\Query
     *
     * @param  Q  $query
     * @param  array{kind: string, names: list<string>}  $rule
     * @return Q
     */
    public static function applyListingRule($query, array $rule)
    {
        $list = '('.implode(',', array_map(Have::operand(...), $rule['names'])).')';
        if ($rule['kind'] === 'only') {
            return $query->filter('map_name', 'in', $list);
        }

        return $rule['names'] !== [] ? $query->not('map_name', 'in', $list) : $query;
    }

    /**
     * Each map's own licence row and the blanket row, chunked because the
     * filter travels in the URL.
     *
     * @param  list<string>  $mapNames
     * @return list<array<string, mixed>>|null
     */
    private static function readLicences(Client $supabase, array $mapNames): ?array
    {
        $rows = [];
        try {
            foreach (array_chunk($mapNames, Have::NAMES_PER_QUERY) as $chunk) {
                $filters = ['all_maps.is.true', ...array_map(Have::nameFilter(...), $chunk)];
                array_push($rows, ...$supabase->from('asset_licence')->select('*')->or(implode(',', $filters))->get());
            }
        } catch (SupabaseException) {
            return null;
        }

        return $rows;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array{0: array<string, array<string, mixed>>, 1: array<string, mixed>|null}
     */
    private static function splitLicences(array $rows): array
    {
        $perMap = [];
        $blanket = null;
        foreach ($rows as $row) {
            if (! empty($row['all_maps'])) {
                $blanket = $row;
            } elseif (($row['map_name'] ?? null) !== null) {
                $perMap[(string) $row['map_name']] = $row;
            }
        }

        return [$perMap, $blanket];
    }

    /** Withheld only when the resolved row permits no picture of either origin, which is what a takedown is. */
    private static function permitsAnything(?array $licence): bool
    {
        if ($licence === null) {
            return false;
        }

        return ($licence['redistribute_extracted'] ?? null) === 'allowed' || ($licence['redistribute_rendered'] ?? null) === 'allowed';
    }
}
