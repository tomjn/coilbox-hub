<?php

namespace App\Domain\Gallery;

use Illuminate\Http\Request;

/**
 * A query string read the way the browser's `URLSearchParams` reads it.
 *
 * PHP's own parsing keeps only the last of a repeated key unless it ends in
 * `[]`, and turns dots and spaces in names into underscores. The gallery takes
 * `?kind=preset&kind=blueprint` as two kinds, and the API rejects names it does
 * not know, so both need the names and values exactly as sent.
 */
final class SearchParams
{
    /** @param list<array{0: string, 1: string}> $pairs */
    public function __construct(private readonly array $pairs) {}

    public static function fromRequest(Request $request): self
    {
        return self::parse((string) $request->server('QUERY_STRING', ''));
    }

    public static function parse(string $query): self
    {
        $query = ltrim($query, '?');
        $pairs = [];
        foreach (explode('&', $query) as $part) {
            if ($part === '') {
                continue;
            }
            $at = strpos($part, '=');
            $name = $at === false ? $part : substr($part, 0, $at);
            $value = $at === false ? '' : substr($part, $at + 1);
            $pairs[] = [self::decode($name), self::decode($value)];
        }

        return new self($pairs);
    }

    /** Every name in order of first appearance, each once. @return list<string> */
    public function keys(): array
    {
        $keys = [];
        foreach ($this->pairs as [$name]) {
            if (! in_array($name, $keys, true)) {
                $keys[] = $name;
            }
        }

        return $keys;
    }

    /** @return list<string> */
    public function getAll(string $name): array
    {
        $values = [];
        foreach ($this->pairs as [$key, $value]) {
            if ($key === $name) {
                $values[] = $value;
            }
        }

        return $values;
    }

    public function get(string $name): ?string
    {
        return $this->getAll($name)[0] ?? null;
    }

    public function has(string $name): bool
    {
        return $this->get($name) !== null;
    }

    /**
     * The shape `parseFilters` takes in the Next app: a bare string for a key
     * given once, a list for a key given more than once.
     *
     * @return array<string, string|list<string>>
     */
    public function toRecord(): array
    {
        $record = [];
        foreach ($this->keys() as $key) {
            $values = $this->getAll($key);
            $record[$key] = count($values) > 1 ? $values : $values[0];
        }

        return $record;
    }

    private static function decode(string $part): string
    {
        $decoded = rawurldecode(str_replace('+', ' ', $part));

        // URLSearchParams replaces bytes that are not UTF-8 rather than failing.
        return mb_check_encoding($decoded, 'UTF-8') ? $decoded : mb_convert_encoding($decoded, 'UTF-8', 'UTF-8');
    }
}
