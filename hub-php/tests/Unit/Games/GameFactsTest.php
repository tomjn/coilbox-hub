<?php

namespace Tests\Unit\Games;

use App\Domain\Games\Download;
use App\Domain\Games\GameFacts;
use Tests\TestCase;

/**
 * `lib/api/gameFacts.test.ts`. Bodies are built as PHP, then sent through
 * JSON so they arrive as the controller hands them over, objects as `stdClass`.
 */
class GameFactsTest extends TestCase
{
    private const ABSENT = '__absent__';

    private function parse(array $overrides = []): array
    {
        $body = $overrides + ['format' => GameFacts::FORMAT, 'version' => 1, 'shortname' => 'BA', 'release' => '1.9.0', 'units' => [['name' => 'armcom']]];
        $body = array_filter($body, fn ($value) => $value !== self::ABSENT);

        return GameFacts::parse(json_decode(json_encode($body)));
    }

    private function refusal(array $overrides): string
    {
        $parsed = $this->parse($overrides);
        $this->assertFalse($parsed['ok']);

        return $parsed['error'];
    }

    public function test_a_well_formed_submission_parses_whole(): void
    {
        $parsed = $this->parse([
            'complete' => true,
            'startUnits' => ['armcom'],
            'factions' => [['key' => 'armada', 'name' => 'Armada']],
            'units' => [['name' => 'armcom', 'fullName' => 'Commander', 'factionKey' => 'armada', 'buildOptions' => ['armsolar', 'armsolar', 'armmex'], 'stats' => ['health' => 5000]]],
        ]);
        $this->assertTrue($parsed['ok']);
        $s = $parsed['submission'];
        $this->assertSame('BA', $s['shortname']);
        $this->assertSame('1.9.0', $s['release']);
        $this->assertTrue($s['complete']);
        $this->assertSame(['armcom'], $s['start_units']);
        $this->assertSame([['key' => 'armada', 'name' => 'Armada']], $s['factions']);
        $this->assertSame(['armmex', 'armsolar'], $s['units'][0]['build_options']);
    }

    public function test_the_envelope_is_named_before_anything_else(): void
    {
        $this->assertFalse($this->parse(['format' => 'coilbox-hub-maps'])['ok']);
        $this->assertFalse($this->parse(['version' => 2])['ok']);
        // JSON has one number type, so 1.0 is version 1, as it is in JavaScript.
        $this->assertTrue(GameFacts::parse(json_decode('{"format":"coilbox-hub-games","version":1.0,"shortname":"BA","release":"1","units":[]}'))['ok']);
        $this->assertSame(400, GameFacts::parse(json_decode('[]'))['status']);
    }

    public function test_an_unknown_field_anywhere_is_a_refusal_naming_it(): void
    {
        $this->assertStringContainsString('slug', $this->refusal(['slug' => 'ba']));
        $this->assertStringContainsString('full_name', $this->refusal(['units' => [['name' => 'armcom', 'full_name' => 'Commander']]]));
        $this->assertStringContainsString('logo', $this->refusal(['factions' => [['key' => 'armada', 'name' => 'Armada', 'logo' => 'x']]]));
        $this->assertStringContainsString('facts_digest', $this->refusal(['units' => [['name' => 'armcom', 'facts_digest' => 'd']]]));
    }

    public function test_absent_things_stay_absent(): void
    {
        $s = $this->parse()['submission'];
        $this->assertFalse($s['complete']);
        $this->assertNull($s['factions']);
        $this->assertNull($s['start_units']);
        $this->assertNull($s['display_name']);
        $this->assertNull($s['description']);
        $this->assertNull($s['base_game']);
        $this->assertNull($s['links']);
        $this->assertNull($s['downloads']);
    }

    public function test_blank_optional_text_arrives_as_null(): void
    {
        $parsed = $this->parse(['units' => [['name' => 'armcom', 'fullName' => ''], ['name' => 'armmex', 'fullName' => null]]]);
        foreach ($parsed['submission']['units'] as $unit) {
            $this->assertNull($unit['full_name']);
            $this->assertNull($unit['faction_key']);
        }
        $s = $this->parse(['name' => '  ', 'description' => ''])['submission'];
        $this->assertNull($s['display_name']);
        $this->assertNull($s['description']);
    }

    public function test_build_options_are_sorted_and_deduplicated(): void
    {
        $this->assertSame(['armmex', 'armsolar'], $this->parse(['units' => [['name' => 'armcom', 'buildOptions' => ['armmex', 'armsolar', 'armmex']]]])['submission']['units'][0]['build_options']);
    }

    public function test_stats_must_be_an_object_of_bounded_size(): void
    {
        $this->assertTrue($this->parse(['units' => [['name' => 'armcom', 'stats' => ['health' => 5000]]]])['ok']);
        $this->assertFalse($this->parse(['units' => [['name' => 'armcom', 'stats' => [1, 2]]]])['ok']);
        $this->assertFalse($this->parse(['units' => [['name' => 'armcom', 'stats' => ['blob' => str_repeat('x', 10_000)]]]])['ok']);
    }

    public function test_a_request_past_the_unit_cap_is_refused(): void
    {
        $units = array_map(fn ($i) => ['name' => 'u'.$i], range(0, GameFacts::MAX_UNITS));
        $parsed = $this->parse(['units' => $units]);
        $this->assertSame(413, $parsed['status']);
        $this->assertStringContainsString('at most', $parsed['error']);
    }

