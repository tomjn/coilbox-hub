<?php

namespace App\Domain\Seo;

/**
 * What `robots.txt` says, from `lib/robots.ts`. The Next app disallowed
 * everything on a preview deployment. There are none here, so that branch
 * is gone.
 */
final class Robots
{
    /**
     * Pages a crawler gains nothing from, or that are not for it. `/i/` is the
     * raw container an import link fetches, `/export` is the whole gallery as
     * one file, and the edit pages need a sign in. A unit compared with another
     * is one page for every pair of units, a crawl with no end. A unit at a
     * past release is one page per unit per release, and each names the latest
     * unit page as its canonical.
     */
    public const PRIVATE_PATHS = [
        '/moderation',
        '/ops',
        '/account',
        '/publish',
        '/auth',
        '/api',
        '/dev',
        '/i/',
        '/export',
        '/item/*/edit',
        '/games/*/edit',
        '/games/*/units/*/compare?with=',
        '/games/*/units/*@',
    ];

    /** Crawlers that gather pages to train AI models. Each visit renders a page, and none send visitors back. */
    public const AI_CRAWLERS = [
        'GPTBot',
        'ClaudeBot',
        'CCBot',
        'Bytespider',
        'meta-externalagent',
        'Amazonbot',
        'PerplexityBot',
    ];

    /** The file as Next's `MetadataRoute.Robots` serialiser writes it. */
    public static function text(string $origin): string
    {
        $lines = ['User-Agent: *', 'Allow: /'];
        foreach (self::PRIVATE_PATHS as $path) {
            $lines[] = 'Disallow: '.$path;
        }
        $lines[] = '';
        foreach (self::AI_CRAWLERS as $agent) {
            $lines[] = 'User-Agent: '.$agent;
        }
        $lines[] = 'Disallow: /';
        $lines[] = '';
        $lines[] = 'Sitemap: '.$origin.'/sitemap.xml';

        return implode("\n", $lines)."\n";
    }
}
