<?php

namespace Tests\Unit\Games;

use App\Domain\Games\Changes;
use App\Supabase\Supabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Ported from `lib/games/changes.test.ts`. */
class ChangesTest extends TestCase
{
    private const FULL = ['from' => true, 'to' => true];

    /** @param array<string, mixed> $over @return array<string, mixed> */
    private function unit(string $name, array $over = []): array
    {
        return [
            'unit_name' => $name,
            'full_name' => strtoupper($name),
            'faction_key' => 'arm',
            'build_options' => [],
            'stats' => ['health' => 100],
            ...$over,
        ];
    }

    /** @param list<array<string, mixed>> $units @return list<string> */
    private function names(array $units): array
    {
        return array_column($units, 'unit_name');
    }

    public function test_a_unit_only_the_newer_release_holds_is_added(): void
    {
        $changes = Changes::diffReleases([$this->unit('a')], [$this->unit('a'), $this->unit('b')], self::FULL);

        $this->assertSame(['b'], $this->names($changes['added']));
        $this->assertSame([], $changes['removed']);
        $this->assertSame([], $changes['changed']);
        $this->assertSame(1, $changes['unchanged']);
    }

    public function test_a_unit_only_the_older_release_holds_is_removed(): void
    {
        $changes = Changes::diffReleases([$this->unit('a'), $this->unit('b')], [$this->unit('a')], self::FULL);

        $this->assertSame(['b'], $this->names($changes['removed']));
        $this->assertSame([], $changes['added']);
    }

    public function test_removed_units_are_not_listed_when_the_newer_release_is_not_known_to_be_complete(): void
    {
        // The newer release may simply not have been told about "b".
        $changes = Changes::diffReleases([$this->unit('a'), $this->unit('b')], [$this->unit('a')], ['from' => true, 'to' => false]);

        $this->assertSame([], $changes['removed']);
        $this->assertFalse($changes['removedKnown']);
        $this->assertTrue($changes['addedKnown']);
    }

    public function test_added_units_are_not_listed_when_the_older_release_is_not_known_to_be_complete(): void
    {
        // The older release may simply not have been told about "b".
        $changes = Changes::diffReleases([$this->unit('a')], [$this->unit('a'), $this->unit('b')], ['from' => false, 'to' => true]);

        $this->assertSame([], $changes['added']);
        $this->assertFalse($changes['addedKnown']);
        $this->assertTrue($changes['removedKnown']);
    }

    public function test_changed_and_unchanged_counts_do_not_depend_on_completeness(): void
    {
        $changes = Changes::diffReleases(
            [$this->unit('a'), $this->unit('b', ['full_name' => 'Old'])],
            [$this->unit('a'), $this->unit('b', ['full_name' => 'New'])],
            ['from' => false, 'to' => false],
        );

        $this->assertSame(['b'], $this->names($changes['changed']));
        $this->assertSame(1, $changes['unchanged']);
    }

    public function test_both_lists_are_known_when_both_releases_are_complete(): void
    {
        $changes = Changes::diffReleases([$this->unit('a')], [$this->unit('b')], self::FULL);

        $this->assertTrue($changes['addedKnown']);
        $this->assertTrue($changes['removedKnown']);
    }

    public function test_a_unit_neither_release_holds_is_nowhere_in_the_answer(): void
    {
        // A revision is the only evidence a release held a unit, and
        // `removed_at` is today's state and not a release's.
        $changes = Changes::diffReleases([$this->unit('a')], [$this->unit('a')], self::FULL);

        $this->assertSame([], $changes['added']);
        $this->assertSame([], $changes['removed']);
        $this->assertSame(1, $changes['unchanged']);
    }

    public function test_a_unit_with_the_same_facts_in_both_releases_is_unchanged(): void
    {
        $changes = Changes::diffReleases([$this->unit('a')], [$this->unit('a')], self::FULL);

        $this->assertSame([], $changes['changed']);
        $this->assertSame(1, $changes['unchanged']);
    }

