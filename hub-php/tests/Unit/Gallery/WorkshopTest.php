<?php

namespace Tests\Unit\Gallery;

use App\Domain\Container\Json;
use App\Domain\Gallery\Workshop\BarPack;
use App\Domain\Gallery\Workshop\Compile;
use App\Domain\Gallery\Workshop\LobbyCommands;
use App\Domain\Gallery\Workshop\Lua;
use App\Domain\Gallery\Workshop\PresetCommands;
use App\Domain\Gallery\Workshop\Project;
use App\Domain\Gallery\Workshop\TooLarge;
use stdClass;
use Tests\TestCase;

/**
 * Ported from `lib/workshop/lobbyCommands.test.ts`, `presetCommands.test.ts` and
 * `barPack.test.ts`. The deep nesting case from lobbyCommands.test.ts has no
 * equivalent: `Json::decode()` refuses a payload nested past 512 levels, so one
 * never reaches these classes.
 */
class WorkshopTest extends TestCase
{
    /** A payload as the item page holds it: objects as `stdClass`, arrays as lists. */
    private function payload(mixed $value): mixed
    {
        return Json::decode(json_encode($value));
    }

    private function lobby(mixed $value): ?array
    {
        return LobbyCommands::commands($this->payload($value));
    }

    private function obj(array $values = []): stdClass
    {
        return (object) $values;
    }

    // lobbyCommands.test.ts

    public function test_the_project_on_issue_418_packs_to_one_tweakdefs_line(): void
    {
        $commands = $this->lobby([
            'name' => 'Disabled AKs in BA',
            'gameName' => 'Balanced Annihilation V15.9.8',
            'edits' => ['text' => $this->obj(), 'menus' => $this->obj(), 'clones' => $this->obj(), 'disabled' => ['corak'], 'overrides' => $this->obj()],
        ]);
        $this->assertCount(1, $commands['lines']);
        $this->assertStringStartsWith('!bset tweakdefs ', $commands['lines'][0]);
        $this->assertSame([], $commands['withheld']);
        $this->assertStringContainsString('["corak"] = true', $commands['chunks'][0]['lua']);
    }

    public function test_a_field_change_and_a_switched_off_unit_share_one_tweakdefs_line(): void
    {
        $commands = $this->lobby(['edits' => ['overrides' => ['armcom' => ['maxDamage' => 1]], 'disabled' => ['corak']]]);
        $this->assertCount(1, $commands['lines']);
        $this->assertSame('tweakdefs', explode(' ', $commands['lines'][0])[1]);
    }

    public function test_a_typed_number_against_the_games_own_unit_notes_it_is_written_as_typed(): void
    {
        $commands = $this->lobby(['edits' => ['overrides' => ['armcom' => ['maxDamage' => 1]], 'disabled' => ['corak']]]);
        $this->assertSame('These lines write each value as typed. The game may load some of them as something else.', $commands['typedValuesNote']);
    }

    public function test_a_switched_off_unit_alone_gets_no_typed_values_note(): void
    {
        $this->assertNull($this->lobby(['edits' => ['disabled' => ['corak']]])['typedValuesNote']);
    }

    public function test_a_text_only_field_change_gets_no_typed_values_note(): void
    {
        $commands = $this->lobby(['edits' => ['overrides' => ['armcom' => ['description' => 'x']], 'disabled' => ['corak']]]);
        $this->assertNull($commands['typedValuesNote']);
    }

    public function test_a_number_against_a_copys_own_field_gets_no_typed_values_note(): void
    {
        $commands = $this->lobby(['edits' => [
            'clones' => ['armhuge' => ['key' => 'armhuge', 'def' => ['maxDamage' => 1]]],
            'overrides' => ['armhuge' => ['maxDamage' => 2]],
            'disabled' => ['corak'],
        ]]);
        $this->assertNull($commands['typedValuesNote']);
    }

