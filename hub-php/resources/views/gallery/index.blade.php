@extends('layouts.app')

@php
    use App\Domain\Container\Container;
    use App\Domain\Gallery\GalleryQuery;
    use App\Domain\Gallery\Labels;
    $chip = fn (bool $active) => $active
        ? 'rounded-full bg-neutral-100 px-3 py-1 text-sm font-medium text-neutral-900'
        : 'rounded-full border border-neutral-800 px-3 py-1 text-sm text-neutral-400 transition-colors hover:border-neutral-600 active:border-neutral-500 hover:text-neutral-200 active:text-neutral-200';
    $rowLabel = 'w-12 shrink-0 text-sm uppercase tracking-wide text-neutral-400';
    $items = $page['items'];
@endphp

@push('head')
    @vite('resources/js/gallery/index.ts')
@endpush

@section('content')
<main id="main-content" tabindex="-1" class="relative flex-1">
    {{-- Fainter than the landing page's, behind a grid of real content. --}}
    <x-ui.art-backdrop drawing="hub" :strength="0.07" />
    <div class="relative z-10 mx-auto flex w-full max-w-5xl flex-col gap-8 px-6 py-12">
        <div class="flex flex-col gap-2">
            <h1 class="text-3xl font-semibold tracking-tight">{{ count($accounts) === 1 ? 'Gallery by '.$accounts[0]['name'] : 'Gallery' }}</h1>
            <p class="text-neutral-400">Made by other players. Importing needs no account.</p>
        </div>

        <form class="group flex gap-2" action="/gallery" data-busy-form>
            @foreach ($filters->kind as $kind)<input type="hidden" name="kind" value="{{ $kind }}">@endforeach
            @if ($filters->game !== null)<input type="hidden" name="game" value="{{ $filters->game }}">@endif
            @if ($filters->map !== null)<input type="hidden" name="map" value="{{ $filters->map }}">@endif
            @foreach ($filters->tag as $tag)<input type="hidden" name="tag" value="{{ $tag }}">@endforeach
            @foreach ($filters->author as $author)<input type="hidden" name="author" value="{{ $author }}">@endforeach
            @foreach ($filters->account as $account)<input type="hidden" name="account" value="{{ $account }}">@endforeach
            <input id="gallery-q" type="search" name="q" aria-keyshortcuts="/" aria-label="Search titles and descriptions" value="{{ $filters->q ?? '' }}" placeholder="Search titles and descriptions" class="w-full rounded-md border border-neutral-800 bg-card px-3 py-2 text-sm text-neutral-100 placeholder:text-neutral-400 focus-visible:border-neutral-600 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-400">
            <button type="submit" class="{{ App\Support\Ui::button('ghost', 'base', 'shrink-0') }}">Search</button>
        </form>

        <nav class="flex flex-col gap-3 border-b border-neutral-900 pb-6">
            <div class="flex flex-wrap items-baseline gap-2">
                <span class="{{ $rowLabel }}">Kind</span>
                @foreach (Container::GALLERY_KINDS as $kind)
                    @php $active = in_array($kind, $filters->kind, true); @endphp
                    <a href="{{ GalleryQuery::filterHref($filters, ['kind' => $active ? [] : [$kind]]) }}" class="{{ $chip($active) }}">{{ Labels::kindPlural($kind) }}</a>
                @endforeach
            </div>

            @if (count($facets['games']) > 1 || $filters->game !== null)
                <div class="flex flex-wrap items-baseline gap-2">
                    <span class="{{ $rowLabel }}">Game</span>
                    @foreach ($facets['games'] as $game)
                        <a href="{{ GalleryQuery::filterHref($filters, ['game' => $filters->game === $game ? null : $game]) }}" class="{{ $chip($filters->game === $game) }}">{{ $game }}</a>
                    @endforeach
                </div>
            @endif

            @if (count($facets['maps']) > 1 || $filters->map !== null)
                <div class="flex flex-wrap items-baseline gap-2">
                    <span class="{{ $rowLabel }}">Map</span>
                    @foreach ($facets['maps'] as $map)
                        <a href="{{ GalleryQuery::filterHref($filters, ['map' => $filters->map === $map ? null : $map]) }}" class="{{ $chip($filters->map === $map) }}">{{ $map }}</a>
                    @endforeach
                </div>
            @endif

            @if ($filters->tag !== [])
                <div class="flex flex-wrap items-baseline gap-2">
                    <span class="{{ $rowLabel }}">Tag</span>
                    @foreach ($filters->tag as $tag)
                        <a href="{{ GalleryQuery::filterHref($filters, ['tag' => array_values(array_diff($filters->tag, [$tag]))]) }}" class="{{ $chip(true) }}">{{ $tag }}</a>
                    @endforeach
                </div>
            @endif

            @if ($filters->author !== [] || $accounts !== [])
                <div class="flex flex-wrap items-baseline gap-2">
                    <span class="{{ $rowLabel }}">By</span>
                    @foreach ($accounts as $account)
                        <a href="{{ GalleryQuery::filterHref($filters, ['account' => array_values(array_diff($filters->account, [$account['id']]))]) }}" class="{{ $chip(true) }}">{{ $account['name'] }}</a>
                    @endforeach
                    @foreach ($filters->author as $author)
                        <a href="{{ GalleryQuery::filterHref($filters, ['author' => array_values(array_diff($filters->author, [$author]))]) }}" class="{{ $chip(true) }}">{{ $author }}</a>
                    @endforeach
                </div>
            @endif
        </nav>

        @if ($page['error'] !== null)
            <p class="text-sm text-red-400">The gallery could not be read just now. Try again in a moment.</p>
        @elseif ($items === [])
            @php $filtered = ! $filters->isUnfiltered(); @endphp
            <div class="rounded-md border border-neutral-800 bg-card p-8 text-center">
                <p class="text-sm text-neutral-400">{{ $filtered ? 'Nothing matches that yet.' : 'Nothing has been published yet.' }}</p>
                <a href="{{ $filtered ? '/gallery' : '/publish' }}" class="mt-3 inline-block text-sm text-neutral-300 underline-offset-4 hover:underline active:underline">{{ $filtered ? 'Clear the filters' : 'Publish the first thing' }}</a>
            </div>
        @else
            <ul class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($items as $item)
                    <li>
                        <x-gallery.item-card :item="$item" :filters="$filters" :origin="$origin"
                            :picture="$item['map_name'] ? ($page['pictures'][$item['map_name']] ?? null) : null"
                            :shape="$page['shapes'][$item['id']] ?? null"
                            :counts="$page['counts'][$item['id']] ?? null"
                            :title="$page['titles'][$item['id']] ?? null" />
                    </li>
                @endforeach
            </ul>

            @if ($lastPage > 1)
                <div class="flex items-center justify-between text-sm text-neutral-400">
                    @if ($filters->page > 1)
                        <a href="{{ GalleryQuery::filterHref($filters, ['page' => $filters->page - 1]) }}" class="hover:text-neutral-200 active:text-neutral-200"><span class="transition-opacity">Newer</span></a>
                    @else
                        <span></span>
                    @endif
                    <span>Page {{ $filters->page }} of {{ $lastPage }}</span>
                    @if ($filters->page < $lastPage)
                        <a href="{{ GalleryQuery::filterHref($filters, ['page' => $filters->page + 1]) }}" class="hover:text-neutral-200 active:text-neutral-200"><span class="transition-opacity">Older</span></a>
                    @else
                        <span></span>
                    @endif
                </div>
            @endif
        @endif
    </div>
</main>
@endsection

@push('scripts')
    <script>
        // "/" jumps to the search box, unless the reader is typing somewhere.
        document.addEventListener('keydown', (event) => {
            if (event.key !== '/' || event.defaultPrevented || event.isComposing) return;
            if (event.ctrlKey || event.metaKey || event.altKey) return;
            const target = event.target instanceof HTMLElement ? event.target : null;
            if (target && (target.isContentEditable || ['INPUT', 'TEXTAREA', 'SELECT'].includes(target.tagName))) return;
            const input = document.getElementById('gallery-q');
            if (!(input instanceof HTMLInputElement)) return;
            event.preventDefault();
            input.focus();
            input.select();
        });
        // Marks the form busy while the next page loads, and clears it when the
        // browser brings this page back from its history cache.
        for (const form of document.querySelectorAll('[data-busy-form]')) {
            form.addEventListener('submit', () => form.setAttribute('aria-busy', 'true'));
        }
        window.addEventListener('pageshow', (event) => {
            if (event.persisted) document.querySelectorAll('[data-busy-form]').forEach((form) => form.removeAttribute('aria-busy'));
        });
    </script>
@endpush
