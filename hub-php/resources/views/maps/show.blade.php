@extends('layouts.app')
@use('App\Domain\Maps\Js')
@use('App\Domain\Maps\Labels')
@use('App\Domain\Maps\MapPage')
@php
    // The column the words are set in. Applied per block, because the terrain spans the window.
    $column = 'mx-auto w-full max-w-3xl px-6';
    $players = Labels::players(count($facts['points']['start'] ?? []));
    $wind = Labels::wind($facts['min_wind'] ?? null, $facts['max_wind'] ?? null);
    $term = 'text-sm uppercase tracking-wide text-neutral-400';
    $value = 'text-sm text-neutral-100';
@endphp

@section('content')
<main id="main-content" tabindex="-1" class="relative flex-1">
    {{-- Faint: the minimap is the page, and a backdrop competing with terrain makes it harder to read. --}}
    <x-ui.art-backdrop drawing="skirmish" :strength="0.05" />
    <div class="relative z-10 flex w-full flex-col gap-8 py-12">
        <div class="{{ $column }} flex flex-col gap-3">
            <h1 class="break-words text-3xl font-semibold tracking-tight">{{ $title }}</h1>
            {{-- The canonical name, which is what a lobby shows, whenever the archive gave a friendlier one. --}}
            @if ($title !== $mapName)
                <p class="break-words text-sm text-neutral-400">{{ $mapName }}</p>
            @endif
            @if ($facts['description'] ?? null)
                <p class="whitespace-pre-wrap text-neutral-400">{{ $facts['description'] }}</p>
            @endif
        </div>

        {{-- Where the hub holds a height overlay the terrain takes the figure's place once drawn. Most maps stay flat. --}}
        @if ($preview)
            <x-maps.preview :preview="$preview" :name="$mapName" :column="$column">
                <x-maps.figure :name="$mapName" :map="$facts" :points="$facts['points']" :picture="$picture" />
            </x-maps.preview>
        @else
            <div class="{{ $column }}"><x-maps.figure :name="$mapName" :map="$facts" :points="$facts['points']" :picture="$picture" /></div>
        @endif

        <div class="{{ $column }} flex flex-col gap-8">
            <dl class="grid grid-cols-2 gap-6 border-t border-neutral-900 pt-6 sm:grid-cols-4">
                <div class="flex flex-col gap-1"><dt class="{{ $term }}">Size</dt><dd class="{{ $value }}">{{ Labels::size($facts['width_elmos'], $facts['height_elmos']) }}</dd></div>
                @if ($players)
                    <div class="flex flex-col gap-1"><dt class="{{ $term }}">Players</dt><dd class="{{ $value }}">{{ $players }}</dd></div>
                @endif
                @if ($wind)
                    <div class="flex flex-col gap-1"><dt class="{{ $term }}">Wind</dt><dd class="{{ $value }}">{{ $wind }}</dd></div>
                @endif
                @if (($facts['tidal_strength'] ?? null) !== null)
                    <div class="flex flex-col gap-1"><dt class="{{ $term }}">Tidal</dt><dd class="{{ $value }}">{{ Js::number($facts['tidal_strength']) }}</dd></div>
                @endif
                {{-- Only when the archive says so outright. Saying "some" otherwise would be the hub making it up. --}}
                @if ($facts['void_water'] ?? false)
                    <div class="flex flex-col gap-1"><dt class="{{ $term }}">Water</dt><dd class="{{ $value }}">None</dd></div>
                @endif
                @if (($facts['authors'] ?? []) !== [])
                    <div class="flex flex-col gap-1"><dt class="{{ $term }}">Made by</dt><dd class="{{ $value }}">
                        <ul class="flex flex-col gap-0.5">
                            @foreach ($facts['authors'] as $author)
                                <li><a class="hover:text-white active:text-white" href="/maps?author={{ MapPage::encodeURIComponent($author['key']) }}">{{ $author['name'] }}</a></li>
                            @endforeach
                        </ul>
                    </dd></div>
                @endif
            </dl>

            @if (($facts['tags'] ?? []) !== [])
                <ul class="flex flex-wrap gap-1.5">
                    @foreach ($facts['tags'] as $tag)
                        <li><a class="inline-block rounded bg-neutral-900 px-2 py-1 text-sm text-neutral-400 transition-colors hover:text-neutral-200 active:text-neutral-200" href="/maps?tag={{ MapPage::encodeURIComponent($tag) }}">{{ $tag }}</a></li>
                    @endforeach
                </ul>
            @endif

            <x-maps.mirrors :links="$mirrors" />

            <x-maps.played-on :map-name="$mapName" :items="$played" :picture="$picture" :origin="$origin" />

            <x-maps.report-button :map-id="$mapId" :slug="$facts['slug'] ?? request()->route('slug')" :state="session('mapReport')" />
        </div>
    </div>
</main>
@endsection

@push('scripts')
    @vite('resources/js/maps/page.js')
@endpush
