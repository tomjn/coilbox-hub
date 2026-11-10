<?php

namespace Tests\Unit\Moderation;

use App\Domain\Moderation\AuthorMerge;
use App\Domain\Moderation\MapModeration;
use App\Domain\Moderation\MapPacks;
use App\Supabase\Supabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Ported from `lib/maps/moderation.test.ts`, `lib/maps/authorMerge.test.ts`
 * and the form rules in `lib/maps/packs.test.ts`.
 */
class MapsAndAuthorsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['hub.supabase.url' => 'https://sb.test', 'hub.supabase.publishable_key' => 'sb_publishable_test', 'hub.supabase.service_role_key' => 'service-key']);
    }

    private static function report(int $id, string $mapId, string $at): array
    {
        return ['id' => $id, 'map_id' => $mapId, 'source_archive' => 'comet.sd7', 'held_source_hash' => 'src-held',
            'reported_source_hash' => "src-reported-{$id}", 'reported_by' => null, 'at' => $at];
    }

    private const MAPS = [
        ['id' => 'map-one', 'map_name' => 'Comet Catcher 1.8', 'slug' => 'comet-catcher-1-8'],
        ['id' => 'map-two', 'map_name' => 'Tangerine 1.1', 'slug' => 'tangerine-1-1'],
    ];

    public function test_the_most_reported_map_is_first_whoever_reported_last(): void
    {
        $grouped = MapModeration::groupConflicts([
            self::report(1, 'map-two', '2026-08-19T12:00:00Z'),
            self::report(2, 'map-one', '2026-08-19T11:00:00Z'),
            self::report(3, 'map-one', '2026-08-19T10:00:00Z'),
        ], self::MAPS);

        $this->assertSame(['Comet Catcher 1.8', 'Tangerine 1.1'], array_column($grouped, 'mapName'));
        $this->assertSame([2, 3], array_column($grouped[0]['reports'], 'id'));
    }

    public function test_a_report_about_a_map_no_longer_held_is_dropped(): void
    {
        $this->assertSame([], MapModeration::groupConflicts([self::report(1, 'map-gone', '2026-08-19T12:00:00Z')], self::MAPS));
    }

    public function test_tags_are_folded_deduplicated_and_bounded(): void
    {
        $this->assertSame(['asymmetric', '1v1', 'chokepoint'], MapModeration::parseCuratedTags('Asymmetric, 1v1 , CHOKEPOINT'));
        $this->assertSame([], MapModeration::parseCuratedTags(''));
        $this->assertSame([], MapModeration::parseCuratedTags(' , ,'));
        $this->assertSame(['1v1'], MapModeration::parseCuratedTags('1v1, 1v1'));
        $this->assertSame(['asymmetric'], MapModeration::parseCuratedTags('asymmetric, '.str_repeat('a', 65)));
        $this->assertCount(MapModeration::CURATED_TAG_LIMIT, MapModeration::parseCuratedTags(implode(',', range(1, 30))));
    }

    public function test_the_field_a_map_already_carries_reads_back_unchanged(): void
    {
        $tags = ['asymmetric', '1v1'];

        $this->assertSame($tags, MapModeration::parseCuratedTags(MapModeration::curatedTagsField($tags)));
    }

    public function test_a_wildcard_in_a_map_name_is_searched_for_rather_than_obeyed(): void
    {
        Http::fake(['*' => Http::response([])]);

        MapModeration::search(Supabase::forToken('t'), 'comet_catcher 100%');

        Http::assertSent(fn (Request $r) => str_contains(urldecode($r->url()), 'map_name=ilike.%comet\_catcher 100\%%'));
    }

    public function test_an_empty_search_asks_the_database_nothing(): void
    {
        Http::fake();

        $this->assertSame([], MapModeration::search(Supabase::forToken('t'), '   '));
        Http::assertNothingSent();
    }

    public function test_a_merge_is_one_call_with_every_key_in_it(): void
    {
        Http::fake(['*' => Http::response(json_encode('merged'))]);

        $this->assertSame('merged', AuthorMerge::merge(Supabase::admin(), 'beherith', ['original from beherith', 'bherith'], ' Beherith ', '  '));
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/rpc/merge_author_keys') && $r->data() === [
            'p_into' => 'beherith', 'p_keys' => ['original from beherith', 'bherith'], 'p_name' => 'Beherith', 'p_note' => null,
        ]);
    }

    public function test_merge_answers_other_than_merged_and_chained_are_refusals(): void
    {
        Http::fakeSequence()->push(json_encode('chained'))->push(['message' => 'boom'], 400)->push(json_encode('something else'));
        $admin = Supabase::admin();

        $this->assertSame('chained', AuthorMerge::merge($admin, 'bherith', ['jools'], '', ''));
        $this->assertSame('refused', AuthorMerge::merge($admin, 'beherith', ['bherith'], '', ''));
        $this->assertSame('refused', AuthorMerge::merge($admin, 'beherith', ['bherith'], '', ''));
    }

    public function test_withdrawing_a_merge_says_whether_it_went(): void
    {
        Http::fake(['*' => Http::response('', 204)]);

        $this->assertTrue(AuthorMerge::unmerge(Supabase::admin(), 'bherith'));
    }

    public function test_ticked_keys_are_read_once_each_in_order_without_blanks(): void
    {
        $this->assertSame(['beherith', 'bherith'], AuthorMerge::selectedKeys(['beherith', '  ', 'bherith', 'beherith']));
        $this->assertSame(['beherith'], AuthorMerge::selectedKeys('beherith'));
        $this->assertSame([], AuthorMerge::selectedKeys(null));
    }

    public function test_a_search_matches_what_was_typed_anywhere_in_the_key(): void
    {
        $this->assertSame('%Beherith%', AuthorMerge::searchPattern(' Beherith '));
        $this->assertSame('%the\_yak 100\%%', AuthorMerge::searchPattern('the_yak 100%'));
        $this->assertSame('%a\\\\b%', AuthorMerge::searchPattern('a\\b'));
        $this->assertNull(AuthorMerge::searchPattern('   '));
        $this->assertNull(AuthorMerge::searchPattern(null));
    }

    public function test_each_spelling_is_offered_once_the_most_used_first(): void
    {
        $this->assertSame(['Beherith', '[teh]Beherith (mysterme@gmail.com)', 'bherith'], AuthorMerge::nameChoices([
            ['key' => 'beherith', 'raw' => 'Beherith', 'credits' => 1],
            ['key' => 'beherith', 'raw' => '[teh]Beherith (mysterme@gmail.com)', 'credits' => 2],
            ['key' => 'bherith', 'raw' => 'Beherith', 'credits' => 3],
            ['key' => 'bherith', 'raw' => 'bherith', 'credits' => 1],
        ]));
    }

    public function test_merges_are_grouped_under_the_person_they_point_at(): void
    {
        $grouped = AuthorMerge::byTarget(AuthorMerge::markChains([
            ['from_key' => 'bherith', 'to_key' => 'beherith', 'note' => null, 'set_at' => '2026-08-19T10:00:00Z'],
            ['from_key' => 'jools2', 'to_key' => 'jools', 'note' => null, 'set_at' => '2026-08-19T10:00:00Z'],
            ['from_key' => 'original from beherith', 'to_key' => 'beherith', 'note' => null, 'set_at' => '2026-08-19T10:00:00Z'],
        ]));

        $this->assertSame(['beherith', 'jools'], array_keys($grouped));
        $this->assertSame(['bherith', 'original from beherith'], array_column($grouped['beherith'], 'fromKey'));
    }

    public function test_an_alias_whose_target_is_itself_merged_is_marked(): void
    {
        $marked = AuthorMerge::markChains([
            ['from_key' => 'bherith', 'to_key' => 'beherith', 'note' => null, 'set_at' => '2026-08-19T10:00:00Z'],
            ['from_key' => 'beherith', 'to_key' => 'beherith the mapper', 'note' => null, 'set_at' => '2026-08-19T11:00:00Z'],
        ]);

        $this->assertSame([['bherith', true], ['beherith', false]], array_map(fn ($a) => [$a['fromKey'], $a['chained']], $marked));
    }

    public function test_a_pasted_list_is_split_into_trimmed_lines_and_a_comma_is_part_of_a_name(): void
    {
        $this->assertSame(['Isis 1.3', 'comet_catcher_remake_1.8.sd7'], MapPacks::parsePastedMapNames("Isis 1.3\r\n\n  comet_catcher_remake_1.8.sd7  \n"));
        $this->assertSame(['Red Comet, Remake 2'], MapPacks::parsePastedMapNames('Red Comet, Remake 2'));
    }

    public function test_pack_text_is_trimmed_a_blank_blurb_is_none_and_a_blank_title_is_refused(): void
    {
        $this->assertSame(['title' => 'BAR maps', 'blurb' => null], MapPacks::readText('  BAR maps ', '  '));
        $this->assertSame(['title' => 'BAR maps', 'blurb' => 'Every map'], MapPacks::readText('BAR maps', ' Every map '));
        $this->assertNull(MapPacks::readText('   ', ''));
        $this->assertNull(MapPacks::readText(str_repeat('x', 129), ''));
        $this->assertNull(MapPacks::readText('BAR maps', str_repeat('x', 2001)));
    }

    public function test_adding_says_how_many_lines_matched_and_names_the_ones_that_did_not(): void
    {
        $this->assertSame('1 line matched a map. No map is called: Nowhere 9, Elsewhere.', MapPacks::addedMessage([
            ['wanted' => 'isis_1.3.sd7', 'map_name' => 'Isis 1.3'],
            ['wanted' => 'Nowhere 9', 'map_name' => null],
            ['wanted' => 'Elsewhere', 'map_name' => null],
        ]));
        $this->assertSame('1 line matched a map. Maps already in the pack stay where they were.', MapPacks::addedMessage([['wanted' => 'Isis 1.3', 'map_name' => 'Isis 1.3']]));
    }
}
