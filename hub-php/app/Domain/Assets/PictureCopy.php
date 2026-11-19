<?php

namespace App\Domain\Assets;

use App\Domain\Games\ReadAll;
use App\Supabase\Client;
use App\Supabase\Local\PictureStore;
use App\Supabase\Local\StagedObjects;
use App\Supabase\Local\Tables;
use App\Supabase\Local\Values;
use Closure;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Copies the picture records from Supabase into MariaDB, and the
 * `staged-pictures` bucket onto the droplet's disk, then switches the hub to
 * them (#598), for `php artisan pictures:copy`.
 *
 * Objects are copied first while uploads still work, which is most of the
 * time the copy takes. Then picture writes and uploads are frozen, every row
 * is replaced, the objects that changed meanwhile are copied, and the counts
 * and sizes are checked. Only a full match switches. Anything else switches
 * back to Supabase.
 */
final class PictureCopy
{
    /** Parents before children, so foreign keys hold as rows arrive. */
    public const TABLES = [
        'asset', 'asset_event', 'asset_licence', 'asset_source_conflict', 'asset_upload_ip', 'asset_withdrawal',
        'staged_object_deletion',
    ];

    /** Rows per insert. */
    private const CHUNK = 500;

    /** Objects per Storage list request, Supabase's own default. */
    private const LIST_PAGE = 100;

    /**
     * @param  Closure(string): void  $say
     * @param  Closure(int): void  $wait  sleeps for the given seconds
     * @param  Closure(string): (float|false)  $freeSpace  free bytes on the disk holding a folder
     */
    public function __construct(
        private readonly Client $supabase,
        private readonly Closure $say,
        private readonly Closure $wait,
        private readonly Closure $freeSpace,
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
     * The bucket in Supabase against the disk, and the room left. Writes nothing.
     *
     * @return array{supabase_objects: int, supabase_bytes: int, local_objects: int, local_bytes: int, to_copy: int, to_copy_bytes: int, free_bytes: float|false}
     */
    public function objectCounts(): array
    {
        $listed = $this->listBucket();
        $missing = $this->changed($listed);

        return [
            'supabase_objects' => count($listed),
            'supabase_bytes' => array_sum(array_column($listed, 'bytes')),
            'local_objects' => DB::table('staged_object')->count(),
            'local_bytes' => (int) DB::table('staged_object')->sum('bytes'),
            'to_copy' => count($missing),
            'to_copy_bytes' => array_sum(array_column($missing, 'bytes')),
            'free_bytes' => ($this->freeSpace)($this->existingFolder()),
        ];
    }

    /**
     * The live copy. Answers the row counts it checked.
     *
     * @return array<string, array{supabase: int, mariadb: int}>
     */
    public function run(): array
    {
        if (PictureStore::fresh() === PictureStore::MARIADB) {
            throw new RuntimeException('The hub already reads pictures from MariaDB. Copying again would lose every picture uploaded or moderated since the switch.');
        }

        // While uploads still land in Supabase.
        $this->syncObjects($this->listBucket());

        PictureStore::set(PictureStore::FROZEN);
        ($this->say)('Picture writes and uploads are refused until the copy finishes.');
        try {
            // A write that read the old state before the freeze finishes, or
            // times out, within one Supabase timeout.
            ($this->wait)((int) config('hub.supabase.timeout', 10));

            $listed = $this->listBucket();
            $this->syncObjects($listed);
            $this->checkObjects($listed);

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
            PictureStore::set(PictureStore::SUPABASE);
            ($this->say)('The hub still reads pictures from Supabase.');

            throw $e;
        }

        PictureStore::set(PictureStore::MARIADB);
        ($this->say)('The hub now reads and writes pictures in MariaDB, and staged pictures on this disk.');

        return $counts;
    }

    /**
     * Every object in the bucket, walking its folders.
     *
     * @return array<string, array{path: string, bytes: int, mime: ?string, created_at: string, updated_at: string}>
     */
    private function listBucket(): array
    {
        $objects = [];
        $folders = [''];
        while ($folders !== []) {
            $prefix = array_shift($folders);
            $offset = 0;
            do {
                $page = $this->supabase->listObjects(StagedObjects::BUCKET, $prefix, self::LIST_PAGE, $offset);
                foreach ($page as $item) {
                    $path = ($prefix === '' ? '' : $prefix.'/').$item['name'];
                    if (($item['id'] ?? null) === null) {
                        $folders[] = $path;

                        continue;
                    }
                    if (! StagedObjects::valid($path)) {
                        throw new RuntimeException("The bucket holds {$path}, which cannot be a file on disk.");
                    }
                    $objects[$path] = [
                        'path' => $path,
                        'bytes' => (int) ($item['metadata']['size'] ?? 0),
                        'mime' => $item['metadata']['mimetype'] ?? null,
                        'created_at' => Values::stored($item['created_at']),
                        'updated_at' => Values::stored($item['updated_at'] ?? $item['created_at']),
                    ];
                }
                $offset += count($page);
            } while (count($page) === self::LIST_PAGE);
        }
        ksort($objects, SORT_STRING);

        return $objects;
    }

    /**
     * The listed objects the disk does not hold as they are in Supabase.
     *
     * @param  array<string, array{path: string, bytes: int, mime: ?string, created_at: string, updated_at: string}>  $listed
     * @return list<array{path: string, bytes: int, mime: ?string, created_at: string, updated_at: string}>
     */
    private function changed(array $listed): array
    {
        $held = [];
        foreach (DB::table('staged_object')->get() as $row) {
            $held[$row->path] = $row;
        }
        $changed = [];
        foreach ($listed as $path => $object) {
            $row = $held[$path] ?? null;
            $same = $row !== null && (int) $row->bytes === $object['bytes']
                && Values::stored($row->updated_at) === $object['updated_at'] && is_file(StagedObjects::file($path));
            if (! $same) {
                $changed[] = $object;
            }
        }

        return $changed;
    }

    /**
     * Downloads what changed and drops what Supabase no longer has, after
     * checking the disk has room.
     *
     * @param  array<string, array{path: string, bytes: int, mime: ?string, created_at: string, updated_at: string}>  $listed
     */
    private function syncObjects(array $listed): void
    {
        $changed = $this->changed($listed);
        $needed = array_sum(array_column($changed, 'bytes'));
        $free = ($this->freeSpace)($this->existingFolder());
        if ($free === false) {
            throw new RuntimeException('Could not read the free space under '.StagedObjects::root().'.');
        }
        if ($free < $needed) {
            throw new RuntimeException(sprintf('The copy needs %d bytes under %s and %d are free.', $needed, StagedObjects::root(), (int) $free));
        }
        ($this->say)(sprintf('Objects: %d in Supabase, %d to copy (%d bytes), %d bytes free.', count($listed), count($changed), $needed, (int) $free));

        foreach ($changed as $object) {
            $bytes = $this->supabase->downloadObject(StagedObjects::BUCKET, $object['path']);
            if ($bytes === null) {
                throw new RuntimeException("{$object['path']} was listed but could not be downloaded.");
            }
            if (strlen($bytes) !== $object['bytes']) {
                throw new RuntimeException(sprintf('%s is %d bytes in the listing and %d downloaded.', $object['path'], $object['bytes'], strlen($bytes)));
            }
            StagedObjects::write($object['path'], $bytes);
            DB::table('staged_object')->updateOrInsert(['path' => $object['path']], [
                'bytes' => $object['bytes'], 'mime' => $object['mime'],
                'created_at' => $object['created_at'], 'updated_at' => $object['updated_at'],
            ]);
        }

        $gone = DB::table('staged_object')->pluck('path')->reject(fn ($path) => isset($listed[$path]))->values()->all();
        if ($gone !== []) {
            StagedObjects::remove($gone);
            ($this->say)(sprintf('Removed %d objects Supabase no longer has.', count($gone)));
        }
    }

    /**
     * The disk holds exactly what Supabase lists, file by file.
     *
     * @param  array<string, array{path: string, bytes: int, mime: ?string, created_at: string, updated_at: string}>  $listed
     */
    private function checkObjects(array $listed): void
    {
        $rows = DB::table('staged_object')->get()->keyBy('path');
        foreach ($listed as $path => $object) {
            $file = StagedObjects::file($path);
            $row = $rows[$path] ?? null;
            if ($row === null || (int) $row->bytes !== $object['bytes'] || ! is_file($file) || filesize($file) !== $object['bytes']) {
                throw new RuntimeException("{$path} did not copy.");
            }
        }
        if ($rows->count() !== count($listed)) {
            throw new RuntimeException(sprintf('The disk holds %d objects and Supabase %d.', $rows->count(), count($listed)));
        }
        ($this->say)(sprintf('staged-pictures: Supabase %d objects, %d bytes. Disk the same.', count($listed), array_sum(array_column($listed, 'bytes'))));
    }

    /** The disk's folder, or the nearest folder above it that exists yet. */
    private function existingFolder(): string
    {
        $folder = StagedObjects::root();
        while (! is_dir($folder) && dirname($folder) !== $folder) {
            $folder = dirname($folder);
        }

        return $folder;
    }

    private function copy(string $table): int
    {
        $types = Tables::columns($table);
        $key = isset($types['id']) ? 'id' : 'path';
        $copied = 0;
        $offset = 0;
        do {
            $rows = $this->supabase->rowsKeepingObjects($table, 'select=*&order='.$key.'.asc&offset='.$offset.'&limit='.ReadAll::PAGE);
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
        $key = isset(Tables::columns($table)['id']) ? 'id' : 'path';
        $held = $this->supabase->from($table)->select($key, 'exact', true)->getWithCount();

        return (int) ($held['count'] ?? throw new RuntimeException("Supabase gave no count for {$table}."));
    }
}
