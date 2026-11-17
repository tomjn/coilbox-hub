@extends('layouts.app', [
    'title' => 'Maps',
    'description' => 'Browse the maps the hub holds facts about, by size, tag, author or name.',
])
@use('App\Domain\Gallery\GalleryQuery')
@use('App\Domain\Maps\Listing')
@php
    $step = 'flex min-h-9 min-w-9 items-center justify-center rounded-md border border-neutral-800 px-2 transition-colors hover:border-neutral-600 active:border-neutral-500 hover:text-neutral-200 active:text-neutral-200 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-400';
    $filtered = Listing::isFiltered($filters);
    $empty = 'mt-3 inline-block text-sm text-neutral-300 underline-offset-4 hover:underline active:underline';
@endphp

@section('content')
<main id="main-content" tabindex="-1" class="relative flex-1">
    {{-- Faint, because a page of minimaps is a page of terrain. --}}
    <x-ui.art-backdrop drawing="skirmish" :strength="0.05" />
    {{-- Edge to edge, so a wide screen gets more maps to a row. The heading keeps the readable width. --}}
    <div class="relative z-10 flex w-full flex-col gap-8 px-6 py-12">
        <div class="flex max-w-5xl flex-col gap-2">
            <h1 class="text-3xl font-semibold tracking-tight">Maps</h1>
            <p class="text-neutral-400">What the hub knows about the maps people play on, from the archives themselves. <a class="text-neutral-300 underline-offset-4 hover:underline active:underline" href="/add">Want to add your own map?</a></p>
        </div>

        @foreach ([['featured', 'Featured', 'View all featured', '/maps?featured=1'], ['uploaded', 'Newest uploads', 'View all new uploads', '/maps?sort=uploaded']] as [$key, $heading, $linkText, $href])
            <x-maps.section :title="$heading" :more-href="$href" :more-label="$linkText" :maps="$sections[$key]['maps'] ?? []" :pictures="$sections[$key]['pictures'] ?? []" :filters="$filters" />
        @endforeach

        <x-maps.filter-bar :filters="$filters" />

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
            <ul class="grid gap-4 grid-cols-[repeat(auto-fill,minmax(min(200px,calc(50%_-_8px)),1fr))]">
                @foreach ($maps as $index => $map)
                    @if (isset($pictures[$map['map_name']]))
                        {{-- The first twelve fill the first screen on most displays, so those load with the page. --}}
                        <li><x-maps.card :map="$map" :picture="$pictures[$map['map_name']]" :filters="$filters" :eager="$index < 12" /></li>
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
