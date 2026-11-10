@props(['name', 'picture', 'note' => null, 'catalog' => null])
{{-- components/MapMinimap.tsx: the map an item names, linked to its catalog
     page when the hub lists it, with its size and player count. --}}
@php
    use App\Domain\Maps\Labels as MapLabels;
    $slug = $catalog['slug'] ?? null;
    $facts = null;
    if ($catalog !== null) {
        $facts = implode(', ', array_filter([
            MapLabels::size($catalog['width_elmos'], $catalog['height_elmos']),
            MapLabels::players(count($catalog['points']['start'] ?? [])),
        ]));
    }
    $class = trim('flex flex-col gap-2 '.$attributes->get('class', ''));
@endphp
@if ($picture['from'] === 'placeholder')
    <figure class="{{ $class }}">
        @if ($slug)<a href="/map/{{ $slug }}" class="block">@endif
        <x-assets.placeholder :of="$picture" />
        @if ($slug)</a>@endif
        @if ($facts || $note)
            <figcaption class="mt-auto flex flex-col gap-0.5 text-xs text-neutral-400">
                @if ($facts)<span>{{ $facts }}</span>@endif
                @if ($note)<span>{{ $note }}</span>@endif
            </figcaption>
        @endif
    </figure>
@else
    <figure class="{{ $class }}">
        @if ($slug)<a href="/map/{{ $slug }}" class="block">@endif
        <div class="relative overflow-hidden rounded-md border border-neutral-800 bg-black" style="aspect-ratio:{{ $picture['width'] }} / {{ $picture['height'] }}">
            <img src="{{ $picture['url'] }}" alt="Minimap of {{ $name }}" class="absolute inset-0 size-full object-cover">
        </div>
        @if ($slug)</a>@endif
        <figcaption class="mt-auto flex flex-col gap-0.5 text-xs text-neutral-400">
            <span class="text-neutral-300">@if ($slug)<a href="/map/{{ $slug }}" class="hover:underline active:underline">{{ $name }}</a>@else{{ $name }}@endif</span>
            @if ($facts)<span>{{ $facts }}</span>@endif
            @if ($note)<span>{{ $note }}</span>@endif
        </figcaption>
    </figure>
@endif
