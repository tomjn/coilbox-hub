<?php

namespace Tests\Feature\Gallery;

use Tests\TestCase;

/** The sentence at the top of `/profile/<name>`. */
class ProfilePageTest extends TestCase
{
    private function summary(array $counts): string
    {
        $kind = fn (string $name) => ['count' => $counts[$name] ?? 0, 'recent' => []];
        $html = view('gallery.profile', [
            'title' => 'Zyx',
            'accountId' => 'c0000000-0000-0000-0000-000000000001',
            'contributions' => [
                'name' => 'Zyx',
                'maps' => $kind('maps') + ['pictures' => []],
                'pictures' => ['count' => $counts['pictures'] ?? 0],
                'games' => $kind('games'),
                'items' => $kind('items') + ['cards' => ['pictures' => [], 'shapes' => [], 'counts' => [], 'titles' => []]],
            ],
            'points' => 0,
            'own' => false,
            'origin' => 'https://hub.test',
        ])->render();
        preg_match('/<p class="text-lg[^"]*">(.*?)<\/p>/s', $html, $match);

        return trim(strip_tags($match[1] ?? ''));
    }

    public function test_each_clause_reads_on_its_own(): void
    {
        $this->assertSame(
            'Zyx owns 7 maps and 1 game, has had 489 pictures approved and has published 13 things in the gallery.',
            $this->summary(['maps' => 7, 'games' => 1, 'pictures' => 489, 'items' => 13]),
        );
        $this->assertSame('Zyx owns 1 map.', $this->summary(['maps' => 1]));
        $this->assertSame('Zyx has had 2 pictures approved.', $this->summary(['pictures' => 2]));
        $this->assertSame('Zyx has not added anything to the hub yet.', $this->summary([]));
    }
}
