<?php

namespace App\Domain\Profile;

use App\Domain\Gallery\GalleryQuery;
use App\Domain\Maps\Lookup;
use App\Supabase\Client;
use App\Supabase\Supabase;
use App\Support\HubCache;
use RuntimeException;

/**
 * What an account has given the hub, for `/profile/<id>`, from the Next app's
 * `lib/profile/contributions.ts` and `lib/profile/cached.ts`.
 *
 * Everything is a public reading, so it is cached. Maps go through the same
 * licence gate as the catalog, so a profile never lists a map the catalog
 * would hide. A failed read throws rather than showing a short count.
 */
final class Contributions
{
    private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

    /** A profile address's id, lower cased, or null when it is not a UUID. */
    public static function parseAccountId(string $raw): ?string
    {
        $id = strtolower($raw);

        return preg_match(self::UUID, $id) === 1 ? $id : null;
    }

    /** @return array<string, mixed>|null */
    public static function cached(string $userId): ?array
    {
        return HubCache::remember('profile:'.$userId, [HubCache::ITEMS, HubCache::MAPS, HubCache::GAMES, HubCache::ASSETS], HubCache::HOURS, function () use ($userId) {
            return Supabase::configured() ? self::load(Supabase::anon(), Supabase::admin(), $userId) : null;
        });
    }

    /**
     * Null when the account has no display name, which is no public profile.
     *
     * @return array{name: string, maps: array{count: int, recent: list<array{slug: string, label: string}>}, pictures: array{count: int}, games: array{count: int, recent: list<array{shortname: string, label: string}>}, items: array{count: int, recent: list<array{id: string, title: string}>}}|null
     */
    public static function load(Client $anon, Client $admin, string $userId): ?array
    {
        $name = Author::displayName($anon, $userId);
        if ($name === null || $name === '') {
            return null;
        }

        $rule = Lookup::listingRule($admin);
        if ($rule === null) {
            throw new RuntimeException('The licence gate could not be read.');
        }

        $maps = Lookup::applyListingRule(
            $anon->from('map')->select('slug,map_name,display_name', 'exact')->eq('submitted_by', $userId),
            $rule,
        )->order('created_at', ascending: false)->limit(GalleryQuery::PAGE_SIZE)->getWithCount();

        $pictures = $anon->from('asset')->select('id', 'exact', head: true)->eq('uploaded_by', $userId)->getWithCount();

        $games = $anon->from('game')->select('shortname,display_name', 'exact')->eq('submitted_by', $userId)
            ->order('created_at', ascending: false)->limit(GalleryQuery::PAGE_SIZE)->getWithCount();

        $items = $anon->from('item')->select('id,title', 'exact')->eq('author_id', $userId)
            ->order('created_at', ascending: false)->limit(GalleryQuery::PAGE_SIZE)->getWithCount();

        return [
            'name' => $name,
            'maps' => [
                'count' => $maps['count'] ?? 0,
                'recent' => array_map(fn ($row) => ['slug' => $row['slug'], 'label' => $row['display_name'] ?? $row['map_name']], $maps['rows']),
            ],
            'pictures' => ['count' => $pictures['count'] ?? 0],
            'games' => [
                'count' => $games['count'] ?? 0,
                'recent' => array_map(fn ($row) => ['shortname' => $row['shortname'], 'label' => $row['display_name'] ?? $row['shortname']], $games['rows']),
            ],
            'items' => [
                'count' => $items['count'] ?? 0,
                'recent' => array_map(fn ($row) => ['id' => $row['id'], 'title' => $row['title']], $items['rows']),
            ],
        ];
    }
}
