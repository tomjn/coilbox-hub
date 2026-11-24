<?php

namespace App\Domain\Links;

use Closure;

/**
 * The two network calls a link card needs. {@see CurlNetwork} makes them for
 * real. Tests swap in a fake that records every connection, so they can show
 * a refused address was never contacted.
 */
interface Network
{
    /**
     * Every address the host's DNS gives, IPv4 and IPv6.
     *
     * @return list<string>
     */
    public function resolve(string $host): array;

    /**
     * One GET to `$ip`, whatever DNS says about `$host`, with no redirects
     * followed. Reading stops at `$maxBytes`, or once `$enough` says the body
     * so far is all that is needed. Null when the connection fails.
     *
     * @param  array<string, string>  $headers
     * @param  (Closure(string): bool)|null  $enough
     */
    public function get(string $url, string $host, int $port, string $ip, array $headers, int $maxBytes, ?Closure $enough = null): ?Reply;
}
