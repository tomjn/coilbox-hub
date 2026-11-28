{{--
    ReplayMapCard. Its heat mode is the minimap with one heat layer, the start
    boxes and the start positions on it, in Coilbox's layer order. `card` is
    ReplayPage's map card, or MapTotals' on a map's page, and `heat` the layer
    shown. Each layer is a link that keeps the rest of the address, so it works
    without JavaScript. A start with a `number` is drawn as a numbered marker.

    With a track, `resources/js/replays/units.ts` adds the units mode
    (ReplayMapUnits): the units moving over the minimap with a timeline. Its
    switch and controls stay hidden until the script shows them.
--}}
@props(['card', 'title', 'heat', 'base', 'heading' => null, 'tag' => 'h2', 'anchor' => '', 'href' => null])
@use('App\Domain\Maps\Js')
@use('App\Domain\Replays\HeatPicture')
@php
    $map = $card['map'];
    $picture = $card['picture'];
    $ratio = $map === null ? '1 / 1' : Js::number($map['width']).' / '.Js::number($map['height']);
    $layer = $heat === null ? null : $card['layers'][$heat];
    $gradient = $layer === null ? '' : implode(', ', array_map(fn ($c, $i) => $c.' '.round($i / (count($layer['ramp']) - 1) * 100).'%', $layer['ramp'], array_keys($layer['ramp'])));
    $layerHref = fn (string $key) => $base.'?'.http_build_query(array_merge(request()->query(), ['heat' => $key])).$anchor;
    $startsLabel = $card['starts_label'] ?? 'Start positions';
    $shown = array_keys(array_filter([
        $layer['label'] ?? '' => $layer !== null,
        'Start boxes' => $card['boxes'] !== [],
        $startsLabel => $card['starts'] !== [],
    ]));
    // With more than one heat layer, the heat layers are links of their own.
    $keys = count($card['layers']) > 1 ? array_values(array_diff($shown, [$layer['label']])) : $shown;
    $focus = 'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-400';
    $track = $card['track'] ?? null;
    $control = 'inline-flex min-h-9 items-center justify-center gap-1.5 rounded-md border border-neutral-700 px-3 text-sm text-neutral-200 transition-colors hover:border-neutral-500 active:border-neutral-400 '.$focus;
