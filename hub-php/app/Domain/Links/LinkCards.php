<?php

namespace App\Domain\Links;

use App\Support\HubCache;
use App\Support\Site;
use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;

/**
 * Preview cards for links posted in Coilbox chat (#654). The hub fetches the
 * page so the linked site never sees a player's address. Each field comes
 * from oEmbed first, then Open Graph, then Twitter Card tags, then the page
 * `<title>`. The oEmbed `html` field is never read: Coilbox shows a card as a
 * link and runs no markup from another site.
 */
final class LinkCards
{
    public const MAX_URL_LENGTH = 2048;

    /** YouTube's `</head>` ends 0.86 MB in, after its scripts. */
    public const PAGE_BYTES = 2 * 1024 * 1024;

    public const OEMBED_BYTES = 64 * 1024;

    public const IMAGE_BYTES = 3 * 1024 * 1024;

    /** Pictures are bigger than cards, so they are kept for less time. */
    public const IMAGE_SECONDS = 600;

    /** The slowest card: four page hops and four oEmbed hops, each up to {@see CurlNetwork::TOTAL_SECONDS}. */
    public const LOCK_SECONDS = 8 * CurlNetwork::TOTAL_SECONDS;

    public const WAIT_SECONDS = 10;

    public const TYPES = ['video', 'photo', 'rich', 'link'];

    /** Formats Coilbox can draw. SVG is left out because it can carry script. */
    public const IMAGE_TYPES = ['image/png', 'image/jpeg', 'image/gif', 'image/webp'];

    public function __construct(private readonly SafeFetch $fetch) {}

    /**
     * The card for a URL, or null for no card. Both are cached, so a link
     * posted in a busy channel is fetched once.
     *
     * @return array{url: string, type: ?string, site_name: ?string, title: string, description: ?string, image: ?string}|null
     */
    public function card(string $url): ?array
    {
        $url = self::withoutFragment(trim($url));

        return self::shared('link-card:'.$url, HubCache::HOURS, fn () => $this->build($url));
    }

    /**
     * Cached, and loaded by one request at a time. Everybody in a channel
     * asks for a new link's card and picture at once, so without the lock
     * each of them would miss the cache and fetch it. A request that waits
     * longer than {@see WAIT_SECONDS} gets null, which is not cached.
     *
     * @template T
     *
     * @param  Closure(): T  $load
     * @return T|null
     */
    private static function shared(string $key, int $seconds, Closure $load): mixed
    {
        try {
            return HubCache::remember($key, [], $seconds, fn () => Cache::lock('lock:'.$key, self::LOCK_SECONDS)
                ->block(self::WAIT_SECONDS, fn () => HubCache::remember($key, [], $seconds, $load)));
        } catch (LockTimeoutException) {
            return null;
        }
    }

