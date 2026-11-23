<?php

namespace App\Domain\Gallery;

use App\Domain\Games\ReadAll;
use App\Supabase\Client;
use App\Supabase\Local\ItemStore;
use App\Supabase\Local\MapStore;
use App\Supabase\Local\Tables;
use App\Supabase\Local\Values;
use Closure;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Copies published items, reports, profiles and moderator capabilities from
 * Supabase into MariaDB and switches the hub to them (#601), for
 * `php artisan items:copy`.
 *
 * Maps must have moved first: a report can name a map, and the moderation
 * queue shows it, so reports need the map rows beside them.
 *
 * The live copy freezes writes first, waits out any Supabase request already
 * under way, replaces every local row with Supabase's, checks the counts
 * match, and only then switches. Anything short of matching counts switches
 * back to Supabase and changes nothing else.
 */
final class ItemCopy
{
    /** Parents before children, so foreign keys hold as rows arrive. Each with the key it is read in order of. */
    public const TABLES = ['profile' => 'user_id', 'user_capability' => 'user_id,capability', 'item' => 'id', 'report' => 'id'];

    /** Rows per insert. Containers can be large, so items go a few at a time. */
    private const CHUNK = 50;

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
        if (ItemStore::fresh() === ItemStore::MARIADB) {
            throw new RuntimeException('The hub already reads published items from MariaDB. Copying again would lose every write made since the switch.');
        }
        $waiting = self::waitingFor();
        if ($waiting !== null) {
            throw new RuntimeException($waiting);
        }

        ItemStore::set(ItemStore::FROZEN);
        ($this->say)('Publishing, editing, reporting, renaming and featuring are refused until the copy finishes.');
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
            ItemStore::set(ItemStore::SUPABASE);
            ($this->say)('The hub still reads published items from Supabase.');

            throw $e;
        }

        ItemStore::set(ItemStore::MARIADB);
        ($this->say)('The hub now reads and writes published items, reports, profiles and capabilities in MariaDB.');

        return $counts;
    }

    /** Why the copy cannot run yet, or null when maps have moved. */
    public static function waitingFor(): ?string
    {
        return MapStore::fresh() === MapStore::MARIADB ? null : 'Move maps first, with maps:copy.';
    }

    private function copy(string $table, string $key): int
    {
        $types = array_diff_key(Tables::columns($table), array_flip(Tables::MARIADB_ONLY[$table] ?? []));
        foreach ($this->supabase->rowsKeepingObjects($table, 'select=*&limit=1') as $sample) {
            $unknown = array_diff(array_keys($sample), array_keys($types), ['search']);
            if ($unknown !== []) {
                throw new RuntimeException("Supabase's {$table} has columns the copy does not know: ".implode(', ', $unknown));
            }
        }
        $order = implode(',', array_map(fn (string $column) => $column.'.asc', explode(',', $key)));
        $copied = 0;
        $offset = 0;
        do {
            // Named columns: `select=*` would bring `item.search`, which is not kept.
            $rows = $this->supabase->rowsKeepingObjects($table, 'select='.implode(',', array_keys($types)).'&order='.$order.'&offset='.$offset.'&limit='.ReadAll::PAGE);
            $stored = [];
            foreach ($rows as $row) {
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