    public function test_morph_targets(): void
    {
        $parsed = $this->parse(['units' => [['name' => 'armcom', 'buildOptions' => [], 'stats' => (object) [], 'morphTargets' => [['into' => 'armcom1', 'morphtime' => 10]]]]]);
        $this->assertTrue($parsed['ok']);
        $this->assertEquals([(object) ['into' => 'armcom1', 'morphtime' => 10]], $parsed['submission']['units'][0]['morph_targets']);

        $this->assertSame([], $this->parse(['units' => [['name' => 'armcom', 'buildOptions' => [], 'stats' => (object) []]]])['submission']['units'][0]['morph_targets']);
        $this->assertStringContainsString('morphTargets', $this->refusal(['units' => [['name' => 'armcom', 'morphTargets' => [['morphtime' => 10]]]]]));
        $this->assertStringContainsString('morphTargets', $this->refusal(['units' => [['name' => 'armcom', 'morphTargets' => ['into' => 'armcom1']]]]));
        $this->assertStringContainsString('morphTargets', $this->refusal(['units' => [['name' => 'armcom', 'morphTargets' => ['armcom1']]]]));
        $this->assertStringContainsString('morphTargets', $this->refusal(['units' => [['name' => 'armcom', 'morphTargets' => [['into' => 'armcom1', 'blob' => str_repeat('x', 10_000)]]]]]));
    }

    public function test_name_and_description_are_trimmed_and_bounded(): void
    {
        $s = $this->parse(['name' => '  SplinterFaction  ', 'description' => ' A tribute to the Total Annihilation mod scene. '])['submission'];
        $this->assertSame('SplinterFaction', $s['display_name']);
        $this->assertSame('A tribute to the Total Annihilation mod scene.', $s['description']);
        $this->assertStringContainsString('name', $this->refusal(['name' => str_repeat('x', 257)]));
        $this->assertStringContainsString('description', $this->refusal(['description' => str_repeat('x', 4001)]));
    }

    public function test_base_game(): void
    {
        $this->assertSame('BAR', $this->parse(['baseGame' => '  BAR  '])['submission']['base_game']);
        foreach (['  ', '', null] as $blank) {
            $this->assertNull($this->parse(['baseGame' => $blank])['submission']['base_game']);
        }
        foreach ([str_repeat('x', 65), 7, ['BAR']] as $bad) {
            $parsed = $this->parse(['baseGame' => $bad]);
            $this->assertSame(400, $parsed['status']);
            $this->assertStringContainsString('baseGame', $parsed['error']);
        }
        $this->assertStringContainsString('baseGame', $this->refusal(['shortname' => 'BAR', 'baseGame' => 'BAR']));
    }

    public function test_links(): void
    {
        $this->assertSame([['label' => 'Website', 'url' => 'https://example.test/']], $this->parse(['links' => [['label' => ' Website ', 'url' => ' https://example.test/ ']]])['submission']['links']);
        $links = array_map(fn ($i) => ['label' => 'Link '.$i, 'url' => 'https://example.test/'.$i], range(0, GameFacts::MAX_LINKS));
        $this->assertStringContainsString('at most', $this->refusal(['links' => $links]));
        $this->assertFalse($this->parse(['links' => [['url' => 'https://example.test/']]])['ok']);
        $this->assertFalse($this->parse(['links' => [['label' => 'Website']]])['ok']);
        $this->assertStringContainsString('icon', $this->refusal(['links' => [['label' => 'Website', 'url' => 'https://example.test/', 'icon' => 'x']]]));
    }

    public function test_downloads_parse_into_the_source_table_shape(): void
    {
        $this->assertSame([
            ['kind' => 'rapid', 'value' => 'metalfactions:stable'],
            ['kind' => 'github', 'value' => 'springraaar/metal_factions', 'asset' => 'metal_factions'],
            ['kind' => 'url', 'value' => 'https://example.test/mf.sdz', 'filename' => 'mf.sdz'],
        ], $this->parse(['downloads' => [
            ['kind' => 'rapid', 'value' => ' metalfactions:stable '],
            ['kind' => 'github', 'value' => 'springraaar/metal_factions', 'asset' => 'metal_factions'],
            ['kind' => 'url', 'value' => 'https://example.test/mf.sdz', 'filename' => 'mf.sdz'],
        ]])['submission']['downloads']);
    }

    public function test_a_submitted_source_goes_through_the_edit_forms_check(): void
    {
        $this->assertFalse($this->parse(['downloads' => [['kind' => 'rapid', 'value' => 'metalfactions']]])['ok']);
        $this->assertFalse($this->parse(['downloads' => [['kind' => 'github', 'value' => 'https://github.com/a/b']]])['ok']);
        $this->assertFalse($this->parse(['downloads' => [['kind' => 'url', 'value' => 'https://example.test/mf.sdz']]])['ok']);
        $this->assertFalse($this->parse(['downloads' => [['kind' => 'url', 'value' => 'javascript:alert(1)', 'filename' => 'mf.sdz']]])['ok']);
        $this->assertFalse($this->parse(['downloads' => [['kind' => 'url', 'value' => 'https://example.test/mf.sdz', 'filename' => '../../mf.sdz']]])['ok']);
        $this->assertStringContainsString('checksum', $this->refusal(['downloads' => [['kind' => 'rapid', 'value' => 'mf:stable', 'checksum' => 'deadbeef']]]));
        $many = array_map(fn ($i) => ['kind' => 'rapid', 'value' => 'game'.$i.':stable'], range(0, Download::MAX_DOWNLOADS));
        $this->assertStringContainsString('downloads', $this->refusal(['downloads' => $many]));
    }
}
