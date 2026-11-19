<?php

namespace App\Supabase\Local;

/** One entry in a select list: `star`, `column` or `embed`. */
final class SelectItem
{
    public function __construct(
        public readonly string $kind,
        public readonly string $name,
        public readonly ?string $alias = null,
        public readonly bool $inner = false,
        public readonly ?Select $nested = null,
        public readonly array $path = [],
        public readonly bool $text = false,
    ) {}

    /** A JSON column's value at this item's path, null where the path leads nowhere. */
    public function at(mixed $value): mixed
    {
        foreach ($this->path as $step) {
            if (! is_array($value)) {
                return null;
            }
            $key = array_is_list($value) && ctype_digit($step) ? (int) $step : $step;
            if (! array_key_exists($key, $value)) {
                return null;
            }
            $value = $value[$key];
        }
        if ($this->text && $value !== null && ! is_string($value)) {
            return is_bool($value) ? ($value ? 'true' : 'false') : json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        return $value;
    }

    /** The key the item answers under, and the name filters use for an embed. */
    public function key(): string
    {
        return $this->alias ?? $this->name;
    }
}
