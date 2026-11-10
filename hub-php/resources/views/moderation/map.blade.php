@extends('layouts.app', ['title' => 'Map tags - Moderation'])
@use('App\Domain\Moderation\MapModeration')
@use('App\Support\Ui')
@php
    $input = 'rounded-md border border-neutral-800 bg-black px-3 py-2 text-sm text-neutral-200 placeholder:text-neutral-400 focus-visible:border-neutral-500 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-400';
    $heading = $map['displayName'] ?? $map['mapName'];
    $featured = $map['featuredAt'] !== null;
@endphp

@section('content')
<main id="main-content" tabindex="-1" class="relative flex-1">
    <x-ui.art-backdrop drawing="archives" :strength="0.08" />
    <x-moderation.nav current="maps" />
    <div class="relative z-10 mx-auto flex w-full max-w-3xl flex-col gap-6 px-6 py-12">
        <div class="flex flex-col gap-1">
            <x-moderation.crumb parent="maps">{{ $heading }}</x-moderation.crumb>
            <div class="flex flex-wrap items-baseline justify-between gap-3">
                <h1 class="text-3xl font-semibold tracking-tight">{{ $heading }}</h1>
                {{-- The only link here that leaves moderation. --}}
                <a href="/map/{{ $map['slug'] }}" class="text-sm text-neutral-400 transition-colors hover:text-neutral-300 active:text-neutral-300">The map</a>
            </div>
        </div>

        <p class="text-sm text-neutral-400">{{ $map['mapName'] }}</p>

        <div class="flex items-center justify-between gap-3 rounded-md border border-neutral-800 bg-card px-5 py-3 text-sm">
            <span class="text-neutral-400">{{ $featured ? 'Featured, so the catalog lists this above the rest.' : 'Not featured.' }}</span>
            <x-moderation.toggle-form action="/moderation/maps/feature" :fields="['id' => $map['id'], 'slug' => $map['slug'], 'featured' => $featured ? 'false' : 'true']" :label="$featured ? 'Unfeature' : 'Feature'" form-class="flex flex-wrap items-center justify-end gap-2" :button-class="Ui::button('ghost', 'md')" />
        </div>

        {{-- The archive's own words live in description. These are kept apart,
             and ingest never writes this column, so nothing a client sends undoes it. --}}
        <form method="post" action="/moderation/maps/tags" class="flex flex-col gap-3">
            @csrf
            <input type="hidden" name="map" value="{{ $map['id'] }}">
            <input type="hidden" name="slug" value="{{ $map['slug'] }}">
            <label for="curated-tags" class="text-sm text-neutral-400">Tags for what no measurement captures, separated by commas. Up to {{ MapModeration::CURATED_TAG_LIMIT }} of them, and the whole box is the answer, so deleting a word here takes the tag off the map.</label>
            <input id="curated-tags" name="tags" value="{{ MapModeration::curatedTagsField($map['curatedTags']) }}" placeholder="asymmetric, 1v1, chokepoint" class="{{ $input }}">
            <div>
                <button type="submit" class="{{ Ui::button() }}">Save the tags</button>
            </div>
        </form>

        <p class="text-sm text-neutral-400">{{ $map['derivedTags'] === [] ? 'Nothing was measured that produces a tag of its own.' : 'Worked out from the measurements, and not editable: '.implode(', ', $map['derivedTags']).'.' }}</p>
    </div>
</main>
@endsection
