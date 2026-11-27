<?php

namespace App\Domain\Replays;

use App\Supabase\Local\Values;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * What listed replays say about each game (#670), for the game's page. Each
 * listed replay has one share in `replay_game_share`, and the shares of one
 * game version are added up in `replay_game_total`. {@see refresh} is the only
 * writer of both.
 *
 * A share and a total are counts, as `{section: {key: [number, ...]}}`, so one
 * is added to or taken from another number by number. Who built and who won is
 * counted for human players only. An AI's orders and results say nothing about
 * what players do.
 */
final class GameTotals
{
    /** How many folded build order entries make an opening. Coilbox's view has no default length, so this is the hub's choice. */
    public const OPENING_ENTRIES = 5;

    /** How many rows the unit lists and the opening list show. */
    private const UNITS_SHOWN = 10;

    private const OPENINGS_SHOWN = 5;

    /**
     * Makes the totals hold what this replay says now: adds a newly listed
     * one, swaps its share when its facts change, and takes it out once it is
     * no longer listed. Safe to call again. Call it after any change to a
     * replay's state or kept analysis.
     */
    public static function refresh(string $gameId): void
    {
        DB::transaction(function () use ($gameId) {
            // Locked first, so two changes to one replay cannot both add it.
            $replay = DB::table('replay')->where('game_id', $gameId)->lockForUpdate()->first();
            $old = DB::table('replay_game_share')->where('game_id', $gameId)->lockForUpdate()->first();
            $new = $replay === null || $replay->state !== 'listed' || $replay->game === null ? null : self::share($replay);

            $changes = [];
            if ($old !== null) {
                self::gather($changes, $old->game, $old->game_version, json_decode($old->figures, true), -1);
                DB::table('replay_game_share')->where('game_id', $gameId)->delete();
            }
            if ($new !== null) {
                $version = (string) $replay->game_version;
                self::gather($changes, $replay->game, $version, $new, 1);
                DB::table('replay_game_share')->insert([
                    'game_id' => $gameId, 'game' => $replay->game, 'game_version' => $version,
                    'figures' => json_encode($new), 'counted_at' => Values::now(),
                ]);
            }
            // One order for every writer, so two replays of one game cannot deadlock.
            ksort($changes);
            foreach ($changes as $change) {
                self::apply($change);
            }
        });
    }

    /**
     * What the game's page shows, or null when no listed replay is of the
     * game. `$version` narrows it to one version, and a version with no replays
     * shows every version. Each figure comes with the replays behind it.
     *
     * @return array<string, mixed>|null
     */
    public static function page(string $game, ?string $version = null): ?array
    {
        try {
            $rows = DB::table('replay_game_total')->where('game', $game)->get();
        } catch (QueryException) {
            return null;
        }
        if ($rows->isEmpty()) {
            return null;
        }
        $versions = $rows->filter(fn ($r) => $r->game_version !== '')->mapWithKeys(fn ($r) => [$r->game_version => (int) $r->replays])->all();
        // Newest first, as far as release numbers say.
        uksort($versions, fn ($a, $b) => strnatcasecmp((string) $b, (string) $a));
        $selected = $version !== null && isset($versions[$version]) ? $version : null;

        $matches = 0;
        $sum = [];
        foreach ($rows as $row) {
            if ($selected === null || $row->game_version === $selected) {
                $matches += (int) $row->replays;
                self::add($sum, json_decode($row->figures, true), 1);
            }
        }
        $from = fn (string $section) => $sum['from'][$section][0] ?? 0;

        // The unit name Coilbox sent most often for each unit, else the catalogue's.
        $sent = [];
        foreach ($sum['names'] ?? [] as $key => [$times]) {
            [$name, $label] = json_decode((string) $key, true);
            if ($times > ($sent[$name][1] ?? 0)) {
                $sent[$name] = [$label, $times];
            }
        }
        $factionKeys = array_map(strval(...), array_keys($sum['factions'] ?? []));
        foreach (array_keys($sum['awards'] ?? []) as $key) {
            $factionKeys[] = (string) (json_decode((string) $key, true)[1] ?? '');
        }
        $unitKeys = array_map(strval(...), array_keys(($sum['orders'] ?? []) + ($sum['finished'] ?? []) + ($sum['killers'] ?? [])));
        $full = ReplayList::unitNames([(object) ['game' => $game]], array_values(array_unique(array_filter([...$factionKeys, ...$unitKeys]))))[$game] ?? [];
        $unit = fn (string $name) => $sent[$name][0] ?? $full[$name] ?? $name;
        // A faction that is a unit name reads better by the name the game shows for it.
        $faction = fn (string $key) => $full[$key] ?? $key;

        $lengths = $sum['lengths'] ?? [];
        ksort($lengths);

        $factions = [];
        foreach ($sum['factions'] ?? [] as $key => [$decided, $won, $undecided, $replays]) {
            $factions[] = ['name' => $faction((string) $key), 'decided' => $decided, 'won' => $won, 'undecided' => $undecided, 'replays' => $replays];
        }
        // By how much there is to go on, so one lucky match never tops the list.
        usort($factions, fn ($a, $b) => [$b['decided'], $b['replays'], $a['name']] <=> [$a['decided'], $a['replays'], $b['name']]);

        $pairs = [];
        foreach ($sum['pairs'] ?? [] as $key => [$played, $first, $second, $undecided]) {
            [$a, $b] = json_decode((string) $key, true);
            $pairs[] = ['first' => $faction($a), 'second' => $faction($b), 'mirror' => $a === $b, 'matches' => $played, 'first_won' => $first, 'second_won' => $second, 'undecided' => $undecided];
        }
        usort($pairs, fn ($a, $b) => [$b['matches'], $a['first'], $a['second']] <=> [$a['matches'], $b['first'], $b['second']]);

        $units = function (string $section) use ($sum, $unit) {
            $out = [];
            foreach ($sum[$section] ?? [] as $name => [$amount, $replays]) {
                $out[] = ['name' => $unit((string) $name), 'amount' => $amount, 'replays' => $replays];
            }
            usort($out, fn ($a, $b) => [$b['amount'], $b['replays'], $a['name']] <=> [$a['amount'], $a['replays'], $b['name']]);

            return array_slice($out, 0, self::UNITS_SHOWN);
        };

        $openings = [];
        foreach ($sum['openings'] ?? [] as $key => [$times, $replays]) {
            $openings[] = ['key' => (string) $key, 'times' => $times, 'replays' => $replays];
        }
        usort($openings, fn ($a, $b) => [$b['times'], $b['replays'], $a['key']] <=> [$a['times'], $a['replays'], $b['key']]);
        $openings = array_map(fn ($o) => [
            'entries' => array_map(fn ($e) => ['name' => $unit($e[0]), 'count' => $e[1]], json_decode($o['key'], true)),
            'times' => $o['times'], 'replays' => $o['replays'],
        ], array_slice($openings, 0, self::OPENINGS_SHOWN));

        $awards = [];
        foreach (ReplayPage::AWARDS as $id => $title) {
            $winners = [];
            $times = 0;
            foreach ($sum['awards'] ?? [] as $key => [$count]) {
                [$award, $by] = json_decode((string) $key, true);
                if ($award === $id) {
                    $winners[] = ['faction' => $by === null ? null : $faction($by), 'times' => $count];
                    $times += $count;
                }
            }
            if ($winners !== []) {
                usort($winners, fn ($a, $b) => [$b['times'], $a['faction'] === null, $a['faction']] <=> [$a['times'], $b['faction'] === null, $b['faction']]);
                $awards[] = ['title' => $title, 'replays' => $times, 'winners' => $winners];
            }
        }

        $sizes = [];
        foreach ($sum['sizes'] ?? [] as $size => [$replays]) {
            $sizes[] = ['size' => (string) $size, 'replays' => $replays];
        }
        usort($sizes, fn ($a, $b) => [$b['replays'], $a['size']] <=> [$a['replays'], $b['size']]);

        return [
            'versions' => $versions,
            'version' => $selected,
            'all' => (int) $rows->sum('replays'),
            'matches' => $matches,
            'median' => self::median($lengths, $matches),
            'shortest' => (int) array_key_first($lengths),
            'longest' => (int) array_key_last($lengths),
            'sizes' => $sizes,
            'factions' => $factions,
            'factions_from' => $from('factions'),
            'unknown' => ['players' => $sum['unknown']['players'][0] ?? 0, 'replays' => $sum['unknown']['players'][1] ?? 0],
            'pairs' => $pairs,
            'pairs_from' => $from('pairs'),
            'orders' => $units('orders'),
            'orders_from' => $from('orders'),
            'openings' => $openings,
            'openings_of' => $sum['from']['openings'][1] ?? 0,
            'openings_from' => $from('openings'),
            'analysed' => $from('analysis'),
            'finished' => $units('finished'),
            'killers' => $units('killers'),
            'unnamed_finished' => $sum['unnamed']['finished'][0] ?? 0,
            'unnamed_kills' => $sum['unnamed']['kills'][0] ?? 0,
            'awards' => $awards,
            'awards_from' => $from('awards'),
        ];
    }

    /**
     * The median of lengths counted as `[seconds => [replays]]` in order: the
     * two middle ones of an even number, halved, as `lengthSummary` does.
     *
     * @param  array<int, array{0: int}>  $lengths
     */
    private static function median(array $lengths, int $count): int
    {
        $at = function (int $index) use ($lengths) {
            foreach ($lengths as $seconds => [$replays]) {
                $index -= $replays;
                if ($index < 0) {
                    return (int) $seconds;
                }
            }

            return 0;
        };

        return (int) round(($at(intdiv($count - 1, 2)) + $at(intdiv($count, 2))) / 2);
    }

    /**
     * What one listed replay adds. Every amount is a whole number, so that
     * subtracting a share leaves no fraction behind.
     *
     * @return array<string, array<int|string, list<int>>>
     */
    private static function share(object $replay): array
    {
        $seats = DB::table('replay_player')->where('game_id', $replay->game_id)->where('kind', '!=', 'spectator')
            ->orderByDesc('kind')->orderBy('number')->get(['kind', 'team', 'ally_team', 'faction', 'won']);
        $players = $seats->where('kind', 'player');
        $humans = $players->pluck('team')->filter(fn ($t) => $t !== null)->flip();
        $decided = (json_decode((string) $replay->winners, true) ?: []) !== [];

        $out = ['lengths' => [(int) $replay->duration => [1]]];
        $sides = $seats->whereNotNull('ally_team')->countBy('ally_team')->sortDesc()->values();
        if ($sides->isNotEmpty()) {
            $out['sizes'][$sides->implode(' v ')] = [1];
        }

        $unknown = 0;
        foreach ($players as $seat) {
            if ($seat->faction === null) {
                $unknown++;

                continue;
            }
            // [players in matches somebody won, of those winners, players in matches nobody won, replays]
            $row = $out['factions'][$seat->faction] ?? [0, 0, 0, 1];
            $row[$decided ? 0 : 2]++;
            $row[1] += $seat->won ? 1 : 0;
            $out['factions'][$seat->faction] = $row;
        }
        if ($unknown > 0) {
            $out['unknown']['players'] = [$unknown, 1];
        }

        $pair = self::pair($seats, $decided);
        if ($pair !== null) {
            $out['pairs'][$pair[0]] = $pair[1];
        }

        $summary = json_decode((string) $replay->summary, true);
        $units = [];
        foreach ($summary['units'] ?? [] as $u) {
            $units[$u['unit_def_id']] = $u;
        }
        $teams = DB::table('replay_team')->where('game_id', $replay->game_id)->orderBy('team')->get(['team', 'build_orders'])
            ->filter(fn ($t) => $humans->has($t->team));
        $openings = 0;
        foreach ($teams as $team) {
            $orders = array_values(array_filter(json_decode((string) $team->build_orders, true) ?: [], fn ($o) => isset($units[$o['unit_def_id']])));
            if ($orders === []) {
                continue;
            }
            foreach ($orders as $order) {
                $name = $units[$order['unit_def_id']]['name'];
                $out['orders'][$name] = [($out['orders'][$name][0] ?? 0) + 1, 1];
            }
            $opening = json_encode(array_map(
                fn ($e) => [$units[$e['unit_def_id']]['name'], $e['count']],
                array_slice(ReplayPage::folded($orders), 0, self::OPENING_ENTRIES),
            ));
            $out['openings'][$opening] = [($out['openings'][$opening][0] ?? 0) + 1, 1];
            $openings++;
        }

        $facts = ReplayPage::facts($replay->game_id);
        $analysed = isset($facts['by_unit']);
        $unnamed = ['finished' => 0.0, 'kills' => 0.0];
        foreach ($analysed ? $facts['by_unit'] : [] as $team) {
            if (! $humans->has($team['team'])) {
                continue;
            }
            foreach (['finished' => 'finished', 'kills' => 'killers'] as $of => $section) {
                foreach ($team[$of] as [$name, $amount]) {
                    $out[$section][$name] = [($out[$section][$name][0] ?? 0) + $amount, 1];
                }
                $unnamed[$of] += $team['unnamed_'.$of];
            }
        }
        // Rounded once for the whole replay. A unit's metal that rounds to nothing is left out.
        foreach ($out['killers'] ?? [] as $name => [$metal]) {
            $out['killers'][$name][0] = (int) round($metal);
        }
        $out['killers'] = array_filter($out['killers'] ?? [], fn ($k) => $k[0] > 0);
        foreach ($unnamed as $of => $amount) {
            if ((int) round($amount) > 0) {
                $out['unnamed'][$of] = [(int) round($amount)];
            }
        }

        $named = ($out['orders'] ?? []) + ($out['finished'] ?? []) + $out['killers'];
        foreach ($units as $u) {
            if (isset($named[$u['name']]) && is_string($u['display_name'] ?? null) && $u['display_name'] !== '') {
                $out['names'][json_encode([$u['name'], $u['display_name']])] = [1];
            }
        }

        $recorded = json_decode((string) $replay->extra, true)['bar.awards'] ?? [];
        $given = [];
        foreach ($recorded as $award) {
            // The game gives each award's places best first, so the first one with a team won it.
            if (($award['team'] ?? null) === null || isset($given[$award['name']])) {
                continue;
            }
            $given[$award['name']] = true;
            $winner = $players->firstWhere('team', $award['team']);
            if ($winner !== null) {
                $out['awards'][json_encode([$award['name'], $winner->faction])] = [1];
            }
        }

        foreach (['factions' => isset($out['factions']) || $unknown > 0, 'pairs' => $pair !== null, 'orders' => $openings > 0,
            'analysis' => $analysed, 'awards' => isset($out['awards'])] as $section => $has) {
            if ($has) {
                $out['from'][$section] = [1];
            }
        }
        if ($openings > 0) {
            // [replays, openings]
            $out['from']['openings'] = [1, $openings];
        }

        return array_filter($out);
    }

    /**
     * A one against one match between two players whose factions are known, as
     * the two factions in order and [matches, first won, second won, nobody
     * won]. A faction against itself says nothing about who wins.
     *
     * @return array{0: string, 1: array{0: int, 1: int, 2: int, 3: int}}|null
     */
    private static function pair($seats, bool $decided): ?array
    {
        if ($seats->count() !== 2 || $seats->where('kind', 'player')->whereNotNull('faction')->whereNotNull('ally_team')->pluck('ally_team')->unique()->count() !== 2) {
            return null;
        }
        $won = $seats->where('won', true)->pluck('faction')->all();
        $factions = $seats->pluck('faction')->all();
        sort($factions, SORT_STRING);
        [$a, $b] = $factions;
        $mirror = $a === $b;

        return [json_encode([$a, $b]), [1, (int) (! $mirror && in_array($a, $won, true)), (int) (! $mirror && in_array($b, $won, true)), (int) ! $decided]];
    }

    /** Adds one share to the changes to make, `$sign` 1 to add and -1 to take out. */
    private static function gather(array &$changes, string $game, string $version, array $figures, int $sign): void
    {
        $key = $game."\0".$version;
        $changes[$key] ??= ['row' => ['game' => $game, 'game_version' => $version], 'replays' => 0, 'figures' => []];
        $changes[$key]['replays'] += $sign;
        self::add($changes[$key]['figures'], $figures, $sign);
    }

    /** Adds counts to counts, number by number, and drops whatever comes to nothing. */
    private static function add(array &$total, array $counts, int $sign): void
    {
        foreach ($counts as $section => $keys) {
            foreach ($keys as $key => $numbers) {
                $at = $total[$section][$key] ?? [];
                foreach ($numbers as $i => $number) {
                    $at[$i] = ($at[$i] ?? 0) + $sign * $number;
                }
                if (array_filter($at) === []) {
                    unset($total[$section][$key]);
                } else {
                    $total[$section][$key] = $at;
                }
            }
            if (($total[$section] ?? null) === []) {
                unset($total[$section]);
            }
        }
    }

    /** @param array{row: array<string, string>, replays: int, figures: array<string, mixed>} $change */
    private static function apply(array $change): void
    {
        // An upsert takes the row's write lock whether or not it was there, which an ignored insert does not.
        DB::table('replay_game_total')->upsert([$change['row'] + ['replays' => 0, 'figures' => '[]']], array_keys($change['row']), ['game']);
        $query = DB::table('replay_game_total')->where($change['row']);
        $total = (clone $query)->lockForUpdate()->first();
        $replays = (int) $total->replays + $change['replays'];
        if ($replays <= 0) {
            $query->delete();

            return;
        }
        $figures = json_decode($total->figures, true);
        self::add($figures, $change['figures'], 1);
        $query->update(['replays' => $replays, 'figures' => json_encode($figures)]);
    }
}
