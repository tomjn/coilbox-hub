<?php

namespace App\Domain\Container;

/**
 * JSON as `JSON.parse` and `JSON.stringify` see it, for containers.
 *
 * Objects decode to `stdClass` so `{}` survives a round trip. Encoding leaves
 * slashes, Unicode and line separators alone, as `JSON.stringify` does, and
 * writes a whole float without its `.0`.
 */
final class Json
{
    public const FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_LINE_TERMINATORS;

    /** The decoded value, or null when the text is not JSON (or is the JSON `null`). */
    public static function decode(string $text): mixed
    {
        $value = json_decode($text, false, 512, JSON_INVALID_UTF8_SUBSTITUTE);

        return json_last_error() === JSON_ERROR_NONE ? $value : null;
    }

    public static function encode(mixed $value): string
    {
        return json_encode($value, self::FLAGS | JSON_THROW_ON_ERROR);
    }
}
