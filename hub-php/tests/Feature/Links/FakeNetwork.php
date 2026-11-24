<?php

namespace Tests\Feature\Links;

use App\Domain\Links\Network;
use App\Domain\Links\Reply;
use Closure;

/** A {@see Network} that answers from fixed tables and records every connection. */
final class FakeNetwork implements Network
{
    /** @var list<array{url: string, host: string, ip: string, headers: array<string, string>}> */
    public array $connections = [];

    /** @var list<string> */
    public array $lookups = [];

    /**
     * @param  array<string, list<string>>  $dns
     * @param  array<string, Reply>  $replies  By URL. A URL with no reply fails to connect.
     */
    public function __construct(public array $dns = [], public array $replies = []) {}

    public function resolve(string $host): array
    {
        $this->lookups[] = $host;

        return $this->dns[$host] ?? [];
    }

    public function get(string $url, string $host, int $port, string $ip, array $headers, int $maxBytes, ?Closure $enough = null): ?Reply
    {
        $this->connections[] = ['url' => $url, 'host' => $host, 'ip' => $ip, 'headers' => $headers];
        $reply = $this->replies[$url] ?? null;
        if ($reply === null || strlen($reply->body) <= $maxBytes) {
            return $reply;
        }

        return new Reply($reply->status, $reply->headers, substr($reply->body, 0, $maxBytes), true);
    }

    /** @return list<string> */
    public function connectedIps(): array
    {
        return array_column($this->connections, 'ip');
    }
}
