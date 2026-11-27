<?php

namespace Tests\Concerns;

use App\Jobs\CheckReplay;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Unit\Mirrors\MirrorFixtures;

/**
 * Real replay fixtures put through the real check (#663). Each
 * `<fixture>.summary.json` is what Coilbox's own reader gives for the replay
 * beside it.
 */
trait ChecksReplays
{
    use MirrorFixtures;

    private const USER = '11111111-1111-1111-1111-111111111111';

    private const BAR = '3d92086b0b2c592e3aea5585ff7aa2fa';

    private const SF = '9cf5d76a4468b019403831ac99faf0f7';

    /** A mirror that takes replays, which a check fetches the file from. */
    private function replayMirror(): void
    {
        DB::table('mirror')->insert([
            'id' => 'jj-darkstars', 'name' => 'jj.darkstars.co.uk', 'base_url' => 'https://mirror.test',
            'public_key' => self::fixtureKeys()['mirror_public_key'], 'enabled' => true, 'healthy' => true,
            'takes_replays' => true, 'takes_engines' => true, 'created_at' => '2026-11-17 12:00:00.000000',
        ]);
    }

    private static function file(string $fixture): string
    {
        return file_get_contents(base_path('tests/Fixtures/replays/'.$fixture.'.sdfz'));
    }

    /** @return array<string, mixed> */
    private static function summary(string $fixture): array
    {
        return json_decode(file_get_contents(base_path('tests/Fixtures/replays/'.$fixture.'.summary.json')), true);
    }

    /**
     * A replay waiting for its check, uploaded by USER as `$name`, with the
     * mirror serving `$served`. `$bytes` replaces the fixture's file.
     */
    private function uploaded(string $gameId, string $fixture, array $summary, string $name, string|PromiseInterface|null $served = null, ?string $bytes = null): void
    {
        $bytes ??= self::file($fixture);
        DB::table('replay')->insert([
            'game_id' => $gameId, 'state' => 'checking', 'uploader' => self::USER, 'sha256' => hash('sha256', $bytes),
            'size' => strlen($bytes), 'upload_mirror_id' => 'jj-darkstars', 'upload_id' => '33333333-3333-4333-8333-333333333333',
            'summary' => json_encode($summary, JSON_PRESERVE_ZERO_FRACTION), 'reserved_until' => null, 'received_at' => '2026-11-26 09:00:00.000000',
            'created_at' => '2026-11-26 09:00:00.000000', 'updated_at' => '2026-11-26 09:00:00.000000',
        ]);
        DB::table('replay_sender')->insert(['game_id' => $gameId, 'user_id' => self::USER, 'name' => $name, 'sent_at' => '2026-11-26 09:00:00.000000']);
        Http::fake(['https://mirror.test/replays/'.$gameId.'.sdfz' => $served instanceof PromiseInterface ? $served : Http::response($served ?? $bytes)]);
    }

    private function check(string $gameId): void
    {
        (new CheckReplay($gameId))->handle();
    }
}