    public function test_no_lines_at_all_when_part_of_the_project_would_be_missing_from_them(): void
    {
        $commands = $this->lobby(['edits' => [
            'clones' => ['big' => ['key' => 'armhuge', 'def' => ['description' => str_repeat('x', 13000)]]],
            'disabled' => ['corak'],
        ]]);
        $this->assertSame([], $commands['lines']);
        $this->assertSame(['"1 unit added" is too long for one autohost command.'], $commands['withheld']);
        // The Lua is still there to read.
        $this->assertCount(2, $commands['chunks']);
    }

    public function test_two_copies_claiming_one_name_withhold_the_lines(): void
    {
        $commands = $this->lobby(['edits' => ['clones' => [
            'first' => ['key' => 'armdup', 'def' => ['maxDamage' => 1]],
            'second' => ['key' => 'armdup', 'def' => ['maxDamage' => 2]],
        ]]]);
        $this->assertSame([], $commands['lines']);
        $this->assertSame(['More than one copy in this project is named armdup.'], $commands['withheld']);
    }

    public function test_what_a_lobby_cannot_carry_is_said_beside_the_lines(): void
    {
        $commands = $this->lobby([
            'edits' => ['disabled' => ['corak'], 'text' => ['armcom' => ['en' => ['name' => 'A', 'description' => 'B']]]],
            'readOnlyLua' => [['title' => 't', 'lua' => 'x()', 'note' => 'n']],
        ]);
        $this->assertCount(1, $commands['lines']);
        $this->assertCount(2, $commands['notCarried']);
        $this->assertStringStartsWith('2 name or description edits.', $commands['notCarried'][0]);
    }

    public function test_imported_lua_the_decoder_proved_parses_is_in_the_lines_not_listed_as_missing(): void
    {
        $commands = $this->lobby([
            'edits' => ['disabled' => ['corak']],
            'readOnlyLua' => [['title' => 'tweakdefs', 'lua' => 'do local imported = 1 end', 'note' => 'n', 'form' => 'block']],
        ]);
        $this->assertSame([], $commands['notCarried']);
        $lua = implode("\n", array_column($commands['chunks'], 'lua'));
        $this->assertStringContainsString('local imported = 1', $lua);
        // First, because the import is the baseline the author edited on top of.
        $this->assertSame('tweakdefs', $commands['chunks'][0]['title']);
    }

    public function test_imported_lua_that_never_parsed_is_left_out_and_said_so(): void
    {
        $commands = $this->lobby([
            'edits' => ['disabled' => ['corak']],
            'readOnlyLua' => [['title' => 't', 'lua' => 'not lua {{{', 'note' => 'n', 'form' => 'unrecognised']],
        ]);
        $this->assertSame(['1 block of imported Lua that never parsed, so it is not in these lines.'], $commands['notCarried']);
        $this->assertStringNotContainsString('not lua', implode("\n", array_column($commands['chunks'], 'lua')));
    }

    public function test_nothing_is_offered_for_a_payload_that_is_not_a_project_or_holds_no_edit(): void
    {
        $this->assertNull(LobbyCommands::commands(null));
        $this->assertNull($this->lobby(['edits' => $this->obj()]));
        $this->assertNull($this->lobby(['edits' => ['text' => ['armcom' => ['en' => ['name' => 'A']]]]]));
        $this->assertNull($this->lobby(['edits' => ['disabled' => 'corak']]));
    }

    // presetCommands.test.ts

    private function preset(array $over = []): array
    {
        return array_merge([
            'participants' => [],
            'gameName' => 'Beyond All Reason test-1234',
            'mapName' => 'Supreme Isthmus V2',
            'startPosType' => 2,
            'modOptionValues' => $this->obj(),
        ], $over);
    }

    private function ai(array $over = []): array
    {
        return array_merge([
            'id' => 'a',
            'kind' => 'ai',
            'name' => 'Barbarian',
            'ai' => ['shortName' => 'BARb', 'kind' => 'native'],
            'side' => 'Armada',
            'color' => [1, 0, 0],
            'allyTeam' => 1,
            'spectator' => false,
        ], $over);
    }

    private function human(): array
    {
        return ['id' => 'you', 'kind' => 'you', 'name' => 'Tom', 'side' => 'Cortex', 'allyTeam' => 0, 'spectator' => false];
    }

