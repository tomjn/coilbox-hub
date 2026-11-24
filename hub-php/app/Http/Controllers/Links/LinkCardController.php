<?php

namespace App\Http\Controllers\Links;

use App\Domain\Links\LinkCards;
use App\Http\Controllers\Controller;
use App\Support\Api;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * `GET /api/v1/links/card` and `GET /api/v1/links/image`, the preview cards
 * Coilbox shows under links in chat (#654). No sign in, because most players
 * in a lobby have no hub account. Both are rate limited per address.
 */
class LinkCardController extends Controller
{
    public function card(Request $request, LinkCards $cards): JsonResponse
    {
        $url = $request->query('url');
        if (! is_string($url) || trim($url) === '' || strlen($url) > LinkCards::MAX_URL_LENGTH) {
            return Api::error('Give the link as the url parameter.', 400);
        }

        return Api::json(['card' => $cards->card($url)]);
    }

    public function image(Request $request, LinkCards $cards): Response|JsonResponse
    {
        $url = $request->query('url');
        $sig = $request->query('sig');
        if (! is_string($url) || ! is_string($sig) || ! hash_equals(LinkCards::sign($url), $sig)) {
            return Api::error('This picture is not from a link card.', 403);
        }

        $image = $cards->image($url);
        if ($image === null) {
            return Api::error('The picture could not be loaded.', 404);
        }

        return new Response($image['bytes'], 200, Api::CORS_HEADERS + [
            'Content-Type' => $image['type'],
            'Cache-Control' => 'public, max-age=86400',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'",
        ]);
    }

    public function preflight(): Response
    {
        return Api::preflight();
    }
}
