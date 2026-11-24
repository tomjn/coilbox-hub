<?php

namespace App\Domain\Links;

use DOMDocument;
use DOMElement;

/** The tags in a page's `<head>` that a link card is built from. */
final readonly class PageTags
{
    /** @param array<string, string> $meta First value of each `<meta>` property or name, keys in lower case. */
    public function __construct(
        public array $meta,
        public ?string $title,
        public ?string $oembed,
        public ?string $canonical,
    ) {}

    public static function parse(string $html, ?string $contentType): self
    {
        $html = self::toUtf8($html, $contentType);

        $doc = new DOMDocument;
        $errors = libxml_use_internal_errors(true);
        // The encoding hint makes libxml read the page as UTF-8.
        $doc->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($errors);

        $meta = [];
        foreach ($doc->getElementsByTagName('meta') as $tag) {
            /** @var DOMElement $tag */
            $key = strtolower(trim($tag->getAttribute('property') ?: $tag->getAttribute('name')));
            $content = $tag->getAttribute('content');
            if ($key !== '' && $content !== '' && ! isset($meta[$key])) {
                $meta[$key] = $content;
            }
        }

        $oembed = null;
        $canonical = null;
        foreach ($doc->getElementsByTagName('link') as $tag) {
            /** @var DOMElement $tag */
            $rel = preg_split('/\s+/', strtolower(trim($tag->getAttribute('rel')))) ?: [];
            $href = trim($tag->getAttribute('href'));
            if ($href === '') {
                continue;
            }
            if ($oembed === null && in_array('alternate', $rel, true) && strtolower(trim($tag->getAttribute('type'))) === 'application/json+oembed') {
                $oembed = $href;
            }
            if ($canonical === null && in_array('canonical', $rel, true)) {
                $canonical = $href;
            }
        }

        $titles = $doc->getElementsByTagName('title');
        $title = $titles->length > 0 ? $titles->item(0)?->textContent : null;

        return new self($meta, $title, $oembed, $canonical);
    }

    /** Pages that say they are in another character set are converted from it. */
    private static function toUtf8(string $html, ?string $contentType): string
    {
        $charset = null;
        if ($contentType !== null && preg_match('/charset\s*=\s*["\']?([\w.:-]+)/i', $contentType, $match) === 1) {
            $charset = $match[1];
        } elseif (preg_match('/<meta[^>]+charset\s*=\s*["\']?([\w.:-]+)/i', $html, $match) === 1) {
            $charset = $match[1];
        }

        if ($charset !== null && strcasecmp($charset, 'utf-8') !== 0 && in_array(strtolower($charset), array_map('strtolower', mb_list_encodings()), true)) {
            return mb_convert_encoding($html, 'UTF-8', $charset);
        }

        // A capped read can end partway through a character.
        return mb_scrub($html, 'UTF-8');
    }
}
