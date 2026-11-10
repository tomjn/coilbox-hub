<?php

namespace Tests\Unit\Seo;

use App\Domain\Seo\Robots;
use PHPUnit\Framework\TestCase;

/** Ported from `lib/robots.test.ts`, without the preview cases. */
class RobotsTest extends TestCase
{
    private const ORIGIN = 'https://hub.example';

    /** @return list<string> the first group's disallow lines */
    private function disallowed(): array
    {
        $first = explode("\n\n", Robots::text(self::ORIGIN))[0];
        preg_match_all('/^Disallow: (.*)$/m', $first, $m);

        return $m[1];
    }

    public function test_crawlers_are_kept_out_of_the_private_and_utility_routes(): void
    {
        $out = $this->disallowed();
        foreach (['/moderation', '/ops', '/account', '/publish', '/auth', '/api', '/dev', '/i/', '/export', '/item/*/edit', '/games/*/edit', '/games/*/units/*/compare?with=', '/games/*/units/*@'] as $path) {
            $this->assertContains($path, $out);
        }
    }

    public function test_the_public_pages_are_allowed_and_the_sitemap_named(): void
    {
        $text = Robots::text(self::ORIGIN);
        $this->assertStringStartsWith("User-Agent: *\nAllow: /\n", $text);
        $this->assertStringEndsWith("\nSitemap: https://hub.example/sitemap.xml\n", $text);
        $this->assertNotContains('/', $this->disallowed());
        $this->assertNotContains('/developers', $this->disallowed());
    }

    public function test_ai_training_crawlers_are_turned_away_from_the_whole_site(): void
    {
        $group = explode("\n\n", Robots::text(self::ORIGIN))[1];
        foreach (['GPTBot', 'ClaudeBot', 'CCBot'] as $agent) {
            $this->assertStringContainsString('User-Agent: '.$agent."\n", $group);
        }
        $this->assertStringEndsWith("\nDisallow: /", $group);
    }
}
