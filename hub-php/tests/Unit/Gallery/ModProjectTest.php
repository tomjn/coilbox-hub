<?php

namespace Tests\Unit\Gallery;

use App\Domain\Container\Json;
use App\Domain\Gallery\ModProject;
use PHPUnit\Framework\TestCase;

/** Ported from `lib/gallery/modProjectPreview.test.ts`. */
class ModProjectTest extends TestCase
{
    /** A payload as Coilbox writes one, as JSON text so both decode shapes can be tried. */
    private function json(array|string $edits): string
    {
        return is_string($edits)
            ? '{"name":"A project","gameName":"Balanced Annihilation","edits":'.$edits.'}'
            : '{"name":"A project","gameName":"Balanced Annihilation","edits":'.json_encode($edits, JSON_FORCE_OBJECT).'}';
    }

    /** Both ways a payload reaches the class, which must agree on counts. */
    private function shapes(string $json): array
    {
        return [json_decode($json, true), Json::decode($json)];
    }

    public function test_counts_read_the_five_stores_the_way_coilbox_does(): void
    {
        $json = $this->json('{"overrides":{"armsolar":{"maxdamage":100,"buildpic":"x.png"},"armmex":{"metal":5}},'
            .'"text":{"armsolar":{"en":{"name":"Sun Catcher"}}},'
            .'"clones":{"armsolar2":{"source":"armsolar","def":{}}},'
            .'"menus":{"armlab":[{"op":"add","unit":"armsolar2"}]},'
            .'"disabled":["armwar"]}');

        foreach ($this->shapes($json) as $payload) {
            $this->assertSame([
                'unitsTouched' => 2,
                'fields' => 4,
                'clones' => 1,
                'menuOps' => 1,
                'disabled' => 1,
            ], ModProject::countsFromPayload($payload));
        }
    }

    public function test_a_unit_named_in_overrides_and_text_counts_once_for_units_touched(): void
    {
        $json = $this->json('{"overrides":{"armsolar":{"maxdamage":100}},"text":{"armsolar":{"en":{"name":"Sun Catcher"}}}}');

        foreach ($this->shapes($json) as $payload) {
            $counts = ModProject::countsFromPayload($payload);
            $this->assertSame(1, $counts['unitsTouched']);
            $this->assertSame(2, $counts['fields']);
        }
    }

    public function test_an_empty_or_malformed_payload_counts_as_nothing_changed(): void
    {
        $none = ['unitsTouched' => 0, 'fields' => 0, 'clones' => 0, 'menuOps' => 0, 'disabled' => 0];
        $this->assertSame($none, ModProject::countsFromPayload([]));
        $this->assertSame($none, ModProject::countsFromPayload(null));
        $this->assertSame($none, ModProject::countsFromPayload(new \stdClass));

        $json = $this->json('{"overrides":"not an object","clones":["not an object either"],"disabled":"not an array"}');
        foreach ($this->shapes($json) as $payload) {
            $this->assertSame($none, ModProject::countsFromPayload($payload));
        }
    }

    public function test_a_unit_with_an_empty_patch_is_not_counted_as_touched(): void
    {
        foreach ($this->shapes($this->json('{"overrides":{"armsolar":{}}}')) as $payload) {
            $counts = ModProject::countsFromPayload($payload);
            $this->assertSame(0, $counts['unitsTouched']);
            $this->assertSame(0, $counts['fields']);
        }
    }

    public function test_unit_keys_are_read_trimmed_and_lower_cased(): void
    {
        foreach ($this->shapes($this->json('{"overrides":{" ArmSolar ":{"maxdamage":100}}}')) as $payload) {
            $changelog = ModProject::changelog($payload);
            $this->assertCount(1, $changelog);
            $this->assertSame('armsolar', $changelog[0]['unit']);
        }
    }

    public function test_the_changelog_lists_every_unit_any_store_names_alphabetically(): void
    {
        $json = $this->json('{"overrides":{"armwar":{"maxdamage":1}},"clones":{"armsolar2":{"source":"armsolar","def":{}}},"disabled":["armmex"]}');

        foreach ($this->shapes($json) as $payload) {
            $this->assertSame(['armmex', 'armsolar2', 'armwar'], array_column(ModProject::changelog($payload), 'unit'));
        }
    }

