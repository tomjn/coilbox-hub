{{--
    What the gallery holds that was made for this map. components/MapPlayedOn.tsx.

    Nothing at all when there is nothing. The cards are the gallery's own, with
    the gallery filtered to this map, so a card's links land back there. The
    map's picture is already resolved, so every card is handed it.
--}}
@props(['mapName', 'items', 'picture', 'origin'])
@use('App\Domain\Gallery\Cards')
@use('App\Domain\Gallery\Filters')
@if ($items !== [])
    @php
        $filters = new Filters(map: $mapName);
        // Over this map's own items: twins only matter side by side.
        $titles = Cards::titles($items);
    @endphp
    <section class="flex flex-col gap-4 border-t border-neutral-900 pt-6">
        <div class="flex flex-wrap items-baseline justify-between gap-3">
            <h2 class="text-xl font-semibold tracking-tight">Played on this map</h2>
            <a href="/gallery?map={{ \App\Domain\Maps\MapPage::encodeURIComponent($mapName) }}" class="text-sm text-neutral-400 transition-colors hover:text-neutral-300 active:text-neutral-300">See these in the gallery</a>
        </div>
        <ul class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ($items as $item)
                <li><x-gallery.item-card :item="$item" :filters="$filters" :origin="$origin" :picture="$picture" :title="$titles[$item['id']] ?? null" /></li>
            @endforeach
        </ul>
    </section>
@endif
