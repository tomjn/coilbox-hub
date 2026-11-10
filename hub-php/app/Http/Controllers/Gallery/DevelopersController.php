<?php

namespace App\Http\Controllers\Gallery;

use App\Domain\Assets\AuthDiscovery;
use App\Domain\Container\Container;
use App\Domain\Gallery\ApiItems;
use App\Domain\Gallery\Feed;
use App\Domain\Gallery\GalleryQuery;
use App\Domain\Games\Download;
use App\Domain\Games\GameList;
use App\Domain\Maps\MapPackList;
use App\Http\Controllers\Controller;
use App\Support\Site;
use Illuminate\Http\Response;

/** `/developers`, from `app/developers/page.tsx`. It documents the desktop API with example bodies. */
class DevelopersController extends Controller
{
    /** `ENDPOINTS` in the page. */
    private const ENDPOINTS = [
        ['id' => 'items', 'label' => 'List items', 'path' => 'GET /api/v1/items'],
        ['id' => 'item', 'label' => 'Get one item', 'path' => 'GET /api/v1/items/[id]'],
        ['id' => 'games', 'label' => 'List games', 'path' => 'GET /api/v1/games'],
        ['id' => 'map-packs', 'label' => 'List map packs', 'path' => 'GET /api/v1/map-packs'],
        ['id' => 'auth', 'label' => 'Sign in configuration', 'path' => 'GET /api/v1/auth'],
        ['id' => 'profile', 'label' => "Get the signed in account's name", 'path' => 'GET /api/v1/profile'],
        ['id' => 'container', 'label' => "Get an item's container", 'path' => 'GET /i/[id]'],
        ['id' => 'export', 'label' => 'Export the gallery', 'path' => 'GET /export'],
        ['id' => 'feed', 'label' => 'Atom feed', 'path' => 'GET /feed.xml'],
    ];

    /** Placeholder ids and author the page's examples use. They belong to nobody. */
    private const FIRST_ID = '0f8fad5b-0449-4000-8000-0000000000a1';

    private const SECOND_ID = '0f8fad5b-0449-4000-8000-0000000000a2';

    /** The two digests in `AUTH_EXAMPLE` of the page. The real ones, as the page says. */
    private const ASSET_VOCABULARY_DIGEST = 'sha256:4879f85b17a5cee05e71b04fbe44607ba2cfb957bef0b2ac7c3dd658519d8b4e';

    private const MAP_CATALOG_DIGEST = 'sha256:f013ff255fef10683673599df2d513e53eab69dddc87c79622ae1d207f611b20';

