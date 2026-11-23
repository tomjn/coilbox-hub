<?php

namespace App\Domain\Profile;

use App\Domain\Gallery\Cards;
use App\Domain\Gallery\GalleryQuery;
use App\Domain\Games\GameArt;
use App\Domain\Maps\Authors;
use App\Domain\Maps\Have;
use App\Domain\Maps\Listing;
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
    /** Gallery cards shown on a profile, newest first. One row of the gallery's grid. */
    public const RECENT_ITEMS = 3;

    private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D';

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
     * @return array{name: string, maps: array{count: int, recent: list<array{slug: string, label: string}>}, pictures: array{count: int}, games: array{count: int, recent: list<array{shortname: string, label: string}>}, items: array{count: int, recent: list<array<string, mixed>>, cards: array<string, mixed>}}|null
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

        $maps = self::ownedMaps($anon, $admin, $userId, $rule);
        $cards = $maps['recent'];

        $pictures = $anon->from('asset')->select('id', 'exact', head: true)->eq('uploaded_by', $userId)->getWithCount();

        $games = $anon->from('game')->select('shortname,display_name,logo_path,logo_hash,logo_staged_tier', 'exact')->eq('owner_user_id', $userId)
            ->order('created_at', ascending: false)->limit(GalleryQuery::PAGE_SIZE)->getWithCount();

        $items = $anon->from('item')->select(GalleryQuery::ITEM_SUMMARY_COLUMNS, 'exact')->eq('author_id', $userId)
            ->order('created_at', ascending: false)->limit(self::RECENT_ITEMS)->getWithCount();

        return [
            'name' => $name,
            'maps' => [
                'count' => $maps['count'],
                'recent' => $cards,
                'pictures' => Listing::pictures($anon, $cards),
            ],
            'pictures' => ['count' => $pictures['count'] ?? 0],
            'games' => [
                'count' => $games['count'] ?? 0,
                'recent' => array_map(fn ($row) => ['shortname' => $row['shortname'], 'label' => $row['display_name'] ?? $row['shortname'], 'logo' => GameArt::fromRow($row, 'logo')], $games['rows']),
            ],
            'items' => [
                'count' => $items['count'] ?? 0,
                'recent' => $items['rows'],
                'cards' => Cards::forPage($anon, $items['rows']),
            ],
        ];
    }

    /**
     * The maps this account owns, as `can_edit_map` decides: each map a
     * moderator made theirs, and every map credited to an author they own.
     * A map owned both ways counts once. The newest come first.
     *
     * @param  array{kind: string, names: list<string>}  $rule
     * @return array{count: int, recent: list<array<string, mixed>>}
     */
    private static function ownedMaps(Client $anon, Client $admin, string $userId, array $rule): array
    {
        $columns = Listing::SUMMARY_COLUMNS.',created_at';
        $keys = self::ownedAuthorKeys($anon, $admin, $userId);

        $byAuthor = ['rows' => [], 'count' => 0];
        if ($keys !== []) {
            $byAuthor = Lookup::applyListingRule($anon->from('map_browse')->select($columns, 'exact')->overlaps('author_keys', $keys), $rule)
                ->order('created_at', ascending: false)->limit(GalleryQuery::PAGE_SIZE)->getWithCount();
        }

        $ids = array_map(fn ($row) => (string) $row['id'], $anon->from('map')->select('id')->eq('owner_user_id', $userId)->get());
        $single = [];
        foreach (array_chunk($ids, Have::NAMES_PER_QUERY) as $chunk) {
            foreach (Lookup::applyListingRule($anon->from('map_browse')->select($columns)->in('id', $chunk), $rule)->get() as $map) {
                if (array_intersect($map['author_keys'] ?? [], $keys) === []) {
                    $single[] = $map;
                }
            }
        }

        $recent = [...$byAuthor['rows'], ...$single];
        usort($recent, fn (array $a, array $b) => strtotime((string) $b['created_at']) <=> strtotime((string) $a['created_at']));

        return ['count' => ($byAuthor['count'] ?? 0) + count($single), 'recent' => array_slice($recent, 0, GalleryQuery::PAGE_SIZE)];
    }

    /**
     * The author keys this account owns, after any merge, so they match
     * `map_browse.author_keys`. Read with the secret key, since a browser
     * cannot read `author_owner`.
     *
     * @return list<string>
     */
    private static function ownedAuthorKeys(Client $anon, Client $admin, string $userId): array
    {
        $keys = array_map(fn ($row) => (string) $row['key'], $admin->from('author_owner')->select('key')->eq('user_id', $userId)->get());
        if ($keys === []) {
            return [];
        }
        $aliases = [];
        foreach ($anon->from('author_alias')->select('from_key,to_key')->in('from_key', $keys)->get() as $alias) {
            $aliases[(string) $alias['from_key']] = (string) $alias['to_key'];
        }

        return array_values(array_unique(array_map(fn (string $key) => Authors::resolve($key, $aliases), $keys)));
    }
}
