<?php

namespace App\Domain\Games;

use App\Supabase\Client;
use App\Supabase\Local\GameStore;
use App\Supabase\Local\Tables;
use App\Supabase\Local\Values;
use Closure;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Copies the game catalogue from Supabase into MariaDB and switches the hub
 * to it (#597), for `php artisan games:copy`.
 *
 * The live copy freezes writes first, waits out any Supabase request already
 * under way, replaces every local row with Supabase's, checks the counts
 * match, and only then switches. Anything short of matching counts switches
 * back to Supabase and changes nothing else.
 */
final class CatalogueCopy
{
    /** Parents before children, so foreign keys hold as rows arrive. */
    public const TABLES = [
        'game', 'game_version', 'game_faction', 'game_unit', 'game_unit_revision',
        'game_download_source', 'game_download_offer', 'game_ownership_request',
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
        foreach (self::TABLES as $table) {
            $counts[$table] = ['supabase' => $this->supabaseCount($table), 'mariadb' => DB::table($table)->count()];
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
        if (GameStore::fresh() === GameStore::MARIADB) {
            throw new RuntimeException('The hub already reads games from MariaDB. Copying again would lose every write made since the switch.');
        }

        GameStore::set(GameStore::FROZEN);
        ($this->say)('Game writes are refused until the copy finishes.');
        try {
            // A write that read the old state before the freeze finishes, or
            // times out, within one Supabase timeout.
            ($this->wait)((int) config('hub.supabase.timeout', 10));

            $counts = DB::transaction(function () {
                foreach (array_reverse(self::TABLES) as $table) {
                    DB::table($table)->delete();
                }
                $counts = [];
                foreach (self::TABLES as $table) {
                    $expected = $this->supabaseCount($table);
                    $copied = $this->copy($table);
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
            GameStore::set(GameStore::SUPABASE);
            ($this->say)('The hub still reads games from Supabase.');

            throw $e;
        }

        GameStore::set(GameStore::MARIADB);
        ($this->say)('The hub now reads and writes games in MariaDB.');

        return $counts;
    }

    private function copy(string $table): int
    {
        $types = Tables::columns($table);
        $copied = 0;
        $offset = 0;
        do {
            $rows = $this->supabase->rowsKeepingObjects($table, 'select=*&order=id.asc&offset='.$offset.'&limit='.ReadAll::PAGE);
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

    private function supabaseCount(string $table): int
    {
        $held = $this->supabase->from($table)->select('id', 'exact', true)->getWithCount();

        return (int) ($held['count'] ?? throw new RuntimeException("Supabase gave no count for {$table}."));
    }
}
