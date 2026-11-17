{{--
    One map in the catalog listing. components/MapCard.tsx in the Next app.

    Leads with the picture in a square frame, drawn at the map's own
    proportions and never squashed. A map with nothing stored gets the
    placeholder drawing in the same frame.
--}}
@props(['map', 'picture', 'filters', 'eager' => false, 'headingLevel' => 2])
@use('App\Domain\Maps\Js')
@use('App\Domain\Maps\Labels')
@use('App\Domain\Maps\Listing')
@php
    $title = Labels::title($map['display_name'], $map['map_name']);
    $players = Labels::players((int) $map['start_positions']);
    $heading = 'h'.$headingLevel;
    $chip = 'inline-block whitespace-nowrap rounded bg-neutral-900 px-2 py-1 text-sm text-neutral-400 transition-colors hover:text-neutral-200 active:text-neutral-200';
    $authors = $map['author_keys'] ?? [];
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

    <div class="flex flex-col gap-0.5">
        <{{ $heading }} class="min-w-0 break-words text-base font-medium leading-snug">
            <a class="hover:underline active:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-400" href="/map/{{ $map['slug'] }}">{{ $title }}</a>
        </{{ $heading }}>
        {{-- The canonical name, which is what a lobby shows. Only when the archive gave a friendlier one. --}}
        @if ($title !== $map['map_name'])
            <p class="break-words text-sm text-neutral-400">{{ $map['map_name'] }}</p>
        @endif
    </div>

    {{-- Size, starts and authors on one line. Each author link carries the key, so it finds every map that person made under any spelling. --}}
    <p class="break-words text-sm text-neutral-400">{{ Labels::size($map['width_elmos'], $map['height_elmos']) }}@if ($players) &middot; {{ $players }}@endif @foreach ($authors as $index => $key) &middot; <a class="transition-colors hover:text-neutral-200 active:text-neutral-200" href="{{ Listing::filterHref($filters, ['author' => $key]) }}">{{ $map['author_names'][$index] ?? $key }}</a>@endforeach</p>

    @if (($map['tags'] ?? []) !== [])
        <ul class="mt-auto flex flex-nowrap gap-1.5 overflow-hidden pt-1">
            @foreach ($map['tags'] as $tag)
                <li class="shrink-0"><a class="{{ $chip }}" href="{{ Listing::filterHref($filters, Listing::isSize($tag) ? ['size' => $tag] : Listing::withTag($filters, $tag)) }}">{{ $tag }}</a></li>
            @endforeach
        </ul>
    @endif
</article>
