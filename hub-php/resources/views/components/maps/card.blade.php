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
    $title = Labels::title($map['display_name'], $map['map_name'], $map['map_version'] ?? null);
    $players = Labels::players((int) $map['start_positions']);
    $heading = 'h'.$headingLevel;
    // Every healthy hub mirror holding the archive. The link goes to the first, and listing.js picks one at random on click.
    $mirrors = array_values(array_filter($map['mirror_urls'] ?? [], 'is_string'));
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

    {{-- Size and starts. Names, authors and tags are on the map page. The download button's negative margin keeps this row the same height on every card. --}}
    <div class="flex items-center gap-2">
        <p class="min-w-0 flex-1 truncate text-sm text-neutral-400">{{ Labels::size($map['width_elmos'], $map['height_elmos']) }}@if ($players) &middot; {{ $players }}@endif</p>
        @if ($mirrors !== [])
            <a class="group relative -my-1.5 -mr-1.5 flex size-8 shrink-0 items-center justify-center rounded-md text-neutral-400 transition-colors hover:bg-neutral-800 hover:text-neutral-100 active:bg-neutral-700 active:text-neutral-100 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-400" href="{{ $mirrors[0] }}" rel="noreferrer" data-mirror-urls="{{ json_encode($mirrors) }}">
                <x-icon name="download" class="size-4" />
                <span class="pointer-events-none absolute bottom-full right-0 mb-1.5 whitespace-nowrap rounded border border-neutral-700 bg-neutral-950 px-2 py-1 text-xs text-neutral-100 opacity-0 transition-opacity group-hover:opacity-100 group-focus-visible:opacity-100">Download<span class="sr-only"> {{ $title }}</span></span>
            </a>
        @endif
    </div>
</article>
