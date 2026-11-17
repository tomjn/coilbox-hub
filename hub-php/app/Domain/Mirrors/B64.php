<?php

namespace App\Domain\Mirrors;

use SodiumException;

/** base64url from RFC 4648, with no padding. */
final class B64
{
    public static function encode(string $bin): string
    {
        return sodium_bin2base64($bin, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
    }

    /** Returns null for padding, the standard alphabet, whitespace or any other invalid input. */
    public static function decode(string $s): ?string
    {
        try {
            return sodium_base642bin($s, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
        } catch (SodiumException) {
            return null;
        }
    }
}
