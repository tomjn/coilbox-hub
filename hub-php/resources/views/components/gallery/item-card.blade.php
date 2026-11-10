@props(['item', 'filters', 'origin', 'picture' => null, 'shape' => null, 'counts' => null, 'title' => null])
{{-- components/ItemCard.tsx. `origin` is absolute, because Coilbox only
     fetches an https URL. --}}
@php
    use App\Domain\Gallery\Cards;
    use App\Domain\Gallery\GalleryQuery;
    use App\Domain\Gallery\Labels;
    $cardTitle = $title ?? ['title' => $item['title'], 'tail' => null];
    $countLine = $counts !== null ? Cards::projectCountLine($counts) : '';
    $authorHref = $item['author_id'] !== null
        ? GalleryQuery::filterHref($filters, ['author' => [], 'account' => [$item['author_id']]])
        : GalleryQuery::filterHref($filters, ['author' => [$item['author_name']]]);
@endphp
<article class="flex h-full flex-col gap-3 rounded-md border border-neutral-800 bg-card p-5">
    {{-- Over the picture, so a featured card keeps the same shape as the rest (#395). --}}
    <div class="relative">
        <x-gallery.item-card-art :item="$item" :picture="$picture" :shape="$shape" />
        @if ($item['featured_at'])
            <span class="absolute left-2 top-2 rounded border border-neutral-700 bg-black/80 px-2 py-1 text-sm text-neutral-100">Featured</span>
        @endif
    </div>
    <div class="flex items-start justify-between gap-3">
        {{-- A title can be one long word, which would drag the grid sideways. --}}
        <h2 class="min-w-0 break-words text-base font-medium leading-snug">
            <a href="/item/{{ $item['id'] }}" class="hover:underline active:underline">{{ $cardTitle['title'] }}@if ($cardTitle['tail'] !== null)<span class="text-neutral-400"> #{{ $cardTitle['tail'] }}</span>@endif</a>
        </h2>
        <a href="{{ GalleryQuery::filterHref($filters, ['kind' => [$item['kind']]]) }}" class="flex shrink-0 items-center gap-1.5 rounded border border-neutral-800 px-2 py-1 text-sm text-neutral-300 transition-colors hover:border-neutral-600 active:border-neutral-500 hover:text-white active:text-white"><x-gallery.kind-icon :kind="$item['kind']" :mode="$item['mode']" class="w-3.5" />{{ Labels::item($item['kind'], $item['mode']) }}</a>
    </div>

    @if ($item['description'])
        <p class="line-clamp-3 text-sm text-neutral-400">{{ $item['description'] }}</p>
    @endif

    @if ($countLine !== '')
        <p class="text-sm text-neutral-400">{{ $countLine }}</p>
    @endif

    <dl class="flex flex-wrap gap-x-4 gap-y-1 text-sm text-neutral-400">
        @if ($item['game_name'])
            <div class="flex gap-1"><dt class="sr-only">Game</dt><dd>{{ $item['game_name'] }}</dd></div>
        @endif
        @if ($item['map_name'])
            <div class="flex gap-1"><dt class="sr-only">Map</dt><dd>{{ $item['map_name'] }}</dd></div>
        @endif
        <div class="flex gap-1"><dt class="sr-only">Published by</dt><dd>by <a href="{{ $authorHref }}" class="transition-colors hover:text-neutral-200 active:text-neutral-200">{{ $item['author_name'] }}</a></dd></div>
        {{-- Newest first says nothing on its own about this week or last year. --}}
        <div class="flex gap-1"><dt class="sr-only">Published</dt><dd><x-ui.date-time :value="$item['created_at']" /></dd></div>
    </dl>

    @if ($item['tags'] !== [])
        <ul class="flex flex-wrap gap-1.5">
            @foreach ($item['tags'] as $tag)
                <li><a href="{{ GalleryQuery::filterHref($filters, ['tag' => [$tag]]) }}" class="inline-block rounded bg-neutral-900 px-2 py-1 text-sm text-neutral-400 transition-colors hover:text-neutral-200 active:text-neutral-200">{{ $tag }}</a></li>
            @endforeach
        </ul>
    @endif

    {{-- At the bottom, so the one action every card has lines up across a row. --}}
    <div class="mt-auto pt-1">
        <x-ui.import-link :share-url="$origin.'/i/'.$item['id']" />
    </div>
</article>
