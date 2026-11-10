<?php

namespace App\Domain\Assets;

use Closure;
use stdClass;

/**
 * The `{"keys": [...]}` envelope `/api/v1/assets/have` and
 * `/api/v1/assets/pictures` share: the same refusals, wording and limits.
 */
final class Batch
{
    /**
     * @param  Closure(mixed): array  $parseKey  answers `['ok' => true, 'value' => key]` or `['ok' => false, 'error' => ...]`
     * @param  Closure(mixed): array<string, string>  $identityOf
     * @return array{ok: true, keys: list<mixed>}|array{ok: false, error: string, status: int}
     */
    public static function parse(mixed $body, int $maxKeys, Closure $parseKey, Closure $identityOf): array
    {
        if (! $body instanceof stdClass) {
            return ['ok' => false, 'error' => 'The request body must be a JSON object.', 'status' => 400];
        }

        $extra = Js::unknownField($body, ['keys']);
        if ($extra !== null) {
            return ['ok' => false, 'error' => 'Unknown field: '.$extra, 'status' => 400];
        }

        $raw = Js::get($body, 'keys');
        if (! is_array($raw)) {
            return ['ok' => false, 'error' => '`keys` is required and must be an array.', 'status' => 400];
        }
        if ($raw === []) {
            return ['ok' => false, 'error' => '`keys` must not be empty.', 'status' => 400];
        }
        if (count($raw) > $maxKeys) {
            return [
                'ok' => false,
                'error' => 'A batch may carry at most '.$maxKeys.' keys. That request carried '.count($raw).'. Split it.',
                'status' => 413,
            ];
        }

        $keys = [];
        $seen = [];
        foreach ($raw as $index => $value) {
            $parsed = $parseKey($value);
            if (! $parsed['ok']) {
                return ['ok' => false, 'error' => 'keys['.$index.'] '.$parsed['error'], 'status' => 400];
            }

            // A repeated key spends batch room, and for the have check has no single right answer.
            $seenKey = Have::identityKey($identityOf($parsed['value']));
            if (isset($seen[$seenKey])) {
                return ['ok' => false, 'error' => 'keys['.$index.'] repeats a key already in the batch.', 'status' => 400];
            }
            $seen[$seenKey] = true;
            $keys[] = $parsed['value'];
        }

        return ['ok' => true, 'keys' => $keys];
    }
}
