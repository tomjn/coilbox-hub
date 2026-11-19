<?php

namespace App\Supabase\Local;

use Illuminate\Database\Query\Builder;

/**
 * PostgREST filters: `column=op.value`, `column=not.op.value` and the
 * `or=(...)`/`and=(...)` trees, turned into SQL on the local tables.
 */
final class Filter
{
    private const OPERATORS = ['eq' => '=', 'neq' => '<>', 'gt' => '>', 'gte' => '>=', 'lt' => '<', 'lte' => '<=', 'like' => 'like', 'ilike' => 'ilike', 'is' => 'is', 'in' => 'in'];

    /** Array and text search operators. Only the map views, worked out in PHP, take them. */
    public const VIEW_OPERATORS = ['cs', 'ov', 'fts', 'plfts', 'wfts'];

    /** @return array<string, mixed> */
    public static function parse(string $column, string $value): array
    {
        $negated = false;
        if (str_starts_with($value, 'not.')) {
            $negated = true;
            $value = substr($value, 4);
        }
        if (in_array($column, ['or', 'and'], true)) {
            return self::logic($column, $value, $negated);
        }
        $dot = strpos($value, '.');
        $operator = $dot === false ? $value : substr($value, 0, $dot);
        if (! isset(self::OPERATORS[$operator]) && ! in_array($operator, self::VIEW_OPERATORS, true)) {
            throw new LocalException('The local store does not support the "'.$operator.'" operator', 400, 'PGRST100');
        }

        return ['column' => $column, 'operator' => $operator, 'negated' => $negated, 'value' => $dot === false ? '' : substr($value, $dot + 1)];
    }

    /** @return array<string, mixed> */
    public static function logic(string $kind, string $value, bool $negated): array
    {
        if (! str_starts_with($value, '(') || ! str_ends_with($value, ')')) {
            throw new LocalException('"failed to parse logic tree ('.$value.')"', 400, 'PGRST100');
        }
        $items = [];
        foreach (Select::split(substr($value, 1, -1)) as $part) {
            $not = false;
            if (str_starts_with($part, 'not.')) {
                $not = true;
                $part = substr($part, 4);
            }
            if (preg_match('/^(and|or)(\(.*\))$/s', $part, $m)) {
                $items[] = self::logic($m[1], $m[2], $not);

                continue;
            }
            $dot = strpos($part, '.');
            if ($dot === false) {
                throw new LocalException('"failed to parse logic tree ('.$value.')"', 400, 'PGRST100');
            }
            $filter = self::parse(substr($part, 0, $dot), substr($part, $dot + 1));
            $filter['negated'] = $filter['negated'] !== $not;
            $filter['value'] = self::unquote($filter['value']);
            $items[] = $filter;
        }

        return ['logic' => $kind, 'negated' => $negated, 'items' => $items];
    }

    /** @param array<string, mixed> $filter */
    public static function apply(Builder $query, string $table, string $alias, array $filter, string $boolean = 'and'): void
    {
        $query->where(function (Builder $where) use ($table, $alias, $filter) {
            if (isset($filter['logic'])) {
                foreach ($filter['items'] as $item) {
                    self::apply($where, $table, $alias, $item, $filter['logic']);
                }

                return;
            }
            self::condition($where, $table, $alias, $filter);
        }, null, null, $boolean.($filter['negated'] ? ' not' : ''));
    }

    /** @param array<string, mixed> $filter */
    private static function condition(Builder $where, string $table, string $alias, array $filter): void
    {
        $columns = Tables::columns($table);
        $column = (string) $filter['column'];
        if (! isset($columns[$column])) {
            throw new LocalException('column '.$table.'.'.$column.' does not exist', 400, '42703');
        }
        $type = $columns[$column];
        $qualified = $alias.'.'.$column;
        $value = (string) $filter['value'];

        switch ($filter['operator']) {
            case 'is':
                match (strtolower($value)) {
                    'null' => $where->whereNull($qualified),
                    'true' => $where->where($qualified, true),
                    'false' => $where->where($qualified, false),
                    default => throw new LocalException('"failed to parse filter (is.'.$value.')"', 400, 'PGRST100'),
                };
                break;
            case 'in':
                if (! str_starts_with($value, '(') || ! str_ends_with($value, ')')) {
                    throw new LocalException('"failed to parse filter (in.'.$value.')"', 400, 'PGRST100');
                }
                $list = array_map(fn ($item) => self::typed($type, self::unquote($item)), Select::split(substr($value, 1, -1)));
                $where->whereIn($qualified, $list);
                break;
            case 'like':
                $where->where($qualified, 'like', str_replace('*', '%', $value));
                break;
            case 'ilike':
                $wrapped = $where->getGrammar()->wrap($qualified);
                $where->whereRaw('lower('.$wrapped.') like lower(?)', [str_replace('*', '%', $value)]);
                break;
            default:
                if (! isset(self::OPERATORS[$filter['operator']])) {
                    throw new LocalException('The local store does not support the "'.$filter['operator'].'" operator on '.$table, 400, 'PGRST100');
                }
                $where->where($qualified, self::OPERATORS[$filter['operator']], self::typed($type, $value));
        }
    }

    private static function typed(string $type, string $value): mixed
    {
        return match ($type) {
            'int' => (int) $value,
            'bool' => in_array(strtolower($value), ['true', 't', '1'], true),
            'ts' => Values::stored($value),
            default => $value,
        };
    }

    public static function unquote(string $value): string
    {
        if (strlen($value) >= 2 && $value[0] === '"' && str_ends_with($value, '"')) {
            return stripcslashes(substr($value, 1, -1));
        }

        return $value;
    }
}
