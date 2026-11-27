{{--
    ReplayMapCard in its heat mode: the minimap with where orders were aimed,
    the start boxes and the start positions on it, in Coilbox's layer order.
    `card` is ReplayPage's map card. Drawn without JavaScript.
--}}
@props(['card', 'title'])
@use('App\Domain\Maps\Js')
@use('App\Domain\Replays\HeatPicture')
@php
    $map = $card['map'];
    $picture = $card['picture'];
    $ratio = $map === null ? '1 / 1' : Js::number($map['width']).' / '.Js::number($map['height']);
    $gradient = implode(', ', array_map(fn ($c, $i) => $c.' '.round($i / (count(HeatPicture::RAMP) - 1) * 100).'%', HeatPicture::RAMP, array_keys(HeatPicture::RAMP)));
    $keys = array_filter([
        'Order density' => $card['heat'] !== null,
        'Start boxes' => $card['boxes'] !== [],
        'Start positions' => $card['starts'] !== [],
    ]);
@endphp
<section class="flex min-w-0 flex-col gap-2" aria-labelledby="replay-map">
    <h2 id="replay-map" class="text-sm font-medium">Map &middot; {{ $title }}</h2>
    <div class="flex flex-col gap-3 rounded-lg border border-neutral-800 bg-card p-3 sm:flex-row sm:items-start">
        <figure class="relative mx-auto w-full max-w-[36rem] overflow-hidden rounded-md bg-black" style="aspect-ratio: {{ $ratio }}">
            @if ($picture['from'] === 'placeholder')
                <x-assets.placeholder :of="$picture" :quiet="true" class="size-full !p-4" />
            @else
                <img src="{{ $picture['url'] }}" alt="Minimap of {{ $title }}" decoding="async" class="absolute inset-0 size-full object-fill">
            @endif
            @if ($card['heat'] !== null)
                {{-- One pixel a grid cell, stretched and smoothed by the browser as Coilbox's canvas is. --}}
                <img src="{{ $card['heat'] }}" alt="" class="absolute inset-0 size-full object-fill [filter:drop-shadow(0_0_1px_rgb(0_0_0/.8))_drop-shadow(0_0_3px_rgb(0_0_0/.5))]">
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
                <span class="absolute size-3 -translate-x-1/2 -translate-y-1/2 rounded-full border-2 border-white shadow-[0_0_0_1.5px_rgba(9,13,22,.9)]" style="left: {{ $start['left'] }}%; top: {{ $start['top'] }}%; background: {{ $start['colour'] }}" title="{{ $start['name'] }}"></span>
            @endforeach
            <figcaption class="sr-only">
                Minimap of {{ $title }}@if ($keys !== []), showing {{ strtolower(implode(', ', array_keys($keys))) }}@endif.
                @foreach ($card['starts'] as $start) {{ $start['name'] }} started at {{ round($start['left']) }}% across and {{ round($start['top']) }}% down. @endforeach
            </figcaption>
        </figure>

        <div class="flex flex-col gap-3 text-sm sm:w-48 sm:shrink-0">
            @if ($keys !== [])
                <ul class="flex flex-col gap-1.5" aria-label="Layers">
                    @foreach (array_keys($keys) as $key)
                        <li class="rounded-md bg-neutral-900 px-3 py-1.5 text-neutral-200">{{ $key }}</li>
                    @endforeach
                </ul>
            @endif
            @if ($card['legend'] !== null)
                <div class="flex flex-col gap-1">
                    <p class="text-xs font-medium">Where orders were aimed</p>
                    <span class="h-2 rounded-full opacity-90" style="background: linear-gradient(to right, {{ $gradient }})" aria-hidden="true"></span>
                    <p class="text-xs text-neutral-400">{{ number_format($card['legend']['within']) }} {{ $card['legend']['within'] === 1 ? 'order' : 'orders' }} within {{ number_format($card['legend']['radius']) }} elmos of one spot</p>
                </div>
            @elseif ($map === null)
                <p class="text-xs text-neutral-400">The hub's map catalogue does not have this map, so there is no heat and no start positions.</p>
            @else
                <p class="text-xs text-neutral-400">This replay has no order heat yet.</p>
            @endif
            @if ($card['boxes'] === [])
                <p class="text-xs text-neutral-400">This match set no start boxes.</p>
            @endif
        </div>
    </div>
</section>
