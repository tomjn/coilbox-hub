{{--
    What the Summary tab shows from a kept analysis (#668): how many people's
    runs agreed, LivingValueChart's panel for each team, and each team's
    finished buildings and units, kills and losses. `analysis` is ReplayPage's
    analysis, `value` is metal or energy, and the switch between them is a link,
    so it all works without JavaScript.
--}}
@props(['analysis', 'value', 'base'])
@use('App\Domain\Replays\AnalysisFacts')
@use('App\Domain\Replays\ReplayPage')
@php
    $muted = 'text-neutral-400';
    $focus = 'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-400';
    $living = $analysis['living'];
    $labels = ['economy' => 'Economy', 'defence' => 'Defence', 'offence' => 'Offence', 'other' => 'Builders, factories, sensors and transports', 'unclassified' => 'Unclassified'];
    // `SPLIT_COLOURS.dark`.
    $colours = ['economy' => '#3987e5', 'defence' => '#d95926', 'offence' => '#199e70', 'other' => '#c98500', 'unclassified' => '#898781'];
    $at = fn (array $sample, int $bucket) => $sample[$bucket * 2 + ($value === 'energy' ? 1 : 0)];
    $peak = 0;
    foreach ($living['teams'] as $team) {
        foreach ($team['samples'] as $sample) {
            $peak = max($peak, array_sum(array_map(fn ($b) => $at($sample, $b), array_keys(AnalysisFacts::BUCKETS))));
        }
    }
    $width = 320;
    $height = 120;
    $end = max(1, end($living['seconds']) ?: 1);
    $x = fn (int $i) => round($living['seconds'][$i] / $end * $width, 1);
    $y = fn (float $v) => round($height - ($peak > 0 ? $v / $peak : 0) * $height, 1);
    $agreed = $analysis['agreements'];
    $valueHref = fn (string $v) => $base.'?'.http_build_query(array_merge(request()->query(), ['value' => $v]));
    $count = fn (int $n, string $one, string $many) => number_format($n).' '.($n === 1 ? $one : $many);
@endphp
<section class="flex flex-col gap-4" aria-labelledby="replay-analysis">
    <div class="flex flex-col gap-1">
        <h2 id="replay-analysis" class="text-sm font-medium">Analysis</h2>
        <p class="text-xs {{ $muted }}">From a playback of the match in Coilbox that reproduced the replay's own totals. {{ $agreed === 1 ? 'One person has sent an analysis that agreed.' : number_format($agreed).' people sent separate analyses that agreed.' }}</p>
    </div>

    <section class="flex flex-col gap-2" aria-labelledby="replay-living">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h3 id="replay-living" class="text-sm font-medium">Value of living units</h3>
            <nav aria-label="Cost in">
                <ul class="flex gap-1 rounded-lg bg-neutral-900 p-1 text-xs">
                    @foreach (['metal' => 'Metal', 'energy' => 'Energy'] as $key => $label)
                        <li><a href="{{ $valueHref($key) }}" @if ($value === $key) aria-current="true" @endif class="block rounded-md px-2.5 py-1 transition-colors {{ $focus }} {{ $value === $key ? 'bg-neutral-700 text-white' : $muted.' hover:text-neutral-100 active:text-neutral-100' }}">{{ $label }}</a></li>
                    @endforeach
                </ul>
            </nav>
        </div>
        @if ($peak <= 0 || count($living['seconds']) < 2)
            <p class="text-sm {{ $muted }}">No finished unit with {{ $value === 'energy' ? 'an energy' : 'a metal' }} cost is known for this match, so there is nothing to draw.</p>
        @else
            <ul class="flex flex-wrap gap-x-4 gap-y-1 text-xs {{ $muted }}" aria-label="Kinds of unit">
                @foreach ($labels as $bucket => $label)
                    <li class="flex items-center gap-1.5"><span class="size-3 rounded-sm" style="background: {{ $colours[$bucket] }}"></span>{{ $label }}</li>
                @endforeach
            </ul>
            <div class="grid grid-cols-[repeat(auto-fit,minmax(min(16rem,100%),1fr))] gap-3">
                @foreach ($living['teams'] as $team)
                    @php
                        $last = end($team['samples']) ?: [];
                        $finalTotal = $last === [] ? 0 : array_sum(array_map(fn ($b) => $at($last, $b), array_keys(AnalysisFacts::BUCKETS)));
                        $teamPeak = 0;
                        foreach ($team['samples'] as $sample) {
                            $teamPeak = max($teamPeak, array_sum(array_map(fn ($b) => $at($sample, $b), array_keys(AnalysisFacts::BUCKETS))));
                        }
                        $below = array_fill(0, count($team['samples']), 0);
                    @endphp
                    <figure class="flex min-w-0 flex-col gap-1.5 rounded-lg border border-neutral-800 bg-card p-3">
                        <figcaption class="flex items-baseline justify-between gap-2 text-xs">
                            <span class="flex min-w-0 items-center gap-1.5 font-medium"><span class="size-3 shrink-0 rounded-sm border border-neutral-700" style="background: {{ $team['colour'] ?? 'transparent' }}" aria-hidden="true"></span><span class="break-words">{{ $team['name'] }}</span></span>
                            <span class="shrink-0 tabular-nums {{ $muted }}">peak {{ ReplayPage::total($teamPeak) }}</span>
                        </figcaption>
                        <svg viewBox="0 0 {{ $width }} {{ $height }}" preserveAspectRatio="none" class="h-28 w-full overflow-visible" role="img" aria-label="{{ $team['name'] }}'s living units in {{ $value }} over the match, at most {{ ReplayPage::total($teamPeak) }} and {{ ReplayPage::total($finalTotal) }} at the end.">
                            <line x1="0" y1="{{ $height }}" x2="{{ $width }}" y2="{{ $height }}" stroke="rgb(64 64 64)" vector-effect="non-scaling-stroke" />
                            <line x1="0" y1="0" x2="{{ $width }}" y2="0" stroke="rgb(38 38 38)" stroke-dasharray="3 3" vector-effect="non-scaling-stroke" />
                            @foreach (AnalysisFacts::BUCKETS as $b => $bucket)
                                @php
                                    $top = [];
                                    foreach ($team['samples'] as $i => $sample) {
                                        $top[$i] = $below[$i] + $at($sample, $b);
                                    }
                                    $upper = array_map(fn ($i) => $x($i).','.$y($top[$i]), array_keys($top));
                                    $lower = array_reverse(array_map(fn ($i) => $x($i).','.$y($below[$i]), array_keys($below)));
                                    $drawn = $top !== $below;
                                    $below = $top;
                                @endphp
                                @if ($drawn)
                                    <polygon points="{{ implode(' ', [...$upper, ...$lower]) }}" fill="{{ $colours[$bucket] }}" fill-opacity="0.85" />
                                @endif
                            @endforeach
                        </svg>
                        <div class="flex justify-between text-[10px] tabular-nums {{ $muted }}" aria-hidden="true">
                            <span>0:00</span><span>{{ ReplayPage::duration($end) }}</span>
                        </div>
                    </figure>
                @endforeach
            </div>
        @endif
        <p class="text-xs {{ $muted }}">
            @if ($peak > 0)
                Every {{ $living['period'] }} seconds, all on one scale, up to {{ ReplayPage::total($peak) }} {{ $value }}. A unit counts once it is finished, until it is destroyed or changes hands.
            @endif
            @if ($living['unpriced'] > 0)
                {{ $count($living['unpriced'], 'finished unit has', 'finished units have') }} no known cost, of {{ number_format($living['finished']) }}, and add nothing. A cost comes from the replay's summary or the hub's game catalogue.
            @endif
        </p>
    </section>

    <section class="flex flex-col gap-2" aria-labelledby="replay-finished">
        <h3 id="replay-finished" class="text-sm font-medium">Finished, kills and losses</h3>
        <div class="overflow-x-auto rounded-lg border border-neutral-800 bg-card">
            <table class="w-full text-xs">
                <thead class="{{ $muted }}">
                    <tr class="border-b border-neutral-800">
                        <th scope="col" class="px-3 py-2 text-left font-medium">Player</th>
                        <th scope="col" class="px-3 py-2 text-right font-medium">Buildings finished</th>
                        <th scope="col" class="px-3 py-2 text-right font-medium">Units finished</th>
                        @if (array_sum(array_column($analysis['teams'], 'unknown')) > 0)
                            <th scope="col" class="px-3 py-2 text-right font-medium">Finished, not known which</th>
                        @endif
                        <th scope="col" class="px-3 py-2 text-right font-medium">Kills</th>
                        <th scope="col" class="px-3 py-2 text-right font-medium">Losses</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($analysis['teams'] as $team)
                        <tr class="border-b border-neutral-800 last:border-b-0">
                            <th scope="row" class="px-3 py-2 text-left font-medium"><span class="flex items-center gap-1.5"><span class="size-3 shrink-0 rounded-sm border border-neutral-700" style="background: {{ $team['colour'] ?? 'transparent' }}" aria-hidden="true"></span><span class="break-words">{{ $team['name'] }}</span></span></th>
                            <td class="px-3 py-2 text-right tabular-nums">{{ number_format($team['buildings']) }}</td>
                            <td class="px-3 py-2 text-right tabular-nums">{{ number_format($team['units']) }}</td>
                            @if (array_sum(array_column($analysis['teams'], 'unknown')) > 0)
                                <td class="px-3 py-2 text-right tabular-nums">{{ number_format($team['unknown']) }}</td>
                            @endif
                            <td class="px-3 py-2 text-right tabular-nums">{{ number_format($team['kills']) }}</td>
                            <td class="px-3 py-2 text-right tabular-nums">{{ number_format($team['losses']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <p class="text-xs {{ $muted }}">A building is a finished unit that cannot move. A kill is an enemy unit the player destroyed, and a loss is any of their own units destroyed, finished or not.@if (array_sum(array_column($analysis['teams'], 'unknown')) > 0) Neither the replay's summary nor the hub's game catalogue says whether some units move.@endif</p>
    </section>
</section>
