{{--
    One map in the catalog listing. components/MapCard.tsx in the Next app.

    Leads with the picture, drawn at the catalog's own proportions and capped
    at 200 pixels tall by narrowing the frame rather than squashing it. A map
    with nothing stored gets the placeholder drawing at the same proportions.
--}}
@props(['map', 'picture', 'filters', 'eager' => false])
@use('App\Domain\Maps\Js')
@use('App\Domain\Maps\Labels')
@use('App\Domain\Maps\Listing')
@php
    $title = Labels::title($map['display_name'], $map['map_name']);
    $players = Labels::players((int) $map['start_positions']);
    $maxWidth = Js::number((200 * $map['width_elmos']) / $map['height_elmos']);
    $chip = 'inline-block rounded bg-neutral-900 px-2 py-1 text-xs text-neutral-400 transition-colors hover:text-neutral-200 active:text-neutral-200';
@endphp
<article class="flex h-full flex-col gap-3 rounded-md border border-neutral-800 bg-card p-4">
    <a class="relative block" href="/map/{{ $map['slug'] }}">
        @if ($picture['from'] === 'placeholder')
            <x-assets.placeholder :of="$picture" />
        @else
            <div class="mx-auto overflow-hidden rounded-md border border-neutral-800 bg-black" style="aspect-ratio:{{ Js::number($map['width_elmos']) }} / {{ Js::number($map['height_elmos']) }};max-width:{{ $maxWidth }}px">
                <img src="{{ $picture['url'] }}" alt="Minimap of {{ $map['map_name'] }}" @unless ($eager) loading="lazy" @endunless decoding="async" class="size-full object-fill">
            </div>
        @endif
        @if ($map['featured_at'])
            <span class="absolute left-2 top-2 rounded border border-neutral-700 bg-black/80 px-2 py-1 text-xs text-neutral-100">Featured</span>
        @endif
    </a>

    <div class="flex flex-col gap-1">
        <h2 class="min-w-0 break-words text-base font-medium leading-snug">
            <a class="hover:underline active:underline" href="/map/{{ $map['slug'] }}">{{ $title }}</a>
        </h2>
        {{-- The canonical name, which is what a lobby shows. Only when the archive gave a friendlier one. --}}
        @if ($title !== $map['map_name'])
            <p class="break-words text-xs text-neutral-400">{{ $map['map_name'] }}</p>
        @endif
    </div>

    <dl class="flex flex-wrap gap-x-4 gap-y-1 text-xs text-neutral-400">
        <div class="flex gap-1">
            <dt class="sr-only">Size</dt>
            <dd>{{ Labels::size($map['width_elmos'], $map['height_elmos']) }}</dd>
        </div>
        @if ($players)
            <div class="flex gap-1">
                <dt class="sr-only">Players</dt>
                <dd>{{ $players }}</dd>
            </div>
        @endif
        @if (($map['author_keys'] ?? []) !== [])
            <div class="flex gap-1">
                <dt class="sr-only">Made by</dt>
                {{-- Each link carries the key, so it finds every map that person made under any spelling. --}}
                <dd>by @foreach ($map['author_keys'] as $index => $key)<span>@if ($index > 0), @endif<a class="transition-colors hover:text-neutral-200 active:text-neutral-200" href="{{ Listing::filterHref($filters, ['author' => $key]) }}">{{ $map['author_names'][$index] ?? $key }}</a></span>@endforeach</dd>
            </div>
        @endif
    </dl>

    @if (($map['tags'] ?? []) !== [])
        <ul class="mt-auto flex flex-wrap gap-1.5 pt-1">
            @foreach ($map['tags'] as $tag)
                <li><a class="{{ $chip }}" href="{{ Listing::filterHref($filters, Listing::isSize($tag) ? ['size' => $tag] : ['tag' => $tag]) }}">{{ $tag }}</a></li>
            @endforeach
        </ul>
    @endif
</article>
