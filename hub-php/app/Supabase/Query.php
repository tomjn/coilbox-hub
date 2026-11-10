<?php

namespace App\Supabase;

/**
 * A PostgREST query, built the way supabase-js builds one so code ports call
 * for call: `from('item')->select('id,title')->eq('kind', 'preset')->get()`.
 *
 * Filters are sent as PostgREST query parameters. Values are quoted where
 * PostgREST needs it (inside `in.(...)`), and everything is URL encoded.
 */
class Query
{
    /** @var list<array{0: string, 1: string}> */
    private array $params = [];

    private string $method = 'GET';

    /** @var array<string, mixed>|list<array<string, mixed>>|null */
    private ?array $body = null;

    /** @var list<string> */
    private array $prefer = [];

    private ?string $countMode = null;

    private ?string $rangeHeader = null;

    private bool $returnRows = false;

    public function __construct(
        private readonly Client $client,
        private readonly string $table,
    ) {}

    /**
     * `$head` sends a HEAD request, as supabase-js's `{ head: true }`: the
     * count comes back in the headers and no rows are fetched.
     */
    public function select(string $columns = '*', ?string $count = null, bool $head = false): static
    {
        $this->setParam('select', self::compactColumns($columns));
        if ($count !== null) {
            $this->countMode = $count;
        }
        if ($head) {
            $this->method = 'HEAD';
        }
        if ($this->method !== 'GET') {
            $this->returnRows = true;
        }

        return $this;
    }

    public function insert(array $rows, ?string $onConflict = null, bool $upsert = false, bool $ignoreDuplicates = false): static
    {
        $this->method = 'POST';
        $this->body = $rows;
        if ($upsert) {
            $this->prefer[] = $ignoreDuplicates ? 'resolution=ignore-duplicates' : 'resolution=merge-duplicates';
        }
        if ($onConflict !== null) {
            $this->setParam('on_conflict', $onConflict);
        }
        if (array_is_list($rows) && $rows !== []) {
            $this->setParam('columns', implode(',', array_keys($rows[0])));
        }

        return $this;
    }

    public function upsert(array $rows, ?string $onConflict = null, bool $ignoreDuplicates = false): static
    {
        return $this->insert($rows, $onConflict, true, $ignoreDuplicates);
    }

    public function update(array $values): static
    {
        $this->method = 'PATCH';
        $this->body = $values;

        return $this;
    }

    public function delete(): static
    {
        $this->method = 'DELETE';

        return $this;
    }

    public function eq(string $column, mixed $value): static
    {
        return $this->filter($column, 'eq', $value);
    }

    public function neq(string $column, mixed $value): static
    {
        return $this->filter($column, 'neq', $value);
    }

    public function gt(string $column, mixed $value): static
    {
        return $this->filter($column, 'gt', $value);
    }

    public function gte(string $column, mixed $value): static
    {
        return $this->filter($column, 'gte', $value);
    }

    public function lt(string $column, mixed $value): static
    {
        return $this->filter($column, 'lt', $value);
    }

    public function lte(string $column, mixed $value): static
    {
        return $this->filter($column, 'lte', $value);
    }

    public function like(string $column, string $pattern): static
    {
        return $this->filter($column, 'like', $pattern);
    }

    public function ilike(string $column, string $pattern): static
    {
        return $this->filter($column, 'ilike', $pattern);
    }

    /** `null`, `true` or `false`, as supabase-js's `.is()`. */
    public function is(string $column, ?bool $value): static
    {
        return $this->filter($column, 'is', $value === null ? 'null' : ($value ? 'true' : 'false'));
    }

    /** @param iterable<scalar> $values */
    public function in(string $column, iterable $values): static
    {
        $quoted = [];
        foreach ($values as $value) {
            $quoted[] = self::quoteListValue($value);
        }

        return $this->filter($column, 'in', '('.implode(',', $quoted).')');
    }

    /** Array or jsonb contains, as supabase-js's `.contains()`. @param list<scalar>|array<string, mixed> $value */
    public function contains(string $column, array $value): static
    {
        return $this->filter($column, 'cs', self::arrayLiteral($value));
    }

    /** @param list<scalar> $value */
    public function overlaps(string $column, array $value): static
    {
        return $this->filter($column, 'ov', self::arrayLiteral($value));
    }

    /**
     * Full text search, as supabase-js's `.textSearch()`. `$type` is null
     * (`fts`), `plain` (`plfts`), `phrase` (`phfts`) or `websearch` (`wfts`).
     */
    public function textSearch(string $column, string $query, ?string $config = null, ?string $type = null): static
    {
        $op = match ($type) {
            'plain' => 'plfts',
            'phrase' => 'phfts',
            'websearch' => 'wfts',
            default => 'fts',
        };
        if ($config !== null) {
            $op .= '('.$config.')';
        }

        return $this->filter($column, $op, $query);
    }

    /** As supabase-js's `.not(column, operator, value)`. The value is sent as given. */
    public function not(string $column, string $operator, mixed $value): static
    {
        return $this->filter($column, 'not.'.$operator, $value);
    }

