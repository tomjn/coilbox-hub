<?php

namespace App\Domain\Engines;

use App\Supabase\Local\MirrorSources;
use App\Support\Site;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Rules shared by the engine upload and the engine pages.
 */
final class Engines
{
    public const NOTE_MAX = 1000;

    public const WARNING = 'Unofficial test build. Not made or supported by the Recoil team. It may crash, desync in multiplayer or damage your settings. Use it only if you know why you want it.';

    public static function pagePath(string $version, string $platform): string
    {
        return '/engines/'.rawurlencode($version).'/'.rawurlencode($platform);
    }

    /**
     * Listed engines, newest first, optionally for one platform.
     *
     * @return list<array{id: string, version: string, platform: string, filename: string, size: int, sha256: string, note: string, listed_at: string, page_url: string, sources: list<string>}>
     */
    public static function listed(?string $platform = null): array
    {
        $rows = self::query()->when($platform !== null, fn ($q) => $q->where('platform', $platform))
            ->orderByDesc('listed_at')->orderByDesc('id')->get()->all();

        return self::shape($rows);
    }

    /**
     * One listed engine, even when no mirror holds it.
     *
     * @return array{id: string, version: string, platform: string, filename: string, size: int, sha256: string, note: string, listed_at: string, page_url: string, sources: list<string>, uploader: ?string}|null
     */
    public static function find(string $version, string $platform): ?array
    {
        $row = self::query()->where('version', $version)->where('platform', $platform)->first();
        if ($row === null) {
            return null;
        }

        return self::shape([$row])[0] + ['uploader' => $row->uploader];
    }

    private static function query(): Builder
    {
        return DB::table('archive')->where('kind', 'engine')->where('state', 'listed');
    }

    /**
     * @param  list<object>  $rows
     * @return list<array{id: string, version: string, platform: string, filename: string, size: int, sha256: string, note: string, listed_at: string, page_url: string, sources: list<string>}>
     */
    private static function shape(array $rows): array
    {
        if ($rows === []) {
            return [];
        }
        $held = DB::table('archive_mirror as am')->join('mirror as m', 'm.id', '=', 'am.mirror_id')
            ->whereIn('am.archive_id', array_map(fn ($r) => $r->id, $rows))->where('m.enabled', true)->where('m.healthy', true)
            ->orderBy('m.id')->get(['am.archive_id', 'm.base_url']);
        $byId = [];
        $sources = [];
        foreach ($rows as $row) {
            $byId[(string) $row->id] = $row;
            $sources[(string) $row->id] = [];
        }
        foreach ($held as $copy) {
            $row = $byId[(string) $copy->archive_id];
            $sources[(string) $copy->archive_id][] = $copy->base_url.'/engines/'.MirrorSources::segment((string) $row->filename);
        }

        return array_map(fn ($row) => [
            'id' => (string) $row->id,
            'version' => (string) $row->version,
            'platform' => (string) $row->platform,
            'filename' => (string) $row->filename,
            'size' => (int) $row->size,
            'sha256' => (string) $row->sha256,
            'note' => (string) $row->description,
            'listed_at' => CarbonImmutable::parse((string) $row->listed_at, 'UTC')->utc()->format('Y-m-d\TH:i:s\Z'),
            'page_url' => Site::absolute(self::pagePath((string) $row->version, (string) $row->platform)),
            'sources' => $sources[(string) $row->id],
        ], $rows);
    }
}
