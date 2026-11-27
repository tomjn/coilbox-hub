<?php

namespace App\Domain\Replays;

/**
 * The header and info chunk of a replay track (#665), as
 * `docs/reference/replay-track-format.md` in tomjn/coilbox sets them out. The
 * rest of the file is never read.
 */
final class TrackFile
{
    /** The track format version this reads. */
    public const FORMAT = 1;

    private const HEADER_BYTES = 96;

    /** The most a chunk may be, decompressed, and the most any `u32` in the header may say. */
    private const MAX_CHUNK = 16777215;

    private const BLOCK = 64;

    private const U8 = 1;

    private const U16 = 2;

    /** @param array<int, array{ally_team: int, leader: string}> $teams by team id */
    private function __construct(
        public readonly int $recorderVersion,
        public readonly int $framesPerSecond,
        public readonly int $firstFrame,
        public readonly int $lastFrame,
        public readonly string $gameId,
        public readonly array $teams,
    ) {}

    /** Reads the file at `$path`, or says why it will not as a reason and detail. */
    public static function read(string $path): self|array
    {
        $file = fopen($path, 'rb');
        try {
            $bytes = (string) fread($file, self::HEADER_BYTES);
            if (strlen($bytes) < self::HEADER_BYTES || ! str_starts_with($bytes, 'CBTK')) {
                return ['unreadable', 'The file does not start with a track header.'];
            }
            $header = unpack('vformat/vsize/vrecorder/vfps/Vfirst/Vlast', $bytes, 4) + unpack('Vblock/Vlength', $bytes, 36);
            if ($header['format'] !== self::FORMAT) {
                return ['unknown_format', "The file is track format {$header['format']}, and the hub reads format ".self::FORMAT.'.'];
            }
            if ($header['size'] < self::HEADER_BYTES || $header['last'] < $header['first']) {
                return ['unreadable', 'The track header does not make sense.'];
            }
            if ($header['block'] < 1 || $header['block'] > self::MAX_CHUNK || $header['length'] < 1 || $header['length'] > self::MAX_CHUNK
                || fstat($file)['size'] < $header['block'] * self::BLOCK + $header['length']) {
                return ['unreadable', 'The info chunk is not inside the file.'];
            }
            fseek($file, $header['block'] * self::BLOCK);
            $info = @zlib_decode((string) stream_get_contents($file, $header['length']), self::MAX_CHUNK);
        } finally {
            fclose($file);
        }

        $teams = $info === false ? null : self::teams($info);
        if ($teams === null) {
            return ['unreadable', 'The info chunk cannot be read.'];
        }

        return new self($header['recorder'], $header['fps'], $header['first'], $header['last'], bin2hex(substr($bytes, 64, 16)), $teams);
    }

    /** @return array<int, array{ally_team: int, leader: string}>|null */
    private static function teams(string $chunk): ?array
    {
        $length = strlen($chunk);
        if ($length < 4) {
            return null;
        }
        $count = unpack('V', $chunk)[1];
        if ($count > intdiv($length - 4, 12)) {
            return null;
        }
        $columns = [];
        for ($i = 0; $i < $count; $i++) {
            $column = unpack('vid/vtype/Voffset/Vcount', $chunk, 4 + 12 * $i);
            $columns[$column['id']] = $column;
        }
        // The bytes of one column. One that is not listed, or has another type, has none.
        $column = function (int $id, int $type) use ($columns, $chunk, $length): ?string {
            $column = $columns[$id] ?? null;
            if ($column === null || $column['type'] !== $type) {
                return '';
            }
            $bytes = $column['count'] * ($type === self::U16 ? 2 : 1);

            return $length < $column['offset'] + $bytes ? null : substr($chunk, $column['offset'], $bytes);
        };

        $ids = $column(172, self::U8);
        $allies = $column(173, self::U8);
        $lengths = $column(178, self::U16);
        $text = $column(179, self::U8);
        // A team id is one byte, so there are at most 256.
        if ($ids === null || $allies === null || $lengths === null || $text === null || $ids === ''
            || strlen($ids) > 256 || strlen($allies) !== strlen($ids) || ($lengths !== '' && strlen($lengths) !== 2 * strlen($ids))) {
            return null;
        }
        // No names listed at all means every name is empty.
        $lengths = $lengths === '' ? array_fill(0, strlen($ids), 0) : array_values(unpack('v*', $lengths));
        if (array_sum($lengths) > strlen($text)) {
            return null;
        }

        $teams = [];
        $at = 0;
        foreach ($lengths as $i => $size) {
            $teams[ord($ids[$i])] = ['ally_team' => ord($allies[$i]), 'leader' => substr($text, $at, $size)];
            $at += $size;
        }

        return count($teams) === strlen($ids) ? $teams : null;
    }
}
