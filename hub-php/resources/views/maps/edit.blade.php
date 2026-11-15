@extends('layouts.app')

@use('App\Support\Ui')

{{-- A map's edit page. Only the download sources for now. The list is
     reordered in the browser and travels as one hidden JSON value, the way
     the game edit page sends its downloads. --}}
@php
    $base = '/map/'.$slug.'/edit';
    $heading = 'text-sm uppercase tracking-wide text-neutral-400';
    // A refused save keeps the list as it was sent.
    $payload = old('downloads', json_encode(array_map(fn ($d) => [
        'kind' => $d['kind'], 'value' => $d['value'], 'asset' => $d['asset'] ?? '', 'filename' => $d['filename'] ?? '',
    ], $downloads)));
@endphp

@section('content')
<main id="main-content" tabindex="-1" class="mx-auto w-full max-w-3xl flex-1 px-6 py-10">
    <x-games.breadcrumb :crumbs="[['label' => 'Maps', 'href' => '/maps'], ['label' => $mapTitle, 'href' => '/map/'.$slug], ['label' => 'Edit']]" />

    <div class="flex flex-col gap-10 pt-8">
        <h1 class="text-2xl font-semibold tracking-tight">Edit {{ $mapTitle }}</h1>

        <section class="flex flex-col gap-3">
            <h2 class="{{ $heading }}">Downloads</h2>
            <div class="flex flex-col gap-3" data-list-editor="map-downloads" data-control-class="{{ Ui::FIELD }}" data-row-button-class="{{ Ui::button('quiet', 'xs') }}">
                <p class="text-sm text-neutral-400">Every place this map can be downloaded from, best first. The map page lists them in this order, and Coilbox will try them in this order. An address should point at the archive itself, with the filename to save it as.</p>
                <div class="flex flex-col gap-2" data-rows></div>
                <noscript><p class="text-sm text-neutral-400">Changing this list needs JavaScript.</p></noscript>
                <button type="button" data-add class="{{ Ui::button('ghost', 'sm', 'self-start') }}">Add source</button>
                <form method="post" action="{{ $base }}/downloads" class="flex items-center gap-3">
                    @csrf
                    <input type="hidden" name="_form" value="downloads">
                    <input type="hidden" name="downloads" value="{{ $payload }}" data-payload>
                    <button type="submit" class="{{ Ui::button('ghost', 'base', 'self-start') }}">Save downloads</button>
                    <x-games.form-answer form="downloads" />
                </form>
            </div>
        </section>
    </div>
</main>
@vite('resources/js/games/edit.ts')
@endsection