    public function test_a_changed_stat_is_listed_with_both_values_and_its_label(): void
    {
        $changes = Changes::diffReleases(
            [$this->unit('a', ['stats' => ['health' => 100, 'metalCost' => 50]])],
            [$this->unit('a', ['stats' => ['health' => 120, 'metalCost' => 50]])],
            self::FULL,
        );

        $this->assertCount(1, $changes['changed']);
        $this->assertSame([['key' => 'health', 'label' => 'Health', 'from' => '100', 'to' => '120']], $changes['changed'][0]['stats']);
    }

    public function test_a_stat_only_one_release_carries_reads_as_a_dash_on_the_other_side(): void
    {
        $changes = Changes::diffReleases(
            [$this->unit('a', ['stats' => ['health' => 100]])],
            [$this->unit('a', ['stats' => ['health' => 100, 'buildTime' => 30]])],
            self::FULL,
        );
        $this->assertSame([['key' => 'buildTime', 'label' => 'Build time', 'from' => '-', 'to' => '30']], $changes['changed'][0]['stats']);

        $dropped = Changes::diffReleases(
            [$this->unit('a', ['stats' => ['health' => 100, 'buildTime' => 30]])],
            [$this->unit('a', ['stats' => ['health' => 100]])],
            self::FULL,
        );
        $this->assertSame([['key' => 'buildTime', 'label' => 'Build time', 'from' => '30', 'to' => '-']], $dropped['changed'][0]['stats']);
    }

    public function test_a_stat_that_is_null_on_one_side_and_absent_on_the_other_is_not_a_change(): void
    {
        $changes = Changes::diffReleases(
            [$this->unit('a', ['stats' => ['health' => 1, 'x' => null]])],
            [$this->unit('a', ['stats' => ['health' => 1]])],
            self::FULL,
        );

        $this->assertSame([], $changes['changed']);
    }

    public function test_a_number_and_the_same_number_as_a_string_read_the_same(): void
    {
        $changes = Changes::diffReleases(
            [$this->unit('a', ['stats' => ['health' => 100]])],
            [$this->unit('a', ['stats' => ['health' => '100']])],
            self::FULL,
        );

        $this->assertSame([], $changes['changed']);
    }

    public function test_a_number_and_a_different_string_is_a_change(): void
    {
        $changes = Changes::diffReleases(
            [$this->unit('a', ['stats' => ['health' => 100]])],
            [$this->unit('a', ['stats' => ['health' => 'high']])],
            self::FULL,
        );

        $this->assertSame([['key' => 'health', 'label' => 'Health', 'from' => '100', 'to' => 'high']], $changes['changed'][0]['stats']);
    }

    public function test_nested_and_array_stats_differ_by_their_printed_value(): void
    {
        $weapons = fn (int $damage) => [['range' => 300, 'damage' => $damage]];
        $same = Changes::diffReleases(
            [$this->unit('a', ['stats' => ['weapons' => $weapons(10)]])],
            [$this->unit('a', ['stats' => ['weapons' => $weapons(10)]])],
            self::FULL,
        );
        $this->assertSame([], $same['changed']);

        $changes = Changes::diffReleases(
            [$this->unit('a', ['stats' => ['weapons' => $weapons(10)]])],
            [$this->unit('a', ['stats' => ['weapons' => $weapons(12)]])],
            self::FULL,
        );
        $this->assertSame(
            [['key' => 'weapons', 'label' => 'weapons', 'from' => '[{"range":300,"damage":10}]', 'to' => '[{"range":300,"damage":12}]']],
            $changes['changed'][0]['stats'],
        );
    }

    public function test_stats_read_in_the_compare_pages_order_known_keys_first(): void
    {
        $changes = Changes::diffReleases(
            [$this->unit('a', ['stats' => ['zeta' => 1, 'metalCost' => 1, 'health' => 1]])],
            [$this->unit('a', ['stats' => ['zeta' => 2, 'metalCost' => 2, 'health' => 2]])],
            self::FULL,
        );

        $this->assertSame(['health', 'metalCost', 'zeta'], array_column($changes['changed'][0]['stats'], 'key'));
    }

