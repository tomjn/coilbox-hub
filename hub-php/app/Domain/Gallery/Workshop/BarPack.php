<?php

namespace App\Domain\Gallery\Workshop;

/**
 * Packing compiled chunks across a game's numbered tweak slots (issue #3126),
 * ported from `lib/workshop/barPack.ts`, which follows coilbox's `tweak_pack.rs`.
 * Every line carries the `!bset <slot> ` prefix a SPADS based autohost answers
 * to, because the length that matters is the whole chat line.
 *
 * Every chunk goes to a `tweakdefs` slot in unpadded URL safe base64. A table
 * chunk is wrapped as a block first. As many chunks as fit are joined into one
 * slot before the next is started, in the order the compiler wrote them.
 */
final class BarPack
{
    /** The base64 payload cap per slot. Coilbox's own figure (its issue #1277). */
    public const PAYLOAD_CAP = 16000;

    /** The whole `!bset` line, past which teiserver silently drops characters. */
    private const LINE_CAP = 16385;

    /** The most slots any checked game declares: the bare option plus numbered 1 to 29. */
    private const MAX_SLOTS = 30;

    /**
     * @param  list<array>  $chunks
     * @return array{tweakdefs: list<string>, oversized: list<string>, unplaced: list<string>}
     */
    public static function pack(array $chunks): array
    {
        $pack = ['tweakdefs' => [], 'oversized' => [], 'unplaced' => []];
        $current = '';
        $seal = function () use (&$pack, &$current) {
            $pack['tweakdefs'][] = self::prefix(count($pack['tweakdefs'])).self::encode($current);
            $current = '';
        };

        foreach ($chunks as $chunk) {
            if ($current === '' && count($pack['tweakdefs']) >= self::MAX_SLOTS) {
                $pack['unplaced'][] = $chunk['title'];

                continue;
            }
            $minified = self::minify(self::slotLua($chunk));
            $candidate = $current === '' ? $minified : $current.' '.$minified;
            if (self::fits(self::prefix(count($pack['tweakdefs'])), self::encode($candidate))) {
                $current = $candidate;

                continue;
            }

            // Did not fit beside what this slot holds. Seal it, then try this chunk alone in the next.
            if ($current !== '') {
                $seal();
            }
            if (count($pack['tweakdefs']) >= self::MAX_SLOTS) {
                $pack['unplaced'][] = $chunk['title'];
            } elseif (self::fits(self::prefix(count($pack['tweakdefs'])), self::encode($minified))) {
                $current = $minified;
            } else {
                $pack['oversized'][] = $chunk['title'];
            }
        }
        if ($current !== '') {
            $seal();
        }

        return $pack;
    }

    /**
     * Strip comments and collapse white space to single spaces, without
     * touching a string literal. A removed run becomes one space and never
     * nothing, or `end` and `if` on their own lines would merge. All three of
     * Lua's string forms are tracked, so a `--` inside a single quoted string
     * is not read as a comment.
     */
    public static function minify(string $source): string
    {
        $chars = preg_split('//u', $source, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $count = count($chars);
        $out = '';
        $short = null;
        $long = null;
        $pendingSpace = false;

        for ($i = 0; $i < $count; $i++) {
            $c = $chars[$i];
            if ($short !== null) {
                $out .= $c;
                if ($c === '\\' && $i + 1 < $count) {
                    $out .= $chars[++$i];
                } elseif ($c === $short) {
                    $short = null;
                }

                continue;
            }
            if ($long !== null) {
                $end = self::longBracketClose($chars, $i, $long);
                if ($end === null) {
                    $out .= $c;
                } else {
                    $out .= implode('', array_slice($chars, $i, $end - $i));
                    $i = $end - 1;
                    $long = null;
                }

                continue;
            }
            if ($c === '"' || $c === "'") {
                if ($pendingSpace) {
                    $out .= ' ';
                }
                $pendingSpace = false;
                $short = $c;
                $out .= $c;

                continue;
            }
            if ($c === '-' && ($chars[$i + 1] ?? null) === '-') {
                // A long comment runs to its matching bracket, a plain one to the newline.
                $level = self::longBracketLevel($chars, $i + 2);
                if ($level !== null) {
                    $i = (self::longBracketClose($chars, $i + 2 + $level + 2, $level) ?? $count) - 1;
                } else {
                    while ($i < $count && $chars[$i] !== "\n") {
                        $i++;
                    }
                }
                $pendingSpace = $out !== '';

                continue;
            }
            $level = self::longBracketLevel($chars, $i);
            if ($level !== null) {
                if ($pendingSpace) {
                    $out .= ' ';
                }
                $pendingSpace = false;
                $body = $i + $level + 2;
                $out .= implode('', array_slice($chars, $i, $body - $i));
                $i = $body - 1;
                $long = $level;

                continue;
            }
            if (preg_match('/^['.Js::WS.']$/u', $c) === 1) {
                $pendingSpace = $out !== '';

                continue;
            }
            if ($pendingSpace) {
                $out .= ' ';
            }
            $pendingSpace = false;
            $out .= $c;
        }

        return $out;
    }

    /** The level of a long bracket opening at `$start`, or null for an ordinary `[`. */
    private static function longBracketLevel(array $chars, int $start): ?int
    {
        if (($chars[$start] ?? null) !== '[') {
            return null;
        }
        $level = 0;
        while (($chars[$start + 1 + $level] ?? null) === '=') {
            $level++;
        }

        return ($chars[$start + 1 + $level] ?? null) === '[' ? $level : null;
    }

    /** Where the long bracket opened at `$level` closes, as the index one past its final `]`, or null. */
    private static function longBracketClose(array $chars, int $from, int $level): ?int
    {
        $count = count($chars);
        for ($i = $from; $i < $count; $i++) {
            if ($chars[$i] !== ']') {
                continue;
            }
            $matched = true;
            for ($n = 0; $n < $level; $n++) {
                if (($chars[$i + 1 + $n] ?? null) !== '=') {
                    $matched = false;
                }
            }
            if ($matched && ($chars[$i + 1 + $level] ?? null) === ']') {
                return $i + $level + 2;
            }
        }

        return null;
    }

    /** The URL safe, unpadded base64 a `tweakdefs` slot carries. */
    private static function encode(string $text): string
    {
        return strtr(rtrim(base64_encode($text), '='), '+/', '-_');
    }

    private static function prefix(int $slot): string
    {
        return $slot === 0 ? '!bset tweakdefs ' : "!bset tweakdefs{$slot} ";
    }

    /** Measured on the whole line as well as the payload. */
    private static function fits(string $linePrefix, string $payload): bool
    {
        return strlen($payload) <= self::PAYLOAD_CAP && strlen($linePrefix) + strlen($payload) <= self::LINE_CAP;
    }

    private static function slotLua(array $chunk): string
    {
        return $chunk['form'] === 'table' ? Compile::tableAsBlock($chunk['lua']) : $chunk['lua'];
    }
}
