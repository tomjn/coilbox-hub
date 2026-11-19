<?php

namespace App\Supabase\Local;

/**
 * Postgres full text search with the `english` configuration, near enough
 * for the maps listing's search box: `to_tsvector('english', ...)` on the
 * document and `websearch_to_tsquery('english', ...)` on what a reader typed.
 *
 * Words are lower cased, stop words dropped and the rest stemmed with the
 * Snowball English stemmer, as Postgres does. Words with digits in them and
 * numbers are kept whole. A hyphenated word is indexed whole and in parts.
 * Postgres's parser knows more token kinds (hosts, paths, emails), which map
 * names and descriptions rarely hold, so those are split into plain words.
 */
final class TextSearch
{
    /** Postgres's `english.stop`. */
    private const STOP_WORDS = [
        'i', 'me', 'my', 'myself', 'we', 'our', 'ours', 'ourselves', 'you', 'your', 'yours', 'yourself', 'yourselves',
        'he', 'him', 'his', 'himself', 'she', 'her', 'hers', 'herself', 'it', 'its', 'itself', 'they', 'them', 'their',
        'theirs', 'themselves', 'what', 'which', 'who', 'whom', 'this', 'that', 'these', 'those', 'am', 'is', 'are',
        'was', 'were', 'be', 'been', 'being', 'have', 'has', 'had', 'having', 'do', 'does', 'did', 'doing', 'a', 'an',
        'the', 'and', 'but', 'if', 'or', 'because', 'as', 'until', 'while', 'of', 'at', 'by', 'for', 'with', 'about',
        'against', 'between', 'into', 'through', 'during', 'before', 'after', 'above', 'below', 'to', 'from', 'up',
        'down', 'in', 'out', 'on', 'off', 'over', 'under', 'again', 'further', 'then', 'once', 'here', 'there', 'when',
        'where', 'why', 'how', 'all', 'any', 'both', 'each', 'few', 'more', 'most', 'other', 'some', 'such', 'no', 'nor',
        'not', 'only', 'own', 'same', 'so', 'than', 'too', 'very', 's', 't', 'can', 'will', 'just', 'don', 'should', 'now',
    ];

    /**
     * A document as Postgres would index it: lexeme => positions.
     *
     * @param  list<string|null>  $parts  joined as `||` joins tsvectors, positions running on
     * @return array<string, list<int>>
     */
    public static function vector(array $parts): array
    {
        $vector = [];
        $offset = 0;
        foreach ($parts as $part) {
            $last = 0;
            foreach (self::lexemes((string) $part) as [$lexeme, $position]) {
                $vector[$lexeme][] = $offset + $position;
                $last = max($last, $position);
            }
            $offset += $last;
        }

        return $vector;
    }

    /**
     * Whether a document matches what a reader typed, as `@@
     * websearch_to_tsquery`. A query with nothing searchable in it, only stop
     * words say, matches nothing.
     *
     * @param  array<string, list<int>>  $vector
     */
    public static function matches(array $vector, string $query): bool
    {
        $tree = self::parse($query);

        return $tree !== null && self::evaluate($tree, $vector);
    }

    /**
     * The lexemes of one text with their positions, from 1. Stop words take a
     * position but are not kept.
     *
     * @return list<array{0: string, 1: int}>
     */
    public static function lexemes(string $text): array
    {
        $w = '[\p{L}\p{N}]';
        preg_match_all(
            '/(?<email>[\p{L}\p{N}._+-]*'.$w.'@'.$w.'+(?:[.-]'.$w.'+)*\.'.$w.'+)'
            .'|(?<path>[\p{L}\p{N}_.-]*'.$w.'\/[\p{L}\p{N}_.\/-]*'.$w.')'
            .'|(?<dotted>'.$w.'+(?:\.'.$w.'+)+)'
            .'|(?<signed>[+-]\d+(?!'.$w.'))'
            .'|(?<word>'.$w.'+(?:-'.$w.'+)*)/u',
            $text, $matches, PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL,
        );
        // Each token the parser finds, and whether it is a plain word the
        // stemmer takes. Addresses, paths, versions and numbers are kept whole.
        $tokens = [];
        foreach ($matches as $match) {
            $word = $match['word'] ?? null;
            if ($word === null) {
                $tokens[] = [mb_strtolower($match[0]), false];

                continue;
            }
            // A hyphen before a number signs it, `2-4` is 2 and -4, so the
            // hyphenated words are the runs between numbers.
            $run = [];
            foreach (explode('-', $word) as $i => $part) {
                if (ctype_digit($part)) {
                    array_push($tokens, ...self::hyphenated($run));
                    $run = [];
                    $tokens[] = [($i > 0 ? '-' : '').$part, false];
                } else {
                    $run[] = $part;
                }
            }
            array_push($tokens, ...self::hyphenated($run));
        }

        $out = [];
        foreach ($tokens as $position => [$token, $stems]) {
            if (! $stems) {
                $out[] = [$token, $position + 1];
            } elseif (! in_array($token, self::STOP_WORDS, true)) {
                $out[] = [EnglishStemmer::stem($token), $position + 1];
            }
        }

        return $out;
    }

