@extends('layouts.app')

@php
    use App\Domain\Gallery\Labels;
    // Shared by the two secondary buttons so a third cannot drift from them.
    $outlineButton = App\Support\Ui::button('ghost', 'lg', 'font-medium');
    $rows = [
        // A row of its own below Newest (#399), so the heading's promise of the newest six stays true.
        ['heading' => 'Newest', 'list' => $newest],
        ['heading' => 'Featured', 'list' => $featured],
    ];
@endphp

@push('head')
    @vite('resources/js/gallery/index.ts')
@endpush

@section('content')
<main id="main-content" tabindex="-1" class="relative mx-auto flex w-full max-w-5xl flex-1 flex-col gap-12 px-6 py-16">
    {{-- Fixed to the viewport, so it stays put as the page scrolls. The mask
         fades it from full at the bottom to nothing at the top. --}}
    <div aria-hidden="true" class="pointer-events-none fixed inset-0 z-0 overflow-hidden" style="mask-image:linear-gradient(to top, black, transparent);-webkit-mask-image:linear-gradient(to top, black, transparent)">
        <x-ui.coil-art drawing="hub" :strength="0.11" class="h-full w-full" />
    </div>

    <div class="relative z-10 flex flex-col items-center gap-6 text-center">
        <x-coil-logo class="w-20" />
        <div class="flex flex-col gap-3">
            <h1 class="text-4xl font-semibold tracking-tight sm:text-5xl">Coilbox Hub</h1>
            {{-- Built from the kinds carried, so it cannot fall behind them (coilbox#1502). --}}
            <p class="mx-auto max-w-md text-balance text-neutral-400">{{ Labels::kindsPlural() }} made by other players. Importing needs no account.</p>
        </div>
        <div class="flex flex-wrap justify-center gap-3">
            <a href="/gallery" class="rounded-md bg-neutral-100 px-5 py-2.5 text-sm font-medium text-neutral-900 transition-colors hover:bg-white active:bg-neutral-300">Browse the gallery</a>
            <a href="/publish" class="{{ $outlineButton }}">Publish something</a>
            <a href="{{ config('hub.coilbox_url') }}" target="_blank" rel="noreferrer" class="{{ $outlineButton }}">Get Coilbox</a>
        </div>
    </div>

    @foreach ($rows as $row)
        {{-- Absent rather than a heading over nothing. --}}
        @if ($row['list']['items'] !== [])
            <section class="relative z-10 flex flex-col gap-4">
                <div class="flex items-baseline justify-between">
                    <h2 class="text-sm uppercase tracking-wide text-neutral-400">{{ $row['heading'] }}</h2>
                    {{-- The gallery already lists featured items first under any sort. --}}
                    <a href="/gallery" class="text-sm text-neutral-400 transition-colors hover:text-white active:text-white">See all</a>
                </div>
                <ul class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach ($row['list']['items'] as $item)
                        <li>
                            <x-gallery.item-card :item="$item" :filters="$filters" :origin="$origin"
                                :picture="$item['map_name'] ? ($row['list']['pictures'][$item['map_name']] ?? null) : null"
                                :shape="$row['list']['shapes'][$item['id']] ?? null"
                                :counts="$row['list']['counts'][$item['id']] ?? null"
                                :title="$row['list']['titles'][$item['id']] ?? null" />
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif
    @endforeach
</main>
@endsection