@endphp
<section class="flex min-w-0 flex-col gap-2" aria-labelledby="replay-map"
    @if ($track !== null) data-replay-units data-track="{{ $track['url'] }}" data-colours="{{ json_encode((object) $track['colours']) }}" @endif>
    <{{ $tag }} id="replay-map" class="scroll-mt-4 text-sm font-medium">@if ($heading === null)Map &middot; @if ($href !== null)<a href="{{ $href }}" class="underline-offset-4 hover:underline active:underline {{ $focus }}">{{ $title }}</a>@else{{ $title }}@endif @else{{ $heading }}@endif</{{ $tag }}>
    <div class="flex flex-col gap-3 rounded-lg border border-neutral-800 bg-card p-3">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-start">
        <figure class="relative mx-auto w-full max-w-[36rem] overflow-hidden rounded-md bg-black" style="aspect-ratio: {{ $ratio }}">
            @if ($picture['from'] === 'placeholder')
                <x-assets.placeholder :of="$picture" :quiet="true" class="size-full !p-4" />
            @else
                <img src="{{ $picture['url'] }}" alt="Minimap of {{ $title }}" decoding="async" class="absolute inset-0 size-full object-fill">
            @endif
            <div data-heat-view class="absolute inset-0">
            @if ($layer !== null)
                {{-- One pixel a grid cell, stretched and smoothed by the browser as Coilbox's canvas is. --}}
                <img src="{{ $layer['url'] }}" alt="" class="absolute inset-0 size-full object-fill [filter:drop-shadow(0_0_1px_rgb(0_0_0/.8))_drop-shadow(0_0_3px_rgb(0_0_0/.5))]">
            @endif
            @if ($card['boxes'] !== [])
                <svg viewBox="0 0 100 100" preserveAspectRatio="none" class="absolute inset-0 size-full" aria-hidden="true">
                    @foreach ($card['boxes'] as $box)
                        @php($w = ($box['right'] - $box['left']) * 100)
                        @php($h = ($box['bottom'] - $box['top']) * 100)
                        <rect x="{{ $box['left'] * 100 }}" y="{{ $box['top'] * 100 }}" width="{{ $w }}" height="{{ $h }}" fill="{{ $box['colour'] }}33" stroke="rgba(0,0,0,.6)" stroke-width="4" vector-effect="non-scaling-stroke" />
                        <rect x="{{ $box['left'] * 100 }}" y="{{ $box['top'] * 100 }}" width="{{ $w }}" height="{{ $h }}" fill="none" stroke="{{ $box['colour'] }}" stroke-width="2" vector-effect="non-scaling-stroke" />
                    @endforeach
                </svg>
                @foreach ($card['boxes'] as $box)
                    <span class="absolute rounded-br px-1 text-[10px] font-bold leading-4 text-neutral-950" style="left: {{ $box['left'] * 100 }}%; top: {{ $box['top'] * 100 }}%; background: {{ $box['colour'] }}" aria-hidden="true">{{ $box['label'] }}</span>
                @endforeach
            @endif
            @foreach ($card['starts'] as $start)
                @if (isset($start['number']))
                    <span class="absolute flex h-4 min-w-4 -translate-x-1/2 -translate-y-1/2 items-center justify-center rounded-full px-1 text-[10px] font-bold leading-none text-neutral-950 shadow-[0_0_0_1.5px_rgba(9,13,22,.9)]" style="left: {{ $start['left'] }}%; top: {{ $start['top'] }}%; background: {{ $start['colour'] }}" title="{{ $start['name'] }}" aria-hidden="true">{{ $start['number'] }}</span>
                @else
                    <span class="absolute size-3 -translate-x-1/2 -translate-y-1/2 rounded-full border-2 border-white shadow-[0_0_0_1.5px_rgba(9,13,22,.9)]" style="left: {{ $start['left'] }}%; top: {{ $start['top'] }}%; background: {{ $start['colour'] }}" title="{{ $start['name'] }}"></span>
                @endif
            @endforeach
            </div>
            @if ($track !== null)
                <canvas data-units-canvas hidden class="absolute inset-0 size-full" role="img" aria-label="Units of the match over the minimap of {{ $title }}"></canvas>
                <div data-units-failed hidden class="absolute inset-0 flex items-center justify-center bg-neutral-950/75 p-6">
                    <p class="max-w-xs text-center text-sm text-neutral-200">The track could not be read from its mirror in this browser, so the units cannot play here. <a href="{{ $track['url'] }}" download class="font-medium text-white underline underline-offset-4 {{ $focus }}">Download the track</a> to watch it in Coilbox.</p>
                </div>
            @endif
            <figcaption class="sr-only">
                Minimap of {{ $title }}@if ($shown !== []), showing {{ strtolower(implode(', ', $shown)) }}@endif.
                @foreach ($card['starts'] as $start) {{ $start['caption'] ?? $start['name'].' started at '.round($start['left']).'% across and '.round($start['top']).'% down.' }} @endforeach
            </figcaption>
        </figure>

        <div class="flex flex-col gap-3 text-sm sm:w-48 sm:shrink-0">
            @if ($track !== null)
                <div data-units-modes hidden role="group" aria-label="Map mode" class="grid grid-cols-2 gap-1 rounded-lg bg-neutral-900 p-1">
                    <button type="button" data-mode="heat" aria-pressed="true" class="rounded-md px-3 py-1.5 transition-colors {{ $focus }} aria-pressed:bg-neutral-700 aria-pressed:text-white text-neutral-400 hover:text-neutral-100">Heat</button>
                    <button type="button" data-mode="units" aria-pressed="false" class="rounded-md px-3 py-1.5 transition-colors {{ $focus }} aria-pressed:bg-neutral-700 aria-pressed:text-white text-neutral-400 hover:text-neutral-100">Units</button>
                </div>
                <p data-units-view hidden class="text-xs text-neutral-400">Each unit is its footprint in its team's colour, pointing the way it faces, and faint while it is being built.</p>
            @endif
            <div data-heat-view class="flex flex-col gap-3">
            @if (count($card['layers']) > 1)
                <nav aria-label="Heat layer">
                    <ul class="flex flex-col gap-1.5">
                        @foreach ($card['layers'] as $key => $l)
                            <li><a href="{{ $layerHref($key) }}" @if ($key === $heat) aria-current="true" @endif class="block rounded-md px-3 py-1.5 transition-colors {{ $focus }} {{ $key === $heat ? 'bg-neutral-700 text-white' : 'bg-neutral-900 text-neutral-400 hover:text-neutral-100 active:text-neutral-100' }}">{{ $l['label'] }}</a></li>
                        @endforeach
                    </ul>
                </nav>
            @endif
            @if ($keys !== [])
                <ul class="flex flex-col gap-1.5" aria-label="Layers">
                    @foreach ($keys as $key)
                        <li class="rounded-md bg-neutral-900 px-3 py-1.5 text-neutral-200">{{ $key }}</li>
                    @endforeach
                </ul>
            @endif
            @if ($layer !== null)
                <div class="flex flex-col gap-1">
                    <p class="text-xs font-medium">{{ $layer['title'] }}</p>
                    <span class="h-2 rounded-full opacity-90" style="background: linear-gradient(to right, {{ $gradient }})" aria-hidden="true"></span>
                    <p class="text-xs text-neutral-400">{{ $layer['legend'] }}</p>
                </div>
            @elseif ($map === null)
                <p class="text-xs text-neutral-400">The hub's map catalogue does not have this map, so there is no heat and no start positions.</p>
            @else
                <p class="text-xs text-neutral-400">{{ $card['no_heat'] }}</p>
            @endif
            @if ($card['boxes'] === [] && $card['no_boxes'] !== null)
                <p class="text-xs text-neutral-400">{{ $card['no_boxes'] }}</p>
            @endif
            </div>
        </div>
    </div>
    @if ($track !== null)
        {{-- ReplayTimelineDock's controls for the units mode. --}}
        <div data-units-controls hidden class="flex flex-wrap items-center gap-x-3 gap-y-2">
            <button type="button" data-play class="{{ $control }} w-20">Play</button>
            <label class="flex min-w-48 flex-1 items-center gap-2 text-xs text-neutral-400">
                <span class="sr-only">Match time</span>
                <input type="range" data-scrub min="0" max="0" step="1" value="0" class="h-9 w-full accent-amber-400 {{ $focus }}">
            </label>
            <output data-time class="text-xs tabular-nums text-neutral-300">0:00 / 0:00</output>
            <label class="flex items-center gap-1.5 text-xs text-neutral-400">
                Speed
                <select data-speed class="min-h-9 rounded-md border border-neutral-700 bg-neutral-950 px-2 text-sm text-neutral-100 {{ $focus }}">
                    @foreach ([0.5, 1, 2, 4, 8, 16, 32] as $speed)
                        <option value="{{ $speed }}" @selected($speed === 1)>{{ $speed }}&times;</option>
                    @endforeach
                </select>
            </label>
        </div>
        <p data-units-status hidden role="status" class="text-sm text-neutral-400"></p>
    @endif
    </div>
</section>
