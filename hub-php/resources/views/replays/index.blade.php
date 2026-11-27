@extends('layouts.app', [
    'title' => 'Replays',
    'description' => 'Match replays that players shared from Coilbox, by game, map, player, length and date.',
])
@use('App\Domain\Gallery\GalleryQuery')
@use('App\Domain\Replays\ReplayList')
@php
    $step = 'flex min-h-9 min-w-9 items-center justify-center rounded-md border border-neutral-800 px-2 transition-colors hover:border-neutral-600 active:border-neutral-500 hover:text-neutral-200 active:text-neutral-200 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-400';
    $empty = 'mt-3 inline-block py-1 text-sm text-neutral-300 underline-offset-4 hover:underline active:underline';
@endphp

@section('content')
<main id="main-content" tabindex="-1" class="relative flex-1">
    <x-ui.art-backdrop drawing="skirmish" :strength="0.05" />
    <div class="relative z-10 mx-auto flex w-full max-w-5xl flex-col gap-8 px-6 py-12">
        <div class="flex flex-col gap-2">
            <h1 class="text-3xl font-semibold tracking-tight">Replays</h1>
            <p class="text-neutral-400">Matches that players shared from Coilbox, newest first. Each one is checked against its replay file.</p>
            <p class="text-sm text-neutral-400">To analyse them yourself, <a class="text-neutral-200 underline underline-offset-4 transition-colors hover:text-white active:text-white" href="/replays/data">download the replay data</a>.</p>
        </div>

        <x-replays.filter-bar :filters="$filters" :options="$options" />

        @if ($replays === [])
            {{-- Past the last page is told apart from no match: paging one step past the end is routine. --}}
            <div class="rounded-md border border-neutral-800 bg-card p-8 text-center">
                @if ($count > 0)
                    <p class="text-sm text-neutral-400">That page is past the last replay.</p>
                    <a class="{{ $empty }}" href="{{ ReplayList::href($filters) }}">Back to the first page</a>
                @elseif (ReplayList::isFiltered($filters))
                    <p class="text-sm text-neutral-400">No replay matches that.</p>
                    <a class="{{ $empty }}" href="/replays">Clear the filters</a>
                @else
                    <p class="text-sm text-neutral-400">Nobody has shared a replay yet. Replays shared from Coilbox appear here.</p>
                @endif
            </div>
        @else
            <ul class="flex flex-col gap-4">
                @foreach ($replays as $replay)
                    <li><x-replays.row :replay="$replay" :map="$maps[$replay['map']] ?? null" /></li>
                @endforeach
            </ul>

            <nav aria-label="Pages" class="flex flex-col items-center gap-3 text-sm text-neutral-400">
                <ul class="flex flex-wrap items-center justify-center gap-1.5">
                    @if ($filters['page'] > 1)
                        <li><a class="{{ $step }}" href="{{ ReplayList::href($filters, ['page' => $filters['page'] - 1]) }}">Previous</a></li>
                    @endif
                    @foreach (GalleryQuery::pageNumbers($filters['page'], $lastPage) as $number)
                        @if ($number === GalleryQuery::PAGE_GAP)
                            {{-- Not a control, so not in the tab order and not read out. --}}
                            <li aria-hidden="true" class="px-1">&hellip;</li>
                        @elseif ($number === $filters['page'])
                            <li><span aria-current="page" class="{{ $step }} border-neutral-600 bg-neutral-900 text-neutral-100">{{ $number }}</span></li>
                        @else
                            <li><a class="{{ $step }}" href="{{ ReplayList::href($filters, ['page' => $number]) }}">{{ $number }}</a></li>
                        @endif
                    @endforeach
                    @if ($filters['page'] < $lastPage)
                        <li><a class="{{ $step }}" href="{{ ReplayList::href($filters, ['page' => $filters['page'] + 1]) }}">Next</a></li>
                    @endif
                </ul>
                <p>Page {{ $filters['page'] }} of {{ $lastPage }}, {{ $count === 1 ? '1 replay' : $count.' replays' }}</p>
            </nav>
        @endif
    </div>
</main>
@endsection

@push('scripts')
    @vite('resources/js/maps/listing.js')
@endpush