    /** As supabase-js's `.or('a.eq.1,b.is.null')`. The filter string is sent as given. */
    public function or(string $filters, ?string $foreignTable = null): static
    {
        $this->params[] = [$foreignTable ? $foreignTable.'.or' : 'or', '('.$filters.')'];

        return $this;
    }

    /** A raw filter, as supabase-js's `.filter(column, operator, value)`. */
    public function filter(string $column, string $operator, mixed $value): static
    {
        $this->params[] = [$column, $operator.'.'.self::scalar($value)];

        return $this;
    }

    public function order(string $column, bool $ascending = true, ?bool $nullsFirst = null, ?string $foreignTable = null): static
    {
        $part = $column.'.'.($ascending ? 'asc' : 'desc');
        if ($nullsFirst !== null) {
            $part .= $nullsFirst ? '.nullsfirst' : '.nullslast';
        }
        $key = $foreignTable ? $foreignTable.'.order' : 'order';
        foreach ($this->params as $i => [$name, $existing]) {
            if ($name === $key) {
                $this->params[$i] = [$key, $existing.','.$part];

                return $this;
            }
        }
        $this->params[] = [$key, $part];

        return $this;
    }

    public function limit(int $count, ?string $foreignTable = null): static
    {
        $this->setParam($foreignTable ? $foreignTable.'.limit' : 'limit', (string) $count);

        return $this;
    }

    /** Inclusive on both ends, as supabase-js's `.range()`. */
    public function range(int $from, int $to): static
    {
        $this->setParam('offset', (string) $from);
        $this->setParam('limit', (string) ($to - $from + 1));

        return $this;
    }

    /**
     * Run the query and return every row. With `select(..., count: 'exact')`
     * use {@see getWithCount()} instead to read the total as well.
     *
     * @return list<array<string, mixed>>
     */
    public function get(): array
    {
        return $this->send()['rows'];
    }

    /** @return array{rows: list<array<string, mixed>>, count: int|null} */
    public function getWithCount(): array
    {
        return $this->send();
    }

    /** Exactly one row, or a {@see SupabaseException} with code `PGRST116`. @return array<string, mixed> */
    public function single(): array
    {
        $rows = $this->limit(2)->get();
        if (count($rows) !== 1) {
            throw new SupabaseException(
                'JSON object requested, multiple (or no) rows returned',
                406,
                'PGRST116',
                'The result contains '.count($rows).' rows',
            );
        }

        return $rows[0];
    }

    /** One row or null, as supabase-js's `.maybeSingle()`. @return array<string, mixed>|null */
    public function maybeSingle(): ?array
    {
        $rows = $this->limit(2)->get();
        if (count($rows) > 1) {
            throw new SupabaseException('JSON object requested, multiple rows returned', 406, 'PGRST116');
        }

        return $rows[0] ?? null;
    }

    /** Run a write and return nothing. */
    public function execute(): void
    {
        $this->send();
    }

    /** @return array{rows: list<array<string, mixed>>, count: int|null} */
    private function send(): array
    {
        $prefer = $this->prefer;
        if ($this->countMode !== null) {
            $prefer[] = 'count='.$this->countMode;
        }
        if ($this->method !== 'GET' && $this->method !== 'HEAD') {
            $prefer[] = $this->returnRows ? 'return=representation' : 'return=minimal';
        }

        $headers = [];
        if ($prefer !== []) {
            $headers['Prefer'] = implode(',', $prefer);
        }
        if ($this->rangeHeader !== null) {
            $headers['Range'] = $this->rangeHeader;
        }

        return $this->client->rest($this->method, $this->table, $this->queryString(), $this->body, $headers);
    }

    private function queryString(): string
    {
        $parts = [];
        foreach ($this->params as [$name, $value]) {
            $parts[] = rawurlencode($name).'='.rawurlencode($value);
        }

        return implode('&', $parts);
    }

    private function setParam(string $name, string $value): void
    {
        foreach ($this->params as $i => [$existing]) {
            if ($existing === $name) {
                $this->params[$i] = [$name, $value];

                return;
            }
        }
        $this->params[] = [$name, $value];
    }

    /** supabase-js strips whitespace outside quotes from a select list. */
    private static function compactColumns(string $columns): string
    {
        $out = '';
        $quoted = false;
        foreach (str_split($columns) as $char) {
            if ($char === '"') {
                $quoted = ! $quoted;
            }
            if (! $quoted && ctype_space($char)) {
                continue;
            }
            $out .= $char;
        }

        return $out;
    }

    private static function scalar(mixed $value): string
    {
        return match (true) {
            $value === null => 'null',
            is_bool($value) => $value ? 'true' : 'false',
            default => (string) $value,
        };
    }

    private static function quoteListValue(mixed $value): string
    {
        $string = self::scalar($value);
        if (preg_match('/[,()":\s]/', $string)) {
            return '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $string).'"';
        }

        return $string;
    }

    /** @param array<array-key, mixed> $value */
    private static function arrayLiteral(array $value): string
    {
        if (! array_is_list($value)) {
            return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        }

        return '{'.implode(',', array_map(self::quoteListValue(...), $value)).'}';
    }
}
