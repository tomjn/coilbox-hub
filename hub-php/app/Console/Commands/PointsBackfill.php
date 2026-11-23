<?php

namespace App\Console\Commands;

use App\Domain\Points\CommunityPoints;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PointsBackfill extends Command
{
    protected $signature = 'points:backfill {--dry-run : Show the awards and points per kind and write nothing}';

    protected $description = 'Award community points for work done before points existed';

    private const CHUNK = 500;

    /** @var array<string, array{awards: int, points: int}> */
    private array $totals = [];

    /** @var array<string, true> */
    private array $accounts = [];

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        foreach (array_keys(CommunityPoints::POINTS) as $kind) {
            $this->totals[$kind] = ['awards' => 0, 'points' => 0];
        }

        DB::table('game')->whereNotNull('owner_user_id')->orderBy('id')
            ->each(fn ($game) => $this->give($dry, $game->owner_user_id, CommunityPoints::GAME_OWNER, $game->id));

        DB::table('asset')->where('moderation', 'approved')->whereNotNull('uploaded_by')
            ->chunkById(self::CHUNK, function ($assets) use ($dry) {
                foreach ($assets as $asset) {
                    $kind = $asset->unit_name !== null ? CommunityPoints::UNIT_PICTURE : CommunityPoints::PICTURE;
                    $this->give($dry, $asset->uploaded_by, $kind, $asset->id);
                }
            }, 'id');

        DB::table('archive')->where('state', 'listed')->whereNotNull('uploader')
            ->chunkById(self::CHUNK, function ($archives) use ($dry) {
                foreach ($archives as $archive) {
                    $this->give($dry, $archive->uploader, CommunityPoints::ARCHIVE, $archive->id);
                }
            }, 'id');

        DB::table('item')->whereNull('deleted_at')
            ->chunkById(self::CHUNK, function ($items) use ($dry) {
                foreach ($items as $item) {
                    $this->give($dry, $item->author_id, CommunityPoints::ITEM, $item->id);
                }
            }, 'id');

        DB::table('map')->whereNotNull('submitted_by')
            ->chunkById(self::CHUNK, function ($maps) use ($dry) {
                foreach ($maps as $map) {
                    $this->give($dry, $map->submitted_by, CommunityPoints::MAP, $map->id);
                }
            }, 'id');

        $owners = [];
        DB::table('game')->orderBy('id')->each(function ($game) use ($dry, &$owners) {
            $this->give($dry, $game->submitted_by, CommunityPoints::GAME_FACTS, $game->id);
            if ($game->owner_user_id === null) {
                return;
            }
            $owners[$game->id] = $game->owner_user_id;
            if (! self::blank($game->description)) {
                $this->give($dry, $game->owner_user_id, CommunityPoints::FIRST_TEXT, CommunityPoints::firstTextSubject($game->id, null, 'description'));
            }
        });

        DB::table('game_faction')->chunkById(self::CHUNK, function ($factions) use ($dry, $owners) {
            foreach ($factions as $faction) {
                $owner = $owners[$faction->game_id] ?? null;
                if ($owner === null) {
                    continue;
                }
                foreach (['tagline', 'description'] as $field) {
                    if (! self::blank($faction->{$field})) {
                        $this->give($dry, $owner, CommunityPoints::FIRST_TEXT, CommunityPoints::firstTextSubject($faction->game_id, $faction->key, $field));
                    }
                }
            }
        }, 'id');

        foreach ($this->totals as $kind => $total) {
            $this->line(sprintf('%s: %d awards, %d points', $kind, $total['awards'], $total['points']));
        }
        $this->line('Accounts: '.count($this->accounts));
        if ($dry) {
            $this->line('Dry run: nothing was written.');
        }

        return 0;
    }

    /** A row already held counts as attempted, because award ignores it. */
    private function give(bool $dry, ?string $userId, string $kind, string $subject): void
    {
        if ($userId === null) {
            return;
        }
        $this->totals[$kind]['awards']++;
        $this->totals[$kind]['points'] += CommunityPoints::POINTS[$kind];
        $this->accounts[$userId] = true;
        if (! $dry) {
            CommunityPoints::award($userId, $kind, $subject);
        }
    }

    private static function blank(?string $text): bool
    {
        return $text === null || trim($text) === '';
    }
}