    public function show(): Response
    {
        $origin = Site::url();
        $kinds = implode(', ', Container::GALLERY_KINDS);
        $pageSize = GalleryQuery::PAGE_SIZE;
        $maxPage = GalleryQuery::maxPage($pageSize);

        $first = [
            'id' => self::FIRST_ID,
            'kind' => 'preset',
            'mode' => null,
            'title' => 'Seed item, just published',
            'description' => 'Seeded for local checking.',
            'game_name' => 'Tab Test Game',
            'game_key' => null,
            'map_name' => 'Seed Map',
            'tags' => ['seed'],
            'author_name' => 'Seed Author',
            'author_id' => '0f8fad5b-0449-4000-8000-0000000000b1',
            'created_at' => '2026-10-04T01:34:37.214+00:00',
            'featured_at' => null,
        ];
        $second = [
            ...$first,
            'id' => self::SECOND_ID,
            'title' => 'Seed item, months old',
            'created_at' => '2026-05-07T01:39:37.214+00:00',
        ];

        $container = [
            'kind' => 'preset',
            'format' => 'coilbox',
            'payload' => [
                'title' => $first['title'],
                'mapName' => $first['map_name'],
                'gameName' => $first['game_name'],
                'participants' => [],
                'startPosType' => 2,
                'modOptionValues' => (object) [],
            ],
            'container' => 1,
            'kindVersion' => 1,
        ];

        $gamesExample = [
            'format' => GameList::FORMAT,
            'version' => GameList::VERSION,
            'games' => [[
                'shortname' => 'tabtest',
                'title' => 'Tab Test Game',
                'description' => null,
                'featured' => false,
                'downloads' => [],
                'logo' => null,
                'card' => null,
                'banner' => null,
                'faction_count' => 2,
                'unit_count' => 12,
                'item_count' => 0,
                'base_game' => null,
            ]],
        ];

        // Constructed, not captured: the local database holds no map packs.
        $mapPacksExample = [
            'format' => MapPackList::FORMAT,
            'version' => MapPackList::VERSION,
            'packs' => [[
                'id' => '00000000-0000-4000-8000-000000000001',
                'title' => 'Example pack',
                'blurb' => 'A short line about the pack.',
                'featured' => true,
                'maps' => [[
                    'id' => 'Example Map 1.0',
                    'title' => 'Example Map',
                    'download' => ['kind' => 'map', 'springName' => 'Example Map 1.0'],
                    'filename' => 'example_map_1.0.sd7',
                ]],
            ]],
        ];

        // The address and the key are placeholders. A deployment's own values are what the route returns.
        $authExample = [
            'format' => AuthDiscovery::FORMAT,
            'version' => AuthDiscovery::VERSION,
            'supabase_url' => 'https://PROJECT.supabase.co',
            'publishable_key' => 'sb_publishable_PLACEHOLDER',
            'asset_vocabulary' => self::ASSET_VOCABULARY_DIGEST,
            'map_catalog' => self::MAP_CATALOG_DIGEST,
            'providers' => ['discord', 'github', 'twitch'],
            'email_password' => AuthDiscovery::EMAIL_PASSWORD_ENABLED,
        ];

        $exportExample = [
            'format' => 'coilbox-hub-export',
            'version' => 1,
            'count' => 2,
            'items' => [[
                'id' => self::FIRST_ID,
                'kind' => $first['kind'],
                'mode' => $first['mode'],
                'title' => $first['title'],
                'description' => $first['description'],
                'game_name' => $first['game_name'],
                'game_key' => $first['game_key'],
                'map_name' => $first['map_name'],
                'tags' => $first['tags'],
                'container' => $container,
                'author_name' => $first['author_name'],
                'created_at' => $first['created_at'],
                'updated_at' => $first['created_at'],
            ]],
        ];

        $feedExample = '<?xml version="1.0" encoding="utf-8"?>
<feed xmlns="http://www.w3.org/2005/Atom">
<id>'.$origin.'/feed.xml?kind=preset</id>
<title type="text">Coilbox Hub: Presets</title>
<subtitle type="text">New presets published on Coilbox Hub.</subtitle>
<updated>2026-10-04T03:16:19.717Z</updated>
<link rel="self" type="application/atom+xml" href="'.$origin.'/feed.xml?kind=preset"/>
<link rel="alternate" type="text/html" href="'.$origin.'/gallery?kind=preset"/>
<author><name>Coilbox Hub</name></author>
<entry>
<id>urn:uuid:'.self::FIRST_ID.'</id>
<title type="text">'.$first['title'].'</title>
<link rel="alternate" type="text/html" href="'.$origin.'/item/'.self::FIRST_ID.'"/>
<published>2026-10-04T01:34:37.214Z</published>
<updated>2026-10-04T01:34:37.214Z</updated>
<author><name>'.$first['author_name'].'</name></author>
<category term="preset" label="Preset"/>
<summary type="text">'.$first['description'].'</summary>
</entry>
</feed>';

        $notFound = ['status' => 404, 'body' => '{"error":"No such item."}', 'text' => 'No item has that id, or the item has been withdrawn.'];
        $dbDown = 'The hub could not read its database. Try again later.';

        return response()->view('gallery.developers', [
            'title' => 'API',
            'description' => 'How to read the gallery, games and map packs of Coilbox Hub from your own program.',
            'origin' => $origin,
            'endpoints' => self::ENDPOINTS,
            'firstId' => self::FIRST_ID,
            'kinds' => $kinds,
            'downloadKinds' => implode(', ', Download::KINDS),
            'feedEntries' => Feed::ENTRIES,
            'pageSize' => $pageSize,
            'maxPage' => $maxPage,
            'itemsParams' => $this->itemsParams($kinds, $pageSize, $maxPage),
            'itemsExample' => self::json(ApiItems::listBody([$first, $second], 1, 2)),
            'itemExample' => self::json(ApiItems::itemBody($first, $origin.'/i/'.self::FIRST_ID)),
            'gamesExample' => self::json($gamesExample),
            'mapPacksExample' => self::json($mapPacksExample),
            'authExample' => self::json($authExample),
            'containerExample' => self::json($container),
            'exportExample' => self::json($exportExample),
            'feedExample' => $feedExample,
            'apiErrors' => [
                'items' => [
                    ['status' => 400, 'body' => '{"error":"Unknown kind: challeng"}', 'text' => 'A kind that is not one of the listed kinds.'],
                    ['status' => 400, 'body' => '{"error":"Unknown query parameter: foo"}', 'text' => 'A parameter name the endpoint does not know.'],
                    ['status' => 400, 'body' => '{"error":"Unknown sort: oldest"}', 'text' => 'A sort that is not one of the listed values.'],
                    ['status' => 400, 'body' => '{"error":"page takes one value, not several. Send it once."}', 'text' => "A single value parameter sent more than once. The first word is the parameter's name."],
                    ['status' => 400, 'body' => '{"error":"page is too large. The highest page is '.$maxPage.'."}', 'text' => 'A page number above the highest the endpoint can serve.'],
                    ['status' => 503, 'body' => '{"error":"The gallery could not be read just now."}', 'text' => $dbDown],
                ],
                'item' => [$notFound],
                'games' => [
                    ['status' => 503, 'body' => '{"error":"The catalog could not be read just now."}', 'text' => $dbDown],
                ],
                'mapPacks' => [
                    ['status' => 503, 'body' => '{"error":"The map packs could not be read just now."}', 'text' => $dbDown],
                ],
                'auth' => [
                    ['status' => 503, 'body' => '{"error":"This deployment has not configured Supabase sign-in."}', 'text' => 'The hub is missing its sign in settings.'],
                ],
                'profile' => [
                    ['status' => 401, 'body' => '{"error":"Send an access token as \"Authorization: Bearer <token>\"."}', 'text' => 'No token was sent, or the token is not valid.'],
                ],
                'container' => [$notFound],
                'export' => [
                    ['status' => 503, 'body' => '{"error":"<reason>"}', 'text' => "The hub could not read its database. The reason is the database's own message."],
                ],
                'feed' => [
                    ['status' => 400, 'body' => 'Unknown kind. Use one of: '.$kinds.'.', 'text' => 'A kind that is not one of the listed kinds. The body is plain text, not JSON.'],
                ],
            ],
        ]);
    }

