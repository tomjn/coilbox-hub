{{-- One builder and everything beneath it, components/GameTree.tsx Node.
     `claims` says which children this unit unfolds. The rest are links. --}}
@props(['game', 'node', 'byName', 'pictures', 'claims'])
@php
    $children = [];
    foreach ($node['builds'] as $childName) {
        $child = $byName[$childName] ?? null;
        if ($child === null) {
            continue;
        }
        $fresh = isset($claims[$node['name']][$child['name']]);
        $children[] = ['child' => $child, 'fresh' => $fresh];
    }
    // Dead ends read across, the builders carry the hierarchy down. Armed dead
    // ends group first so the red reads as a set.
    $leaves = array_filter($children, fn ($entry) => count($entry['child']['builds']) === 0);
    $armedLeaves = array_filter($leaves, fn ($entry) => $entry['child']['armed']);
    $quietLeaves = array_filter($leaves, fn ($entry) => ! $entry['child']['armed']);
    $builders = array_filter($children, fn ($entry) => count($entry['child']['builds']) > 0);
@endphp
<li>
    <x-games.tree-row :game="$game" :node="$node" :picture="$pictures[$node['name']] ?? null" fit />
    @if (count($children) > 0)
        <details open="" class="group">
            <summary class="ml-1 inline cursor-pointer list-none text-xs text-neutral-400 transition-colors hover:text-neutral-300 active:text-neutral-300">
                <span aria-hidden="true" class="mr-1 inline-block text-center transition-transform group-open:rotate-45">+</span>
                builds {{ count($children) }}
            </summary>
            <ul class="ml-6 mt-1 flex flex-col gap-1.5 border-l border-neutral-800 pl-5">
                @if (count($leaves) > 0)
                    <li class="grid grid-cols-[repeat(auto-fill,minmax(10rem,1fr))] items-center gap-x-3 gap-y-1 py-0.5">
                        @foreach ([...$armedLeaves, ...$quietLeaves] as $entry)
                            <x-games.tree-row :game="$game" :node="$entry['child']" :picture="$pictures[$entry['child']['name']] ?? null" muted />
                        @endforeach
                    </li>
                @endif
                @foreach ($builders as $entry)
                    @if ($entry['fresh'])
                        <x-games.tree-node :game="$game" :node="$entry['child']" :by-name="$byName" :pictures="$pictures" :claims="$claims" />
                    @else
                        <li><x-games.tree-row :game="$game" :node="$entry['child']" :picture="$pictures[$entry['child']['name']] ?? null" muted fit /></li>
                    @endif
                @endforeach
            </ul>
        </details>
    @endif
</li>
