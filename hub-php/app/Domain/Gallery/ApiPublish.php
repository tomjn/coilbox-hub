<?php

namespace App\Domain\Gallery;

/**
 * The shape check on `POST /api/v1/items`, from the Next app's
 * `lib/api/publish.ts`. As strict as the list's query string: a client that
 * sent `Tags` and had it ignored is worse off than one told the name is wrong.
 * What makes a code publishable is {@see Publisher}'s job, shared with the form.
 */
final class ApiPublish
{
    private const BODY_KEYS = ['code', 'title', 'description', 'tags'];

    /**
     * `$body` is the decoded JSON with objects as `stdClass`, so an object and
     * an array are told apart as `JSON.parse` would.
     *
     * @return array{ok: true, fields: array{code: string, title: string, description: string, tags: list<string>}}|array{ok: false, error: string}
     */
    public static function parseBody(mixed $body): array
    {
        if (! $body instanceof \stdClass) {
            return ['ok' => false, 'error' => 'The request body must be a JSON object.'];
        }
        $record = get_object_vars($body);

        foreach (array_keys($record) as $key) {
            if (! in_array((string) $key, self::BODY_KEYS, true)) {
                return ['ok' => false, 'error' => 'Unknown field: '.$key];
            }
        }

        $code = $record['code'] ?? null;
        if (! is_string($code) || \App\Domain\Container\Container::trim($code) === '') {
            return ['ok' => false, 'error' => '`code` is required and must be a string.'];
        }

        $title = $record['title'] ?? null;
        if (! is_string($title)) {
            return ['ok' => false, 'error' => '`title` is required and must be a string.'];
        }

        $description = $record['description'] ?? '';
        if (! is_string($description)) {
            return ['ok' => false, 'error' => '`description` must be a string.'];
        }

        $tags = $record['tags'] ?? [];
        if (! is_array($tags) || array_filter($tags, fn ($tag) => ! is_string($tag)) !== []) {
            return ['ok' => false, 'error' => '`tags` must be an array of strings.'];
        }

        return ['ok' => true, 'fields' => ['code' => $code, 'title' => $title, 'description' => $description, 'tags' => array_values($tags)]];
    }

    /** 422 for something that cannot be published, 429 for the rate limit, 500 for a failed write. */
    public static function statusForFailure(string $status): int
    {
        return match ($status) {
            'invalid' => 422,
            'rate_limited' => 429,
            default => 500,
        };
    }
}