    /** @return array{url: string, type: ?string, site_name: ?string, title: string, description: ?string, image: ?string}|null */
    private function build(string $url): ?array
    {
        $page = $this->fetch->get(
            $url,
            'text/html,application/xhtml+xml;q=0.9,*/*;q=0.1',
            self::PAGE_BYTES,
            fn (string $body) => stripos($body, '</head>') !== false,
        );
        if ($page === null) {
            return null;
        }
        $contentType = strtolower((string) $page['reply']->header('content-type'));
        if (! str_contains($contentType, 'text/html') && ! str_contains($contentType, 'application/xhtml+xml')) {
            return null;
        }

        $pageUrl = $page['url'];
        $tags = PageTags::parse($page['reply']->body, $contentType);
        $meta = $tags->meta;
        $oembed = $tags->oembed !== null ? $this->oembed(Urls::resolve($pageUrl, $tags->oembed)) : [];

        $title = self::text($oembed['title'] ?? null, 300)
            ?? self::text($meta['og:title'] ?? null, 300)
            ?? self::text($meta['twitter:title'] ?? null, 300)
            ?? self::text($tags->title, 300);
        if ($title === null) {
            return null;
        }

        $image = self::firstUrl($pageUrl, [
            $oembed['thumbnail_url'] ?? null,
            $meta['og:image:secure_url'] ?? null,
            $meta['og:image'] ?? null,
            $meta['og:image:url'] ?? null,
            $meta['twitter:image'] ?? null,
            $meta['twitter:image:src'] ?? null,
        ]);
        $type = is_string($oembed['type'] ?? null) && in_array($oembed['type'], self::TYPES, true) ? $oembed['type'] : null;

        return [
            'url' => self::firstUrl($pageUrl, [$meta['og:url'] ?? null, $tags->canonical]) ?? $pageUrl,
            'type' => $type,
            'site_name' => self::text($oembed['provider_name'] ?? null, 100)
                ?? self::text($meta['og:site_name'] ?? null, 100)
                ?? self::text(preg_replace('/^www\./', '', (string) parse_url($pageUrl, PHP_URL_HOST)), 100),
            'title' => $title,
            'description' => self::text($meta['og:description'] ?? null, 500)
                ?? self::text($meta['twitter:description'] ?? null, 500)
                ?? self::text($meta['description'] ?? null, 500),
            'image' => $image !== null ? self::imageUrl($image) : null,
        ];
    }

    /** @return array<string, mixed> */
    private function oembed(?string $url): array
    {
        if ($url === null) {
            return [];
        }
        $fetched = $this->fetch->get($url, 'application/json', self::OEMBED_BYTES);
        if ($fetched === null || $fetched['reply']->truncated) {
            return [];
        }
        $data = json_decode($fetched['reply']->body, true);

        return is_array($data) ? $data : [];
    }

    /** The hub address Coilbox loads a card's picture from. */
    public static function imageUrl(string $image): string
    {
        return Site::absolute('/api/v1/links/image').'?'.http_build_query(['url' => $image, 'sig' => self::sign($image)]);
    }

    /** Signed so the picture proxy only serves pictures the hub put on a card. */
    public static function sign(string $image): string
    {
        return substr(hash_hmac('sha256', 'link-image|'.$image, (string) config('app.key')), 0, 32);
    }

    /**
     * The picture's bytes and type, or null when it cannot be fetched, is not
     * a picture in a format Coilbox draws, or is bigger than the cap.
     *
     * @return array{bytes: string, type: string}|null
     */
    public function image(string $url): ?array
    {
        return self::shared('link-image:'.$url, self::IMAGE_SECONDS, fn () => $this->fetchImage($url));
    }

    /** @return array{bytes: string, type: string}|null */
    private function fetchImage(string $url): ?array
    {
        $fetched = $this->fetch->get($url, 'image/*', self::IMAGE_BYTES);
        if ($fetched === null || $fetched['reply']->truncated) {
            return null;
        }
        $bytes = $fetched['reply']->body;
        $info = @getimagesizefromstring($bytes);
        if ($info === false || ! in_array($info['mime'], self::IMAGE_TYPES, true)) {
            return null;
        }

        return ['bytes' => $bytes, 'type' => $info['mime']];
    }

    /** @param list<mixed> $candidates */
    private static function firstUrl(string $base, array $candidates): ?string
    {
        foreach ($candidates as $candidate) {
            if (is_string($candidate) && ($url = Urls::resolve($base, $candidate)) !== null && strlen($url) <= self::MAX_URL_LENGTH) {
                return $url;
            }
        }

        return null;
    }

    private static function text(mixed $value, int $max): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $text = trim((string) preg_replace('/\s+/u', ' ', html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
        if ($text === '') {
            return null;
        }

        return mb_strlen($text) > $max ? rtrim(mb_substr($text, 0, $max - 1)).'…' : $text;
    }

    private static function withoutFragment(string $url): string
    {
        $hash = strpos($url, '#');

        return $hash === false ? $url : substr($url, 0, $hash);
    }
}
