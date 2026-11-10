{{-- One unit's chip in the build tree, components/GameTree.tsx UnitRow. The
     border says what the unit is: yellow dashed builds, red only shoots. --}}
@props(['game', 'node', 'picture' => null, 'muted' => false, 'fit' => false])
@php
    $border = count($node['builds']) > 0
        ? 'border-2 border-dashed border-yellow-400/80'
        : ($node['armed'] ? 'border-2 border-red-400/50' : 'border-2 border-neutral-800');
    $text = $muted
        ? 'text-sm text-neutral-400 underline-offset-4 hover:text-white active:text-white hover:underline active:underline'
        : 'text-sm text-neutral-300 underline-offset-4 hover:text-white active:text-white hover:underline active:underline';
@endphp
<a href="/games/{{ $game }}/units/{{ \App\Domain\Games\UnitRelease::unitSegment($node['name']) }}" class="flex min-w-0 items-center gap-2 rounded-sm border px-1 py-0.5 {{ $border }} {{ $fit ? 'w-fit min-w-40' : '' }} {{ $text }}">
    @if ($picture && $picture['from'] !== 'placeholder')
        <img src="{{ $picture['url'] }}" alt="" width="{{ $picture['width'] }}" height="{{ $picture['height'] }}" loading="lazy" decoding="async" class="size-8 shrink-0 object-contain"/>
    @endif
    <span class="truncate">{{ $node['label'] }}</span>
    {{-- A folded morph chain is one node, so say how many stages it stands for. --}}
    @if ($node['stages'] > 1)
        <span class="shrink-0 rounded bg-neutral-800 px-1 py-0.5 text-sm text-neutral-400">{{ $node['stages'] }} stages</span>
    @endif
</a>
