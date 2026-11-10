<?php

namespace Tests\Unit\Games;

use App\Domain\Games\Js;
use App\Domain\Games\Submit;
use App\Supabase\Supabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use stdClass;
use Tests\TestCase;

/** `lib/games/submit.test.ts`, plus digests pinned to what the Next app computes. */
class SubmitTest extends TestCase
{
    private const USER = '11111111-1111-1111-1111-111111111111';

    private function unit(string $name, array $overrides = []): array
    {
        return $overrides + [
            'name' => $name,
            'full_name' => null,
            'faction_key' => null,
            'build_options' => [],
            'stats' => new stdClass,
            'morph_targets' => [],
        ];
    }

    public function test_digests_match_the_next_app_byte_for_byte(): void
    {
        // Both values were computed by running `unitDigest` from the Next app
        // on the same units. A mismatch would make every unit read as changed.
        $stats = json_decode('{"health":5000,"speed":1.25,"big":1e21,"tiny":0.0000001,"small":0.000001,"neg":-0,"int":890.0,"arr":[1,"x",null,true],"nested":{"b":1,"a":"é \""}}');
        $unit = $this->unit('armcom', [
            'full_name' => 'Commander',
            'faction_key' => 'armada',
            'build_options' => ['armsolar', 'armmex'],
            'stats' => $stats,
            'morph_targets' => json_decode('[{"into":"b2","time":10},{"into":"a1"}]'),
        ]);
        $this->assertSame('1336988dbf540e88972671f3d64276e6383ff835f3a2816af73bc66a778ee72b', Submit::unitDigest($unit));
        $this->assertSame('f485580ac708c6b067824aaa0aa8165d85812cedda070fe0e28d5d4238a7820f', Submit::unitDigest($this->unit('x')));
    }

    public function test_canonical_json_sorts_keys_by_code_unit_and_writes_numbers_as_javascript_does(): void
    {
        $value = json_decode('{"z":1,"😀":2,"�":3,"a":[1.5,1e15,123456789012345680000]}');
        $this->assertSame('{"a":[1.5,1000000000000000,123456789012345680000],"z":1,"😀":2,"�":3}', Js::canonicalJson($value));
    }

    public function test_the_digest_is_a_hash_over_the_normalised_facts(): void
    {
        $digest = Submit::unitDigest($this->unit('armcom', ['full_name' => 'Commander']));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $digest);
        $this->assertSame($digest, Submit::unitDigest($this->unit('armcom', ['full_name' => 'Commander'])));
    }

    public function test_a_changed_stat_changes_the_digest(): void
    {
        $this->assertNotSame(
            Submit::unitDigest($this->unit('armcom', ['stats' => (object) ['health' => 5000]])),
            Submit::unitDigest($this->unit('armcom', ['stats' => (object) ['health' => 4500]])),
        );
    }

    public function test_absent_and_null_optional_text_are_one_fact(): void
    {
        $this->assertSame(Submit::unitDigest($this->unit('armcom')), Submit::unitDigest($this->unit('armcom', ['full_name' => null])));
    }

    public function test_build_options_reach_one_digest_whatever_order(): void
    {
        $this->assertSame(
            Submit::unitDigest($this->unit('armcom', ['build_options' => ['armmex', 'armsolar']])),
            Submit::unitDigest($this->unit('armcom', ['build_options' => ['armsolar', 'armmex']])),
        );
    }

    public function test_changed_morphs_are_different_facts(): void
    {
        $this->assertNotSame(
            Submit::unitDigest($this->unit('armcom')),
            Submit::unitDigest($this->unit('armcom', ['morph_targets' => [(object) ['into' => 'armcom1']]])),
        );
    }

    public function test_morph_targets_reach_one_digest_whatever_order(): void
    {
        $this->assertSame(
            Submit::unitDigest($this->unit('armcom', ['morph_targets' => [(object) ['into' => 'armcom1'], (object) ['into' => 'armcom2']]])),
            Submit::unitDigest($this->unit('armcom', ['morph_targets' => [(object) ['into' => 'armcom2'], (object) ['into' => 'armcom1']]])),
        );
    }

    public function test_one_request_carries_the_whole_batch_and_answers_in_request_order(): void
    {
        config(['hub.supabase.url' => 'https://sb.test', 'hub.supabase.publishable_key' => 'pk']);
        Http::fake(['https://sb.test/rest/v1/rpc/submit_game_facts' => Http::response([
            ['kind' => 'unit', 'name' => 'armmex', 'outcome' => 'refused', 'said' => 'nope'],
            ['kind' => 'unit', 'name' => 'armcom', 'outcome' => 'unchanged', 'said' => null],
            ['kind' => 'faction', 'name' => 'armada', 'outcome' => 'accepted', 'said' => null],
        ])]);

        $built = Submit::buildGameSubmission([
            'shortname' => 'BA',
            'release' => '1.9.0',
            'complete' => true,
            'start_units' => ['armcom'],
            'factions' => [['key' => 'armada', 'name' => 'Armada']],
            'units' => [$this->unit('armcom'), $this->unit('armmex')],
            'display_name' => 'Balanced Annihilation',
            'description' => null,
            'base_game' => 'BAR',
            'links' => null,
        ]);
        $written = Submit::submitGameFacts(Supabase::anon(), $built, self::USER);

        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $built['units'][0]['facts_digest']);
        $this->assertTrue($written['ok']);
        $this->assertSame(['faction:accepted', 'unit:unchanged', 'unit:refused'], array_map(fn ($r) => $r['kind'].':'.$r['outcome'], $written['results']));
        $this->assertSame('nope', $written['results'][2]['said']);
        $this->assertArrayNotHasKey('said', $written['results'][0]);

        Http::assertSent(function (Request $request) {
            $body = json_decode($request->body(), true);

            return $body['p_submitted_by'] === self::USER
                && $body['p_submission']['units'][0]['unit']['name'] === 'armcom'
                && str_contains($request->body(), '"stats":{}');
        });
    }

    public function test_a_database_failure_is_one_refusal_for_the_request(): void
    {
        config(['hub.supabase.url' => 'https://sb.test', 'hub.supabase.publishable_key' => 'pk']);
        Http::fake(['https://sb.test/*' => Http::response(['code' => 'XX000', 'message' => 'boom'], 500)]);

        $written = Submit::submitGameFacts(Supabase::anon(), Submit::buildGameSubmission([
            'shortname' => 'BA', 'release' => '1.9.0', 'complete' => false, 'start_units' => null, 'factions' => null,
            'units' => [], 'display_name' => null, 'description' => null, 'base_game' => null, 'links' => null,
        ]), self::USER);

        $this->assertFalse($written['ok']);
    }
}
