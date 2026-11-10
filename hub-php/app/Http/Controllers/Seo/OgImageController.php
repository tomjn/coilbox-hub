<?php

namespace App\Http\Controllers\Seo;

use App\Domain\Og\Cards;
use App\Http\Controllers\Controller;
use Illuminate\Http\Response;

/**
 * The `opengraph-image` routes. The CDN may keep a card for an hour, then
 * serve it stale for up to a day while one request fetches a new copy, as
 * `lib/cache/card.ts` set it.
 */
class OgImageController extends Controller
{
    private const CACHE_CONTROL = 'public, s-maxage=3600, stale-while-revalidate=86400';

    public function home(): Response
    {
        return self::png(Cards::home());
    }

    public function item(string $id): Response
    {
        return self::png(Cards::item($id));
    }

    public function map(string $slug): Response
    {
        return self::png(Cards::map($slug));
    }

    public function game(string $shortname): Response
    {
        return self::png(Cards::game($shortname));
    }

    private static function png(string $bytes): Response
    {
        return new Response($bytes, 200, ['Content-Type' => 'image/png', 'Cache-Control' => self::CACHE_CONTROL]);
    }
}
