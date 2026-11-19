<?php

namespace App\Supabase\Local;

/**
 * A PostgREST select list: `*`, columns (`alias:column`) and embeds
 * (`alias:table!inner(...)`), nested.
 */
final class Select
{
    /** @param list<SelectItem> $items */
    public function __construct(public readonly array $items) {}

    public static function parse(string $list): self
    {
        $items = [];
        foreach (self::split($list === '' ? '*' : $list) as $part) {
            $items[] = self::item($part);
        }

        return new self($items);
    }

    /** @return list<SelectItem> */
    public function embeds(): array
    {
        return array_values(array_filter($this->items, fn (SelectItem $item) => $item->kind === 'embed'));
    }

    private static function item(string $part): SelectItem
    {
        if ($part === '*') {
            return new SelectItem('star', '*');
        }
        $alias = null;
        $open = strpos($part, '(');
        $colon = strpos($part, ':');
        if ($colon !== false && ($open === false || $colon < $open) && ($part[$colon + 1] ?? '') !== ':') {
            $alias = substr($part, 0, $colon);
            $part = substr($part, $colon + 1);
            $open = strpos($part, '(');
        }
        if ($open === false) {
            if (str_contains($part, '::')) {
                throw new LocalException('Casts are not supported by the local store: '.$part, 400, 'PGRST100');
            }
            if (str_contains($part, '->')) {
                // `stats->weapons->0`: `->` keeps JSON, a final `->>` gives text.
                $text = str_contains($part, '->>');
                $steps = preg_split('/->>?/', $part);
                $column = array_shift($steps);

                return new SelectItem('column', $column, $alias ?? end($steps), path: $steps, text: $text);
            }

            return new SelectItem('column', $part, $alias);
        }
        if (! str_ends_with($part, ')')) {
            throw new LocalException('"failed to parse select parameter ('.$part.')"', 400, 'PGRST100');
        }
        $head = substr($part, 0, $open);
        $inner = false;
        if (str_contains($head, '!')) {
            [$head, $hint] = explode('!', $head, 2);
            if ($hint !== 'inner' && $hint !== 'left') {
                throw new LocalException('Embed hints other than !inner are not supported by the local store', 400, 'PGRST100');
            }
            $inner = $hint === 'inner';
        }
        $body = substr($part, $open + 1, -1);
        $items = [];
        foreach (self::split($body === '' ? '*' : $body) as $child) {
            $items[] = self::item($child);
        }

        return new SelectItem('embed', $head, $alias, $inner, new self($items));
    }

    /**
     * Splits on commas outside brackets and quotes.
     *
     * @return list<string>
     */
    public static function split(string $list): array
    {
        $parts = [];
        $depth = 0;
        $quoted = false;
        $current = '';
        $length = strlen($list);
        for ($i = 0; $i < $length; $i++) {
            $char = $list[$i];
            if ($char === '\\' && $quoted && $i + 1 < $length) {
                $current .= $char.$list[++$i];

                continue;
            }
            if ($char === '"') {
                $quoted = ! $quoted;
            } elseif (! $quoted && $char === '(') {
                $depth++;
            } elseif (! $quoted && $char === ')') {
                $depth--;
            } elseif (! $quoted && $depth === 0 && $char === ',') {
                $parts[] = $current;
                $current = '';

                continue;
            }
            $current .= $char;
        }
        if ($current !== '') {
            $parts[] = $current;
        }

        return $parts;
    }
}
