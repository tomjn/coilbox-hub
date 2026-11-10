@extends('layouts.app', ['title' => 'Map pack - Moderation'])
@use('App\Domain\Moderation\MapPacks')
@use('App\Support\Ui')
@php
    $input = 'rounded-md border border-neutral-800 bg-black px-3 py-2 text-sm text-neutral-200 placeholder:text-neutral-400 focus-visible:border-neutral-500 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-400';
    $featured = $pack['featuredAt'] !== null;
    $count = count($pack['maps']);
@endphp

@section('content')
<main id="main-content" tabindex="-1" class="relative flex-1">
    <x-ui.art-backdrop drawing="archives" :strength="0.08" />
    <x-moderation.nav current="mapPacks" />
    <div class="relative z-10 mx-auto flex w-full max-w-3xl flex-col gap-6 px-6 py-12">
        <div class="flex flex-col gap-1">
            <x-moderation.crumb parent="mapPacks">{{ $pack['title'] }}</x-moderation.crumb>
            <h1 class="text-3xl font-semibold tracking-tight">{{ $pack['title'] }}</h1>
        </div>

        <div class="flex items-center justify-between gap-3 rounded-md border border-neutral-800 bg-card px-5 py-3 text-sm">
            <span class="text-neutral-400">{{ $featured ? 'Featured, so Coilbox shows it in the Map packs menu.' : 'Not featured, so Coilbox does not show it.' }}</span>
            <x-moderation.toggle-form action="/moderation/map-packs/feature" :fields="['id' => $pack['id'], 'featured' => $featured ? 'false' : 'true']" :label="$featured ? 'Unfeature' : 'Feature'" form-class="flex flex-wrap items-center justify-end gap-2" :button-class="Ui::button('ghost', 'md')" />
        </div>

        <x-moderation.toggle-form action="/moderation/map-packs/save" :fields="['id' => $pack['id']]" label="Save the title and blurb" form-class="flex flex-col items-start gap-3" :button-class="Ui::button()">
            <label for="pack-title" class="text-sm text-neutral-400">Title</label>
            <input id="pack-title" name="title" required maxlength="{{ MapPacks::TITLE_MAX }}" value="{{ old('title', $pack['title']) }}" class="{{ $input }} w-full">
            <label for="pack-blurb" class="text-sm text-neutral-400">Blurb, shown under the title in Coilbox. Optional.</label>
            <textarea id="pack-blurb" name="blurb" rows="2" maxlength="{{ MapPacks::BLURB_MAX }}" class="{{ $input }} w-full">{{ old('blurb', $pack['blurb'] ?? '') }}</textarea>
        </x-moderation.toggle-form>

        {{-- A mirror's file list pastes straight in, which is how a pack of every map on a mirror is built. --}}
        <x-moderation.toggle-form action="/moderation/map-packs/add" :fields="['id' => $pack['id']]" label="Add these maps" form-class="flex flex-col items-start gap-3" :button-class="Ui::button()">
            <label for="pack-names" class="text-sm text-neutral-400">Map names or archive filenames, one per line. A mirror's file list pastes straight in. Case does not matter, and maps already in the pack stay where they are.</label>
            <textarea id="pack-names" name="names" rows="8" placeholder="Comet Catcher Remake 1.8&#10;isis_1.3.sd7" class="{{ $input }} w-full font-mono">{{ old('names') }}</textarea>
        </x-moderation.toggle-form>

        <section class="flex flex-col gap-3 border-t border-neutral-900 pt-6" aria-labelledby="pack-maps">
            <h2 id="pack-maps" class="text-sm uppercase tracking-wide text-neutral-400">{{ $count === 1 ? '1 map' : $count.' maps' }}</h2>
            @if ($count === 0)
                <p class="text-sm text-neutral-400">Nothing in this pack yet, so Coilbox will not show it even when it is featured.</p>
            @else
                <ul class="flex flex-col gap-1.5">
                    @foreach ($pack['maps'] as $map)
                        <li class="flex items-center justify-between gap-3 text-sm">
                            @if ($map['slug'])
                                <a href="/moderation/maps/{{ $map['slug'] }}" class="min-w-0 break-words text-neutral-300 underline-offset-4 hover:underline active:underline">{{ $map['mapName'] }}</a>
                            @else
                                {{-- Still offered to Coilbox under its name, which is what it downloads by. --}}
                                <span class="min-w-0 break-words text-neutral-400">{{ $map['mapName'] }} (not in the catalog right now)</span>
                            @endif
                            @if ($map['takenDown'])
                                <span class="shrink-0 text-xs text-neutral-400">Taken down, so Coilbox is not offered it</span>
                            @endif
                            <x-moderation.toggle-form action="/moderation/map-packs/remove" :fields="['id' => $pack['id'], 'map' => $map['mapName']]" label="Remove" form-class="flex shrink-0 items-center gap-2" :button-class="Ui::button('quiet', 'sm')" />
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>

        <div class="flex justify-end border-t border-neutral-900 pt-6">
            {{-- Red, because nothing brings a deleted pack back. --}}
            <x-moderation.toggle-form action="/moderation/map-packs/delete" :fields="['id' => $pack['id']]" label="Delete this pack" form-class="flex flex-col items-end gap-1.5" :button-class="Ui::button('destructive', 'sm')" />
        </div>
    </div>
</main>
@endsection