    private function presetFor(array $over = [], array $factions = []): array
    {
        return PresetCommands::commands($this->payload($this->preset($over)), $factions);
    }

    private function startingWith(array $lines, string $prefix): array
    {
        return array_values(array_filter($lines, fn ($line) => str_starts_with($line, $prefix)));
    }

    public function test_the_map_comes_first(): void
    {
        $this->assertSame('!map Supreme Isthmus V2', $this->presetFor()['lines'][0]);
    }

    public function test_the_start_position_type_is_set_before_anything_that_depends_on_it(): void
    {
        $lines = $this->presetFor(['startPosType' => 1])['lines'];
        $this->assertSame(1, array_search('!bSet startPosType 1', $lines, true));
    }

    public function test_a_start_position_type_the_engine_does_not_define_is_left_out(): void
    {
        $this->assertSame([], $this->startingWith($this->presetFor(['startPosType' => 7])['lines'], '!bSet startPosType'));
    }

    public function test_every_changed_mod_option_becomes_its_own_bset_line(): void
    {
        $lines = $this->presetFor(['modOptionValues' => ['maxunits' => '1000', 'deathmode' => 'killall']])['lines'];
        $this->assertContains('!bSet maxunits 1000', $lines);
        $this->assertContains('!bSet deathmode killall', $lines);
    }

    public function test_mod_options_are_sorted_by_name(): void
    {
        $lines = $this->presetFor(['startPosType' => 7, 'modOptionValues' => ['zed' => '1', 'Alpha' => '2', 'beta' => '3']])['lines'];
        $this->assertSame(['!map Supreme Isthmus V2', '!bSet Alpha 2', '!bSet beta 3', '!bSet zed 1'], $lines);
    }

    public function test_start_boxes_carry_the_same_grid_on_spads_one_based_team_number(): void
    {
        $lines = $this->presetFor(['startRects' => $this->obj(['0' => ['left' => 0, 'top' => 0, 'right' => 50, 'bottom' => 200]])])['lines'];
        $this->assertContains('!addBox 0 0 50 200 1', $lines);
    }

    public function test_no_boxes_unless_the_engine_is_told_players_choose_in_game(): void
    {
        $lines = $this->presetFor([
            'startPosType' => 0,
            'startRects' => $this->obj(['0' => ['left' => 0, 'top' => 0, 'right' => 50, 'bottom' => 200]]),
        ])['lines'];
        $this->assertSame([], $this->startingWith($lines, '!addBox'));
    }

    public function test_an_ai_becomes_a_bot_placed_on_its_ally_team_counting_from_one(): void
    {
        $lines = $this->presetFor(['participants' => [$this->ai()]])['lines'];
        $this->assertContains('!addBot Barbarian Armada BARb', $lines);
        $this->assertContains('!force %Barbarian team 2', $lines);
    }

    public function test_balancing_is_turned_off_before_a_force_or_the_host_refuses_it(): void
    {
        $lines = $this->presetFor(['participants' => [$this->ai()]])['lines'];
        $this->assertContains('!set autoBalance off', $lines);
        $this->assertLessThan(array_search('!force %Barbarian team 2', $lines, true), array_search('!set autoBalance off', $lines, true));
    }

    public function test_a_bot_is_added_before_it_is_placed(): void
    {
        $lines = $this->presetFor(['participants' => [$this->ai()]])['lines'];
        $this->assertLessThan(array_search('!force %Barbarian team 2', $lines, true), array_search('!addBot Barbarian Armada BARb', $lines, true));
    }

    public function test_a_bot_name_a_lobby_will_not_take_is_rewritten_and_the_page_says_so(): void
    {
        $commands = $this->presetFor(['participants' => [$this->ai(['name' => 'Bad Guy #1!'])]]);
        $this->assertContains('!addBot BadGuy1 Armada BARb', $commands['lines']);
        $this->assertContains("1 bot was renamed. A lobby only takes letters, digits and brackets in a bot's name.", $commands['notCarried']);
    }

