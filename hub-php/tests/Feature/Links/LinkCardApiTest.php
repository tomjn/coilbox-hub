<?php

namespace Tests\Feature\Links;

use App\Domain\Links\LinkCards;
use App\Domain\Links\Network;
use App\Domain\Links\Reply;
use App\Domain\Links\SafeFetch;
use Illuminate\Cache\ArrayStore;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** `GET /api/v1/links/card` and `GET /api/v1/links/image` (#654). */
class LinkCardApiTest extends TestCase
{
    private const PUBLIC_IP = '93.184.215.14';

    /** A 1 by 1 PNG. */
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';

    private FakeNetwork $network;

    protected function setUp(): void
    {
        parent::setUp();
        $this->network = new FakeNetwork(['example.com' => [self::PUBLIC_IP], 'video.example' => [self::PUBLIC_IP]]);
        $this->app->instance(Network::class, $this->network);
    }

    private static function html(string $head): Reply
    {
        return new Reply(200, ['content-type' => 'text/html; charset=utf-8'], '<!doctype html><html><head>'.$head.'</head><body>Body</body></html>');
    }

    private function card(string $url): mixed
    {
        return $this->getJson('/api/v1/links/card?url='.rawurlencode($url))->assertOk()->json('card');
    }

    public function test_reads_oembed_first_and_ignores_its_html(): void
    {
        $this->network->replies = [
            'https://example.com/watch?v=1' => self::html(
                '<title>Page title</title>'
                .'<meta property="og:title" content="OG title">'
                .'<meta property="og:description" content="OG description">'
                .'<link rel="alternate" type="application/json+oembed" href="https://video.example/oembed?url=1">',
            ),
            'https://video.example/oembed?url=1' => new Reply(200, ['content-type' => 'application/json'], json_encode([
                'type' => 'video',
                'title' => 'Oembed title',
                'provider_name' => 'VideoSite',
                'thumbnail_url' => 'https://video.example/thumb.jpg',
                'html' => '<iframe src="https://video.example/embed/1"></iframe>',
            ])),
        ];

        $card = $this->card('https://example.com/watch?v=1');

        $this->assertSame([
            'url' => 'https://example.com/watch?v=1',
            'type' => 'video',
            'site_name' => 'VideoSite',
            'title' => 'Oembed title',
            'description' => 'OG description',
            'image' => LinkCards::imageUrl('https://video.example/thumb.jpg'),
        ], $card);
        $this->assertStringStartsWith(url('/api/v1/links/image'), $card['image']);
        $this->assertStringNotContainsString('iframe', json_encode($card));
    }

    public function test_falls_back_to_open_graph_tags(): void
    {
        $this->network->replies = ['https://example.com/post' => self::html(
            '<meta property="og:site_name" content="Example Blog">'
            .'<meta property="og:title" content="A post &amp; more">'
            .'<meta property="og:description" content="  What the post
            says.  ">'
            .'<meta property="og:image" content="/images/post.png">'
            .'<meta property="og:url" content="https://example.com/post-canonical">',
        )];

        $this->assertSame([
            'url' => 'https://example.com/post-canonical',
            'type' => null,
            'site_name' => 'Example Blog',
            'title' => 'A post & more',
            'description' => 'What the post says.',
            'image' => LinkCards::imageUrl('https://example.com/images/post.png'),
        ], $this->card('https://example.com/post'));
    }

    public function test_falls_back_to_twitter_tags_then_the_page_title(): void
    {
        $this->network->replies = [
            'https://example.com/a' => self::html('<meta name="twitter:title" content="Tweet title"><meta name="twitter:image" content="https://example.com/t.png">'),
            'https://example.com/b' => self::html('<title> Just a title </title><meta name="description" content="Plain description">'),
        ];

        $a = $this->card('https://example.com/a');
        $this->assertSame('Tweet title', $a['title']);
        $this->assertSame(LinkCards::imageUrl('https://example.com/t.png'), $a['image']);

        $b = $this->card('https://example.com/b');
        $this->assertSame('Just a title', $b['title']);
        $this->assertSame('Plain description', $b['description']);
        $this->assertSame('example.com', $b['site_name']);
        $this->assertNull($b['image']);
    }

    public function test_a_page_with_no_title_is_no_card_and_is_cached(): void
    {
        $this->network->replies = ['https://example.com/bare' => self::html('<meta charset="utf-8">')];

        $this->assertNull($this->card('https://example.com/bare'));
        $this->assertNull($this->card('https://example.com/bare'));
        $this->assertCount(1, $this->network->connections);
    }

    public function test_an_unreachable_host_is_no_card_and_is_cached(): void
    {
        $this->assertNull($this->card('https://example.com/down'));
        $this->assertNull($this->card('https://example.com/down'));
        $this->assertCount(1, $this->network->connections);
    }

    public function test_a_host_with_no_dns_answer_is_no_card(): void
    {
        $this->assertNull($this->card('https://missing.example/'));
        $this->assertSame([], $this->network->connections);
    }

    public function test_a_response_that_is_not_html_is_no_card_and_is_cached(): void
    {
        $this->network->replies = ['https://example.com/file.zip' => new Reply(200, ['content-type' => 'application/zip'], 'PK')];

        $this->assertNull($this->card('https://example.com/file.zip'));
        $this->assertNull($this->card('https://example.com/file.zip'));
        $this->assertCount(1, $this->network->connections);
    }

    public function test_an_error_status_is_no_card(): void
    {
        $this->network->replies = ['https://example.com/gone' => new Reply(404, ['content-type' => 'text/html'], '<title>Not found</title>')];

        $this->assertNull($this->card('https://example.com/gone'));
    }

    public function test_a_second_request_inside_the_cache_lifetime_makes_no_outbound_request(): void
    {
        $this->network->replies = ['https://example.com/post' => self::html('<meta property="og:title" content="Cached">')];

        $first = $this->card('https://example.com/post');
        $second = $this->card('https://example.com/post#comments');

        $this->assertSame('Cached', $first['title']);
        $this->assertSame($first, $second);
        $this->assertCount(1, $this->network->connections);
        $this->assertSame(['example.com'], $this->network->lookups);
    }

    public function test_sends_the_hub_user_agent_and_connects_to_the_checked_address(): void
    {
        $this->network->replies = ['https://example.com/post' => self::html('<title>T</title>')];

        $this->card('https://example.com/post');

        $this->assertSame([[
            'url' => 'https://example.com/post',
            'host' => 'example.com',
            'ip' => self::PUBLIC_IP,
            'headers' => ['User-Agent' => SafeFetch::userAgent(), 'Accept' => 'text/html,application/xhtml+xml;q=0.9,*/*;q=0.1'],
        ]], $this->network->connections);
        $this->assertStringStartsWith('CoilboxHub/1.0 (+', SafeFetch::userAgent());
    }

    /** @return array<string, array{string}> */
    public static function refusedUrls(): array
    {
        return [
            'loopback' => ['http://127.0.0.1/'],
            'loopback written as a number' => ['http://2130706433/'],
            'cloud metadata' => ['http://169.254.169.254/latest/meta-data/'],
            'private' => ['http://10.0.0.5/'],
            'private 192.168' => ['http://192.168.1.1/'],
            'IPv6 loopback' => ['http://[::1]/'],
            'IPv6 mapped IPv4 loopback' => ['http://[::ffff:127.0.0.1]/'],
            'IPv6 unique local' => ['http://[fd00::1]/'],
            'host resolving to private' => ['https://internal.example/'],
            'host with one private answer among public ones' => ['https://mixed.example/'],
            'localhost' => ['http://localhost/'],
            'other scheme' => ['ftp://example.com/'],
            'file' => ['file:///etc/passwd'],
            'non default port' => ['http://example.com:8080/'],
            'https on port 80' => ['https://example.com:80/'],
            'credentials' => ['http://user:pass@example.com/'],
            'not a url' => ['example.com'],
        ];
    }

    #[DataProvider('refusedUrls')]
    public function test_refuses_without_connecting(string $url): void
    {
        $this->network->dns += [
            'internal.example' => ['10.1.2.3'],
            'mixed.example' => [self::PUBLIC_IP, '192.168.0.10'],
            'localhost' => ['127.0.0.1'],
            '2130706433' => ['127.0.0.1'],
        ];

        $this->assertNull($this->card($url));
        $this->assertSame([], $this->network->connections);
    }

    public function test_a_public_url_that_redirects_to_a_private_address_is_refused(): void
    {
        $this->network->dns['internal.example'] = ['10.1.2.3'];
        $this->network->replies = [
            'https://example.com/a' => new Reply(302, ['location' => 'http://169.254.169.254/latest/meta-data/'], ''),
            'https://example.com/b' => new Reply(301, ['location' => 'https://internal.example/'], ''),
        ];

        $this->assertNull($this->card('https://example.com/a'));
        $this->assertNull($this->card('https://example.com/b'));
        $this->assertSame([self::PUBLIC_IP, self::PUBLIC_IP], $this->network->connectedIps());
    }

    public function test_follows_a_few_redirects_and_no_more(): void
    {
        $this->network->replies = [
            'https://example.com/1' => new Reply(301, ['location' => '/2'], ''),
            'https://example.com/2' => new Reply(302, ['location' => 'https://example.com/3'], ''),
            'https://example.com/3' => self::html('<title>Landed</title>'),
            'https://example.com/loop' => new Reply(302, ['location' => '/loop'], ''),
        ];

        $card = $this->card('https://example.com/1');
        $this->assertSame('Landed', $card['title']);
        $this->assertSame('https://example.com/3', $card['url']);

        $before = count($this->network->connections);
        $this->assertNull($this->card('https://example.com/loop'));
        $this->assertSame(SafeFetch::MAX_REDIRECTS + 1, count($this->network->connections) - $before);
    }

    public function test_an_oembed_link_to_a_private_address_is_not_fetched(): void
    {
        $this->network->replies = ['https://example.com/p' => self::html(
            '<meta property="og:title" content="Still a card">'
            .'<link rel="alternate" type="application/json+oembed" href="http://127.0.0.1/oembed">',
        )];

        $this->assertSame('Still a card', $this->card('https://example.com/p')['title']);
        $this->assertSame([self::PUBLIC_IP], $this->network->connectedIps());
    }

    public function test_a_missing_url_is_a_bad_request(): void
    {
        $this->getJson('/api/v1/links/card')->assertStatus(400);
    }

    public function test_the_picture_endpoint_serves_a_card_picture(): void
    {
        $image = 'https://example.com/p.png';
        $this->network->replies = [$image => new Reply(200, ['content-type' => 'image/png'], base64_decode(self::PNG))];

        $response = $this->get(LinkCards::imageUrl($image));

        $response->assertOk();
        $this->assertSame('image/png', $response->headers->get('Content-Type'));
        $this->assertSame(base64_decode(self::PNG), $response->getContent());
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
    }

    public function test_a_picture_is_fetched_once_for_everybody_who_loads_it(): void
    {
        $image = 'https://example.com/p.png';
        $this->network->replies = [$image => new Reply(200, ['content-type' => 'image/png'], base64_decode(self::PNG))];

        $this->get(LinkCards::imageUrl($image))->assertOk();
        $this->get(LinkCards::imageUrl($image))->assertOk();

        $this->assertCount(1, $this->network->connections);
    }

    public function test_a_request_that_waits_too_long_for_another_fetch_gets_no_card_and_caches_nothing(): void
    {
        $this->network->replies = ['https://example.com/post' => self::html('<title>Fetched later</title>')];
        // A store whose first lock another request already holds.
        $busy = Mockery::mock(Lock::class);
        $busy->shouldReceive('block')->andThrow(new LockTimeoutException);
        $store = new class($busy) extends ArrayStore
        {
            public function __construct(private ?Lock $busy) {}

            public function lock($name, $seconds = 0, $owner = null)
            {
                [$lock, $this->busy] = [$this->busy, null];

                return $lock ?? parent::lock($name, $seconds, $owner);
            }
        };
        Cache::extend('busy-once', fn () => Cache::repository($store));
        config(['cache.stores.busy-once' => ['driver' => 'busy-once'], 'cache.default' => 'busy-once']);

        $this->assertNull($this->card('https://example.com/post'));
        $this->assertSame([], $this->network->connections);

        $this->assertSame('Fetched later', $this->card('https://example.com/post')['title']);
        $this->assertCount(1, $this->network->connections);
    }

    public function test_the_picture_endpoint_refuses_what_is_not_a_picture(): void
    {
        $this->network->replies = [
            'https://example.com/fake.png' => new Reply(200, ['content-type' => 'image/png'], '<html>not a picture</html>'),
            'https://example.com/p.svg' => new Reply(200, ['content-type' => 'image/svg+xml'], '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'),
        ];

        $this->get(LinkCards::imageUrl('https://example.com/fake.png'))->assertNotFound();
        $this->get(LinkCards::imageUrl('https://example.com/p.svg'))->assertNotFound();
    }

    public function test_the_picture_endpoint_refuses_a_picture_over_the_cap(): void
    {
        $big = base64_decode(self::PNG).str_repeat("\0", LinkCards::IMAGE_BYTES);
        $this->network->replies = ['https://example.com/big.png' => new Reply(200, ['content-type' => 'image/png'], $big)];

        $this->get(LinkCards::imageUrl('https://example.com/big.png'))->assertNotFound();
    }

    public function test_the_picture_endpoint_refuses_unsigned_urls_without_connecting(): void
    {
        $this->get('/api/v1/links/image?url='.rawurlencode('https://example.com/p.png').'&sig=nope')->assertForbidden();
        $this->get('/api/v1/links/image?url='.rawurlencode('https://example.com/p.png'))->assertForbidden();
        $this->assertSame([], $this->network->connections);
    }

    public function test_the_picture_endpoint_refuses_private_addresses(): void
    {
        $this->get(LinkCards::imageUrl('http://169.254.169.254/x.png'))->assertNotFound();
        $this->assertSame([], $this->network->connections);
    }

    public function test_card_requests_are_rate_limited_per_address(): void
    {
        $this->network->replies = ['https://example.com/post' => self::html('<title>T</title>')];

        for ($i = 0; $i < 60; $i++) {
            $this->getJson('/api/v1/links/card?url='.rawurlencode('https://example.com/post'))->assertOk();
        }
        $this->getJson('/api/v1/links/card?url='.rawurlencode('https://example.com/post'))->assertStatus(429);
    }
}