    public function test_a_unit_touched_by_more_than_one_store_carries_all_of_it_in_one_row(): void
    {
        $json = $this->json('{"overrides":{"armsolar":{"maxdamage":1,"buildpic":"x"}},"menus":{"armsolar":[{"op":"add","unit":"armmex"}]},"disabled":["armsolar"]}');

        foreach ($this->shapes($json) as $payload) {
            $changelog = ModProject::changelog($payload);
            $this->assertCount(1, $changelog);
            $this->assertSame([
                'unit' => 'armsolar',
                'fields' => 2,
                'textFields' => 0,
                'added' => false,
                'source' => null,
                'disabled' => true,
                'menuOps' => 1,
            ], $changelog[0]);
        }
    }

    public function test_a_unit_named_with_digits_only_keeps_its_name(): void
    {
        $this->assertSame([], ModProject::changelog(Json::decode($this->json('{}'))));
        $this->assertSame([], ModProject::changelog(Json::decode($this->json('{"overrides":{}}'))));
        $changelog = ModProject::changelog(Json::decode($this->json('{"overrides":{"123":{"a":1}}}')));
        $this->assertSame('123', $changelog[0]['unit']);
    }

    public function test_describe_project_says_the_scale_of_a_project_in_one_sentence(): void
    {
        $none = ['unitsTouched' => 0, 'fields' => 0, 'clones' => 0, 'menuOps' => 0, 'disabled' => 0];
        $this->assertSame('Switches off 1 unit.', ModProject::describeProject([...$none, 'disabled' => 1]));
        $this->assertSame(
            'Changes 12 fields on 4 units, adds 2 units, makes 1 build menu edit and switches off 3 units.',
            ModProject::describeProject(['unitsTouched' => 4, 'fields' => 12, 'clones' => 2, 'menuOps' => 1, 'disabled' => 3]),
        );
        $this->assertSame('', ModProject::describeProject($none));
    }

    public function test_details_carry_the_values_the_counts_leave_behind_and_skip_what_is_malformed(): void
    {
        $json = $this->json('{"overrides":{"ArmCom":{"maxDamage":5000,"weapons.0.name":"LASER","colour":{"r":1,"g":0}},"broken":"not a table"},'
            .'"text":{"armcom":{"en":{"name":"Commander","description":5}}},'
            .'"menus":{"armlab":[{"op":"add","unit":"armpw"},{"op":"move","unit":"armrock"},{"op":"swap","unit":"armham"},"junk"]}}');

        $details = ModProject::details(Json::decode($json));

        $this->assertSame([
            'fields' => [
                ['colour', '{ g = 0, r = 1 }'],
                ['maxDamage', '5000'],
                ['weapons.0.name', '"LASER"'],
            ],
            'text' => [['en', 'name', 'Commander']],
            'menu' => [],
        ], $details['armcom']);
        $this->assertSame([
            ['op' => 'add', 'unit' => 'armpw'],
            ['op' => 'move', 'unit' => 'armrock', 'before' => null],
        ], $details['armlab']['menu']);
        $this->assertArrayNotHasKey('broken', $details);
    }

    public function test_details_write_lua_the_way_a_modder_would(): void
    {
        $json = $this->json('{"overrides":{"armcom":{"a":{},"b":[],"c":[1,2.5,true,null],"d":"q\"\n","e":1.0,"f":1e25,"g":{"repeat":1,"my key":2,"7":3},'
            .'"h":{"x":{"y":1}}}}}');
        $fields = array_column(ModProject::details(Json::decode($json))['armcom']['fields'], 1, 0);

        $this->assertSame('{}', $fields['a']);
        $this->assertSame('{}', $fields['b']);
        $this->assertSame('{ [1] = 1, [2] = 2.5, [3] = true, [4] = nil }', $fields['c']);
        $this->assertSame('"q\\"\\n"', $fields['d']);
        $this->assertSame('1', $fields['e']);
        $this->assertSame('1e25', $fields['f']);
        $this->assertSame('{ [7] = 3, ["my key"] = 2, ["repeat"] = 1 }', $fields['g']);
        $this->assertSame("{\n  x = { y = 1 },\n}", $fields['h']);
    }

    public function test_the_row_limit_is_a_real_cap_the_changelog_itself_does_not_apply(): void
    {
        $this->assertGreaterThan(0, ModProject::CHANGELOG_ROW_LIMIT);
        $overrides = [];
        for ($i = 0; $i < ModProject::CHANGELOG_ROW_LIMIT + 50; $i++) {
            $overrides['unit'.$i] = ['field' => 1];
        }
        $json = $this->json(['overrides' => $overrides]);

        foreach ($this->shapes($json) as $payload) {
            $this->assertCount(ModProject::CHANGELOG_ROW_LIMIT + 50, ModProject::changelog($payload));
        }
    }
}
