<?php

namespace Tests\Unit\Mirrors;

use App\Domain\Mirrors\B64;
use App\Domain\Mirrors\Report;
use App\Domain\Mirrors\Signing;
use PHPUnit\Framework\TestCase;

class ReportTest extends TestCase
{
    use MirrorFixtures;

    public function test_fixture_sync_and_upload_reports_open_verify_and_validate(): void
    {
        $key = self::fixtureKeys()['mirror_public_key'];

        foreach (['report-sync.json', 'report-upload.json'] as $name) {
            $opened = Report::open(self::fixture($name));

            $this->assertIsArray($opened, $name);
            $this->assertSame('jj-darkstars', $opened['mirror']);
            $this->assertTrue(Report::verify($opened['payloadBytes'], $opened['sig'], $key), $name);
            $this->assertTrue(Report::validate(json_decode($opened['payloadBytes'], true)), $name);
        }
    }

    public function test_report_fails_under_another_mirrors_key(): void
    {
        $opened = Report::open(self::fixture('report-sync.json'));

        $this->assertFalse(Report::verify($opened['payloadBytes'], $opened['sig'], self::fixtureKeys()['other_mirror_public_key']));
    }

    public function test_sync_event_with_an_upload_id_is_invalid(): void
    {
        $payload = $this->syncPayload();
        $payload['upload'] = 'abc-123';

        $this->assertFalse(Report::validate($payload));
    }

    public function test_duplicate_or_uppercase_hash_in_held_is_invalid(): void
    {
        $hash = str_repeat('a', 64);

        $payload = $this->syncPayload();
        $payload['held'] = [$hash, $hash];
        $this->assertFalse(Report::validate($payload));

        $payload['held'] = [str_repeat('A', 64)];
        $this->assertFalse(Report::validate($payload));

        $payload['held'] = [[$hash], $hash];
        $this->assertFalse(Report::validate($payload));

        $payload['held'] = [$hash];
        $this->assertTrue(Report::validate($payload));
    }

    public function test_padded_base64_payload_is_bad_request(): void
    {
        // A payload of 3n+1 bytes encodes to padding with the standard alphabet.
        $payload = '{"mirror":"jj-darkstars","pad":""}';
        $payload = str_replace('""', '"'.str_repeat('x', (1 - strlen($payload)) % 3 + 3).'"', $payload);
        $this->assertSame(1, strlen($payload) % 3);
        $padded = base64_encode($payload);
        $this->assertStringEndsWith('==', $padded);

        $sig = B64::encode(Signing::sign(Signing::CTX_REPORT, $payload, sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair())));

        $this->assertSame('bad_request', Report::open(json_encode(['payload' => $padded, 'sig' => $sig])));
        $this->assertIsArray(Report::open(json_encode(['payload' => B64::encode($payload), 'sig' => $sig])));
    }

    public function test_open_rejects_other_malformed_bodies(): void
    {
        $this->assertSame('bad_request', Report::open('not json'));
        $this->assertSame('bad_request', Report::open('[]'));
        $this->assertSame('bad_request', Report::open(json_encode(['payload' => 1, 'sig' => 'x'])));

        $badMirror = B64::encode(json_encode(['mirror' => "jj\n"]));
        $sig = B64::encode(str_repeat('a', 64));
        $this->assertSame('bad_request', Report::open(json_encode(['payload' => $badMirror, 'sig' => $sig])));

        $good = B64::encode(json_encode(['mirror' => 'jj-darkstars']));
        $this->assertSame('bad_request', Report::open(json_encode(['payload' => $good, 'sig' => B64::encode('short')])));
    }

    /** @return array<string, mixed> */
    private function syncPayload(): array
    {
        return json_decode(B64::decode(json_decode(self::fixture('report-sync.json'), true)['payload']), true);
    }
}
