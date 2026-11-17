<?php

namespace App\Domain\Mirrors;

use App\Supabase\Client;
use App\Supabase\SupabaseException;
use Illuminate\Support\Facades\Log;

/** Builds, signs, stores and reads the catalogue. Only the admin client may touch these tables. */
final class CatalogueStore
{
    public function __construct(
        private readonly Client $admin,
        private readonly ?HubKeys $keys,
    ) {}

    /**
     * The stored body and signature, read from one row so they always belong together.
     *
     * @return array{body: string, sig: string}|null
     */
    public function current(): ?array
    {
        $row = $this->admin->from('catalogue_state')->select('body,sig')->maybeSingle();
        if ($row === null || ! is_string($row['body'] ?? null) || ! is_string($row['sig'] ?? null)) {
            return null;
        }

        return ['body' => $row['body'], 'sig' => $row['sig']];
    }

    /** True when nothing is stored, a change has landed since the build, or the next key changed. */
    public function isStale(): bool
    {
        $row = $this->admin->from('catalogue_state')->select('body,changes,built_changes,next_key_public')->maybeSingle();
        if ($row === null || ! is_string($row['body'] ?? null)) {
            return true;
        }
        if ((int) $row['changes'] > (int) $row['built_changes']) {
            return true;
        }

        return ($row['next_key_public'] ?? null) !== ($this->keys?->nextKey()['public_key'] ?? null);
    }

    /** Builds and stores a new catalogue. Returns false without a key or when the database fails. */
    public function rebuild(): bool
    {
        if ($this->keys === null) {
            return false;
        }

        try {
            $source = $this->admin->rpc('catalogue_source');
            if (! is_array($source)) {
                return false;
            }
            $generatedAt = time();
            $nextKey = $this->keys->nextKey();
            $body = Catalogue::encode($source, $generatedAt, $nextKey);
            $sig = B64::encode(Signing::sign(Signing::CTX_CATALOGUE, $body, $this->keys->secretKey()));

            $this->admin->rpc('store_catalogue', [
                'p_seq' => (int) $source['seq'],
                'p_changes' => (int) $source['changes'],
                'p_generated_at' => $generatedAt,
                'p_body' => $body,
                'p_sig' => $sig,
                'p_next_key' => $nextKey['public_key'] ?? null,
            ]);
        } catch (SupabaseException $e) {
            Log::warning('Catalogue rebuild failed: '.$e->getMessage());

            return false;
        }

        return true;
    }

    /**
     * Rebuilds when stale, then returns what is stored.
     *
     * @return array{body: string, sig: string}|null
     */
    public function refresh(): ?array
    {
        try {
            if ($this->isStale()) {
                $this->rebuild();
            }

            return $this->current();
        } catch (SupabaseException $e) {
            Log::warning('Catalogue read failed: '.$e->getMessage());

            return null;
        }
    }
}
