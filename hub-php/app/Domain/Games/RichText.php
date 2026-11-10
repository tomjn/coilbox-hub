<?php

namespace App\Domain\Games;

/**
 * The hub's small rich text, `lib/text/richText.ts`: paragraphs split on blank
 * lines, line breaks kept, `**bold**`, `*italic*` and `_italic_`. Nothing else
 * is markup, so stored text can never become HTML.
 *
 * A node is `['type' => 'text', 'value' => ...]` or
 * `['type' => 'bold'|'italic', 'children' => [...]]`.
 */
final class RichText
{
    /** @return list<list<list<array>>> paragraphs of lines of nodes */
    public static function parse(string $raw): array
    {
        $normalised = Js::trim((string) preg_replace('/\r\n?/', "\n", $raw));
        if ($normalised === '') {
            return [];
        }
        $paragraphs = [];
        foreach (preg_split('/\n{2,}/', $normalised) as $block) {
            $block = Js::trim($block);
            if ($block === '') {
                continue;
            }
            $paragraphs[] = array_map(fn (string $line) => self::parseInline(Js::trim($line)), explode("\n", $block));
        }

        return $paragraphs;
    }

    /** The whole text on one line, paragraphs and lines joined by a space. @return list<array> */
    public static function inline(string $raw): array
    {
        $nodes = [];
        foreach (self::parse($raw) as $paragraph) {
            foreach ($paragraph as $line) {
                if ($nodes !== []) {
                    $nodes[] = ['type' => 'text', 'value' => ' '];
                }
                array_push($nodes, ...$line);
            }
        }

        return $nodes;
    }

    public static function toPlainText(string $raw): string
    {
        $paragraphs = array_map(
            fn (array $paragraph) => implode(' ', array_map(self::plainTextOf(...), $paragraph)),
            self::parse($raw),
        );

        return Js::trim(implode(' ', $paragraphs));
    }

    /** Escaped HTML for a list of nodes, as `<Inline>` renders them. */
    public static function html(array $nodes): string
    {
        $out = '';
        foreach ($nodes as $node) {
            $out .= match ($node['type']) {
                'text' => e($node['value']),
                'bold' => '<strong>'.self::html($node['children']).'</strong>',
                default => '<em>'.self::html($node['children']).'</em>',
            };
        }

        return $out;
    }

    /** Escaped HTML for the paragraphs, one `<p>` each with lines split by `<br>`. */
    public static function paragraphsHtml(string $raw, string $class = ''): string
    {
        $out = '';
        foreach (self::parse($raw) as $paragraph) {
            $lines = array_map(self::html(...), $paragraph);
            $out .= '<p'.($class !== '' ? ' class="'.e($class).'"' : '').'>'.implode('<br/>', $lines).'</p>';
        }

        return $out;
    }

    /** @return list<array> */
    public static function parseInline(string $text): array
    {
        $chars = mb_str_split($text);
        $nodes = [];
        $literal = '';
        $flush = function () use (&$nodes, &$literal) {
            if ($literal !== '') {
                $nodes[] = ['type' => 'text', 'value' => $literal];
                $literal = '';
            }
        };
        $count = count($chars);
        $i = 0;
        while ($i < $count) {
            $ch = $chars[$i];
            if ($ch === '*' && ($chars[$i + 1] ?? null) === '*') {
                $close = self::findClose($chars, $i + 2, '**');
                if ($close !== -1) {
                    $flush();
                    $nodes[] = ['type' => 'bold', 'children' => self::parseInline(implode('', array_slice($chars, $i + 2, $close - $i - 2)))];
                    $i = $close + 2;

                    continue;
                }
            } elseif ($ch === '*') {
                $close = self::findClose($chars, $i + 1, '*');
                if ($close !== -1) {
                    $flush();
                    $nodes[] = ['type' => 'italic', 'children' => self::parseInline(implode('', array_slice($chars, $i + 1, $close - $i - 1)))];
                    $i = $close + 1;

                    continue;
                }
            } elseif ($ch === '_') {
                $before = $chars[$i - 1] ?? null;
                if ($before === null || ! self::isWordChar($before)) {
                    $close = self::findClose($chars, $i + 1, '_');
                    if ($close !== -1) {
                        $flush();
                        $nodes[] = ['type' => 'italic', 'children' => self::parseInline(implode('', array_slice($chars, $i + 1, $close - $i - 1)))];
                        $i = $close + 1;

                        continue;
                    }
                }
            }
            $literal .= $ch;
            $i++;
        }
        $flush();

        return $nodes;
    }

    private static function plainTextOf(array $nodes): string
    {
        return implode('', array_map(fn (array $node) => $node['type'] === 'text' ? $node['value'] : self::plainTextOf($node['children']), $nodes));
    }

    private static function isWordChar(string $ch): bool
    {
        return (bool) preg_match('/^[A-Za-z0-9]$/', $ch);
    }

    /** @param list<string> $chars */
    private static function findClose(array $chars, int $start, string $marker): int
    {
        $count = count($chars);
        $i = $start;
        while ($i < $count) {
            if ($chars[$i] === '*' && ($chars[$i + 1] ?? null) === '*') {
                if ($marker === '**') {
                    return $i;
                }
                $nested = self::findClose($chars, $i + 2, '**');
                $i = $nested === -1 ? $i + 2 : $nested + 2;

                continue;
            }
            if ($marker === '*' && $chars[$i] === '*') {
                return $i;
            }
            if ($marker === '_' && $chars[$i] === '_') {
                $after = $chars[$i + 1] ?? null;
                if ($after === null || ! self::isWordChar($after)) {
                    return $i;
                }
            }
            $i++;
        }

        return -1;
    }
}
