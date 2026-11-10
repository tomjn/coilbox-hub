{{--
    A map's minimap with its points drawn on it. components/MapFigure.tsx.

    The frame is the map's extent and the picture is stretched to fill it, so a
    point lands at x / width across and z / height down. The layer toggles are
    checkboxes with a sibling selector, so the figure needs no JavaScript. The
    layers are aria-hidden: the facts beside the picture say the player count.

    `map` carries width_elmos and height_elmos. `points` has start, metal and geo.
--}}
@props(['name', 'map', 'points', 'picture'])
@use('App\Domain\Maps\Js')
@use('App\Domain\Maps\MapPage')
@php
    // The height the figure aims for, applied as a maximum width so every map gets the same share of the page.
    $figureHeight = 512;
    $marker = 'absolute size-2 rounded-full ring-1 ring-black/70 -translate-x-1/2 -translate-y-1/2';
    $chip = 'relative cursor-pointer rounded-full border border-neutral-700 bg-black/70 px-3 py-1 text-sm text-neutral-300 backdrop-blur-sm transition-colors hover:border-neutral-500 active:border-neutral-400 hover:text-white active:text-white';
    $dot = 'mr-1.5 inline-block size-2 rounded-full align-middle';
    $w = $map['width_elmos'];
    $h = $map['height_elmos'];
    // Start positions last, so one is never hidden under a metal spot.
    $layers = [
        [$points['metal'] ?? [], 'bg-amber-300', 'hidden peer-checked/metal:block'],
        [$points['geo'] ?? [], 'bg-rose-400', 'hidden peer-checked/geo:block'],
        [$points['start'] ?? [], 'bg-neutral-100', null],
    ];
@endphp
@if ($picture['from'] === 'placeholder')
    {{-- No markers over the drawing: it stands in for a picture and its coordinates mean nothing. --}}
    <figure><x-assets.placeholder :of="$picture" /></figure>
@else
    <figure class="flex flex-col gap-2">
        <div class="relative mx-auto flex w-full flex-wrap items-end gap-1.5 overflow-hidden rounded-md border border-neutral-800 bg-black p-2" style="aspect-ratio:{{ Js::number($w) }} / {{ Js::number($h) }};max-width:{{ Js::number(($figureHeight * $w) / $h) }}px">
            {{-- Before the layers, because a sibling selector only reaches forwards. --}}
            <input id="map-metal-spots" type="checkbox" class="peer/metal sr-only"><input id="map-geo-vents" type="checkbox" class="peer/geo sr-only"><img src="{{ $picture['url'] }}" alt="Minimap of {{ $name }}" class="absolute inset-0 size-full object-fill">
            @foreach ($layers as [$layerPoints, $colour, $toggle])
                @if ($layerPoints !== [])
                    <ul aria-hidden="true" class="absolute inset-0{{ $toggle ? ' '.$toggle : '' }}">
                        @foreach ($layerPoints as $point)
                            @php $at = MapPage::markerPosition($point, $w, $h); @endphp
                            <li class="{{ $marker }} {{ $colour }}" style="left:{{ Js::number($at['left']) }}%;top:{{ Js::number($at['top']) }}%"></li>
                        @endforeach
                    </ul>
                @endif
            @endforeach
            @if (($points['metal'] ?? []) !== [])
                <label for="map-metal-spots" class="{{ $chip }} peer-checked/metal:border-neutral-300 peer-checked/metal:bg-neutral-100 peer-checked/metal:text-neutral-900 peer-focus-visible/metal:outline-2 peer-focus-visible/metal:outline-offset-2 peer-focus-visible/metal:outline-neutral-300"><span class="{{ $dot }} bg-amber-300"></span>Metal spots</label>
            @endif
            @if (($points['geo'] ?? []) !== [])
                <label for="map-geo-vents" class="{{ $chip }} peer-checked/geo:border-neutral-300 peer-checked/geo:bg-neutral-100 peer-checked/geo:text-neutral-900 peer-focus-visible/geo:outline-2 peer-focus-visible/geo:outline-offset-2 peer-focus-visible/geo:outline-neutral-300"><span class="{{ $dot }} bg-rose-400"></span>Geo vents</label>
            @endif
        </div>
        @if (($points['start'] ?? []) !== [])
            <figcaption class="text-sm text-neutral-400"><span class="{{ $dot }} bg-neutral-100"></span>Start positions, as the map declares them. How a lobby uses them is the lobby&rsquo;s choice.</figcaption>
        @endif
    </figure>
@endif
