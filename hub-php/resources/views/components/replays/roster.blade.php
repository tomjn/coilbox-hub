{{--
    ReplayRoster: one table, one group of rows per side, each seat with its
    team's final totals. Colour never carries the result alone.
--}}
@props(['sides', 'spectators'])
@use('App\Domain\Replays\ReplayPage')
@php
    $measured = collect($sides)->flatMap(fn ($s) => $s['seats'])->contains(fn ($seat) => array_filter($seat['figures'], fn ($v) => $v !== null) !== []);
    $apm = collect($sides)->flatMap(fn ($s) => $s['seats'])->contains(fn ($seat) => $seat['apm'] !== null);
    $columns = 2 + ($apm ? 1 : 0) + ($measured ? count(ReplayPage::ROSTER) : 0);
@endphp
<section class="flex flex-col gap-2">
    <h2 class="text-sm font-medium">Players</h2>
    <div class="overflow-x-auto rounded-lg border border-neutral-800 bg-card">
        <table class="w-full text-sm">
            <thead class="text-left text-xs text-neutral-400">
                <tr class="border-b border-neutral-800">
                    <th scope="col" class="px-3 py-2 font-medium">Player</th>
                    @if ($apm)
                        <th scope="col" class="px-3 py-2 text-right font-medium"><abbr title="Actions per minute" class="no-underline">APM</abbr></th>
                    @endif
                    @if ($measured)
                        @foreach (ReplayPage::ROSTER as $label)
                            <th scope="col" class="whitespace-nowrap px-3 py-2 text-right font-medium">{{ $label }}</th>
                        @endforeach
                    @endif
                    <th scope="col" class="px-3 py-2 font-medium">Result</th>
                </tr>
            </thead>
            @foreach ($sides as $side)
                <tbody class="border-b border-neutral-800 last:border-b-0">
                    <tr class="{{ $side['won'] ? 'bg-amber-500/5' : 'bg-neutral-900/40' }}">
                        <th scope="rowgroup" colspan="{{ $columns }}" class="px-3 py-1.5 text-left text-xs font-semibold text-neutral-400">
                            {{ $side['label'] }}
                            @if ($side['won'])
                                <span class="ml-2 inline-flex items-center gap-1 rounded bg-amber-500/15 px-1.5 text-[10px] text-amber-400"><x-icon name="trophy" class="size-3" />Winner</span>
                            @endif
                        </th>
                    </tr>
                    @foreach ($side['seats'] as $seat)
                        <tr>
                            <th scope="row" class="px-3 py-2 text-left font-normal">
                                <span class="flex min-w-0 items-center gap-2">
                                    <span class="size-3 shrink-0 rounded-sm border border-neutral-700" style="background: {{ $seat['colour'] ?? 'transparent' }}" aria-hidden="true"></span>
                                    @if ($seat['ai'])
                                        <span class="max-w-56 truncate">{{ $seat['name'] }}</span>
                                        <span class="rounded bg-neutral-800 px-1.5 text-[10px] text-neutral-300">Bot</span>
                                    @else
                                        <a class="max-w-56 truncate underline-offset-4 hover:underline active:underline focus-visible:outline-2 focus-visible:outline-neutral-400" href="{{ App\Domain\Replays\ReplayList::playerHref($seat['name']) }}">{{ $seat['name'] }}</a>
                                    @endif
                                    @if ($seat['faction'] !== null)
                                        <span class="whitespace-nowrap text-xs text-neutral-400">{{ $seat['faction'] }}</span>
                                    @endif
                                </span>
                            </th>
                            @if ($apm)
                                <td class="px-3 py-2 text-right tabular-nums text-neutral-400">{{ $seat['apm'] ?? '-' }}</td>
                            @endif
                            @if ($measured)
                                @foreach (array_keys(ReplayPage::ROSTER) as $column)
                                    <td class="px-3 py-2 text-right tabular-nums">{{ $seat['figures'][$column] === null ? '-' : ReplayPage::total($seat['figures'][$column]) }}</td>
                                @endforeach
                            @endif
                            <td class="px-3 py-2">
                                @if ($seat['won'])
                                    <span class="inline-flex items-center gap-1 text-xs font-medium text-amber-400"><x-icon name="trophy" class="size-3.5" />Won</span>
                                @else
                                    <span class="text-xs text-neutral-400">Lost</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            @endforeach
        </table>
    </div>
    @if ($measured)
        <p class="text-xs text-neutral-400">Figures are each team's totals at the end of the match.</p>
    @endif
    @if ($spectators !== [])
        <p class="text-xs text-neutral-400">Spectators: {{ implode(', ', $spectators) }}</p>
    @endif
</section>
