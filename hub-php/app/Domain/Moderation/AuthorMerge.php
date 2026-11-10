<?php

namespace App\Domain\Moderation;

use App\Supabase\Client;
use App\Supabase\SupabaseException;

/**
 * Saying that several author keys are one person, `lib/maps/authorMerge.ts`
 * (issues #193 and #442).
 *
 * `public.author_key` folds case and clan tags. What no rule can know is that
 * `bherith` is a typo or that `original from Beherith` is Beherith, and
 * `public.author_alias` records that judgement. An alias resolves once and
 * stops, and `merge_author_keys` keeps it that way.
 */
final class AuthorMerge
{
    /** How many keys the list shows, busiest first. */
    public const PAGE_SIZE = 200;

    /** A ceiling on recorded merges, which grow at the speed of a person. */
    public const ALIAS_PAGE_SIZE = 500;

    public const OUTCOMES = ['merged', 'chained', 'refused'];

    /**
     * The busiest keys first, the key breaking ties so the order is stable.
     *
     * @return list<array{key: string, name: string, maps: int}>
     */
    public static function counts(Client $session, ?string $search = null, int $limit = self::PAGE_SIZE): array
    {
        $query = $session->from('author_map_count')->select('key, name, maps');
        $pattern = self::searchPattern($search);
        if ($pattern !== null) {
            $query->ilike('key', $pattern);
        }
        try {
            return $query->order('maps', ascending: false)->order('key')->limit($limit)->get();
        } catch (SupabaseException) {
            return [];
        }
    }

    /** An `ilike` pattern for a search anywhere in a key, or null for none. */
    public static function searchPattern(?string $search): ?string
    {
        $trimmed = trim($search ?? '');

        return $trimmed === '' ? null : MapModeration::containsPattern($trimmed);
    }

    /**
     * The ticked keys out of the URL, once each, in order, without blanks.
     *
     * @return list<string>
     */
    public static function selectedKeys(mixed $value): array
    {
        $values = is_array($value) ? $value : ($value === null ? [] : [$value]);
        $values = array_filter($values, fn ($key) => is_string($key) && trim($key) !== '');

        return array_values(array_unique($values));
    }

    /**
     * The authors behind the ticked keys, in ticked order. One read per key,
     * because a key is free text and a quote in one would break an `in` filter.
     * A key merged away since the list was drawn is left out.
     *
     * @param  list<string>  $keys
     * @return list<array{key: string, name: string, maps: int}>
     */
    public static function authors(Client $session, array $keys): array
    {
        $found = [];
        foreach ($keys as $key) {
            try {
                $row = $session->from('author_map_count')->select('key, name, maps')->eq('key', $key)->maybeSingle();
            } catch (SupabaseException) {
                $row = null;
            }
            if ($row !== null) {
                $found[] = $row;
            }
        }

        return $found;
    }

    /**
     * @param  list<string>  $keys
     * @return list<array{key: string, raw: string, credits: int}>
     */
    public static function spellings(Client $session, array $keys): array
    {
        $found = [];
        foreach ($keys as $key) {
            try {
                array_push($found, ...$session->from('author_spelling')->select('key, raw, credits')->eq('key', $key)->get());
            } catch (SupabaseException) {
            }
        }

        return $found;
    }

    /**
     * Each spelling once, the most credited first. The same spelling can sit
     * under two keys, so credits are added before ordering.
     *
     * @param  list<array{raw: string, credits: int}>  $spellings
     * @return list<string>
     */
    public static function nameChoices(array $spellings): array
    {
        $credits = [];
        foreach ($spellings as $spelling) {
            $credits[$spelling['raw']] = ($credits[$spelling['raw']] ?? 0) + $spelling['credits'];
        }
        $pairs = [];
        foreach ($credits as $raw => $count) {
            $pairs[] = [(string) $raw, $count];
        }
        usort($pairs, fn ($a, $b) => $b[1] <=> $a[1] ?: strcmp($a[0], $b[0]));

        return array_column($pairs, 0);
    }

    /**
     * Mark every alias whose target is itself merged, which breaks the one hop rule.
     *
     * @param  list<array{from_key: string, to_key: string, note: string|null, set_at: string}>  $rows
     * @return list<array{fromKey: string, toKey: string, note: string|null, setAt: string, chained: bool}>
     */
    public static function markChains(array $rows): array
    {
        $merged = array_flip(array_column($rows, 'from_key'));

        return array_map(fn (array $row) => [
            'fromKey' => $row['from_key'],
            'toKey' => $row['to_key'],
            'note' => $row['note'],
            'setAt' => $row['set_at'],
            'chained' => isset($merged[$row['to_key']]),
        ], $rows);
    }

    /**
     * Recorded merges grouped under the person they point at, targets in order.
     *
     * @param  list<array<string, mixed>>  $aliases
     * @return array<string, list<array<string, mixed>>>
     */
    public static function byTarget(array $aliases): array
    {
        usort($aliases, fn ($a, $b) => strcmp($a['toKey'], $b['toKey']));
        $grouped = [];
        foreach ($aliases as $alias) {
            $grouped[$alias['toKey']][] = $alias;
        }

        return $grouped;
    }

    /** @return list<array<string, mixed>> */
    public static function aliases(Client $session, int $limit = self::ALIAS_PAGE_SIZE): array
    {
        try {
            $rows = $session->from('author_alias')->select('from_key, to_key, note, set_at')->order('from_key')->limit($limit)->get();
        } catch (SupabaseException) {
            $rows = [];
        }

        return self::markChains($rows);
    }

    /**
     * Merge every key into `$into`, naming the person if a name is given. One
     * call, so the merge is one transaction. Anything but a known answer is a refusal.
     *
     * @param  list<string>  $keys
     */
    public static function merge(Client $admin, string $into, array $keys, string $name, string $note): string
    {
        try {
            $answer = $admin->rpc('merge_author_keys', [
                'p_into' => $into,
                'p_keys' => $keys,
                'p_name' => trim($name) === '' ? null : trim($name),
                'p_note' => trim($note) === '' ? null : trim($note),
            ]);
        } catch (SupabaseException) {
            return 'refused';
        }

        return in_array($answer, self::OUTCOMES, true) ? $answer : 'refused';
    }

    /** Withdraw a merge. The evidence, `map_author.raw`, was never touched. */
    public static function unmerge(Client $admin, string $fromKey): bool
    {
        try {
            $admin->from('author_alias')->delete()->eq('from_key', $fromKey)->execute();
        } catch (SupabaseException) {
            return false;
        }

        return true;
    }
}
