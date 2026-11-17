<?php

namespace App\Http\Controllers\Mirrors;

use App\Domain\Mirrors\CatalogueStore;
use App\Domain\Mirrors\HubKeys;
use App\Http\Controllers\Controller;
use App\Support\Api;
use App\Supabase\Supabase;
use App\Supabase\SupabaseException;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as BaseResponse;

/** The signed catalogue and its signature, served as the stored bytes. */
class CatalogueController extends Controller
{
    private function store(): CatalogueStore
    {
        return new CatalogueStore(Supabase::admin(), HubKeys::fromConfig());
    }

    /** A plain response, so the signed bytes are never re-encoded. */
    private function bytes(string $body, string $contentType): Response
    {
        return new Response($body, 200, Api::CORS_HEADERS + [
            'Content-Type' => $contentType,
            'Cache-Control' => 'no-store',
        ]);
    }

    public function show(): BaseResponse
    {
        try {
            $stored = $this->store()->refresh();
        } catch (SupabaseException) {
            $stored = null;
        }

        return $stored === null
            ? Api::error('The catalogue is not available yet.', 503)
            : $this->bytes($stored['body'], 'application/json');
    }

    public function sig(): BaseResponse
    {
        try {
            $stored = $this->store()->current();
        } catch (SupabaseException) {
            $stored = null;
        }

        return $stored === null
            ? Api::error('The catalogue is not available yet.', 503)
            : $this->bytes($stored['sig'], 'text/plain; charset=utf-8');
    }

    public function preflight(): Response
    {
        return Api::preflight();
    }
}