    public function test_two_bots_cannot_share_one_lobby_name(): void
    {
        $lines = $this->presetFor(['participants' => [$this->ai(['id' => 'a', 'name' => 'Bot']), $this->ai(['id' => 'b', 'name' => 'Bot'])]])['lines'];
        $this->assertContains('!addBot Bot Armada BARb', $lines);
        $this->assertContains('!addBot Bot2 Armada BARb', $lines);
    }

    public function test_a_side_rolled_at_launch_is_asked_for_as_the_games_first_and_said_so(): void
    {
        $commands = $this->presetFor(['participants' => [$this->ai(['side' => '__random__'])]]);
        $this->assertContains('!addBot Barbarian 0 BARb', $commands['lines']);
        $this->assertContains("1 bot had a random faction. A lobby cannot roll one, so it gets the game's first. Set it in the lobby by hand.", $commands['notCarried']);
    }

    public function test_no_lines_at_all_when_a_bot_in_the_roster_cannot_be_named_to_the_host(): void
    {
        $commands = $this->presetFor(['participants' => [$this->ai(), $this->ai(['id' => 'b', 'ai' => ['shortName' => '', 'kind' => 'native']])]]);
        $this->assertSame([], $commands['lines']);
        $this->assertSame(['One of the AI players does not say which AI it is, so the roster would be short a player.'], $commands['withheld']);
    }

    public function test_the_spectating_human_is_not_a_bot_and_neither_is_the_one_playing(): void
    {
        $lines = $this->presetFor(['participants' => [$this->human(), $this->ai()]])['lines'];
        $this->assertCount(1, $this->startingWith($lines, '!addBot'));
    }

    public function test_what_a_lobby_cannot_carry_is_named_not_quietly_dropped(): void
    {
        $notCarried = $this->presetFor([
            'participants' => [$this->human(), $this->ai()],
            'restrictions' => ['disabledUnits' => ['corak'], 'advantage' => 0.1],
        ])['notCarried'];
        $this->assertContains('Team colours. A lobby gives every bot the same colour and lets each person pick their own.', $notCarried);
        $this->assertContains("The faction each person plays. Only a bot's can be set from the chat.", $notCarried);
        $this->assertContains('1 disabled unit, which no autohost command can restrict.', $notCarried);
        $this->assertContains("A resource advantage for the first team, which is not the same as a lobby's handicap.", $notCarried);
    }

    public function test_shared_control_is_named_rather_than_guessed_at(): void
    {
        $commands = $this->presetFor(['participants' => [$this->ai(['id' => 'a', 'team' => 3]), $this->ai(['id' => 'b', 'name' => 'Two', 'team' => 3])]]);
        $this->assertContains('Players sharing control of one team. The host assigns those slots itself.', $commands['notCarried']);
        $this->assertSame([], array_values(array_filter($commands['lines'], fn ($line) => str_contains($line, ' id '))));
    }

    public function test_a_faction_of_two_words_is_asked_for_by_its_first(): void
    {
        $commands = $this->presetFor(['participants' => [$this->ai(['side' => 'Legion Core'])]]);
        $this->assertContains('!addBot Barbarian Legion BARb', $commands['lines']);
        $this->assertSame([], array_values(array_filter($commands['notCarried'], fn ($note) => str_contains($note, 'faction'))));
    }

    public function test_the_first_word_is_still_used_when_the_hub_knows_nothing_of_the_games_factions(): void
    {
        $commands = $this->presetFor(['participants' => [$this->ai(['side' => 'Federation of Kala'])]], []);
        $this->assertContains('!addBot Barbarian Federation BARb', $commands['lines']);
    }

    public function test_a_first_word_that_two_of_the_games_factions_share_picks_neither(): void
    {
        $commands = $this->presetFor(
            ['participants' => [$this->ai(['side' => 'Federation of Kala'])]],
            [['name' => 'Federation of Kala'], ['name' => 'Federation of Zog'], ['name' => 'Loz Alliance']],
        );
        $this->assertContains('!addBot Barbarian 0 BARb', $commands['lines']);
        $this->assertContains('1 bot gets the game\'s first faction, because this game has more than one starting "Federation". Set it in the lobby by hand.', $commands['notCarried']);
    }

