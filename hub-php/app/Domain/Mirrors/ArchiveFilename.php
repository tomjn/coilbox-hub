<?php

namespace App\Domain\Mirrors;

final class ArchiveFilename
{
    /**
     * True for a valid UTF-8 name of 1 to 255 bytes that has no slash, backslash, control, format, line
     * separator or paragraph separator character, does not start with a dot, and ends in .sd7 or .sdz in any case.
     */
    public static function isValid(string $name): bool
    {
        $length = strlen($name);
        if ($length < 1 || $length > 255) {
            return false;
        }

        // The u modifier fails on invalid UTF-8. Cc covers NUL, C0, DEL and C1 controls. Cf covers invisible
        // format characters such as U+202E, which flips text so a listing can show a fake extension, and U+FEFF.
        // Zl and Zp are the line and paragraph separators U+2028 and U+2029.
        return preg_match('/\A[^\/\\\\\p{Cc}\p{Cf}\p{Zl}\p{Zp}.][^\/\\\\\p{Cc}\p{Cf}\p{Zl}\p{Zp}]*\.(?:sd7|sdz)\z/iu', $name) === 1;
    }
}
