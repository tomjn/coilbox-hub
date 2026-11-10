<?php

namespace Tests\Unit\Games;

use App\Domain\Games\Download;
use Tests\TestCase;

/** `lib/games/download.test.ts`. */
class DownloadTest extends TestCase
{
    public function test_the_three_kinds_match_the_check_constraint(): void
    {
        $this->assertSame(['rapid', 'url', 'github'], Download::KINDS);
    }

    public function test_an_empty_form_clears_the_download(): void
    {
        $this->assertSame(['ok' => true, 'download' => null], Download::parseDownload('', ''));
        $this->assertSame(['ok' => true, 'download' => null], Download::parseDownload('rapid', '   '));
    }

    public function test_a_rapid_tag_is_game_colon_branch(): void
    {
        $this->assertSame(['ok' => true, 'download' => ['kind' => 'rapid', 'value' => 'metalfactions:stable']], Download::parseDownload('rapid', 'metalfactions:stable'));
        $this->assertFalse(Download::parseDownload('rapid', 'metalfactions')['ok']);
        $this->assertFalse(Download::parseDownload('rapid', 'a:b:c')['ok']);
    }

    public function test_an_address_must_be_http_or_https(): void
    {
        $this->assertSame(
            ['ok' => true, 'download' => ['kind' => 'url', 'value' => 'https://example.test/game.sdz', 'filename' => 'game.sdz']],
            Download::parseDownload('url', 'https://example.test/game.sdz', '', 'game.sdz'),
        );
        $this->assertFalse(Download::parseDownload('url', 'ftp://example.test/game.sdz', '', 'game.sdz')['ok']);
        $this->assertFalse(Download::parseDownload('url', 'not an address', '', 'game.sdz')['ok']);
        $this->assertFalse(Download::parseDownload('url', 'javascript:alert(1)', '', 'game.sdz')['ok']);
    }

    public function test_an_address_without_a_usable_filename_is_refused(): void
    {
        $this->assertFalse(Download::parseDownload('url', 'https://example.test/download?id=5')['ok']);
        $this->assertFalse(Download::parseDownload('url', 'https://example.test/g.sdz', '', 'maps/g.sdz')['ok']);
        $this->assertFalse(Download::parseDownload('url', 'https://example.test/g.sdz', '', 'two words.sdz')['ok']);
    }

    public function test_a_github_value_is_owner_slash_repo_and_the_asset_is_optional(): void
    {
        $this->assertSame(
            ['ok' => true, 'download' => ['kind' => 'github', 'value' => 'Balanced-Annihilation/Balanced-Annihilation']],
            Download::parseDownload('github', 'Balanced-Annihilation/Balanced-Annihilation'),
        );
        $this->assertSame(
            ['ok' => true, 'download' => ['kind' => 'github', 'value' => 'FluidPlay/TAP', 'asset' => 'TAP_v4']],
            Download::parseDownload('github', 'FluidPlay/TAP', 'TAP_v4'),
        );
        $this->assertFalse(Download::parseDownload('github', 'https://github.com/owner/repo')['ok']);
        $this->assertFalse(Download::parseDownload('github', 'owner')['ok']);
        $this->assertFalse(Download::parseDownload('github', 'owner/repo/extra')['ok']);
    }

    public function test_a_detail_belonging_to_another_kind_is_dropped(): void
    {
        $this->assertSame(['ok' => true, 'download' => ['kind' => 'rapid', 'value' => 'ba:stable']], Download::parseDownload('rapid', 'ba:stable', 'some-asset', 'some.sdz'));
        $this->assertSame(['ok' => true, 'download' => ['kind' => 'github', 'value' => 'owner/repo']], Download::parseDownload('github', 'owner/repo', '', 'some.sdz'));
    }

    public function test_an_unknown_kind_and_overlong_values_are_refused(): void
    {
        $this->assertFalse(Download::parseDownload('torrent', 'whatever')['ok']);
        $this->assertFalse(Download::parseDownload('url', 'https://example.test/'.str_repeat('a', 600), '', 'g.sdz')['ok']);
        $this->assertFalse(Download::parseDownload('github', 'owner/repo', str_repeat('a', 300))['ok']);
    }

    public function test_a_list_keeps_its_order_drops_empty_rows_and_names_a_bad_row(): void
    {
        $this->assertSame(
            ['ok' => true, 'downloads' => [['kind' => 'github', 'value' => 'a/b'], ['kind' => 'rapid', 'value' => 'x:y']]],
            Download::parseDownloads(json_encode([['kind' => 'github', 'value' => 'a/b'], ['kind' => 'rapid', 'value' => '  '], ['kind' => 'rapid', 'value' => 'x:y']])),
        );
        $refused = Download::parseDownloads(json_encode([['kind' => 'rapid', 'value' => 'x:y'], ['kind' => 'rapid', 'value' => 'bad']]));
        $this->assertFalse($refused['ok']);
        $this->assertStringStartsWith('Source 2:', $refused['message']);
    }

    public function test_a_list_that_is_not_a_list_or_is_too_long_is_refused(): void
    {
        $this->assertFalse(Download::parseDownloads('not json')['ok']);
        $this->assertFalse(Download::parseDownloads('{"kind":"rapid"}')['ok']);
        $this->assertTrue(Download::parseDownloads('')['ok']);
        $tooMany = array_fill(0, Download::MAX_DOWNLOADS + 1, ['kind' => 'rapid', 'value' => 'a:b']);
        $this->assertFalse(Download::parseDownloads(json_encode($tooMany))['ok']);
    }

    public function test_reading_stored_sources_is_not_the_same_job_as_writing_them(): void
    {
        $this->assertSame([['kind' => 'url', 'value' => 'https://example.test/g.sdz']], Download::readDownloads([['kind' => 'url', 'value' => 'https://example.test/g.sdz']]));
        $this->assertSame([['kind' => 'rapid', 'value' => 'a:b']], Download::readDownloads([['kind' => 'torrent', 'value' => 'x'], ['kind' => 'rapid', 'value' => ' a:b ', 'asset' => null], 'junk', ['kind' => 'url', 'value' => '']]));
        $this->assertSame([], Download::readDownloads(null));
    }

    public function test_only_two_of_the_three_kinds_have_somewhere_to_click(): void
    {
        $this->assertSame('https://example.test/g.sdz', Download::downloadHref(['kind' => 'url', 'value' => 'https://example.test/g.sdz']));
        $this->assertSame('https://github.com/owner/repo/releases', Download::downloadHref(['kind' => 'github', 'value' => 'owner/repo']));
        $this->assertNull(Download::downloadHref(['kind' => 'rapid', 'value' => 'ba:stable']));
    }
}