    /**
     * `ITEMS_PARAMS` in `lib/api/docs.ts`. The names match {@see ApiItems::FILTER_KEYS}
     * and the repeatable ones are the keys outside {@see ApiItems::SINGLE_VALUE_KEYS}.
     *
     * @return list<array{name: string, type: string, text: string}>
     */
    private function itemsParams(string $kinds, int $pageSize, int $maxPage): array
    {
        $sorts = implode(', ', GalleryQuery::SORT_ORDERS);
        $params = [
            'kind' => ['string', 'none, so every kind', "One of {$kinds}. Send it more than once for any of several kinds. Any other value is a 400."],
            'game' => ['string', 'none', "A game's shortname, as in the shortname field of /api/v1/games. Items that name their game only by an exact archive name do not match."],
            'map' => ['string', 'none', 'A map name, matched exactly against the map_name field.'],
            'tag' => ['string', 'none', 'A tag. The hub lowercases it. Send it more than once for items that have any of the tags.'],
            'author' => ['string', 'none', 'An author name, matched exactly against the author_name field. Send it more than once for items by any of the authors.'],
            'account' => ['string', 'none', 'An account id, a UUID, matched against the author_id field. Unlike author, it still matches after the account is renamed. Send it more than once for items by any of the accounts. Anything that is not a UUID is a 400.'],
            'q' => ['string', 'none', 'A search over item titles and descriptions. It takes what a person types into a search box, including quoted phrases and a minus sign to exclude a word.'],
            'sort' => ['string', GalleryQuery::SORT_ORDERS[0], "One of {$sorts}. Featured items come first in either order. Any other value is a 400."],
            'page' => ['integer', '1', "The page to return, counting from 1, with {$pageSize} items to a page. A value that is not a whole number above zero is read as 1. A page above {$maxPage} is a 400."],
        ];

        $out = [];
        foreach (ApiItems::FILTER_KEYS as $name) {
            [$type, $default, $text] = $params[$name];
            $repeatable = ! in_array($name, ApiItems::SINGLE_VALUE_KEYS, true);
            $out[] = [
                'name' => $name,
                'type' => ($repeatable ? 'string, may repeat' : $type).". Default: {$default}.",
                'text' => $text,
            ];
        }

        return $out;
    }

    /** `JSON.stringify(value, null, 2)`: PHP's pretty printer indents by 4, so halve the leading spaces. */
    private static function json(mixed $value): string
    {
        $text = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        return preg_replace_callback('/^ +/m', fn (array $m) => str_repeat(' ', intdiv(strlen($m[0]), 2)), $text);
    }
}
