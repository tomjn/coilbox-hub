<?php

namespace App\Domain\Links;

/** A response from {@see Network::get()}. Header names are lower case. */
final readonly class Reply
{
    /** @param array<string, string> $headers */
    public function __construct(
        public int $status,
        public array $headers,
        public string $body,
        public bool $truncated = false,
    ) {}

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }
}
