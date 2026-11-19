<?php

namespace App\Supabase\Local;

/**
 * A PostgREST query string taken apart: the select list, the filters, the
 * order, limit and offset for the table and for each embed, and what the
 * `Prefer` header asked for. Embeds are addressed by path, `[]` for the table
 * itself and `['game_unit', 'game']` for an embed inside an embed.
 */
final class Request
{
    public string $select = '*';

    public ?string $count = null;

    public bool $returns = false;

    public bool $upsert = false;

    /** @var array<string, list<array<string, mixed>>> */
    private array $filters = [];

    /** @var array<string, list<array{0: string, 1: bool, 2: ?bool}>> */
    private array $order = [];

    /** @var array<string, int> */
    private array $limit = [];

    /** @var array<string, int> */
    private array $offset = [];

    /** @var list<array{0: string, 1: list<string>}> filters, orders and ranges on embeds, in query order */
    private array $touches = [];

    /** @param array<string, string> $headers */
    public static function parse(string $query, array $headers): self
    {
        $request = new self;
        foreach ($query === '' ? [] : explode('&', $query) as $pair) {
            [$name, $value] = array_pad(explode('=', $pair, 2), 2, '');
            $request->add(rawurldecode($name), rawurldecode($value));
        }
        foreach (explode(',', $headers['Prefer'] ?? '') as $preference) {
            $preference = trim($preference);
            if (str_starts_with($preference, 'count=')) {
                $request->count = substr($preference, 6);
            } elseif ($preference === 'return=representation') {
                $request->returns = true;
            } elseif (str_starts_with($preference, 'resolution=')) {
                $request->upsert = true;
            }
        }

        return $request;
    }

    /**
     * @param  list<string>  $path
     * @return list<array<string, mixed>>
     */
    public function filters(array $path): array
    {
        return $this->filters[self::key($path)] ?? [];
    }

    /**
     * @param  list<string>  $path
     * @return list<array{0: string, 1: bool, 2: ?bool}>
     */
    public function order(array $path): array
    {
        return $this->order[self::key($path)] ?? [];
    }

    /** @param list<string> $path */
    public function limit(array $path): ?int
    {
        return $this->limit[self::key($path)] ?? null;
    }

    /** @param list<string> $path */
    public function offset(array $path): int
    {
        return $this->offset[self::key($path)] ?? 0;
    }

    private function add(string $name, string $value): void
    {
        if ($name === 'select') {
            $this->select = $value;

            return;
        }
        if ($name === 'columns' || $name === 'on_conflict') {
            return;
        }
        $parts = explode('.', $name);
        $last = array_pop($parts);
        $key = self::key($parts);
        if ($parts !== []) {
            $kind = match ($last) {
                'order' => 'order',
                'limit', 'offset' => 'range',
                default => 'filter',
            };
            $this->touches[] = [$kind, $parts];
        }
        match ($last) {
            'order' => $this->order[$key] = self::parseOrder($value),
            'limit' => $this->limit[$key] = (int) $value,
            'offset' => $this->offset[$key] = (int) $value,
            'or', 'and' => $this->filters[$key][] = Filter::logic($last, $value, false),
            default => $this->filters[$key][] = Filter::parse($last, $value),
        };
    }

    /**
     * The order PostgREST answers a level's embeds in. It starts from the
     * select list, and every embed a filter, order or range names is moved to
     * the front as PostgREST builds its plan: filters, then orders, each in
     * reverse query order, then ranges by path.
     *
     * @param  list<string>  $keys  the embeds at this level, in select order
     * @param  list<string>  $path
     * @return list<string>
     */
    public function embedOrder(array $keys, array $path): array
    {
        $depth = count($path);
        $named = function (array $touch) use ($path, $depth) {
            return count($touch[1]) > $depth && array_slice($touch[1], 0, $depth) === $path ? $touch[1][$depth] : null;
        };
        $moves = [];
        foreach (['filter', 'order'] as $kind) {
            foreach (array_reverse($this->touches) as $touch) {
                if ($touch[0] === $kind && ($key = $named($touch)) !== null) {
                    $moves[] = $key;
                }
            }
        }
        $ranges = array_values(array_filter($this->touches, fn ($touch) => $touch[0] === 'range'));
        usort($ranges, fn ($a, $b) => strcmp(implode('.', $a[1]), implode('.', $b[1])));
        foreach ($ranges as $touch) {
            if (($key = $named($touch)) !== null) {
                $moves[] = $key;
            }
        }
        foreach ($moves as $key) {
            if (in_array($key, $keys, true)) {
                $keys = [$key, ...array_values(array_diff($keys, [$key]))];
            }
        }

        return $keys;
    }

    /** @return list<array{0: string, 1: bool, 2: ?bool}> */
    private static function parseOrder(string $value): array
    {
        $order = [];
        foreach (explode(',', $value) as $term) {
            $bits = explode('.', $term);
            $column = array_shift($bits);
            $ascending = ! in_array('desc', $bits, true);
            $nulls = in_array('nullsfirst', $bits, true) ? true : (in_array('nullslast', $bits, true) ? false : null);
            $order[] = [$column, $ascending, $nulls];
        }

        return $order;
    }

    /** @param list<string> $path */
    private static function key(array $path): string
    {
        return implode('.', $path);
    }
}
