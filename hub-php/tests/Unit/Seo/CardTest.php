<?php

namespace Tests\Unit\Seo;

use App\Domain\Og\Card;
use App\Domain\Og\Cards;
use App\Domain\Og\Path;
use Tests\TestCase;

class CardTest extends TestCase
{
    /** @return array{0: int, 1: int} */
    private function dimensions(string $png): array
    {
        $info = getimagesizefromstring($png);

        return [$info[0], $info[1]];
    }

    public function test_cards_are_1200_by_630_pngs(): void
    {
        $this->assertSame([1200, 630], $this->dimensions(Card::home()));
        $this->assertSame([1200, 630], $this->dimensions(Card::standard('Blueprint', 'Solar Plaza', 'Zero-K  ·  Comet', 'by AF')));
    }

    public function test_the_background_is_the_cards_dark_colour(): void
    {
        $image = imagecreatefromstring(Card::standard('Map', 'Title', '', 'Coilbox Hub'));
        $this->assertSame(0x0B0B0D, imagecolorat($image, 1199, 629));
    }

    public function test_a_logo_is_drawn_left_of_the_title(): void
    {
        $red = imagecreatetruecolor(64, 64);
        imagefill($red, 0, 0, imagecolorallocate($red, 255, 0, 0));
        ob_start();
        imagepng($red);
        $logo = (string) ob_get_clean();

        $image = imagecreatefromstring(Card::standard('Game', 'Title', '', 'Coilbox Hub', $logo));
        // The middle of the 128 pixel box, which starts at the padding.
        $this->assertSame(0xFF0000, imagecolorat($image, 72 + 64, 315));
    }

    public function test_unreadable_logo_bytes_draw_the_card_without_one(): void
    {
        $this->assertSame([1200, 630], $this->dimensions(Card::standard('Game', 'Title', '', 'Coilbox Hub', 'not an image')));
    }

    public function test_a_long_title_is_cut_at_80_characters(): void
    {
        $this->assertSame(str_repeat('a', 80).'…', Card::shorten(str_repeat('a', 81)));
        $this->assertSame(str_repeat('a', 80), Card::shorten(str_repeat('a', 80)));
    }

    public function test_titles_wrap_at_spaces_within_the_width(): void
    {
        $lines = Card::wrap('A very long title that will not fit on one line of the card at all', 68, 1056);
        $this->assertGreaterThan(1, count($lines));
        $this->assertSame('A very long title that will not fit on one line of the card at all', implode(' ', $lines));
    }

    public function test_one_word_wider_than_the_card_is_broken_inside(): void
    {
        $lines = Card::wrap(str_repeat('W', 40), 68, 1056);
        $this->assertGreaterThan(1, count($lines));
        $this->assertSame(str_repeat('W', 40), implode('', $lines));
    }

    public function test_the_coil_path_flattens_from_its_start_to_its_end(): void
    {
        $points = Path::flatten('M12 3 18.62 7.39A1.3 1.3 0 0 1 19.2 8.56L11.34 10.4');
        $this->assertSame([12.0, 3.0], $points[0]);
        $this->assertSame([18.62, 7.39], $points[1]);
        $this->assertEqualsWithDelta(19.2, $points[count($points) - 2][0], 1e-9);
        $this->assertEqualsWithDelta(8.56, $points[count($points) - 2][1], 1e-9);
        $this->assertSame([11.34, 10.4], end($points));
    }

    public function test_each_page_links_the_card_nearest_it_in_the_route_tree(): void
    {
        config(['app.url' => 'https://hub.test']);
        $this->assertSame('https://hub.test/opengraph-image', Cards::urlFor('/'));
        $this->assertSame('https://hub.test/opengraph-image', Cards::urlFor('gallery'));
        $this->assertSame('https://hub.test/opengraph-image', Cards::urlFor('games'));
        $this->assertSame('https://hub.test/item/abc/opengraph-image', Cards::urlFor('item/abc/withdrawn'));
        $this->assertSame('https://hub.test/map/seed-map/opengraph-image', Cards::urlFor('map/seed-map'));
        $this->assertSame('https://hub.test/games/BYAR/opengraph-image', Cards::urlFor('games/BYAR/units/armcom'));
    }
}
