<?php

namespace App\Domain\Maps;

use App\Supabase\Client;
use App\Supabase\SupabaseException;

/**
 * Map packs, named bundles of maps a moderator puts together for coilbox's Map
 * packs menu, `lib/maps/packs.ts`.
 */
final class Packs
{
    /** `max_rows` in `supabase/config.toml`. Paging copes with a lower cap. */
    private const ENTRY_PAGE_SIZE = 1000;

    /**
     * Every pack with its maps, featured first. Each entry says whether the map
     * page's licence rule has taken it down, rather than dropping it, so the
     * moderation pages can show it. `$licence` must be able to read
     * `asset_licence`. A failed read is an error, never a pack listing everything.
     *
     * @return array{packs: list<array{id: string, title: string, blurb: string|null, featuredAt: string|null, maps: list<array{mapName: string, slug: string|null, displayName: string|null, archiveFilename: string|null, takenDown: bool}>}>, error: string|null}
     */
    public static function fetch(Client $supabase, Client $licence, ?string $onlyId = null): array
    {
        $rule = Lookup::listingRule($licence);
        if ($rule === null) {
            return ['packs' => [], 'error' => 'The licence rule could not be read.'];
        }

        try {
            $packQuery = $supabase->from('map_pack')->select('id, title, blurb, featured_at');
            if ($onlyId !== null) {
                $packQuery->eq('id', $onlyId);
            }
            $packRows = $packQuery->get();
        } catch (SupabaseException $e) {
            return ['packs' => [], 'error' => $e->getMessage()];
        }

        $entryRows = [];
        $total = null;
        try {
            while ($total === null || count($entryRows) < $total) {
                $query = $supabase->from('map_pack_entry')->select('pack_id, map_name, slug, display_name, archive_filename', 'exact');
                if ($onlyId !== null) {
                    $query->eq('pack_id', $onlyId);
                }
                $from = count($entryRows);
                $page = $query->order('pack_id')->order('position')->range($from, $from + self::ENTRY_PAGE_SIZE - 1)->getWithCount();
                $total ??= $page['count'] ?? count($page['rows']);
                if ($page['rows'] === []) {
                    break;
                }
                array_push($entryRows, ...$page['rows']);
            }
        } catch (SupabaseException $e) {
            return ['packs' => [], 'error' => $e->getMessage()];
        }

        $byPack = [];
        foreach ($entryRows as $row) {
            $byPack[$row['pack_id']][] = [
                'mapName' => $row['map_name'],
                'slug' => $row['slug'],
                'displayName' => $row['display_name'],
                'archiveFilename' => $row['archive_filename'],
                'takenDown' => ! Lookup::ruleListsMap($rule, $row['map_name']),
            ];
        }

        usort($packRows, self::comparePacks(...));
        $packs = array_map(fn (array $row) => [
            'id' => $row['id'],
            'title' => $row['title'],
            'blurb' => $row['blurb'],
            'featuredAt' => $row['featured_at'],
            'maps' => $byPack[$row['id']] ?? [],
        ], $packRows);

        return ['packs' => $packs, 'error' => null];
    }

    /** Featured first, most recently featured at the top, then the rest by title. */
    private static function comparePacks(array $a, array $b): int
    {
        if ($a['featured_at'] && $b['featured_at']) {
            return strcmp($b['featured_at'], $a['featured_at']);
        }
        if ($a['featured_at']) {
            return -1;
        }
        if ($b['featured_at']) {
            return 1;
        }

        return collator_compare(collator_create('en'), $a['title'], $b['title']);
    }
}
