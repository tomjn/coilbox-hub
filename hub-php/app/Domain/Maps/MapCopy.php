<?php

namespace App\Domain\Maps;

use App\Domain\Games\ReadAll;
use App\Supabase\Client;
use App\Supabase\Local\MapStore;
use App\Supabase\Local\Tables;
use App\Supabase\Local\Values;
use Closure;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Copies maps, map authors and map packs from Supabase into MariaDB and
 * switches the hub to them (#599), for `php artisan maps:copy`.
 *
 * The live copy freezes map writes first, waits out any Supabase request
 * already under way, replaces every local row with Supabase's, checks the
 * counts match, and only then switches. Anything short of matching counts
 * switches back to Supabase and changes nothing else.
 */
final class MapCopy
{
    /** Parents before children, so foreign keys hold as rows arrive. Each with the key it is read in order of. */
    public const TABLES = [
        'map' => 'id', 'map_point' => 'id', 'map_author' => 'id', 'author_name' => 'key', 'author_alias' => 'from_key',
        'author_owner' => 'key', 'map_pack' => 'id', 'map_pack_map' => 'pack_id,map_name', 'map_download_source' => 'id',
        'map_mirror_host' => 'id', 'map_ownership_request' => 'id', 'map_source_conflict' => 'id',
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
        if (MapStore::fresh() === MapStore::MARIADB) {
            throw new RuntimeException('The hub already reads maps from MariaDB. Copying again would lose every write made since the switch.');
        }

        MapStore::set(MapStore::FROZEN);
        ($this->say)('Map writes are refused until the copy finishes.');
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
            MapStore::set(MapStore::SUPABASE);
            ($this->say)('The hub still reads maps from Supabase.');

            throw $e;
        }

        MapStore::set(MapStore::MARIADB);
        ($this->say)('The hub now reads and writes maps in MariaDB.');

        return $counts;
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
