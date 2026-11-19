<?php

namespace App\Domain\Maps;

/**
 * How a credit becomes an author key, `public.author_credits` and
 * `public.author_key` in PHP (#599). The same rules key a credit when a map
 * is submitted and when a reader filters by author, so the two always agree.
 */
final class Authors
{
    /**
     * Punctuation as production's `[[:punct:]]` sees it: Unicode punctuation
     * only, so `~`, `$` and emoji are kept. Checked against production's
     * `author_key` on 19 November 2026.
     */
    private const PUNCT = '\p{P}';

    private const SPACE = '[\s\p{Z}]';

    /**
     * `author_credits`: one credit string split into its authors on `&`, `+`,
     * `,` and the word `and`, each trimmed, blanks dropped.
     *
     * @return list<string>
     */
    public static function credits(?string $credit): array
    {
        $parts = preg_split('/[&+,]|(?<![\p{L}\p{N}_])and(?![\p{L}\p{N}_])/iu', $credit ?? '') ?: [];

        return array_values(array_filter(array_map(fn (string $part) => trim($part, ' '), $parts), fn (string $part) => $part !== ''));
    }

    /**
     * `author_key`: lower cased, a clan tag in brackets dropped from either
     * end, punctuation and spaces trimmed from both ends, and runs of spaces
     * made one.
     */
    public static function key(?string $credit): string
    {
        $key = mb_strtolower($credit ?? '');
        $key = (string) preg_replace('/^'.self::SPACE.'*[\[({][^\])}]*[\])}]/u', '', $key, 1);
        $key = (string) preg_replace('/[\[({][^\])}]*[\])}]'.self::SPACE.'*$/u', '', $key, 1);
        $key = (string) preg_replace('/^(?:'.self::PUNCT.'|'.self::SPACE.')+/u', '', $key, 1);
        $key = (string) preg_replace('/(?:'.self::PUNCT.'|'.self::SPACE.')+$/u', '', $key, 1);

        return (string) preg_replace('/'.self::SPACE.'+/u', ' ', $key);
    }

    /**
     * `resolved_author_key`: the key a maintainer merged this one into, or
     * itself.
     *
     * @param  array<string, string>  $aliases  from key => to key
     */
    public static function resolve(string $key, array $aliases): string
    {
        return $aliases[$key] ?? $key;
    }
}
