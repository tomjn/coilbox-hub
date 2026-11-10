<?php

namespace App\Http\Controllers\Gallery;

use App\Domain\Gallery\Feed;
use App\Domain\Gallery\SearchParams;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * `/feed.xml`, the gallery as Atom, from `app/feed.xml/route.ts`. One feed
 * for everything, or one kind with `?kind=`. An unknown kind is a 400 that
 * names the ones there are, rather than a feed that quietly holds everything.
 */
class FeedController extends Controller
{
    public function show(Request $request): Response
    {
        $kind = Feed::parseKind(SearchParams::fromRequest($request)->get('kind'));
        if (! $kind['ok']) {
            return new Response($kind['error'], 400, ['Content-Type' => 'text/plain; charset=utf-8']);
        }

        return new Response(Feed::cached($kind['kind']), 200, [
            'Content-Type' => 'application/atom+xml; charset=utf-8',
            'Cache-Control' => 'public, s-maxage=3600, stale-while-revalidate=86400',
        ]);
    }
}
