{{--
    One map in the catalog listing. components/MapCard.tsx in the Next app.

    Leads with the picture in a square frame, drawn at the map's own
    proportions and never squashed. A map with nothing stored gets the
    placeholder drawing in the same frame.
--}}
@props(['map', 'picture', 'eager' => false, 'headingLevel' => 2])
@use('App\Domain\Maps\Js')
@use('App\Domain\Maps\Labels')
@php
    $title = Labels::title($map['display_name'], $map['map_name']);
    $players = Labels::players((int) $map['start_positions']);
    $heading = 'h'.$headingLevel;
@endphp
<article class="flex h-full flex-col gap-2 rounded-md border border-neutral-800 bg-card p-3">
    <a class="relative block focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-400" href="/map/{{ $map['slug'] }}">
        <div class="flex aspect-square w-full items-center justify-center overflow-hidden rounded-md bg-black">
            @if ($picture['from'] === 'placeholder')
                <x-assets.placeholder :of="$picture" :quiet="true" class="size-full" />
            @else
                <img src="{{ $picture['url'] }}" alt="Minimap of {{ $map['map_name'] }}" @unless ($eager) loading="lazy" @endunless decoding="async" class="max-h-full max-w-full object-fill" style="aspect-ratio:{{ Js::number($map['width_elmos']) }} / {{ Js::number($map['height_elmos']) }}">
            @endif
        </div>
        @if ($map['featured_at'])
            <span class="absolute left-2 top-2 rounded border border-neutral-700 bg-black/80 px-2 py-1 text-sm text-neutral-100">Featured</span>
        @endif
    </a>

    {{-- Two lines are always kept for the title, so every card in a row is the same height. A longer title ends in an ellipsis and is in full in the tooltip. --}}
    <{{ $heading }} class="line-clamp-2 min-h-[2lh] min-w-0 break-words text-base font-medium leading-snug" title="{{ $title }}">
        <a class="hover:underline active:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-400" href="/map/{{ $map['slug'] }}">{{ $title }}</a>
    </{{ $heading }}>

    {{-- Size and starts. Names, authors and tags are on the map page. --}}
    <p class="truncate text-sm text-neutral-400">{{ Labels::size($map['width_elmos'], $map['height_elmos']) }}@if ($players) &middot; {{ $players }}@endif</p>
</article>
