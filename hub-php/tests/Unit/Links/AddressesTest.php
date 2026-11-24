<?php

namespace Tests\Unit\Links;

use App\Domain\Links\Addresses;
use App\Domain\Links\Urls;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class AddressesTest extends TestCase
{
    /** @return array<string, array{string, bool}> */
    public static function addresses(): array
    {
        return [
            'public IPv4' => ['8.8.8.8', true],
            'public IPv6' => ['2606:4700::1', true],
            'loopback' => ['127.0.0.1', false],
            'unspecified' => ['0.0.0.0', false],
            'private 10' => ['10.0.0.1', false],
            'private 172.16' => ['172.16.5.4', false],
            'private 192.168' => ['192.168.0.1', false],
            'shared address space' => ['100.64.0.1', false],
            'link local' => ['169.254.169.254', false],
            'IPv4 multicast' => ['224.0.0.1', false],
            'IPv4 broadcast' => ['255.255.255.255', false],
            'reserved 240' => ['240.0.0.1', false],
            'IPv6 loopback' => ['::1', false],
            'IPv6 unspecified' => ['::', false],
            'IPv6 mapped IPv4' => ['::ffff:127.0.0.1', false],
            'IPv6 compatible IPv4' => ['::7f00:1', false],
            'IPv6 link local' => ['fe80::1', false],
            'IPv6 unique local' => ['fd12:3456::1', false],
            'IPv6 multicast' => ['ff02::1', false],
            'NAT64' => ['64:ff9b::a00:1', false],
            'documentation' => ['2001:db8::1', false],
            'not an address' => ['example.com', false],
        ];
    }

    #[DataProvider('addresses')]
    public function test_only_public_unicast_addresses_pass(string $ip, bool $public): void
    {
        $this->assertSame($public, Addresses::isPublic($ip));
    }

    public function test_resolves_links_against_the_page(): void
    {
        $base = 'https://example.com/blog/post?x=1';
        $this->assertSame('https://example.com/img/a.png', Urls::resolve($base, '/img/a.png'));
        $this->assertSame('https://example.com/blog/a.png', Urls::resolve($base, 'a.png'));
        $this->assertSame('https://cdn.example/a.png', Urls::resolve($base, '//cdn.example/a.png'));
        $this->assertSame('http://other.example/', Urls::resolve($base, 'http://other.example/'));
        $this->assertSame('https://example.com/blog/post?y=2', Urls::resolve($base, '?y=2'));
        $this->assertNull(Urls::resolve($base, 'javascript:alert(1)'));
        $this->assertNull(Urls::resolve($base, 'data:image/png;base64,AAAA'));
        $this->assertNull(Urls::resolve($base, ''));
    }
}