    public function test_a_first_word_only_one_faction_starts_with_is_sent_as_it_is(): void
    {
        $commands = $this->presetFor(
            ['participants' => [$this->ai(['side' => 'Federation of Kala'])]],
            [['name' => 'Federation of Kala'], ['name' => 'Loz Alliance']],
        );
        $this->assertContains('!addBot Barbarian Federation BARb', $commands['lines']);
    }

    public function test_a_teams_income_multiplier_does_not_carry_either(): void
    {
        $this->assertContains(
            'A resource multiplier for the first team, which a lobby has no setting for.',
            $this->presetFor(['restrictions' => ['incomeMultiplier' => 1.5]])['notCarried'],
        );
    }

    public function test_a_start_box_off_the_grid_is_left_out_rather_than_sent_as_nonsense(): void
    {
        $commands = $this->presetFor(['startRects' => $this->obj([
            '0' => ['left' => 0, 'top' => 0, 'right' => 50, 'bottom' => 200],
            '1' => ['left' => 180, 'top' => 0, 'right' => 90, 'bottom' => 200],
        ])]);
        $this->assertSame(['!addBox 0 0 50 200 1'], $this->startingWith($commands['lines'], '!addBox'));
        $this->assertContains('1 start box the grid does not allow, so that team picks anywhere.', $commands['notCarried']);
    }

    public function test_a_preset_with_nothing_but_a_map_still_gets_the_one_line_worth_pasting(): void
    {
        $commands = $this->presetFor(['startPosType' => 7]);
        $this->assertSame(['!map Supreme Isthmus V2'], $commands['lines']);
        $this->assertSame([], $commands['withheld']);
    }

    public function test_nothing_is_offered_for_a_payload_that_is_not_a_preset(): void
    {
        $this->assertNull(PresetCommands::commands(null));
        $this->assertNull(PresetCommands::commands($this->payload(['participants' => [], 'gameName' => 'x'])));
        $this->assertNull(PresetCommands::commands($this->payload(['edits' => ['disabled' => ['corak']]])));
        $this->assertNull($this->presetForOrNull(['mapName' => '']));
    }

    private function presetForOrNull(array $over): ?array
    {
        return PresetCommands::commands($this->payload($this->preset($over)));
    }

    // barPack.test.ts

    /** @return list<array> */
    private function golden(): array
    {
        return json_decode((string) file_get_contents(resource_path('vendor/bar-pack-golden.json')), true);
    }

    public function test_every_golden_project_packs_to_the_lines_coilbox_does(): void
    {
        $raw = Json::decode((string) file_get_contents(resource_path('vendor/bar-pack-golden.json')));
        $golden = $this->golden();
        $this->assertNotEmpty($golden);
        foreach ($raw as $i => $entry) {
            $project = Project::read($entry->project);
            $this->assertNotNull($project, $entry->name);
            $chunks = Compile::compile($project)['chunks'];
            $this->assertEquals($golden[$i]['chunks'], $chunks, "chunks for {$entry->name}");
            $this->assertEquals($golden[$i]['pack'], BarPack::pack($chunks), "pack for {$entry->name}");
        }
    }

    public function test_the_fixture_covers_a_table_chunk_a_split_and_a_chunk_that_fits_nowhere(): void
    {
        $golden = $this->golden();
        $forms = array_merge(...array_map(fn ($e) => array_column($e['chunks'], 'form'), $golden));
        $this->assertContains('table', $forms);
        $this->assertNotEmpty(array_filter($golden, fn ($e) => count($e['pack']['tweakdefs']) > 1));
        $this->assertNotEmpty(array_filter($golden, fn ($e) => $e['pack']['oversized'] !== []));
    }

    public function test_a_comment_marker_inside_a_string_is_not_a_comment(): void
    {
        $this->assertSame('x = "a -- b" y = 1', BarPack::minify("x = \"a -- b\"  -- gone\ny = 1"));
    }

