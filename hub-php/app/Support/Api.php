<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/**
 * Responses for `/api/v1`, matching `lib/api/response.ts` and `lib/api/cors.ts`
 * in the Next app. The desktop app reads these, so the JSON shapes, status
 * codes and headers are a contract: change them only alongside Coilbox.
 */
final class Api
{
    public const CORS_HEADERS = [
        'Access-Control-Allow-Origin' => '*',
        'Access-Control-Allow-Methods' => 'GET, POST, OPTIONS',
        'Access-Control-Allow-Headers' => 'Authorization, Content-Type',
    ];

    /** Success responses may be held for a minute, as in the Next app. */
    public const SUCCESS_CACHE = 'public, max-age=60, s-maxage=60';

    public static function json(mixed $body, int $status = 200, ?string $cacheControl = self::SUCCESS_CACHE): JsonResponse
    {
        $headers = self::CORS_HEADERS;
        if ($cacheControl !== null) {
            $headers['Cache-Control'] = $cacheControl;
        }

        return new JsonResponse($body, $status, $headers, self::JSON_FLAGS);
    }

    public static function error(string $message, int $status): JsonResponse
    {
        return new JsonResponse(['error' => $message], $status, self::CORS_HEADERS + ['Cache-Control' => 'no-store'], self::JSON_FLAGS);
    }

    public static function preflight(): Response
    {
        return new Response('', 204, self::CORS_HEADERS);
    }

    /** `JSON.stringify` leaves slashes and non-ASCII alone, so this does too. */
    public const JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION;
}
