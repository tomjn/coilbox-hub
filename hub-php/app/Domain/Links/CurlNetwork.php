<?php

namespace App\Domain\Links;

use Closure;

/**
 * Plain curl rather than Laravel's HTTP client. Guzzle buffers or swaps
 * handlers in ways that lose `CURLOPT_RESOLVE` or the byte cap, and both are
 * what keeps a fetch on the address {@see SafeFetch} checked.
 */
final class CurlNetwork implements Network
{
    public const CONNECT_SECONDS = 3;

    public const TOTAL_SECONDS = 6;

    public function resolve(string $host): array
    {
        $ips = gethostbynamel($host) ?: [];
        $records = @dns_get_record($host, DNS_AAAA) ?: [];
        foreach ($records as $record) {
            if (isset($record['ipv6'])) {
                $ips[] = $record['ipv6'];
            }
        }

        return array_values(array_unique($ips));
    }

    public function get(string $url, string $host, int $port, string $ip, array $headers, int $maxBytes, ?Closure $enough = null): ?Reply
    {
        $body = '';
        $truncated = false;
        $received = [];

        $lines = [];
        foreach ($headers as $name => $value) {
            $lines[] = $name.': '.$value;
        }

        $handle = curl_init();
        curl_setopt_array($handle, [
            CURLOPT_URL => $url,
            // Connect to the checked address, so a second DNS answer cannot swap in another.
            CURLOPT_RESOLVE => [$host.':'.$port.':'.(str_contains($ip, ':') ? '['.$ip.']' : $ip)],
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROXY => '',
            CURLOPT_NOPROXY => '*',
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_SECONDS,
            CURLOPT_TIMEOUT => self::TOTAL_SECONDS,
            CURLOPT_HTTPHEADER => $lines,
            // Decoded bytes count towards the cap, so a compressed page cannot expand past it.
            CURLOPT_ENCODING => '',
            CURLOPT_HEADERFUNCTION => function ($handle, string $line) use (&$received): int {
                if (str_starts_with($line, 'HTTP/')) {
                    $received = [];
                } elseif (str_contains($line, ':')) {
                    [$name, $value] = explode(':', $line, 2);
                    $received[strtolower(trim($name))] = trim($value);
                }

                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => function ($handle, string $chunk) use (&$body, &$truncated, $maxBytes, $enough): int {
                $body .= $chunk;
                if (strlen($body) > $maxBytes) {
                    $body = substr($body, 0, $maxBytes);
                    $truncated = true;

                    return 0;
                }
                if ($enough !== null && $enough($body)) {
                    return 0;
                }

                return strlen($chunk);
            },
        ]);

        curl_exec($handle);
        $error = curl_errno($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $connected = (string) curl_getinfo($handle, CURLINFO_PRIMARY_IP);
        curl_close($handle);

        // A write error is this class stopping the read on purpose.
        if (($error !== 0 && $error !== CURLE_WRITE_ERROR) || $status === 0) {
            return null;
        }
        if ($connected !== '' && inet_pton($connected) !== inet_pton($ip)) {
            return null;
        }

        return new Reply($status, $received, $body, $truncated);
    }
}