    public function test_build_options_gained_and_lost_are_listed_lower_cased_and_sorted(): void
    {
        $changes = Changes::diffReleases(
            [$this->unit('a', ['build_options' => ['Armmex', 'armsolar']])],
            [$this->unit('a', ['build_options' => ['armsolar', 'armwin', 'ARMLAB']])],
            self::FULL,
        );

        $this->assertSame(['gained' => ['armlab', 'armwin'], 'lost' => ['armmex']], $changes['changed'][0]['builds']);
    }

    public function test_build_options_in_another_order_are_not_a_change(): void
    {
        // The unit page and the tree sort build options, so the array order
        // carries no meaning.
        $changes = Changes::diffReleases(
            [$this->unit('a', ['build_options' => ['x', 'y']])],
            [$this->unit('a', ['build_options' => ['y', 'x', 'x']])],
            self::FULL,
        );

        $this->assertSame([], $changes['changed']);
    }

    public function test_a_rename_on_its_own_is_a_change(): void
    {
        $changes = Changes::diffReleases([$this->unit('a', ['full_name' => 'Old'])], [$this->unit('a', ['full_name' => 'New'])], self::FULL);

        $this->assertCount(1, $changes['changed']);
        $this->assertSame(['from' => 'Old', 'to' => 'New'], $changes['changed'][0]['renamed']);
        $this->assertSame('New', $changes['changed'][0]['full_name']);
        $this->assertSame([], $changes['changed'][0]['stats']);
    }

    public function test_a_unit_moving_to_another_faction_is_a_change(): void
    {
        $changes = Changes::diffReleases([$this->unit('a', ['faction_key' => 'arm'])], [$this->unit('a', ['faction_key' => 'cor'])], self::FULL);

        $this->assertSame(['from' => 'arm', 'to' => 'cor'], $changes['changed'][0]['faction']);
        $this->assertSame('cor', $changes['changed'][0]['faction_key']);
    }

    public function test_the_same_release_on_both_sides_changes_nothing(): void
    {
        $units = [$this->unit('a'), $this->unit('b')];

        $this->assertSame([
            'added' => [],
            'removed' => [],
            'addedKnown' => true,
            'removedKnown' => true,
            'changed' => [],
            'unchanged' => 2,
        ], Changes::diffReleases($units, $units, self::FULL));
    }

    public function test_a_unit_held_by_an_intermediate_release_only_is_nowhere_in_a_pair_that_skips_it(): void
    {
        // Release 2 held `mid` and releases 1 and 3 did not. Comparing 1 with 3
        // must not invent it: only revisions in the two releases asked for count.
        $changes = Changes::diffReleases([$this->unit('a')], [$this->unit('a')], self::FULL);

        $this->assertNotContains('mid', $this->names($changes['added']));
    }

    public function test_an_added_unit_keeps_its_name_and_faction_a_removed_one_its_older_ones(): void
    {
        $changes = Changes::diffReleases(
            [$this->unit('gone', ['full_name' => 'Gone', 'faction_key' => 'cor'])],
            [$this->unit('new', ['full_name' => 'New', 'faction_key' => 'arm'])],
            self::FULL,
        );

        $this->assertSame([['unit_name' => 'new', 'full_name' => 'New', 'faction_key' => 'arm']], $changes['added']);
        $this->assertSame([['unit_name' => 'gone', 'full_name' => 'Gone', 'faction_key' => 'cor']], $changes['removed']);
    }

