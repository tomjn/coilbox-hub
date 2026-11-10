<?php

namespace App\Domain\Maps;

use App\Supabase\Client;
use App\Supabase\SupabaseException;

/**
 * The database half of a map submission, `lib/maps/submit.ts`. The decision and
 * the write are `public.submit_map_facts`, one transaction. This side works out
 * the slug, the alternative slug and the digest, which the database cannot.
 */
final class Submit
{
    /**
     * @param  array<string, mixed>  $entry
     * @return array{entry: array<string, mixed>, slug: string, slug_alternative: string, facts_digest: string}
     */
    public static function build(array $entry): array
    {
        return [
            'entry' => $entry,
            'slug' => Slug::for($entry['map_name']),
            'slug_alternative' => Slug::alternative($entry['map_name']),
            'facts_digest' => Facts::digest($entry),
        ];
    }

    /**
     * The whole batch, decided and written in one call. Sorted by name first, so
     * two overlapping batches lock rows in one order and queue rather than
     * deadlock. `53400` is the rate limit trigger's code.
     *
     * @param  list<array{entry: array<string, mixed>, slug: string, slug_alternative: string, facts_digest: string}>  $submissions
     * @return array{ok: true, outcomes: array<string, array{outcome: string, said: string|null}>}|array{ok: false, rateLimited: bool}
     */
    public static function write(Client $supabase, array $submissions, string $submittedBy): array
    {
        $ordered = $submissions;
        usort($ordered, fn (array $left, array $right) => Js::compare($left['entry']['map_name'], $right['entry']['map_name']) < 0 ? -1 : 1);

        try {
            $data = $supabase->rpc('submit_map_facts', ['p_maps' => $ordered, 'p_submitted_by' => $submittedBy]);
        } catch (SupabaseException $e) {
            return ['ok' => false, 'rateLimited' => $e->errorCode === '53400'];
        }
        if (! is_array($data)) {
            return ['ok' => false, 'rateLimited' => false];
        }

        $outcomes = [];
        foreach ($data as $row) {
            $outcomes[$row['map_name']] = ['outcome' => $row['outcome'], 'said' => $row['said'] ?? null];
        }

        return ['ok' => true, 'outcomes' => $outcomes];
    }
}
