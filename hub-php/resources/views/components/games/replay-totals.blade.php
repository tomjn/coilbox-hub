{{--
    What the shared replays say about this game (#670): matches, factions,
    units, openings and the game's own awards. `totals` is GameTotals::page.
    Every figure says how many replays it comes from.
--}}
@props(['totals', 'base'])
@use('App\Domain\Replays\GameTotals')
@use('App\Domain\Replays\MapTotals')
@use('App\Domain\Replays\ReplayPage')
@use('App\Support\Ui')
@php
    $term = 'text-sm uppercase tracking-wide text-neutral-400';
    $value = 'text-sm text-neutral-100';
    $note = 'text-sm text-pretty text-neutral-400';
    $th = 'py-2 pr-4 text-left font-normal';
    $num = 'py-2 pl-4 text-right whitespace-nowrap tabular-nums';
    $name = 'py-2 pr-4 text-left font-normal break-words text-neutral-100';
    $row = 'border-b border-neutral-900';
    $head = 'border-b border-neutral-800';
    $from = fn (int $count) => MapTotals::replays($count);
    $rate = fn (int $won, int $of) => (int) round($won * 100 / $of).'% ('.number_format($won).' of '.number_format($of).')';
    $size = fn (string $size) => str_contains($size, ' v ') ? $size : ($size === '1' ? '1 player alone' : $size.' players on one side');
    $sequence = fn (array $entries) => implode(', then ', array_map(fn ($e) => $e['name'].($e['count'] > 1 ? " \u{00D7}".$e['count'] : ''), $entries));
    $unitTables = [
        ['id' => 'orders', 'title' => 'Units ordered most', 'amount' => 'Orders', 'rows' => $totals['orders'], 'from' => 'Build orders from '.$from($totals['orders_from']).'. An order is not always a finished unit.'],
        ['id' => 'finished', 'title' => 'Units finished most', 'amount' => 'Finished', 'rows' => $totals['finished'], 'from' => 'From the analyses of '.$from($totals['analysed']).'.'.($totals['unnamed_finished'] > 0 ? ' '.number_format($totals['unnamed_finished']).' finished units have no name in their replay and are left out.' : '')],
        ['id' => 'killers', 'title' => 'Units that destroyed the most value', 'amount' => 'Metal destroyed', 'rows' => $totals['killers'], 'from' => 'The metal cost of finished enemy units each unit destroyed, from the analyses of '.$from($totals['analysed']).'.'.($totals['unnamed_kills'] > 0 ? ' '.ReplayPage::total($totals['unnamed_kills']).' metal was destroyed by units with no name in their replay and is left out.' : '')],
    ];
@endphp
<section id="replay-figures" class="flex flex-col gap-8" aria-labelledby="replay-figures-title">
    <div class="flex flex-col gap-3">
        <h2 id="replay-figures-title" class="{{ $term }}">From shared replays</h2>
        @if (count($totals['versions']) > 1)
            <nav aria-label="Game version">
                <ul class="flex flex-wrap gap-2">
                    <li><a href="{{ $base }}#replay-figures" @if ($totals['version'] === null) aria-current="true" @endif class="{{ Ui::button($totals['version'] === null ? 'primary' : 'ghost', 'md') }}">All versions ({{ number_format($totals['all']) }})</a></li>
                    @foreach ($totals['versions'] as $version => $count)
                        <li><a href="{{ $base }}?version={{ rawurlencode((string) $version) }}#replay-figures" @if ($totals['version'] === (string) $version) aria-current="true" @endif class="{{ Ui::button($totals['version'] === (string) $version ? 'primary' : 'ghost', 'md') }}">{{ $version }} ({{ number_format($count) }})</a></li>
                    @endforeach
                </ul>
            </nav>
        @elseif ($totals['versions'] !== [])
            <p class="{{ $note }}">Version {{ array_key_first($totals['versions']) }}</p>
        @endif
        <dl class="grid grid-cols-2 gap-6 sm:grid-cols-4">
            <div class="flex flex-col gap-1"><dt class="{{ $term }}">Matches</dt><dd class="{{ $value }}">{{ number_format($totals['matches']) }}</dd></div>
            <div class="flex flex-col gap-1"><dt class="{{ $term }}">Median length</dt><dd class="{{ $value }}">{{ ReplayPage::duration($totals['median']) }}</dd></div>
            <div class="flex flex-col gap-1"><dt class="{{ $term }}">Shortest</dt><dd class="{{ $value }}">{{ ReplayPage::duration($totals['shortest']) }}</dd></div>
            <div class="flex flex-col gap-1"><dt class="{{ $term }}">Longest</dt><dd class="{{ $value }}">{{ ReplayPage::duration($totals['longest']) }}</dd></div>
        </dl>
        <p class="{{ $note }}">Match lengths from {{ $from($totals['matches']) }}. Factions, units and openings count human players only.</p>
    </div>

    <div class="grid gap-8 md:grid-cols-2">
        @if ($totals['sizes'] !== [])
            <div class="flex min-w-0 flex-col gap-2">
                <h3 class="text-sm font-medium">Team sizes</h3>
                <table class="w-full text-sm">
                    <thead class="text-neutral-400"><tr class="{{ $head }}"><th scope="col" class="{{ $th }}">Size</th><th scope="col" class="{{ $num }} font-normal">Replays</th></tr></thead>
                    <tbody>
                        @foreach ($totals['sizes'] as $team)
                            <tr class="{{ $row }}"><th scope="row" class="{{ $name }}">{{ $size($team['size']) }}</th><td class="{{ $num }} text-neutral-100">{{ number_format($team['replays']) }}</td></tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        @if ($totals['factions_from'] > 0)
            <div class="flex min-w-0 flex-col gap-2">
                <h3 class="text-sm font-medium">Win rate by faction</h3>
                @if ($totals['factions'] !== [])
                    <table class="w-full text-sm">
                        <thead class="text-neutral-400"><tr class="{{ $head }}"><th scope="col" class="{{ $th }}">Faction</th><th scope="col" class="{{ $num }} font-normal">Players who won</th><th scope="col" class="{{ $num }} font-normal">Replays</th></tr></thead>
                        <tbody>
                            @foreach ($totals['factions'] as $faction)
                                <tr class="{{ $row }}">
                                    <th scope="row" class="{{ $name }}">{{ $faction['name'] }}</th>
                                    <td class="{{ $num }} text-neutral-100">{{ $faction['decided'] === 0 ? 'No result' : $rate($faction['won'], $faction['decided']) }}</td>
                                    <td class="{{ $num }} text-neutral-400">{{ number_format($faction['replays']) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
                <p class="{{ $note }}">From {{ $from($totals['factions_from']) }}. A player in a match nobody won is not counted in a win rate.@if ($totals['unknown']['players'] > 0) {{ $totals['unknown']['players'] === 1 ? '1 player' : number_format($totals['unknown']['players']).' players' }} in {{ $from($totals['unknown']['replays']) }} had no known faction and {{ $totals['unknown']['players'] === 1 ? 'is' : 'are' }} left out.@endif</p>
            </div>
        @endif
    </div>

    @if ($totals['pairs'] !== [])
        <div class="flex flex-col gap-2">
            <h3 class="text-sm font-medium">One against one</h3>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="text-neutral-400"><tr class="{{ $head }}"><th scope="col" class="{{ $th }}">Factions</th><th scope="col" class="{{ $th }}">Wins</th><th scope="col" class="{{ $num }} font-normal">Replays</th></tr></thead>
                    <tbody>
                        @foreach ($totals['pairs'] as $pair)
                            @php $won = $pair['first_won'] + $pair['second_won']; @endphp
                            <tr class="{{ $row }}">
                                <th scope="row" class="{{ $name }}">{{ $pair['first'] }} against {{ $pair['second'] }}</th>
                                <td class="py-2 pr-4 text-neutral-100">
                                    @if ($pair['mirror'])
                                        <span class="text-neutral-400">Same faction on both sides</span>
                                    @elseif ($won > 0)
                                        <div>{{ $pair['first'] }} {{ $rate($pair['first_won'], $won) }}</div>
                                        <div>{{ $pair['second'] }} {{ $rate($pair['second_won'], $won) }}</div>
                                    @endif
                                    @if ($pair['undecided'] > 0)
                                        <div class="text-neutral-400">{{ number_format($pair['undecided']) }} with no result</div>
                                    @endif
                                </td>
                                <td class="{{ $num }} text-neutral-400">{{ number_format($pair['matches']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <p class="{{ $note }}">From {{ $from($totals['pairs_from']) }} of one player against one player.</p>
        </div>
    @endif

    <div class="grid gap-8 md:grid-cols-2">
        @foreach ($unitTables as $table)
            @if ($table['rows'] !== [])
                <div class="flex min-w-0 flex-col gap-2">
                    <h3 class="text-sm font-medium">{{ $table['title'] }}</h3>
                    <table class="w-full text-sm">
                        <thead class="text-neutral-400"><tr class="{{ $head }}"><th scope="col" class="{{ $th }}">Unit</th><th scope="col" class="{{ $num }} font-normal">{{ $table['amount'] }}</th><th scope="col" class="{{ $num }} font-normal">Replays</th></tr></thead>
                        <tbody>
                            @foreach ($table['rows'] as $unit)
                                <tr class="{{ $row }}">
                                    <th scope="row" class="{{ $name }}">{{ $unit['name'] }}</th>
                                    <td class="{{ $num }} text-neutral-100">{{ ReplayPage::total($unit['amount']) }}</td>
                                    <td class="{{ $num }} text-neutral-400">{{ number_format($unit['replays']) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                    <p class="{{ $note }}">{{ $table['from'] }}</p>
                </div>
            @endif
        @endforeach

        @if ($totals['openings'] !== [])
            <div class="flex min-w-0 flex-col gap-2">
                <h3 class="text-sm font-medium">Common openings</h3>
                <ol class="flex flex-col">
                    @foreach ($totals['openings'] as $opening)
                        <li class="flex flex-col gap-1 border-b border-neutral-900 py-2 text-sm">
                            <span class="break-words text-neutral-100">{{ $sequence($opening['entries']) }}</span>
                            <span class="text-neutral-400">{{ number_format($opening['times']) }} of {{ number_format($totals['openings_of']) }} openings, in {{ $from($opening['replays']) }}</span>
                        </li>
                    @endforeach
                </ol>
                <p class="{{ $note }}">An opening is the first {{ GameTotals::OPENING_ENTRIES }} entries of a team&rsquo;s build orders, with repeat orders for one unit folded into one entry. From {{ $from($totals['openings_from']) }}.</p>
            </div>
        @endif
    </div>

    @if ($totals['awards'] !== [])
        <div class="flex flex-col gap-3">
            <h3 class="text-sm font-medium">Awards by faction</h3>
            <ul class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($totals['awards'] as $award)
                    <li class="flex flex-col gap-2 rounded-md border border-neutral-800 bg-card p-4 text-sm">
                        <h4 class="font-medium text-neutral-100">{{ $award['title'] }}</h4>
                        <ul class="flex flex-col gap-1">
                            @foreach ($award['winners'] as $winner)
                                <li class="flex justify-between gap-4"><span class="min-w-0 break-words text-neutral-100">{{ $winner['faction'] ?? 'No known faction' }}</span><span class="shrink-0 tabular-nums text-neutral-400">{{ number_format($winner['times']) }} of {{ number_format($award['replays']) }}</span></li>
                            @endforeach
                        </ul>
                        <p class="text-neutral-400">From {{ $from($award['replays']) }}</p>
                    </li>
                @endforeach
            </ul>
            <p class="{{ $note }}">Awards the game recorded, from {{ $from($totals['awards_from']) }}. An award an AI won is not counted.</p>
        </div>
    @endif
</section>
