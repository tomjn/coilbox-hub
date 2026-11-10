@extends('layouts.app')

@php
    use App\Domain\Gallery\Labels;
    // The hero's pill buttons, as on coilbox.org's homepage. 40 pixels tall.
    $pill = 'rounded-full px-5 py-2.5 text-sm font-semibold transition-colors';
    $altButton = $pill.' bg-button-alt text-nav hover:bg-button-alt-hover active:bg-button-alt-active';
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

    {{-- Laid out like coilbox.org's homepage hero: the mark above the words on
         a narrow screen, beside them from the lg breakpoint. --}}
    <div class="relative z-10 flex flex-col items-center gap-8 text-center lg:flex-row lg:justify-between lg:gap-12 lg:text-left">
        <x-coil-logo class="w-40 shrink-0 text-foreground [stroke-width:1.6] sm:w-48 lg:order-last lg:w-70" />
        <div class="flex max-w-xl flex-col items-center gap-2 sm:gap-3 lg:items-start">
            <h1 class="text-[32px]/10 font-bold tracking-[-0.4px] text-nav sm:text-5xl/14 lg:text-[56px]/16">Coilbox <span class="text-brand">Hub</span></h1>
            {{-- Built from the kinds carried, so it cannot fall behind them (coilbox#1502). --}}
            <p class="text-balance text-lg/7 font-medium text-muted sm:text-xl/8 lg:text-2xl/9">{{ Labels::kindsPlural() }} made by other players. Importing needs no account.</p>
            <div class="flex flex-wrap justify-center gap-3 pt-4 sm:pt-5 lg:justify-start">
                <a href="/gallery" class="{{ $pill }} bg-button text-white hover:bg-button-hover active:bg-brand">Browse the gallery</a>
                <a href="/publish" class="{{ $altButton }}">Publish something</a>
                <a href="{{ config('hub.coilbox_url') }}" target="_blank" rel="noreferrer" class="{{ $altButton }}">Get Coilbox</a>
            </div>
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
