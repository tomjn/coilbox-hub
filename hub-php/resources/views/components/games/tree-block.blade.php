{{-- One faction's block of the build tree, components/GameTree.tsx TreeBlock.
     It hangs from its start unit and a unit opens once across the page, which
     keeps a request bounded whatever the build graph is. --}}
@props(['game', 'heading', 'note' => null, 'roots', 'byName', 'pictures', 'claims'])
@if (count($roots) > 0)
    <section class="flex flex-col gap-2" aria-label="{{ $heading }}">
        <h2 class="text-sm uppercase tracking-wide text-neutral-400">
            {{ $heading }}@if ($note)<span class="ml-2 normal-case tracking-normal text-neutral-400">{{ $note }}</span>@endif
        </h2>
        <ul class="flex flex-col gap-1.5">
            @foreach ($roots as $root)
                <x-games.tree-node :game="$game" :node="$root" :by-name="$byName" :pictures="$pictures" :claims="$claims" />
            @endforeach
        </ul>
    </section>
@endif
