<?php

namespace App\Domain\Links;

/** Which addresses the hub may fetch a link from. */
final class Addresses
{
    /**
     * True for a public unicast address. PHP's global range filter refuses
     * loopback, private, link-local, shared and reserved ranges for IPv4 and
     * IPv6. It lets multicast through, and NAT64 addresses, which a gateway
     * would turn into any IPv4 address, private ones included.
     */
    public static function isPublic(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE) === false) {
            return false;
        }
        $bytes = inet_pton($ip);
        if ($bytes === false) {
            return false;
        }
        if (strlen($bytes) === 4) {
            return (ord($bytes[0]) & 0xF0) !== 0xE0;
        }
        if (ord($bytes[0]) === 0xFF) {
            return false;
        }
        $prefix = substr($bytes, 0, 12);

        return $prefix !== substr((string) inet_pton('64:ff9b::'), 0, 12)
            && $prefix !== str_repeat("\0", 12);
    }
}
