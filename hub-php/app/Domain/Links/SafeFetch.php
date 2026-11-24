<?php

namespace App\Domain\Links;

use App\Support\Site;
use Closure;

/**
 * Fetches a URL somebody posted in chat without letting it reach the hub
 * itself or the network the hub runs on. Only `http` and `https` on their
 * default ports. Every address the host resolves to must be public, the
 * connection goes to the address that was checked, and each redirect is
 * checked the same way.
 */
final class SafeFetch
{
    public const MAX_REDIRECTS = 3;

    public function __construct(private readonly Network $network) {}

    /** Names the hub and nothing about the player who posted the link. */
    public static function userAgent(): string
    {
        return 'CoilboxHub/1.0 (+'.Site::url().')';
    }

    /**
     * The final URL and its 2xx reply, or null when the URL is refused, the
     * fetch fails or the redirects run out.
     *
     * @param  (Closure(string): bool)|null  $enough
     * @return array{url: string, reply: Reply}|null
     */
    public function get(string $url, string $accept, int $maxBytes, ?Closure $enough = null): ?array
    {
        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            $target = $this->target($url);
            if ($target === null) {
                return null;
            }

            $reply = $this->network->get(
                $target['url'],
                $target['host'],
                $target['port'],
                $target['ip'],
                ['User-Agent' => self::userAgent(), 'Accept' => $accept],
                $maxBytes,
                $enough,
            );
            if ($reply === null) {
                return null;
            }

            if (in_array($reply->status, [301, 302, 303, 307, 308], true)) {
                $next = Urls::resolve($target['url'], (string) $reply->header('location'));
                if ($next === null) {
                    return null;
                }
                $url = $next;

                continue;
            }

            return $reply->status >= 200 && $reply->status < 300
                ? ['url' => $target['url'], 'reply' => $reply]
                : null;
        }

        return null;
    }

    /**
     * The URL rebuilt from its checked parts, its host, port and the address
     * to connect to. Null when any part is refused.
     *
     * @return array{url: string, host: string, port: int, ip: string}|null
     */
    public function target(string $url): ?array
    {
        $parts = parse_url($url);
        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            return null;
        }
        $scheme = strtolower($parts['scheme']);
        if ($scheme !== 'http' && $scheme !== 'https') {
            return null;
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }
        $port = $scheme === 'https' ? 443 : 80;
        if (isset($parts['port']) && $parts['port'] !== $port) {
            return null;
        }

        $host = strtolower(rtrim($parts['host'], '.'));
        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            $literal = substr($host, 1, -1);
            if (filter_var($literal, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
                return null;
            }
            $ips = [$literal];
            $urlHost = $host;
        } elseif (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            $ips = [$host];
            $urlHost = $host;
        } else {
            if (preg_match('/[^\x21-\x7e]/', $host) === 1) {
                $ascii = idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
                if ($ascii === false) {
                    return null;
                }
                $host = strtolower($ascii);
            }
            if (preg_match('/^[a-z0-9_.-]+$/D', $host) !== 1) {
                return null;
            }
            $ips = $this->network->resolve($host);
            $urlHost = $host;
        }

        if ($ips === []) {
            return null;
        }
        // One private answer refuses the host, so no answer can be picked to reach it.
        foreach ($ips as $ip) {
            if (! Addresses::isPublic($ip)) {
                return null;
            }
        }
        // IPv4 first, for a server with no IPv6 route.
        usort($ips, fn (string $a, string $b) => str_contains($a, ':') <=> str_contains($b, ':'));

        $rebuilt = $scheme.'://'.$urlHost.($parts['path'] ?? '/');
        if (isset($parts['query'])) {
            $rebuilt .= '?'.$parts['query'];
        }

        return ['url' => $rebuilt, 'host' => trim($urlHost, '[]'), 'port' => $port, 'ip' => $ips[0]];
    }
}
