<?php

namespace App\Domain\Gallery;

/**
 * What a gallery listing is filtered by, read from the query string. The PHP
 * side of `Filters` in the Next app's `lib/gallery/query.ts`.
 */
final class Filters
{
    /**
     * @param  list<string>  $kind  Empty means no filter. More than one is a union.
     * @param  list<string>  $tag  Lowercased. A union when more than one.
     * @param  list<string>  $author  Matches the `author_name` snapshot. A union.
     * @param  list<string>  $account  Account ids, matched against `author_id`. A union.
     * @param  string  $sort  `newest` or `title`.
     */
    public function __construct(
        public readonly array $kind = [],
        public readonly ?string $game = null,
        public readonly ?string $map = null,
        public readonly array $tag = [],
        public readonly array $author = [],
        public readonly array $account = [],
        public readonly ?string $q = null,
        public readonly string $sort = 'newest',
        public readonly int $page = 1,
    ) {}

    /** A copy with some fields changed. @param array<string, mixed> $change */
    public function with(array $change): self
    {
        return new self(...array_merge($this->toArray(), $change));
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return get_object_vars($this);
    }

    /** True when nothing narrows the listing. Sort and page do not narrow it. */
    public function isUnfiltered(): bool
    {
        return $this->kind === [] && $this->game === null && $this->map === null && $this->tag === []
            && $this->author === [] && $this->account === [] && $this->q === null;
    }
}
