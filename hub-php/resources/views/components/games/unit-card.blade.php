{{-- One unit in the encyclopedia's grid, components/UnitCard.tsx. A buildpic
     and a name. A unit with no picture gets the drawing rather than a hole,
     square and quiet, since the cell prints the name below it. --}}
@props(['game', 'unit', 'picture', 'eager' => false])
@php $label = $unit['full_name'] ?? $unit['unit_name']; @endphp
<li>
    <a class="group flex h-full min-w-0 flex-col items-center gap-2 rounded-md border border-neutral-900 p-3 transition-colors hover:border-neutral-600 active:border-neutral-500" href="/games/{{ $game }}/units/{{ \App\Domain\Games\UnitRelease::unitSegment($unit['unit_name']) }}">
        <span class="flex h-16 w-full items-center justify-center">
            @if ($picture['from'] === 'placeholder')
                <x-assets.placeholder :of="['name' => $picture['name'], 'keyedOn' => $picture['keyedOn'], 'footprint' => null]" quiet class="size-16" />
            @else
                <img src="{{ $picture['url'] }}" alt="Buildpic of {{ $label }}" width="64" height="64" @unless ($eager) loading="lazy" @endunless decoding="async" class="max-h-16 w-auto object-contain"/>
            @endif
        </span>
        <span class="line-clamp-2 text-center text-xs text-neutral-300 transition-colors group-hover:text-white group-active:text-white">{{ $label }}</span>
    </a>
</li>
