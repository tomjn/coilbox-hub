<?php

namespace Tests\Unit\Replays;

use App\Domain\Replays\ReplayException;
use App\Domain\Replays\ReplayReader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Each case changes a real replay and expects an error, never a partial
 * result. The bytes are the decompressed Splinter Faction fixture, read as an
 * uncompressed `.sdf`.
 */
class DamagedReplayTest extends TestCase
{
    private static function fixture(): string
    {
        return file_get_contents(__DIR__.'/../../Fixtures/replays/sf-tumult.sdfz');
    }

    private static function bytes(): string
    {
        return gzdecode(self::fixture());
    }

    /** The offset of the first packet of type `$id` in the stream. */
    private static function packet(string $b, int $id): int
    {
        $script = unpack('V', $b, 304)[1];
        $stream = unpack('V', $b, 308)[1];
        $at = 352 + $script;
        while ($at < 352 + $script + $stream) {
            $len = unpack('V', $b, $at + 4)[1];
            if (ord($b[$at + 8]) === $id) {
                return $at + 8;
            }
            $at += 8 + $len;
        }
        throw new \LogicException("no packet of type {$id}");
    }

    private static function put(string $b, int $at, string $bytes): string
    {
        return substr_replace($b, $bytes, $at, strlen($bytes));
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function damaged(): array
    {
        $b = self::bytes();
        $command = self::packet($b, 11);
        $chat = self::packet($b, 35);
        $end = 352 + unpack('V', $b, 304)[1] + unpack('V', $b, 308)[1];

        return [
            'not a replay' => [random_bytes(400), 'not a Recoil replay'],
            'empty' => ['', 'not a Recoil replay'],
            'shorter than the header' => [substr($b, 0, 300), 'not a Recoil replay'],
            'a cut gzip file' => [substr(self::fixture(), 0, 30000), 'could not be decompressed'],
            'version 4' => [self::put($b, 16, pack('V', 4)), 'format version 4'],
            'a larger header' => [self::put($b, 20, pack('V', 356)), '356 byte'],
            'a negative size' => [self::put($b, 304, pack('V', 0xFFFFFFFF)), 'negative'],
            'cut short' => [substr($b, 0, -10), 'is cut short'],
            'cut in the stream' => [substr($b, 0, $end - 100), 'is cut short'],
            'bytes after the end' => [$b."\0", 'has bytes after its end'],
            'a packet longer than the stream' => [self::put($b, $command - 4, pack('V', 0x7FFFFFFF)), 'claims'],
            'a command with the wrong size field' => [self::put($b, $command + 1, pack('v', 3)), 'does not fit'],
            'a command with too many parameters' => [self::put($b, $command + 13, pack('V', 1000)), 'does not fit'],
            'a message with no end' => [self::put($b, $chat + unpack('v', $b, $chat + 1)[1] - 1, 'x'), 'does not fit'],
            'more samples than the block holds' => [self::put($b, $end + 1 + 20, pack('V', 36)), 'sample counts'],
        ];
    }

    #[DataProvider('damaged')]
    public function test_a_damaged_replay_is_an_error(string $bytes, string $message): void
    {
        $this->expectException(ReplayException::class);
        $this->expectExceptionMessage($message);

        (new ReplayReader)->readBytes($bytes);
    }

    public function test_the_unchanged_bytes_read(): void
    {
        $this->assertSame(501, (new ReplayReader)->readBytes(self::bytes())->header->gameTime);
    }

    public function test_a_file_that_decompresses_past_the_cap_is_refused(): void
    {
        $gz = gzencode(str_repeat("\0", ReplayReader::MAX_DECOMPRESSED + (1 << 20)));

        $this->expectException(ReplayException::class);
        $this->expectExceptionMessage('This replay is too large: it decompresses to more than 64 MB.');

        (new ReplayReader)->readBytes($gz);
    }

    public function test_a_missing_file_is_an_error(): void
    {
        $this->expectException(ReplayException::class);

        (new ReplayReader)->read(__DIR__.'/no-such-replay.sdfz');
    }

    public function test_changed_bytes_give_a_result_or_an_error_and_nothing_else(): void
    {
        $b = self::bytes();
        $start = 352 + unpack('V', $b, 304)[1];
        $reader = new ReplayReader;
        mt_srand(661);
        $outcomes = ['read' => 0, 'refused' => 0];
        for ($i = 0; $i < 300; $i++) {
            $changed = $b;
            for ($n = 0; $n < 3; $n++) {
                $changed[mt_rand($start, strlen($b) - 1)] = chr(mt_rand(0, 255));
            }
            try {
                $reader->readBytes($changed);
                $outcomes['read']++;
            } catch (ReplayException) {
                $outcomes['refused']++;
            }
        }
        $this->assertSame(300, $outcomes['read'] + $outcomes['refused']);
        $this->assertGreaterThan(0, $outcomes['refused']);
    }

    public function test_every_cut_of_a_small_replay_is_an_error(): void
    {
        $b = gzdecode(file_get_contents(__DIR__.'/../../Fixtures/replays/mf-valles.sdfz'));
        $reader = new ReplayReader;
        $errors = 0;
        for ($len = 0; $len < strlen($b); $len += 7) {
            try {
                $reader->readBytes(substr($b, 0, $len));
            } catch (ReplayException) {
                $errors++;
            }
        }
        $this->assertSame(intdiv(strlen($b) - 1, 7) + 1, $errors);
    }
}
