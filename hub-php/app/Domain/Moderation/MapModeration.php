<?php

namespace App\Domain\Moderation;

use App\Supabase\Client;
use App\Supabase\SupabaseException;

/**
 * The two things a person does to one map by hand, `lib/maps/moderation.ts`
 * (issue #193): read the disagreements clients reported about it, and put on
 * the tags no measurement produces.
 *
 * Conflicts are read with the secret key, because `map_source_conflict`
 * grants browser roles nothing. The catalog is public, so the map's own view
 * is read with the moderator's session. Nothing here acts on several maps at
 * once: clearing a map without reading its reports is the mistake worth
 * making impossible.
 */
final class MapModeration
{
    /** A ceiling on one queue, and on the second read for the maps it names. */
    public const CONFLICT_PAGE_SIZE = 200;

    /** Bounds on a free text field, not a view about what a tag should be. */
    public const CURATED_TAG_LIMIT = 20;

    public const CURATED_TAG_MAX_LENGTH = 64;

    /** How many maps a search offers. This reaches one map, it is not a listing. */
    public const SEARCH_LIMIT = 20;

    /** What the map feature control says, `lib/maps/featured.ts` (#394). */
    public const FEATURED_MESSAGES = [
        'featured' => 'Map featured.',
        'unfeatured' => 'Map no longer featured.',
        'signedOut' => 'You are signed out. Sign in, then try again.',
        'notAllowed' => 'Only a moderator can feature a map.',
        'notFound' => 'No map with that id.',
        'notSaved' => 'That could not be saved. Try again in a few minutes.',
        'notSent' => 'The form did not reach the hub. Reload the page and try again.',
    ];

    /**
     * Reports grouped under their maps, the most reported first, because one
     * map collecting reports from account after account is the case worth
     * reading. A report about a map the second read did not return was cleared
     * in between, so it is dropped.
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  list<array{id: string, map_name: string, slug: string}>  $maps
     * @return list<array{id: string, mapName: string, slug: string, reports: list<array<string, mixed>>}>
     */
    public static function groupConflicts(array $rows, array $maps): array
    {
        $named = array_column($maps, null, 'id');
        $grouped = [];
        foreach ($rows as $row) {
            $map = $named[$row['map_id']] ?? null;
            if ($map === null) {
                continue;
            }
            $grouped[$map['id']] ??= ['id' => $map['id'], 'mapName' => $map['map_name'], 'slug' => $map['slug'], 'reports' => []];
            $grouped[$map['id']]['reports'][] = [
                'id' => $row['id'],
                'sourceArchive' => $row['source_archive'],
                'heldSourceHash' => $row['held_source_hash'],
                'reportedSourceHash' => $row['reported_source_hash'],
                'reportedBy' => $row['reported_by'],
                'at' => $row['at'],
            ];
        }
        $grouped = array_values($grouped);
        // Stable, so maps with as many reports keep the order of their newest report.
        usort($grouped, fn ($a, $b) => count($b['reports']) <=> count($a['reports']));

        return $grouped;
    }

    /**
     * Every map two clients disagreed about. Two reads rather than an embed,
     * so a stale schema cache cannot turn the queue into an error.
     *
     * @return list<array<string, mixed>>
     */
    public static function conflicts(Client $admin, int $limit = self::CONFLICT_PAGE_SIZE): array
    {
        try {
            $rows = $admin->from('map_source_conflict')
                ->select('id, map_id, source_archive, held_source_hash, reported_source_hash, reported_by, at')
                ->order('at', ascending: false)->limit($limit)->get();
        } catch (SupabaseException) {
            return [];
        }
        if ($rows === []) {
            return [];
        }
        try {
            $maps = $admin->from('map')->select('id, map_name, slug')->in('id', array_values(array_unique(array_column($rows, 'map_id'))))->get();
        } catch (SupabaseException) {
            $maps = [];
        }

        return self::groupConflicts($rows, $maps);
    }

    /**
     * Forget everything held about one map. `clear_map_facts` refuses a map
     * nobody has disagreed about, so this is not a way to delete a map.
     */
    public static function clearFacts(Client $admin, string $mapId): bool
    {
        try {
            return $admin->rpc('clear_map_facts', ['p_map_id' => $mapId]) === true;
        } catch (SupabaseException) {
            return false;
        }
    }

    /**
     * The tags a submitted field means: comma separated, lower cased because
     * every tag filter is, duplicates dropped, the moderator's order kept. A
     * tag longer than a phrase is a paste gone wrong.
     *
     * @return list<string>
     */
    public static function parseCuratedTags(string $value): array
    {
        $seen = [];
        foreach (explode(',', $value) as $part) {
            $tag = mb_strtolower(trim($part));
            if ($tag === '' || mb_strlen($tag) > self::CURATED_TAG_MAX_LENGTH) {
                continue;
            }
            $seen[$tag] = true;
            if (count($seen) === self::CURATED_TAG_LIMIT) {
                break;
            }
        }

        return array_map('strval', array_keys($seen));
    }

    /** @param list<string> $tags */
    public static function curatedTagsField(array $tags): string
    {
        return implode(', ', $tags);
    }

    /** @param list<string> $tags */
    public static function setCuratedTags(Client $admin, string $mapId, array $tags): bool
    {
        try {
            $admin->from('map')->update(['curated_tags' => $tags])->eq('id', $mapId)->execute();
        } catch (SupabaseException) {
            return false;
        }

        return true;
    }

    /**
     * One map as its moderation view shows it, or null. The derived tags are
     * the listing's merged tags minus the curated ones.
     *
     * @return array{id: string, mapName: string, slug: string, displayName: string|null, curatedTags: list<string>, derivedTags: list<string>, featuredAt: string|null}|null
     */
    public static function moderatedMap(Client $session, string $slug): ?array
    {
        try {
            $map = $session->from('map')->select('id, map_name, slug, display_name, curated_tags, featured_at')->eq('slug', $slug)->maybeSingle();
        } catch (SupabaseException) {
            return null;
        }
        if ($map === null) {
            return null;
        }
        try {
            $listing = $session->from('map_listing')->select('tags')->eq('slug', $slug)->maybeSingle();
        } catch (SupabaseException) {
            $listing = null;
        }
        $curated = $map['curated_tags'] ?? [];

        return [
            'id' => $map['id'],
            'mapName' => $map['map_name'],
            'slug' => $map['slug'],
            'displayName' => $map['display_name'],
            'curatedTags' => $curated,
            'derivedTags' => array_values(array_filter($listing['tags'] ?? [], fn ($tag) => ! in_array($tag, $curated, true))),
            'featuredAt' => $map['featured_at'],
        ];
    }

    /** An `ilike` pattern matching the text anywhere, with `\`, `%` and `_` taken literally. */
    public static function containsPattern(string $text): string
    {
        return '%'.preg_replace('/[\\\\%_]/', '\\\\$0', $text).'%';
    }

    /**
     * Maps whose canonical name contains what was typed. A contains match, not
     * ranked search, because the reader knows which map they want.
     *
     * @return list<array{mapName: string, slug: string}>
     */
    public static function search(Client $session, string $term): array
    {
        $wanted = trim($term);
        if ($wanted === '') {
            return [];
        }
        try {
            $rows = $session->from('map')->select('map_name, slug')->ilike('map_name', self::containsPattern($wanted))
                ->order('map_name')->limit(self::SEARCH_LIMIT)->get();
        } catch (SupabaseException) {
            return [];
        }

        return array_map(fn (array $row) => ['mapName' => $row['map_name'], 'slug' => $row['slug']], $rows);
    }
}
