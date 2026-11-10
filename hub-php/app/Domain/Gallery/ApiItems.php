<?php

namespace App\Domain\Gallery;

use App\Domain\Container\Container;

/**
 * The desktop app's item list and item bodies, and the strict reading of their
 * query string, from the Next app's `lib/api/items.ts`.
 *
 * Each body carries its own `format` and `version`, so a desktop build that
 * sits on disk for months can tell a newer service from a shape it misreads.
 */
final class ApiItems
{
    public const ITEMS_FORMAT = 'coilbox-hub-items';

    public const ITEMS_VERSION = 1;

    public const ITEM_FORMAT = 'coilbox-hub-item';

    public const ITEM_VERSION = 1;

    public const FILTER_KEYS = ['kind', 'game', 'map', 'tag', 'author', 'account', 'q', 'sort', 'page'];

    /** Everything else may repeat. A second value of one of these is a client bug, so it is rejected. */
    public const SINGLE_VALUE_KEYS = ['game', 'map', 'q', 'sort', 'page'];

    private const ACCOUNT_ID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    /**
     * @param  list<array<string, mixed>>  $items
     * @return array<string, mixed>
     */
    public static function listBody(array $items, int $page, int $total): array
    {
        return [
            'format' => self::ITEMS_FORMAT,
            'version' => self::ITEMS_VERSION,
            'page' => $page,
            'page_size' => GalleryQuery::PAGE_SIZE,
            'total' => $total,
            'items' => $items,
        ];
    }

    /**
     * The summary plus a pointer at the container, `/i/<id>`, rather than the
     * container itself, which is the largest column and has its own cache rules.
     *
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    public static function itemBody(array $item, string $containerUrl): array
    {
        return [
            'format' => self::ITEM_FORMAT,
            'version' => self::ITEM_VERSION,
            'item' => [...$item, 'container_url' => $containerUrl],
        ];
    }

    /**
     * The gallery's own parser forgives a typo in a shared link. A client that
     * asked for `kind=challeng` and got everything back would show it as only
     * challenges, so here an unknown name, a repeated single value, an unknown
     * kind or sort, an account that is not a UUID and a page too large are all
     * errors.
     *
     * @return array{ok: true, filters: Filters}|array{ok: false, error: string}
     */
    public static function parseFilters(SearchParams $params): array
    {
        foreach ($params->keys() as $key) {
            if (! in_array($key, self::FILTER_KEYS, true)) {
                return ['ok' => false, 'error' => 'Unknown query parameter: '.$key];
            }
        }

        foreach (self::SINGLE_VALUE_KEYS as $key) {
            if (count($params->getAll($key)) > 1) {
                return ['ok' => false, 'error' => $key.' takes one value, not several. Send it once.'];
            }
        }

        foreach ($params->getAll('kind') as $kind) {
            if (! in_array($kind, Container::GALLERY_KINDS, true)) {
                $hint = str_contains($kind, ',') ? '. Repeat kind for more than one, e.g. kind=preset&kind=blueprint.' : '';

                return ['ok' => false, 'error' => 'Unknown kind: '.$kind.$hint];
            }
        }

        foreach ($params->getAll('account') as $account) {
            if (preg_match(self::ACCOUNT_ID, Container::trim($account)) !== 1) {
                return ['ok' => false, 'error' => 'Unknown account: '.$account.'. An account is a UUID.'];
            }
        }

        $sort = $params->get('sort');
        if ($sort !== null && $sort !== '' && ! in_array($sort, GalleryQuery::SORT_ORDERS, true)) {
            return ['ok' => false, 'error' => 'Unknown sort: '.$sort];
        }

        if (GalleryQuery::isPageTooLarge($params->get('page'), GalleryQuery::PAGE_SIZE)) {
            return ['ok' => false, 'error' => 'page is too large. The highest page is '.GalleryQuery::maxPage(GalleryQuery::PAGE_SIZE).'.'];
        }

        return ['ok' => true, 'filters' => GalleryQuery::parseFilters($params->toRecord())];
    }
}
