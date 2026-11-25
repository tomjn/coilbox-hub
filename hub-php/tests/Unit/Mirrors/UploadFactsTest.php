<?php

namespace Tests\Unit\Mirrors;

use App\Domain\Mirrors\UploadFacts;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class UploadFactsTest extends TestCase
{
    private static function map(): array
    {
        return [
            'sha256' => str_repeat('ab', 32),
            'size' => 1234,
            'filename' => 'Test Map v1.sd7',
            'kind' => 'map',
            'internal_name' => '  Test Map 1.0  ',
            'version' => '1.0',
            'author' => 'Someone',
            'description' => 'A map',
        ];
    }

    private static function game(): array
    {
        return [
            'sha256' => str_repeat('01', 32),
            'size' => 99,
            'filename' => 'game.sdz',
            'kind' => 'game',
            'internal_name' => 'Test Game 1.0',
            'version' => '1.0',
            'shortname' => 'tg',
            'author' => null,
            'description' => null,
        ];
    }

    public function test_a_valid_map_is_normalised(): void
    {
        $this->assertSame([
            'sha256' => str_repeat('ab', 32),
            'size' => 1234,
            'filename' => 'Test Map v1.sd7',
            'kind' => 'map',
            'internal_name' => 'Test Map 1.0',
            'version' => '1.0',
            'shortname' => null,
            'author' => 'Someone',
            'description' => 'A map',
            'platform' => '',
        ], UploadFacts::parse(self::map()));
    }

    public function test_a_valid_game_keeps_its_shortname(): void
    {
        $this->assertSame([...self::game(), 'platform' => ''], UploadFacts::parse(self::game()));
    }

    private static function engine(): array
    {
        return [
            'sha256' => str_repeat('cd', 32),
            'size' => 5000,
            'filename' => 'recoil_2026.09.03_arm64-macos.7z',
            'kind' => 'engine',
            'version' => '2026.09.03',
            'platform' => 'arm64-macos',
            'note' => 'Tests the Metal path.',
        ];
    }

    public function test_an_engine_is_accepted_with_internal_name_recoil_and_the_note_as_description(): void
    {
        $facts = UploadFacts::parse(self::engine());

        $this->assertIsArray($facts);
        $this->assertSame('engine', $facts['kind']);
        $this->assertSame('recoil', $facts['internal_name']);
        $this->assertNull($facts['shortname']);
        $this->assertNull($facts['author']);
        $this->assertSame('Tests the Metal path.', $facts['description']);
        $this->assertSame('2026.09.03', $facts['version']);
        $this->assertSame('arm64-macos', $facts['platform']);
        $this->assertSame('recoil_2026.09.03_arm64-macos.7z', $facts['filename']);
    }

    public function test_an_engine_filename_must_agree_with_its_version_and_platform(): void
    {
        $this->assertIsString(UploadFacts::parse([...self::engine(), 'version' => '2026.09.02']));
        $this->assertIsString(UploadFacts::parse([...self::engine(), 'platform' => 'amd64-linux']));
        $this->assertIsString(UploadFacts::parse([...self::engine(), 'filename' => 'engine.7z']));
        $this->assertIsString(UploadFacts::parse([...self::engine(), 'filename' => 'recoil_2026.09.03_arm64-macos.sd7']));
    }

    public function test_an_engine_note_is_required_and_at_most_1000_characters(): void
    {
        $this->assertIsString(UploadFacts::parse([...self::engine(), 'note' => '   ']));
        $this->assertIsString(UploadFacts::parse([...self::engine(), 'note' => null]));
        $this->assertIsString(UploadFacts::parse([...self::engine(), 'note' => str_repeat('a', 1001)]));
        $this->assertIsArray(UploadFacts::parse([...self::engine(), 'note' => str_repeat('a', 1000)]));
    }

    public function test_an_engine_needs_a_known_platform(): void
    {
        $this->assertIsString(UploadFacts::parse([...self::engine(), 'platform' => 'riscv-linux']));
        $this->assertIsString(UploadFacts::parse([...self::engine(), 'platform' => '']));
    }

    public function test_a_map_has_an_empty_platform(): void
    {
        $this->assertSame('', UploadFacts::parse([...self::map(), 'platform' => 'arm64-macos'])['platform']);
    }

    public function test_kind_message_names_all_three_kinds(): void
    {
        $this->assertSame('kind must be "map", "game" or "engine".', UploadFacts::parse([...self::map(), 'kind' => 'mod']));
    }

    /** @param  array<string, mixed>|string  $input */
    #[DataProvider('broken')]
    public function test_each_broken_rule_returns_a_message(mixed $input): void
    {
        $this->assertIsString(UploadFacts::parse($input));
    }

    public static function broken(): array
    {
        $map = self::map();
        $game = self::game();

        return [
            'not an object' => ['text'],
            'a list' => [[1, 2]],
            'sha256 missing' => [array_diff_key($map, ['sha256' => 1])],
            'sha256 uppercase' => [[...$map, 'sha256' => str_repeat('AB', 32)]],
            'sha256 short' => [[...$map, 'sha256' => 'ab']],
            'sha256 trailing newline' => [[...$map, 'sha256' => str_repeat('ab', 32)."\n"]],
            'size zero' => [[...$map, 'size' => 0]],
            'size float' => [[...$map, 'size' => 12.0]],
            'size string' => [[...$map, 'size' => '12']],
            'filename extension' => [[...$map, 'filename' => 'map.zip']],
            'filename slash' => [[...$map, 'filename' => 'a/b.sd7']],
            'filename not a string' => [[...$map, 'filename' => 5]],
            'kind unknown' => [[...$map, 'kind' => 'mod']],
            'internal_name blank' => [[...$map, 'internal_name' => '   ']],
            'internal_name too long' => [[...$map, 'internal_name' => str_repeat('a', 257)]],
            'internal_name not a string' => [[...$map, 'internal_name' => 5]],
            'version too long' => [[...$map, 'version' => str_repeat('a', 257)]],
            'version missing' => [array_diff_key($map, ['version' => 1])],
            'game without shortname' => [[...$game, 'shortname' => null]],
            'game shortname empty' => [[...$game, 'shortname' => '']],
            'game shortname too long' => [[...$game, 'shortname' => str_repeat('a', 65)]],
            'map shortname empty' => [[...$map, 'shortname' => '']],
            'author too long' => [[...$map, 'author' => str_repeat('a', 257)]],
            'author not a string' => [[...$map, 'author' => 5]],
            'description too long' => [[...$map, 'description' => str_repeat('a', 4001)]],
        ];
    }

    public function test_lengths_count_characters_not_bytes(): void
    {
        $this->assertIsArray(UploadFacts::parse([...self::map(), 'author' => str_repeat('é', 256)]));
        $this->assertIsArray(UploadFacts::parse([...self::map(), 'description' => str_repeat('é', 4000)]));
    }
}
