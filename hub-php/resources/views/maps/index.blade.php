@extends('layouts.app', [
    'title' => 'Maps',
    'description' => 'Browse the maps the hub holds facts about, by size, tag, author or name.',
])
@use('App\Domain\Gallery\GalleryQuery')
@use('App\Domain\Maps\Listing')
@use('App\Support\Ui')
@php
    $control = Ui::FIELD;
    $step = 'flex min-h-9 min-w-9 items-center justify-center rounded-md border border-neutral-800 px-2 transition-colors hover:border-neutral-600 active:border-neutral-500 hover:text-neutral-200 active:text-neutral-200 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-400';
    $label = 'text-xs uppercase tracking-wide text-neutral-400';
    $chevron = '<svg aria-hidden="true" viewBox="0 0 16 16" class="pointer-events-none absolute right-3 top-1/2 size-4 -translate-y-1/2 text-neutral-400"><path d="M4 6l4 4 4-4" fill="none" stroke="currentColor" stroke-width="1.5"></path></svg>';
    $sizeLabels = ['small' => 'Small, up to 12 squares', 'medium' => 'Medium, up to 20 squares', 'large' => 'Large, over 20 squares'];
    $sortLabels = ['name' => 'Name', 'size' => 'Longer edge, largest first', 'players' => 'Players, most first', 'added' => 'Recently added'];
    $filtered = Listing::isFiltered($filters);
    $empty = 'mt-3 inline-block text-sm text-neutral-300 underline-offset-4 hover:underline active:underline';
@endphp

