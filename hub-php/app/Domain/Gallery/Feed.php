<?php

namespace App\Domain\Gallery;

use App\Domain\Container\Container;
use App\Supabase\Supabase;
use App\Support\HubCache;
use App\Support\Site;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * The gallery's Atom feed, from the Next app's `lib/feed/atom.ts`,
 * `lib/feed/gallery.ts` and `lib/feed/cached.ts`. Strings are built by hand
 * so the document is exactly what the Next app wrote.
 */
final class Feed
{
    /** How many entries a feed holds. */
    public const ENTRIES = 50;

    public const COLUMNS = 'id,kind,mode,title,description,author_name,created_at,updated_at';

    /** A feed with nothing in it is dated at the epoch rather than now, so it never looks freshly changed. */
    private const EMPTY_FEED_TIME = '1970-01-01T00:00:00.000Z';

    /** Characters XML 1.0 forbids. Removed, since an escaped one is still not allowed. */
    private const ILLEGAL_XML = '/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u';

    /** @return array{ok: true, kind: string|null}|array{ok: false, error: string} */
    public static function parseKind(?string $value): array
    {
        if ($value === null || $value === '') {
            return ['ok' => true, 'kind' => null];
        }
        if (! in_array($value, Container::GALLERY_KINDS, true)) {
            return ['ok' => false, 'error' => 'Unknown kind. Use one of: '.implode(', ', Container::GALLERY_KINDS).'.'];
        }

        return ['ok' => true, 'kind' => $value];
    }

    /** The newest entries, held for hours and refreshed when anything is published. */
    public static function cached(?string $kind): string
    {
        return HubCache::remember('feed:'.($kind ?? ''), [HubCache::ITEMS], HubCache::HOURS, function () use ($kind) {
            $query = Supabase::anon()->from('item')->select(self::COLUMNS);
            if ($kind !== null) {
                $query->eq('kind', $kind);
            }
            $rows = $query->order('created_at', ascending: false)->limit(self::ENTRIES)->get();

            return self::gallery($rows, $kind, Site::url());
        });
    }

    /** @param list<array<string, mixed>> $rows */
    public static function gallery(array $rows, ?string $kind, string $origin): string
    {
        $held = array_slice($rows, 0, self::ENTRIES);
        $query = $kind !== null ? '?kind='.$kind : '';
        $newest = null;
        foreach ($held as $row) {
            $at = self::millis($row['updated_at']);
            if ($at !== null && ($newest === null || $at > $newest)) {
                $newest = $at;
            }
        }

        return self::document([
            'id' => $origin.'/feed.xml'.$query,
            'title' => $kind !== null ? 'Coilbox Hub: '.Labels::kindPlural($kind) : 'Coilbox Hub',
            'subtitle' => $kind !== null ? 'New '.mb_strtolower(Labels::kindPlural($kind)).' published on Coilbox Hub.' : 'New things people have published on Coilbox Hub.',
            'selfUrl' => $origin.'/feed.xml'.$query,
            'alternateUrl' => $origin.'/gallery'.$query,
            'updated' => $newest !== null ? self::isoFromMillis($newest) : self::EMPTY_FEED_TIME,
            'author' => 'Coilbox Hub',
            'entries' => array_map(fn ($row) => [
                'id' => 'urn:uuid:'.$row['id'],
                'title' => $row['title'],
                'link' => $origin.'/item/'.$row['id'],
                'published' => $row['created_at'],
                'updated' => $row['updated_at'],
                'author' => $row['author_name'],
                'summary' => $row['description'],
                'category' => ['term' => $row['kind'], 'label' => Labels::item($row['kind'], $row['mode'])],
            ], $held),
        ]);
    }

    /** @param array<string, mixed> $feed */
    public static function document(array $feed): string
    {
        $lines = [
            '<?xml version="1.0" encoding="utf-8"?>',
            '<feed xmlns="http://www.w3.org/2005/Atom">',
            '<id>'.self::escapeText($feed['id']).'</id>',
            '<title type="text">'.self::escapeText($feed['title']).'</title>',
            '<subtitle type="text">'.self::escapeText($feed['subtitle']).'</subtitle>',
            '<updated>'.self::rfc3339($feed['updated']).'</updated>',
            '<link rel="self" type="application/atom+xml" href="'.self::escapeAttribute($feed['selfUrl']).'"/>',
            '<link rel="alternate" type="text/html" href="'.self::escapeAttribute($feed['alternateUrl']).'"/>',
            self::author($feed['author']),
        ];
        foreach ($feed['entries'] as $entry) {
            $lines[] = self::entry($entry);
        }
        $lines[] = '</feed>';

        return implode("\n", $lines)."\n";
    }

    public static function escapeText(string $text): string
    {
        return str_replace(['&', '<', '>', '"', "'"], ['&amp;', '&lt;', '&gt;', '&quot;', '&apos;'], self::legal($text));
    }

    public static function escapeAttribute(string $text): string
    {
        return str_replace(["\t", "\n", "\r"], ['&#9;', '&#10;', '&#13;'], self::escapeText($text));
    }

    /** A date as `Date.prototype.toISOString` writes it: UTC, milliseconds, `Z`. */
    public static function rfc3339(string $value): string
    {
        $millis = self::millis($value);
        if ($millis === null) {
            throw new InvalidArgumentException('Not a date: '.$value);
        }

        return self::isoFromMillis($millis);
    }

    /** @param array<string, mixed> $item */
    private static function entry(array $item): string
    {
        $name = trim(self::legal($item['author'] ?? ''));
        $summary = trim(self::legal($item['summary'])) !== '' ? self::escapeText($item['summary']) : '';
        $parts = [
            '<entry>',
            '<id>'.self::escapeText($item['id']).'</id>',
            '<title type="text">'.self::escapeText($item['title']).'</title>',
            '<link rel="alternate" type="text/html" href="'.self::escapeAttribute($item['link']).'"/>',
            '<published>'.self::rfc3339($item['published']).'</published>',
            '<updated>'.self::rfc3339($item['updated']).'</updated>',
            $name !== '' ? self::author($name) : '',
            '<category term="'.self::escapeAttribute($item['category']['term']).'" label="'.self::escapeAttribute($item['category']['label']).'"/>',
            $summary !== '' ? '<summary type="text">'.$summary.'</summary>' : '',
            '</entry>',
        ];

        return implode("\n", array_filter($parts, fn ($part) => $part !== ''));
    }

    private static function author(string $name): string
    {
        return '<author><name>'.self::escapeText($name).'</name></author>';
    }

    private static function legal(string $text): string
    {
        return (string) preg_replace(self::ILLEGAL_XML, '', $text);
    }

    /** Milliseconds since the epoch, truncated as `Date` holds them, or null when it is not a date. */
    private static function millis(string $value): ?int
    {
        try {
            $date = new DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }

        return (int) $date->format('U') * 1000 + intdiv((int) $date->format('u'), 1000);
    }

    private static function isoFromMillis(int $millis): string
    {
        $seconds = intdiv($millis, 1000);
        $rest = $millis - $seconds * 1000;
        if ($rest < 0) {
            $seconds--;
            $rest += 1000;
        }

        return (new DateTimeImmutable('@'.$seconds))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s').'.'.str_pad((string) $rest, 3, '0', STR_PAD_LEFT).'Z';
    }
}
