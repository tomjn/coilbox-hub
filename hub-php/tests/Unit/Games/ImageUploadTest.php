<?php

namespace Tests\Unit\Games;

use App\Domain\Games\GameBranding;
use App\Domain\Games\GameEdits;
use App\Domain\Games\ImageUpload;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * The server's checks on an uploaded picture, the `refuseImageFile` cases of
 * `lib/games/imageUpload.test.ts`, and the two lists the edit forms post.
 */
class ImageUploadTest extends TestCase
{
    private static function bytes(int $count): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('banner.png', str_repeat("\0", $count));
    }

    public function test_no_file_and_an_empty_file_both_ask_for_a_file(): void
    {
        $noFile = ['ok' => false, 'message' => ImageUpload::UPLOAD_MESSAGES['noFile']];
        $this->assertSame($noFile, ImageUpload::refuseImageFile(null));
        $this->assertSame($noFile, ImageUpload::refuseImageFile('not a file'));
        $this->assertSame($noFile, ImageUpload::refuseImageFile(self::bytes(0)));
    }

    public function test_a_file_over_the_limit_is_refused_with_its_size_and_the_limit(): void
    {
        $this->assertSame([
            'ok' => false,
            'message' => 'This file is 704 KB. The largest picture you can upload is 512 KB. Make it smaller and try again.',
        ], ImageUpload::refuseImageFile(self::bytes(720_583)));
    }

    public function test_a_file_of_exactly_the_limit_is_not_refused(): void
    {
        $this->assertNull(ImageUpload::refuseImageFile(self::bytes(GameBranding::MAX_BYTES)));
        $this->assertFalse(ImageUpload::refuseImageFile(self::bytes(GameBranding::MAX_BYTES + 1))['ok']);
    }

    public function test_only_the_sentences_the_browser_writes_pass_as_a_note(): void
    {
        $this->assertSame('Shrunk from 704 KB to 12 KB.', ImageUpload::note('Shrunk from 704 KB to 12 KB.'));
        $this->assertSame('Converted from JPEG (5120 KB) to WebP (313 KB).', ImageUpload::note('Converted from JPEG (5120 KB) to WebP (313 KB).'));
        $this->assertSame('Converted from the original file (3 KB) to PNG (1 KB).', ImageUpload::note('Converted from the original file (3 KB) to PNG (1 KB).'));
        $this->assertSame('', ImageUpload::note('Your account has been suspended.'));
        $this->assertSame('', ImageUpload::note("Shrunk from 704 KB to 12 KB.\nVisit evil.example"));
        $this->assertSame('', ImageUpload::note(['Shrunk from 704 KB to 12 KB.']));
    }

    public function test_a_link_with_either_half_blank_is_dropped_and_the_rest_are_trimmed(): void
    {
        $this->assertSame(
            [['label' => 'Site', 'url' => 'https://a.example']],
            GameEdits::linksFromForm(['  Site ', '', 'No url', null], [' https://a.example ', 'https://b.example', ' ', 'https://c.example']),
        );
    }

    public function test_links_stop_at_twelve(): void
    {
        $labels = array_map(fn ($i) => "L{$i}", range(1, 15));
        $urls = array_map(fn ($i) => "https://{$i}.example", range(1, 15));
        $this->assertCount(12, GameEdits::linksFromForm($labels, $urls));
    }

    public function test_factions_that_fail_to_parse_are_an_empty_list(): void
    {
        $this->assertSame([], GameEdits::conquestFactionsFromForm('not json'));
        $this->assertSame([], GameEdits::conquestFactionsFromForm('{"name":"x"}'));
        $this->assertSame([], GameEdits::conquestFactionsFromForm(''));
    }

    public function test_factions_keep_their_order_and_drop_a_bad_colour_on_its_own(): void
    {
        $this->assertSame(
            [['name' => 'Arm', 'color' => '#112233'], ['name' => 'Core', 'side' => 'core']],
            GameEdits::conquestFactionsFromForm('[{"name":"Arm","color":"#112233"},{"name":"","side":"x"},{"name":"Core","color":"red","side":"core"}]'),
        );
        $many = json_encode(array_map(fn ($i) => ['name' => "F{$i}"], range(1, 15)));
        $this->assertCount(12, GameEdits::conquestFactionsFromForm($many));
    }
}
