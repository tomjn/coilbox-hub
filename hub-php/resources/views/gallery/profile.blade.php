@extends('layouts.app')

{{-- One account's public profile: a sentence that sums up what they have added, then the newest of each kind. --}}
@use('App\Domain\Gallery\Labels')
@php
    ['name' => $name, 'maps' => $maps, 'pictures' => $pictures, 'games' => $games, 'items' => $items] = $contributions;
    $n = fn (int $count) => number_format($count);
    $jump = fn (string $href, string $text) => '<a href="'.$href.'" class="font-medium text-neutral-100 underline decoration-neutral-600 underline-offset-4 hover:decoration-neutral-300 active:decoration-neutral-300">'.e($text).'</a>';
    $and = function (array $parts): string {
        $last = array_pop($parts);

        return $parts === [] ? (string) $last : implode(', ', $parts).' and '.$last;
    };

    $facts = [];
    if ($maps['count'] > 0) {
        $facts[] = $jump('#maps', $n($maps['count']).' '.($maps['count'] === 1 ? 'map' : 'maps'));
    }
    if ($games['count'] > 0) {
        $facts[] = $jump('#games', $n($games['count']).' '.($games['count'] === 1 ? 'game' : 'games'));
    }
    $clauses = [];
    if ($facts !== []) {
        $clauses[] = 'sent the facts for '.implode(' and ', $facts);
    }
    if ($pictures['count'] > 0) {
        $clauses[] = 'had <span class="font-medium text-neutral-100">'.$n($pictures['count']).' '.($pictures['count'] === 1 ? 'picture' : 'pictures').'</span> approved';
    }
    if ($items['count'] > 0) {
        $clauses[] = 'published '.$jump('#gallery', $n($items['count']).' '.($items['count'] === 1 ? 'thing' : 'things')).' in the gallery';
    }
    $summary = $clauses === [] ? e($name).' has not added anything to the hub yet.' : e($name).' has '.$and($clauses).'.';

    $sectionHead = 'flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1';
    $count = 'ml-1.5 font-normal tabular-nums text-neutral-400';
    $aside = 'text-sm text-neutral-400';
    $more = 'inline-flex min-h-9 items-center text-sm text-neutral-300 underline underline-offset-4 hover:text-neutral-100 active:text-neutral-100 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-400';
    $shown = fn (array $kind) => $kind['count'] > count($kind['recent']) ? 'Newest '.count($kind['recent']).' of '.$n($kind['count']) : null;
@endphp

@section('content')
<main id="main-content" tabindex="-1" class="relative flex-1">
    <x-ui.art-backdrop drawing="games" :strength="0.09" />
    <div class="relative z-10 mx-auto flex w-full max-w-6xl flex-col gap-12 px-6 py-12">
        <header class="flex max-w-3xl flex-col gap-4">
            <h1 class="break-words text-4xl font-semibold tracking-tight text-balance sm:text-5xl">{{ $name }}</h1>
            <p class="text-lg leading-relaxed text-neutral-300">{!! $summary !!}</p>
            <p class="flex flex-wrap items-baseline gap-x-3 gap-y-1 text-sm text-neutral-400">
                <span><span class="font-semibold tabular-nums text-points">{{ $n($points) }}</span> community {{ $points === 1 ? 'point' : 'points' }}</span>
                @if ($own)
                    <a href="/account" class="text-neutral-300 underline underline-offset-4 hover:text-neutral-100 active:text-neutral-100">Change your name</a>
                @endif
            </p>
        </header>

        @if ($items['recent'] !== [])
            <section id="gallery" aria-labelledby="gallery-heading" class="flex scroll-mt-8 flex-col gap-3">
                <div class="{{ $sectionHead }}">
                    <h2 id="gallery-heading" class="text-xl font-semibold">Gallery<span class="{{ $count }}">{{ $n($items['count']) }}</span></h2>
                    <a href="/gallery?account={{ $accountId }}" class="{{ $more }}">See everything they have published</a>
                </div>
                <ul class="divide-y divide-neutral-800 rounded-md border border-neutral-800 bg-card">
                    @foreach ($items['recent'] as $item)
                        <li class="relative flex items-center gap-3 px-4 py-3 text-sm transition-colors hover:bg-neutral-900/60 has-[a:focus-visible]:outline-2 has-[a:focus-visible]:outline-neutral-300">
                            {{-- A fixed slot, because some kinds have no icon and their titles must still line up. --}}
                            <span class="flex w-4 shrink-0 justify-center text-neutral-400"><x-gallery.kind-icon :kind="$item['kind']" :mode="$item['mode']" class="w-4" /></span>
                            <a href="/item/{{ $item['id'] }}" class="min-w-0 flex-1 truncate text-neutral-100 after:absolute after:inset-0 focus-visible:outline-none">{{ $item['title'] }}</a>
                            <span class="hidden shrink-0 text-neutral-400 sm:inline">{{ Labels::item($item['kind'], $item['mode']) }}</span>
                            <span class="w-24 shrink-0 text-right text-neutral-400"><x-ui.date-time :value="$item['created_at']" /></span>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif

        @if ($games['recent'] !== [])
            <section id="games" aria-labelledby="games-heading" class="flex scroll-mt-8 flex-col gap-3">
                <div class="{{ $sectionHead }}">
                    <h2 id="games-heading" class="text-xl font-semibold">Games<span class="{{ $count }}">{{ $n($games['count']) }}</span></h2>
                    @if ($shown($games))<span class="{{ $aside }}">{{ $shown($games) }}</span>@endif
                </div>
                <ul class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach ($games['recent'] as $game)
                        <li class="relative flex items-center gap-4 rounded-md border border-neutral-800 bg-card p-3 transition-colors hover:border-neutral-600 has-[a:active]:border-neutral-500 has-[a:focus-visible]:outline-2 has-[a:focus-visible]:outline-offset-2 has-[a:focus-visible]:outline-neutral-300">
                            <x-games.logo :src="$game['logo']" alt="" />
                            <h3 class="min-w-0 break-words font-medium leading-snug">
                                <a href="/games/{{ $game['shortname'] }}" class="after:absolute after:inset-0 after:rounded-md focus-visible:outline-none">{{ $game['label'] }}</a>
                            </h3>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif

        @if ($maps['recent'] !== [])
            <section id="maps" aria-labelledby="maps-heading" class="flex scroll-mt-8 flex-col gap-3">
                <div class="{{ $sectionHead }}">
                    <h2 id="maps-heading" class="text-xl font-semibold">Maps<span class="{{ $count }}">{{ $n($maps['count']) }}</span></h2>
                    @if ($shown($maps))<span class="{{ $aside }}">{{ $shown($maps) }}</span>@endif
                </div>
                <p class="-mt-1 text-sm text-neutral-400">{{ $name }} sent the facts for these maps. The mapper is credited on each map's own page.</p>
                <ul class="grid gap-4 grid-cols-[repeat(auto-fill,minmax(min(200px,calc(50%_-_8px)),1fr))]">
                    @foreach ($maps['recent'] as $index => $map)
                        @if (isset($maps['pictures'][$map['map_name']]))
                            <li><x-maps.card :map="$map" :picture="$maps['pictures'][$map['map_name']]" :eager="$index < 4" :heading-level="3" /></li>
                        @endif
                    @endforeach
                </ul>
            </section>
        @endif
    </div>
</main>
@endsection