@section('content')
<main id="main-content" tabindex="-1" class="relative flex-1">
    {{-- Faint, because a page of minimaps is a page of terrain. --}}
    <x-ui.art-backdrop drawing="skirmish" :strength="0.05" />
    {{-- Edge to edge, so a wide screen gets more maps to a row. The heading and filters keep the readable width. --}}
    <div class="relative z-10 flex w-full flex-col gap-8 px-6 py-12">
        <div class="flex max-w-5xl flex-col gap-8">
            <div class="flex flex-col gap-2">
                <h1 class="text-3xl font-semibold tracking-tight">Maps</h1>
                <p class="text-neutral-400">What the hub knows about the maps people play on, from the archives themselves.</p>
            </div>

            {{-- A GET to this same route, so it works with scripting off. The page is not in the form: a filter change starts at page one. --}}
            <form class="group grid gap-4 border-b border-neutral-900 pb-6 sm:grid-cols-2 lg:grid-cols-3" action="/maps" data-busy-form>
                <div class="flex flex-col gap-1.5">
                    <label for="maps-q" class="{{ $label }}">Search</label>
                    <input id="maps-q" type="search" aria-keyshortcuts="/" class="{{ $control }}" name="q" value="{{ $filters['q'] ?? '' }}" data-search-shortcut>
                </div>
                <div class="flex flex-col gap-1.5">
                    <label for="maps-author" class="{{ $label }}">Made by</label>
                    <input id="maps-author" type="text" class="{{ $control }}" name="author" value="{{ $filters['author'] ?? '' }}">
                </div>
                {{-- A text box rather than a list: the chips on the cards are how a reader finds a tag. --}}
                <div class="flex flex-col gap-1.5">
                    <label for="maps-tag" class="{{ $label }}">Tag</label>
                    <input id="maps-tag" type="text" class="{{ $control }}" name="tag" value="{{ $filters['tag'] ?? '' }}">
                </div>
                <div class="flex flex-col gap-1.5">
                    <label for="maps-size" class="{{ $label }}">Size</label>
                    <div class="relative">
                        <select id="maps-size" name="size" class="{{ $control }} appearance-none pr-9">
                            <option value="" @selected($filters['size'] === null)>Any size</option>
                            @foreach (Listing::SIZES as $size)
                                <option value="{{ $size }}" @selected($filters['size'] === $size)>{{ $sizeLabels[$size] }}</option>
                            @endforeach
                        </select>{!! $chevron !!}
                    </div>
                </div>
                <div class="flex flex-col gap-1.5">
                    <label for="maps-players" class="{{ $label }}">Players, at least</label>
                    <input id="maps-players" type="number" min="1" class="{{ $control }}" name="players" value="{{ $filters['players'] ?? '' }}">
                </div>
                <div class="flex flex-col gap-1.5">
                    <label for="maps-sort" class="{{ $label }}">Sort by</label>
                    <div class="relative">
                        <select id="maps-sort" name="sort" class="{{ $control }} appearance-none pr-9">
                            @foreach (Listing::SORTS as $sort)
                                <option value="{{ $sort }}" @selected($filters['sort'] === $sort)>{{ $sortLabels[$sort] }}</option>
                            @endforeach
                        </select>{!! $chevron !!}
                    </div>
                </div>
                <div class="flex items-center gap-3 self-end">
                    <button type="submit" class="{{ Ui::button() }}">Filter</button>
                    @if ($filtered)
                        <a class="text-sm text-neutral-400 hover:text-neutral-200 active:text-neutral-200" href="/maps">Clear</a>
                    @endif
                </div>
            </form>
        </div>

        @if ($error)
            <p class="text-sm text-red-400">The catalog could not be read just now. Try again in a moment.</p>
        @elseif ($maps === [])
            {{-- Past the last page is told apart from no match: paging one step past the end is routine. --}}
            <div class="rounded-md border border-neutral-800 bg-card p-8 text-center">
                @if ($count > 0)
                    <p class="text-sm text-neutral-400">That page is past the last map.</p>
                    <a class="{{ $empty }}" href="{{ Listing::filterHref($filters, ['page' => 1]) }}">Back to the first page</a>
                @else
                    <p class="text-sm text-neutral-400">{{ $filtered ? 'No map matches that.' : 'The hub holds facts about no maps yet.' }}</p>
                    @if ($filtered)
                        <a class="{{ $empty }}" href="/maps">Clear the filters</a>
                    @endif
                @endif
            </div>
        @else
            <ul class="grid gap-4 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5">
                @foreach ($maps as $index => $map)
                    @if (isset($pictures[$map['map_name']]))
                        {{-- Two rows of five fill the first screen, so those load with the page. --}}
                        <li><x-maps.card :map="$map" :picture="$pictures[$map['map_name']]" :filters="$filters" :eager="$index < 10" /></li>
                    @endif
                @endforeach
            </ul>

            <nav aria-label="Pages" class="flex flex-col items-center gap-3 text-sm text-neutral-400">
                <ul class="flex flex-wrap items-center justify-center gap-1.5">
                    @if ($filters['page'] > 1)
                        <li><a class="{{ $step }}" href="{{ Listing::filterHref($filters, ['page' => $filters['page'] - 1]) }}"><span class="transition-opacity">Previous</span></a></li>
                    @endif
                    @foreach (GalleryQuery::pageNumbers($filters['page'], $lastPage) as $number)
                        @if ($number === GalleryQuery::PAGE_GAP)
                            {{-- Not a control, so not in the tab order and not read out. --}}
                            <li aria-hidden="true" class="px-1">&hellip;</li>
                        @elseif ($number === $filters['page'])
                            <li><span aria-current="page" class="{{ $step }} border-neutral-600 bg-neutral-900 text-neutral-100">{{ $number }}</span></li>
                        @else
                            <li><a class="{{ $step }}" href="{{ Listing::filterHref($filters, ['page' => $number]) }}"><span class="transition-opacity">{{ $number }}</span></a></li>
                        @endif
                    @endforeach
                    @if ($filters['page'] < $lastPage)
                        <li><a class="{{ $step }}" href="{{ Listing::filterHref($filters, ['page' => $filters['page'] + 1]) }}"><span class="transition-opacity">Next</span></a></li>
                    @endif
                </ul>
                <p>Page {{ $filters['page'] }} of {{ $lastPage }}, {{ $count === 1 ? '1 map' : $count.' maps' }}</p>
            </nav>
        @endif
    </div>
</main>
@endsection

@push('scripts')
    @vite('resources/js/maps/listing.js')
@endpush