    public function test_group_by_faction_orders_by_the_games_sides_then_the_rest_and_sorts_within_a_group(): void
    {
        $groups = Changes::groupByFaction(
            [
                ['unit_name' => 'c', 'full_name' => 'Zed', 'faction_key' => 'cor'],
                ['unit_name' => 'a', 'full_name' => 'Beta', 'faction_key' => 'arm'],
                ['unit_name' => 'b', 'full_name' => 'Alpha', 'faction_key' => 'arm'],
                ['unit_name' => 'd', 'full_name' => null, 'faction_key' => null],
                ['unit_name' => 'e', 'full_name' => 'Odd', 'faction_key' => 'gone'],
            ],
            [['key' => 'arm', 'name' => 'Arm'], ['key' => 'cor', 'name' => 'Cor']],
        );

        $this->assertSame([
            ['arm', 'Arm', ['b', 'a']],
            ['cor', 'Cor', ['c']],
            [null, 'No faction', ['d', 'e']],
        ], array_map(fn (array $group) => [$group['key'], $group['name'], $this->names($group['units'])], $groups));
    }

    public function test_resolve_release_pair_defaults_to_the_two_newest_releases_older_first(): void
    {
        $this->assertSame(['from' => '2', 'to' => '3', 'notes' => []], Changes::resolveReleasePair(['3', '2', '1'], null, null));
    }

    public function test_resolve_release_pair_keeps_what_was_asked_for_including_a_reversed_pair(): void
    {
        $this->assertSame(['from' => '3', 'to' => '1', 'notes' => []], Changes::resolveReleasePair(['3', '2', '1'], '3', '1'));
    }

    public function test_resolve_release_pair_falls_back_and_says_so_for_a_release_it_does_not_hold(): void
    {
        $pair = Changes::resolveReleasePair(['3', '2', '1'], 'nope', '1');

        $this->assertSame('2', $pair['from']);
        $this->assertSame('1', $pair['to']);
        $this->assertSame(['The hub holds no release called "nope".'], $pair['notes']);
    }

    public function test_resolve_release_pair_does_not_default_one_side_onto_the_other(): void
    {
        $this->assertSame(['from' => '1', 'to' => '2'], array_intersect_key(Changes::resolveReleasePair(['3', '2', '1'], 'nope', '2'), ['from' => 1, 'to' => 1]));
        $this->assertSame(['from' => '2', 'to' => '3'], array_intersect_key(Changes::resolveReleasePair(['3', '2', '1'], '2', 'nope'), ['from' => 1, 'to' => 1]));
        $this->assertSame(['from' => '3', 'to' => '2'], array_intersect_key(Changes::resolveReleasePair(['3', '2', '1'], '3', null), ['from' => 1, 'to' => 1]));
    }

    public function test_resolve_release_pair_has_no_pair_for_fewer_than_two_releases(): void
    {
        $this->assertSame(['from' => null, 'to' => null, 'notes' => []], Changes::resolveReleasePair(['1'], null, null));
        $this->assertSame(['from' => null, 'to' => null, 'notes' => []], Changes::resolveReleasePair([], '1', '2'));
    }

    /** @param array<string, mixed> $over @return array<string, mixed> */
    private function revision(string $name, string $version, array $over = []): array
    {
        return [
            'version' => $version,
            'full_name' => strtoupper($name),
            'faction_key' => 'arm',
            'build_options' => [],
            'stats' => ['health' => 1],
            'game_unit' => ['unit_name' => $name],
            ...$over,
        ];
    }

    /**
     * Revisions come back a window at a time, as `ReadAll` pages through them.
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  list<array<string, mixed>>|int  $versions  the `game_version` rows, or a status code to fail with
     */
    private function fakeRevisions(array $rows, array|int $versions = []): void
    {
        config(['hub.supabase.url' => 'https://sb.test', 'hub.supabase.publishable_key' => 'pk']);

        Http::fake(function (Request $request) use ($rows, $versions) {
            if (str_contains($request->url(), '/rest/v1/game_version')) {
                return is_int($versions) ? Http::response(['message' => 'boom'], $versions) : Http::response($versions);
            }
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return Http::response(array_slice($rows, (int) ($query['offset'] ?? 0), (int) ($query['limit'] ?? 1000)));
        });
    }