    public function test_every_lua_string_form_survives_minifying(): void
    {
        $this->assertSame("do x = 'a -- b' end", BarPack::minify("do x = 'a -- b' end"));
        $this->assertSame("x = 'she said \"hi\"' y = 2", BarPack::minify("x = 'she said \"hi\"' y = 2"));
        $this->assertSame('x = [[ two  spaces ]] y = 3', BarPack::minify('x = [[ two  spaces ]] y = 3'));
        $this->assertSame('x = [==[ ]] inside ]==] y = 4', BarPack::minify('x = [==[ ]] inside ]==] y = 4'));
        $this->assertSame('do x = 1 end', BarPack::minify("do --[[ across\nlines ]] x = 1 end"));
        $this->assertSame('x = a[b[1]]', BarPack::minify('x = a[b[1]]'));
    }

    public function test_a_table_chunks_tweakdefs_payload_keeps_the_url_safe_alphabet(): void
    {
        $project = Project::read($this->payload(['edits' => ['overrides' => ['corak' => ['name' => 'Танк?']]]]));
        $payload = explode(' ', BarPack::pack(Compile::compile($project)['chunks'])['tweakdefs'][0]);
        $payload = end($payload);
        $this->assertStringNotContainsString('/', $payload);
        $this->assertStringNotContainsString('+', $payload);
    }

    public function test_a_project_coilbox_could_not_parse_is_not_read_at_all(): void
    {
        $this->assertNull(Project::read($this->payload(['edits' => ['disabled' => ['corak', 5]]])));
        $this->assertNull(Project::read($this->payload(['edits' => ['menus' => ['armlab' => [['op' => 'swap', 'unit' => 'a']]]]])));
        $this->assertNull(Project::read($this->payload(['edits' => ['clones' => ['a' => ['def' => $this->obj()]]]])));
        $this->assertNull(Project::read($this->payload(['edits' => null])));
        $this->assertNull(Project::read('nope'));
    }

    public function test_an_index_no_slot_could_hold_is_refused_before_it_is_allocated(): void
    {
        $project = Project::read($this->payload(['edits' => [
            'clones' => ['a' => ['key' => 'armbig', 'def' => $this->obj()]],
            'overrides' => ['armbig' => ['weapons.4000000000.name' => 'x']],
        ]]));
        $this->expectException(TooLarge::class);
        Compile::compile($project);
    }

    public function test_a_path_step_named_proto_is_a_key_like_any_other(): void
    {
        $project = Project::read($this->payload(['edits' => [
            'clones' => ['a' => ['key' => 'armsafe', 'def' => $this->obj()]],
            'overrides' => ['armsafe' => ['__proto__.polluted' => 1]],
        ]]));
        $chunks = Compile::compile($project)['chunks'];
        $this->assertStringContainsString('__proto__ = { polluted = 1 }', $chunks[0]['lua']);
    }

    // lua.ts

    public function test_lua_numbers_are_written_as_javascript_writes_them(): void
    {
        $this->assertSame('5', Lua::literal(5.0, ''));
        $this->assertSame('0.1', Lua::literal(0.1, ''));
        $this->assertSame('1e21', Lua::literal(1e21, ''));
        $this->assertSame('1e-7', Lua::literal(1e-7, ''));
        $this->assertSame('10000000000000000', Lua::literal(1e16, ''));
    }

    public function test_lua_strings_escape_control_characters_with_three_digits(): void
    {
        $this->assertSame('"a\\000" ', Lua::string("a\0").' ');
        $this->assertSame('"\\0005"', Lua::string("\0".'5'));
        $this->assertSame('"q\\"\\\\\\n"', Lua::string("q\"\\\n"));
    }

    public function test_lua_tables_sort_keys_and_inline_small_scalar_tables(): void
    {
        $this->assertSame('{ [1] = "a", [2] = 2 }', Lua::literal(['a', 2], ''));
        $this->assertSame('{ [3] = 1, a = 1, b = true, ["c d"] = nil, ["repeat"] = 2 }', Lua::literal(
            Json::decode('{"repeat":2,"b":true,"a":1,"c d":null,"3":1}'),
            '',
        ));
        $long = Lua::literal(Json::decode('{"a":{"b":1}}'), '');
        $this->assertSame("{\n  a = { b = 1 },\n}", $long);
    }
}
