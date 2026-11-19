<?php

namespace App\Supabase\Local;

use App\Supabase\Client;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * The `staged-pictures` bucket on the droplet's disk (#598): files on the
 * `staged` disk, and a `staged_object` row for each, as Supabase keeps
 * `storage.objects` beside its files. {@see Client} hands the
 * bucket's downloads, uploads and removals here once pictures have moved, so
 * the code that stages pictures did not change.
 */
final class StagedObjects
{
    public const BUCKET = 'staged-pictures';

    /** One object's bytes, or null when nothing is at the path. */
    public static function download(string $path): ?string
    {
        if (! self::valid($path) || ! DB::table('staged_object')->where('path', $path)->exists()) {
            return null;
        }
        $file = self::file($path);
        $bytes = is_file($file) ? file_get_contents($file) : false;

        return $bytes === false ? null : $bytes;
    }

    /**
     * Writes one object. Without `$upsert`, refuses with a 409 when the path is
     * taken, as Supabase Storage does.
     */
    public static function upload(string $path, string $bytes, string $contentType, bool $upsert): void
    {
        if (! self::valid($path)) {
            throw new LocalException('Invalid key: '.$path, 400, 'InvalidKey');
        }
        $now = Values::now();
        DB::transaction(function () use ($path, $bytes, $contentType, $upsert, $now) {
            $held = DB::table('staged_object')->where('path', $path)->lockForUpdate()->exists();
            if ($held && ! $upsert) {
                throw new LocalException('The resource already exists', 409, 'Duplicate');
            }
            $row = ['bytes' => strlen($bytes), 'mime' => $contentType, 'updated_at' => $now];
            try {
                if ($held) {
                    DB::table('staged_object')->where('path', $path)->update($row);
                } else {
                    DB::table('staged_object')->insert(['path' => $path, ...$row, 'created_at' => $now]);
                }
            } catch (QueryException) {
                // Another upload took the path between the read and the insert.
                throw new LocalException('The resource already exists', 409, 'Duplicate');
            }
            // Written before the row commits, so a row never names a missing file.
            self::write($path, $bytes);
        });
    }

    /** @param list<string> $paths */
    public static function remove(array $paths): void
    {
        $paths = array_values(array_filter($paths, self::valid(...)));
        if ($paths === []) {
            return;
        }
        DB::table('staged_object')->whereIn('path', $paths)->delete();
        foreach ($paths as $path) {
            $file = self::file($path);
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    /**
     * Writes a file whole, through a temporary name, so a reader never sees
     * half of it. Also used by the copy, which records rows itself.
     */
    public static function write(string $path, string $bytes): void
    {
        $file = self::file($path);
        $folder = dirname($file);
        if (! is_dir($folder) && ! mkdir($folder, 0775, true) && ! is_dir($folder)) {
            throw new LocalException('Could not make the folder for '.$path, 500, null);
        }
        $temporary = $file.'.'.bin2hex(random_bytes(6)).'.part';
        if (file_put_contents($temporary, $bytes) !== strlen($bytes) || ! rename($temporary, $file)) {
            @unlink($temporary);
            throw new LocalException('Could not write '.$path, 500, null);
        }
    }

    /** Where an object's file is. */
    public static function file(string $path): string
    {
        return Storage::disk('staged')->path($path);
    }

    /** The folder the disk keeps its files in. */
    public static function root(): string
    {
        return rtrim(Storage::disk('staged')->path(''), '/');
    }

    /** A bucket key that stays inside the disk's folder. */
    public static function valid(string $path): bool
    {
        if ($path === '' || str_starts_with($path, '/') || str_contains($path, "\0") || str_contains($path, '\\')) {
            return false;
        }
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return false;
            }
        }

        return true;
    }
}