    /**
     * Tokens for one hyphenated word, or one plain word: the whole word, then
     * each part. Words with digits in are kept whole, the rest are stemmed.
     *
     * @param  list<string>  $parts
     * @return list<array{0: string, 1: bool}>
     */
    private static function hyphenated(array $parts): array
    {
        $tokens = [];
        if (count($parts) > 1) {
            $whole = implode('-', $parts);
            $tokens[] = [mb_strtolower($whole), preg_match('/\d/', $whole) !== 1];
        }
        foreach ($parts as $part) {
            $tokens[] = [mb_strtolower($part), preg_match('/\d/', $part) !== 1];
        }

        return $tokens;
    }

    /**
     * `websearch_to_tsquery`: words are ANDed, `or` between two words ORs
     * them, a leading `-` negates, and quotes make a phrase. Anything else is
     * a separator. Null when nothing searchable is left.
     *
     * @return array<string, mixed>|null
     */
    private static function parse(string $query): ?array
    {
        preg_match_all('/"([^"]*)"?|(-?)([^\s"]+)/u', $query, $matches, PREG_SET_ORDER);
        $groups = [];
        $pendingOr = false;
        foreach ($matches as $match) {
            if (isset($match[3]) && $match[3] !== '' && strtolower($match[3]) === 'or' && ($match[2] ?? '') === '') {
                $pendingOr = $groups !== [];

                continue;
            }
            if ($match[1] !== '' || ! isset($match[3])) {
                $node = self::phrase($match[1]);
            } else {
                $node = self::phrase($match[3]);
                if ($node !== null && $match[2] === '-') {
                    $node = ['not' => $node];
                }
            }
            if ($node === null) {
                continue;
            }
            if ($pendingOr) {
                $last = array_pop($groups);
                $groups[] = ['or' => [...($last['or'] ?? [$last]), $node]];
            } else {
                $groups[] = $node;
            }
            $pendingOr = false;
        }

        return $groups === [] ? null : ['and' => $groups];
    }

    /** @return array<string, mixed>|null */
    private static function phrase(string $text): ?array
    {
        $lexemes = self::lexemes($text);
        if ($lexemes === []) {
            return null;
        }
        $first = $lexemes[0][1];

        return ['phrase' => array_map(fn (array $item) => [$item[0], $item[1] - $first], $lexemes)];
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  array<string, list<int>>  $vector
     */
    private static function evaluate(array $node, array $vector): bool
    {
        if (isset($node['and'])) {
            foreach ($node['and'] as $item) {
                if (! self::evaluate($item, $vector)) {
                    return false;
                }
            }

            return true;
        }
        if (isset($node['or'])) {
            foreach ($node['or'] as $item) {
                if (self::evaluate($item, $vector)) {
                    return true;
                }
            }

            return false;
        }
        if (isset($node['not'])) {
            return ! self::evaluate($node['not'], $vector);
        }
        [$head, $start] = $node['phrase'][0];
        foreach ($vector[$head] ?? [] as $position) {
            $found = true;
            foreach ($node['phrase'] as [$lexeme, $gap]) {
                if (! in_array($position - $start + $gap, $vector[$lexeme] ?? [], true)) {
                    $found = false;
                    break;
                }
            }
            if ($found) {
                return true;
            }
        }

        return false;
    }
}