    public function test_load_release_changes_reads_both_releases_with_one_paged_query_and_splits_the_rows_by_release(): void
    {
        $this->fakeRevisions(
            [
                $this->revision('kept', '1'),
                $this->revision('kept', '2', ['stats' => ['health' => 2]]),
                $this->revision('gone', '1'),
                $this->revision('fresh', '2'),
            ],
            [['version' => '1', 'reported_in_full' => true], ['version' => '2', 'reported_in_full' => true]],
        );

        $changes = Changes::loadReleaseChanges(Supabase::anon(), 'game', '1', '2');

        // One window of rows and the empty window `ReadAll` stops on, never a
        // read per unit, plus one read of the two releases' completeness.
        $revisionReads = 0;
        $versionReads = 0;
        foreach (Http::recorded() as [$request]) {
            $revisionReads += str_contains($request->url(), '/rest/v1/game_unit_revision') ? 1 : 0;
            $versionReads += str_contains($request->url(), '/rest/v1/game_version') ? 1 : 0;
        }
        $this->assertSame(2, $revisionReads);
        $this->assertSame(1, $versionReads);
        $this->assertSame(['fresh'], $this->names($changes['added']));
        $this->assertSame(['gone'], $this->names($changes['removed']));
        $this->assertSame(['kept'], $this->names($changes['changed']));
    }

    public function test_load_release_changes_reads_a_release_as_not_complete_when_the_hub_holds_no_word_that_it_is(): void
    {
        $this->fakeRevisions(
            [$this->revision('gone', '1'), $this->revision('fresh', '2')],
            [['version' => '1', 'reported_in_full' => true], ['version' => '2', 'reported_in_full' => false]],
        );

        $changes = Changes::loadReleaseChanges(Supabase::anon(), 'game', '1', '2');

        $this->assertSame([], $changes['removed']);
        $this->assertFalse($changes['removedKnown']);
        $this->assertSame(['fresh'], $this->names($changes['added']));
        $this->assertTrue($changes['addedKnown']);
    }

    public function test_load_release_changes_is_null_when_the_read_of_completeness_fails(): void
    {
        $this->fakeRevisions([], 500);

        $this->assertNull(Changes::loadReleaseChanges(Supabase::anon(), 'game', '1', '2'));
    }

    public function test_load_release_changes_is_null_when_the_read_fails(): void
    {
        config(['hub.supabase.url' => 'https://sb.test', 'hub.supabase.publishable_key' => 'pk']);
        Http::fake(['*/rest/v1/*' => Http::response(['message' => 'boom'], 500)]);

        $this->assertNull(Changes::loadReleaseChanges(Supabase::anon(), 'game', '1', '2'));
    }

    public function test_load_game_releases_lists_versions_newest_report_first_and_is_null_on_failure(): void
    {
        config(['hub.supabase.url' => 'https://sb.test', 'hub.supabase.publishable_key' => 'pk']);
        Http::fake(['*/rest/v1/game_version*' => Http::response([
            ['version' => '2', 'last_seen_at' => '2026-02-01T00:00:00Z', 'game' => ['shortname' => 'BA']],
            ['version' => '1', 'last_seen_at' => '2026-01-01T00:00:00Z', 'game' => ['shortname' => 'BA']],
        ])]);

        $this->assertSame([
            ['version' => '2', 'last_seen_at' => '2026-02-01T00:00:00Z'],
            ['version' => '1', 'last_seen_at' => '2026-01-01T00:00:00Z'],
        ], Changes::loadGameReleases(Supabase::anon(), 'BA'));
        Http::assertSent(fn (Request $request) => str_contains(urldecode($request->url()), 'order=last_seen_at.desc'));
    }

    public function test_load_game_releases_is_null_on_a_failed_read(): void
    {
        config(['hub.supabase.url' => 'https://sb.test', 'hub.supabase.publishable_key' => 'pk']);
        Http::fake(['*/rest/v1/game_version*' => Http::response(['message' => 'boom'], 500)]);

        $this->assertNull(Changes::loadGameReleases(Supabase::anon(), 'BA'));
    }
}
