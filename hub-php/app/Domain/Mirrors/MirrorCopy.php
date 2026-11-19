<?php

namespace App\Domain\Mirrors;

use App\Domain\Games\ReadAll;
use App\Supabase\Client;
use App\Supabase\Local\GameStore;
use App\Supabase\Local\MapStore;
use App\Supabase\Local\MirrorStore;
use App\Supabase\Local\Tables;
use App\Supabase\Local\Values;
use Closure;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Copies the hub mirrors, their archives and the catalogue from Supabase into
 * MariaDB and switches the hub to them (#600), for `php artisan mirrors:copy`.
 *
 * Games and maps must have moved first. Supabase's `game_browse`, `map_browse`
 * and `map_facts` read the mirror tables inside Postgres, so while either
 * area still answers from Supabase it would list mirror copies from rows the
 * hub no longer writes.
 *
 * The live copy freezes mirror writes first, waits out any Supabase request
 * already under way, replaces every local row with Supabase's, checks the
 * counts match, and only then switches. Anything short of matching counts
 * switches back to Supabase and changes nothing else.
 */
final class MirrorCopy
{
    /** Parents before children, so foreign keys hold as rows arrive. Each with the key it is read in order of. */
    public const TABLES = [
        'mirror' => 'id', 'archive' => 'id', 'archive_mirror' => 'archive_id,mirror_id', 'archive_mismatch' => 'id', 'catalogue_state' => 'id',
    ];

    /** Rows per insert. */
    private const CHUNK = 500;

    /**
     * @param  Closure(string): void  $say
     * @param  Closure(int): void  $wait  sleeps for the given seconds
     */
    public function __construct(
        private readonly Client $supabase,
        private readonly Closure $say,
        private readonly Closure $wait,
    ) {}

    /**
     * Rows per table in Supabase and in MariaDB. Writes nothing.
     *
     * @return array<string, array{supabase: int, mariadb: int}>
     */
    public function counts(): array
    {
        $counts = [];
        foreach (self::TABLES as $table => $key) {
            $counts[$table] = ['supabase' => $this->supabaseCount($table, $key), 'mariadb' => DB::table($table)->count()];
        }

        return $counts;
    }

    /**
     * The live copy. Answers the counts it checked.
     *
     * @return array<string, array{supabase: int, mariadb: int}>
     */
    public function run(): array
    {
        if (MirrorStore::fresh() === MirrorStore::MARIADB) {
            throw new RuntimeException('The hub already reads the mirrors from MariaDB. Copying again would lose every write made since the switch.');
        }
        $waiting = self::waitingFor();
        if ($waiting !== null) {
            throw new RuntimeException($waiting);
        }

        MirrorStore::set(MirrorStore::FROZEN);
        ($this->say)('Mirror reports, uploads and mirror changes are refused until the copy finishes.');
        try {
            // A write that read the old state before the freeze finishes, or
            // times out, within one Supabase timeout.
            ($this->wait)((int) config('hub.supabase.timeout', 10));

            $counts = DB::transaction(function () {
                foreach (array_reverse(array_keys(self::TABLES)) as $table) {
                    DB::table($table)->delete();
                }
                $counts = [];
                foreach (self::TABLES as $table => $key) {
                    $expected = $this->supabaseCount($table, $key);
                    $copied = $this->copy($table, $key);
                    $held = DB::table($table)->count();
                    ($this->say)(sprintf('%s: Supabase %d, copied %d, MariaDB %d', $table, $expected, $copied, $held));
                    if ($expected !== $copied || $copied !== $held) {
                        throw new RuntimeException("{$table} did not copy in full: Supabase has {$expected} rows, MariaDB {$held}.");
                    }
                    $counts[$table] = ['supabase' => $expected, 'mariadb' => $held];
                }

                return $counts;
            });
        } catch (\Throwable $e) {
            MirrorStore::set(MirrorStore::SUPABASE);
            ($this->say)('The hub still reads the mirrors from Supabase.');

            throw $e;
        }

        MirrorStore::set(MirrorStore::MARIADB);
        ($this->say)('The hub now reads and writes the mirrors in MariaDB.');

        return $counts;
    }

    /** Why the mirrors cannot move yet, or null when games and maps already have. */
    public static function waitingFor(): ?string
    {
        $behind = array_keys(array_filter([
            'games' => GameStore::fresh() !== GameStore::MARIADB,
            'maps' => MapStore::fresh() !== MapStore::MARIADB,
        ]));

        return $behind === [] ? null
            : 'Move '.implode(' and ', $behind).' first, with '.implode(' and ', array_map(fn ($area) => $area.':copy', $behind)).'.';
    }

    private function copy(string $table, string $key): int
    {
        $types = Tables::columns($table);
        $order = implode(',', array_map(fn (string $column) => $column.'.asc', explode(',', $key)));
        $copied = 0;
        $offset = 0;
        do {
            $rows = $this->supabase->rowsKeepingObjects($table, 'select=*&order='.$order.'&offset='.$offset.'&limit='.ReadAll::PAGE);
            $stored = [];
            foreach ($rows as $row) {
                $unknown = array_diff(array_keys($row), array_keys($types));
                if ($unknown !== []) {
                    throw new RuntimeException("Supabase's {$table} has columns the copy does not know: ".implode(', ', $unknown));
                }
                $record = [];
                foreach ($types as $column => $type) {
                    $record[$column] = Values::write($type, $row[$column] ?? null);
                }
                $stored[] = $record;
            }
            foreach (array_chunk($stored, self::CHUNK) as $chunk) {
                DB::table($table)->insert($chunk);
            }
            $copied += count($rows);
            $offset += count($rows);
        } while ($rows !== []);

        return $copied;
    }

    private function supabaseCount(string $table, string $key): int
    {
        $held = $this->supabase->from($table)->select(explode(',', $key)[0], 'exact', true)->getWithCount();

        return (int) ($held['count'] ?? throw new RuntimeException("Supabase gave no count for {$table}."));
    }
}
