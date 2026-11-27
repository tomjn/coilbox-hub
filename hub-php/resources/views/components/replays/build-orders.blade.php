{{--
    ReplayBuildOrders and ReplayOpeningSplit: what each team ordered built,
    the opening up to a chosen minute and what it cost by kind of unit. The
    minute is a form field sent in the address, so it works without JavaScript.
--}}
@props(['builds', 'units', 'minutes', 'action'])
@use('App\Domain\Replays\ReplayPage')
@php
    $openings = array_map(fn ($b) => ['build' => $b, 'opening' => ReplayPage::opening($b['orders'], $units, $minutes), 'all' => ReplayPage::opening($b['orders'], $units, null)], $builds);
    $buckets = ['economy' => 'Economy', 'defence' => 'Defence', 'offence' => 'Offence', 'other' => 'Other', 'unclassified' => 'Unclassified'];
    $shown = $minutes === null ? null : rtrim(rtrim(number_format($minutes, 2, '.', ''), '0'), '.');
    $field = 'w-32 rounded-md border border-neutral-700 bg-neutral-950 px-2 py-1.5 text-sm text-neutral-100 placeholder:text-neutral-500 focus-visible:outline-2 focus-visible:outline-neutral-400';
@endphp
<section class="flex flex-col gap-3">
    <h2 class="text-sm font-medium">Build orders</h2>
    @if ($builds === [])
        <p class="text-sm text-neutral-400">Nobody ordered anything built in this match.</p>
    @else
        <form method="get" action="{{ $action }}" class="flex flex-wrap items-end gap-2">
            <input type="hidden" name="tab" value="builds">
            <label class="flex flex-col gap-1 text-xs text-neutral-400">
                Opening length in minutes
                <input type="text" inputmode="decimal" name="minutes" value="{{ $shown }}" placeholder="Whole match" class="{{ $field }}">
            </label>
            <button type="submit" class="min-h-9 rounded-md border border-neutral-700 px-3 text-sm text-neutral-200 transition-colors hover:border-neutral-500 active:border-neutral-400 focus-visible:outline-2 focus-visible:outline-neutral-400">Show</button>
        </form>

        <div class="flex flex-col gap-1">
            <h3 class="text-xs font-medium">{{ $shown === null ? 'Cost by kind of unit' : 'Cost by kind of unit, to minute '.$shown }}</h3>
            <div class="overflow-x-auto rounded-lg border border-neutral-800 bg-card">
                <table class="w-full text-xs">
                    <thead class="text-neutral-400">
                        <tr class="border-b border-neutral-800">
                            <th scope="col" class="px-3 py-2 text-left font-medium">Player</th>
                            @foreach ($buckets as $label)
                                <th scope="col" class="px-3 py-2 text-right font-medium">{{ $label }}</th>
                            @endforeach
                            <th scope="col" class="whitespace-nowrap px-3 py-2 text-right font-medium">Not priced</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($openings as $o)
                            <tr class="border-b border-neutral-800 last:border-b-0">
                                <th scope="row" class="px-3 py-2 text-left font-medium">{{ $o['build']['name'] }}</th>
                                @foreach (array_keys($buckets) as $bucket)
                                    <td class="whitespace-nowrap px-3 py-2 text-right tabular-nums">
                                        <div>{{ number_format($o['opening']['split'][$bucket]['metal']) }} metal</div>
                                        <div class="text-neutral-400">{{ number_format($o['opening']['split'][$bucket]['energy']) }} energy</div>
                                    </td>
                                @endforeach
                                <td class="whitespace-nowrap px-3 py-2 text-right tabular-nums">{{ $o['opening']['unpriced'] === 1 ? '1 unit' : number_format($o['opening']['unpriced']).' units' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <p class="text-xs text-neutral-400">The cost of what was ordered, not of what was built. A unit is not priced when the replay's summary gives it no cost.</p>
        </div>

        <ul class="grid grid-cols-[repeat(auto-fit,minmax(min(16rem,100%),1fr))] gap-3">
            @foreach ($openings as $o)
                <li class="flex min-w-0 flex-col gap-2 rounded-lg border border-neutral-800 bg-card p-3">
                    <h3 class="flex items-center gap-2 text-sm font-medium">
                        <span class="size-3 shrink-0 rounded-sm border border-neutral-700" style="background: {{ $o['build']['colour'] ?? 'transparent' }}" aria-hidden="true"></span>
                        <span class="min-w-0 break-words">{{ $o['build']['name'] }}</span>
                    </h3>
                    <h4 class="text-xs text-neutral-400">{{ $shown === null ? 'Opening' : 'Opening, first '.$shown.' min' }}</h4>
                    @if ($o['opening']['entries'] === [])
                        <p class="text-sm text-neutral-400">No orders yet.</p>
                    @else
                        <ol class="flex flex-col gap-0.5 text-sm">
                            @foreach ($o['opening']['entries'] as $entry)
                                <li class="flex gap-2"><span class="w-12 shrink-0 text-xs leading-5 tabular-nums text-neutral-400">{{ $entry['time'] }}</span><span class="min-w-0 break-words">{{ $entry['label'] }}@if ($entry['count'] > 1) <span class="text-neutral-400">&times;{{ $entry['count'] }}</span>@endif</span></li>
                            @endforeach
                        </ol>
                    @endif
                    <p class="text-xs text-neutral-400">Ordered cost: {{ number_format($o['opening']['metal']) }} metal and {{ number_format($o['opening']['energy']) }} energy.</p>
                    @if ($shown !== null)
                        <details class="text-sm">
                            <summary class="cursor-pointer text-xs text-neutral-300">All orders</summary>
                            <ol class="mt-1 flex flex-col gap-0.5">
                                @foreach ($o['all']['entries'] as $entry)
                                    <li class="flex gap-2"><span class="w-12 shrink-0 text-xs leading-5 tabular-nums text-neutral-400">{{ $entry['time'] }}</span><span class="min-w-0 break-words">{{ $entry['label'] }}@if ($entry['count'] > 1) <span class="text-neutral-400">&times;{{ $entry['count'] }}</span>@endif</span></li>
                                @endforeach
                            </ol>
                        </details>
                    @endif
                </li>
            @endforeach
        </ul>
    @endif
</section>
