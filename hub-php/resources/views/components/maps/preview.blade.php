{{--
    The map's terrain, standing in for its minimap. components/MapPreview.tsx.

    The flat figure is in the slot and is what the page is served as. The
    scene's frame is in the page from the start and hidden. resources/js/maps/page.js
    loads three.js only once it finds `data-map-preview`, draws the terrain, and
    swaps the two over when it has. A failure leaves the flat figure, quietly.

    The preview data travels as JSON in the element, from MapPage::preview().
--}}
@props(['preview', 'name', 'column'])
@php
    $chip = 'cursor-pointer rounded-full border border-neutral-700 bg-black/70 px-3 py-1 text-sm text-neutral-300 backdrop-blur-sm transition-colors hover:border-neutral-500 active:border-neutral-400 hover:text-white active:text-white focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300 aria-pressed:border-neutral-300 aria-pressed:bg-neutral-100 aria-pressed:text-neutral-900';
    $dot = 'mr-1.5 inline-block size-2 rounded-full align-middle';
@endphp
<div class="{{ $column }}" data-map-preview-flat>{{ $slot }}</div>

<figure class="hidden" data-map-preview="{{ json_encode($preview, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}">
    {{-- Three to two: a map seen from above and to one side lands about that shape. Capped so the caption stays on screen. --}}
    <div class="relative w-full aspect-[3/2] max-h-[min(70vh,640px)]">
        <div class="absolute inset-0" data-map-preview-host></div>
        <div class="absolute inset-x-0 bottom-0 flex flex-wrap items-end gap-1.5 {{ $column }}">
            @if ($preview['points']['metal'] !== [])
                <button type="button" aria-pressed="true" class="{{ $chip }}" data-map-layer="metal"><span class="{{ $dot }} bg-lime-400"></span>Metal spots</button>
            @endif
            @if ($preview['points']['geo'] !== [])
                <button type="button" aria-pressed="true" class="{{ $chip }}" data-map-layer="geo"><span class="mr-1.5 inline-block h-3 w-1 rounded-full align-middle bg-yellow-300"></span>Geo vents</button>
            @endif
        </div>
    </div>

    <figcaption class="flex flex-col gap-1 text-sm text-neutral-400 {{ $column }}">
        @if ($preview['points']['start'] !== [])
            <span><span class="{{ $dot }} bg-neutral-100"></span>Start positions, as the map declares them. How a lobby uses them is the lobby&rsquo;s choice.</span>
        @endif
        <span class="text-neutral-400">{{ $name }} as the archive measures it. Drag to turn it, scroll to zoom. Heights are eight bits deep, which is what a browser can read.</span>
    </figcaption>
</figure>
