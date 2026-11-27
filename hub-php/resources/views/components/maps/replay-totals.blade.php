{{--
    What the shared replays say about this map version (#669): match lengths,
    the heat of orders, deaths and damage on the minimap, and how each start
    area did. `totals` is MapTotals::page. Every figure says how many replays
    it comes from.
--}}
@props(['totals', 'title', 'heat', 'base'])
@use('App\Domain\Replays\MapTotals')
@use('App\Domain\Replays\ReplayPage')
@php
    $term = 'text-sm uppercase tracking-wide text-neutral-400';
    $value = 'text-sm text-neutral-100';
    $from = MapTotals::replays($totals['matches']);
@endphp
<div class="flex flex-col gap-2">
    <dl class="grid grid-cols-2 gap-6 sm:grid-cols-4">
        <div class="flex flex-col gap-1"><dt class="{{ $term }}">Matches</dt><dd class="{{ $value }}">{{ number_format($totals['matches']) }}</dd></div>
        <div class="flex flex-col gap-1"><dt class="{{ $term }}">Median length</dt><dd class="{{ $value }}">{{ ReplayPage::duration($totals['median']) }}</dd></div>
        <div class="flex flex-col gap-1"><dt class="{{ $term }}">Shortest</dt><dd class="{{ $value }}">{{ ReplayPage::duration($totals['shortest']) }}</dd></div>
        <div class="flex flex-col gap-1"><dt class="{{ $term }}">Longest</dt><dd class="{{ $value }}">{{ ReplayPage::duration($totals['longest']) }}</dd></div>
    </dl>
    <p class="text-sm text-neutral-400">Match lengths from {{ $from }}.</p>
</div>

<x-replays.map-card :card="$totals['card']" :title="$title" :heat="$heat" :base="$base" heading="Where it is played" tag="h3" anchor="#replay-map" />

@if ($totals['areas'] !== [])
    <div class="flex flex-col gap-2">
        <h3 class="text-sm font-medium">Start areas</h3>
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead class="text-neutral-400">
                    <tr class="border-b border-neutral-800">
                        <th scope="col" class="py-2 pr-4 font-normal">Area</th>
                        <th scope="col" class="py-2 pr-4 font-normal">Result</th>
                        <th scope="col" class="py-2 text-right font-normal">From</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($totals['areas'] as $area)
                        <tr class="border-b border-neutral-900">
                            <th scope="row" class="py-2 pr-4 font-normal text-neutral-100">{{ $area['number'] }}@if ($area['declared']) <span class="text-neutral-400">(map position)</span>@endif</th>
                            <td class="py-2 pr-4 text-neutral-100">{{ ucfirst(MapTotals::record($area)) }}</td>
                            <td class="py-2 text-right whitespace-nowrap text-neutral-400">{{ MapTotals::replays($area['replays']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <p class="text-sm text-neutral-400">A start near one of the map&rsquo;s own positions counts there. Other starts close to each other make one area. A team wins when its side does, and a match nobody won counts as no result.</p>
    </div>
@endif
