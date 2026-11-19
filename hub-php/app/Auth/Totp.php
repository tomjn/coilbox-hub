<?php

namespace App\Auth;

/**
 * Time based one time codes, RFC 6238 with the defaults every authenticator
 * app uses: HMAC-SHA1, 6 digits, 30 second steps.
 */
final class Totp
{
    public const PERIOD = 30;

    public const DIGITS = 6;

    /** Codes from one step either side are accepted, for clocks a little out. */
    public const DRIFT = 1;

    private const BASE32 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /** A new secret, 160 bits in base32 as authenticator apps take it. */
    public static function secret(): string
    {
        return self::base32(random_bytes(20));
    }

    public static function step(int $time): int
    {
        return intdiv($time, self::PERIOD);
    }

    /** The code for one step. `$key` is the raw secret, not base32. */
    public static function code(string $key, int $step, int $digits = self::DIGITS): string
    {
        $hash = hash_hmac('sha1', pack('J', $step), $key, true);
        $offset = ord($hash[19]) & 0x0F;
        $value = ((ord($hash[$offset]) & 0x7F) << 24)
            | (ord($hash[$offset + 1]) << 16)
            | (ord($hash[$offset + 2]) << 8)
            | ord($hash[$offset + 3]);

        return str_pad((string) ($value % (10 ** $digits)), $digits, '0', STR_PAD_LEFT);
    }

    /**
     * The step a code matches, within {@see DRIFT} of now and after
     * `$lastStep`, so a code already used is refused. Null when it matches none.
     */
    public static function match(string $secret, string $code, int $time, ?int $lastStep): ?int
    {
        if (strlen($code) !== self::DIGITS) {
            return null;
        }
        $key = self::decode($secret);
        $now = self::step($time);
        for ($step = $now - self::DRIFT; $step <= $now + self::DRIFT; $step++) {
            if (($lastStep === null || $step > $lastStep) && hash_equals(self::code($key, $step), $code)) {
                return $step;
            }
        }

        return null;
    }

    /** The `otpauth://` address authenticator apps open to add the account. */
    public static function uri(string $secret, string $account, string $issuer): string
    {
        return 'otpauth://totp/'.rawurlencode($issuer.':'.$account).'?'.http_build_query([
            'secret' => $secret,
            'issuer' => $issuer,
            'algorithm' => 'SHA1',
            'digits' => self::DIGITS,
            'period' => self::PERIOD,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    public static function base32(string $bytes): string
    {
        $bits = '';
        foreach (str_split($bytes) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::BASE32[bindec(str_pad($chunk, 5, '0'))];
        }

        return $out;
    }

    public static function decode(string $secret): string
    {
        $bits = '';
        foreach (str_split(strtoupper(rtrim($secret, '='))) as $char) {
            $index = strpos(self::BASE32, $char);
            if ($index === false) {
                continue;
            }
            $bits .= str_pad(decbin($index), 5, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 8) as $chunk) {
            if (strlen($chunk) === 8) {
                $out .= chr(bindec($chunk));
            }
        }

        return $out;
    }
}
