@extends('layouts.app')

@use('App\Support\Ui')
@use('App\Domain\Mirrors\Removal')

{{-- A map's edit page. Its hub mirror uploads, read only, then the download sources. The list is
     reordered in the browser and travels as one hidden JSON value, the way
     the game edit page sends its downloads. --}}
@php
    $base = '/map/'.$slug.'/edit';
    $heading = 'text-sm uppercase tracking-wide text-neutral-400';
    $states = ['uploading' => 'Uploading', 'listed' => 'Listed', 'removed' => 'Removed'];
    // A listed upload a linked mirror holds, which the map page offers as a download.
    $mirrored = collect($uploads ?? [])->contains(fn ($u) => $u['state'] === 'listed' && collect($u['mirrors'])->contains('linked', true));
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
            <h2 class="{{ $heading }}">Hub mirrors</h2>
            @if ($uploads === null)
                <p role="alert" class="text-sm text-red-300">The hub mirror uploads could not be read. Reload the page to try again.</p>
            @elseif ($uploads === [])
                <p class="text-sm text-neutral-400">Nobody has uploaded this map to the hub mirrors. Upload it from its page in Coilbox.</p>
            @else
                <p class="text-sm text-neutral-400">Uploads of this map's archive. The map page and Coilbox use a listed upload after the sources below.</p>
                <ul class="flex flex-col gap-3">
                    @foreach ($uploads as $upload)
                        <li class="flex flex-col gap-1 rounded-md border border-neutral-800 bg-card p-4">
                            <div class="flex flex-wrap items-baseline justify-between gap-3">
                                <span class="break-all font-medium">{{ $upload['filename'] }}</span>
                                <span class="text-sm text-neutral-400">{{ $states[$upload['state']] ?? $upload['state'] }}</span>
                            </div>
                            @if ($upload['state'] === 'removed')
                                <p class="text-sm text-neutral-400">{{ Removal::LABELS[$upload['removal_reason']] ?? 'Removed' }}@if ($upload['removed_at']), <x-ui.date-time :value="$upload['removed_at']" />@endif.</p>
                            @elseif ($upload['state'] === 'uploading')
                                <p class="text-sm text-neutral-400">Started <x-ui.date-time :value="$upload['created_at']" />. It is listed once a mirror reports holding it.</p>
                            @else
                                <p class="text-sm text-neutral-400">Listed @if ($upload['listed_at'])<x-ui.date-time :value="$upload['listed_at']" />@endif.</p>
                                @if ($upload['mirrors'] === [])
                                    <p class="text-sm text-neutral-400">No mirror reports holding it right now, so nothing links it.</p>
                                @else
                                    <ul class="flex flex-col gap-1 text-sm">
                                        @foreach ($upload['mirrors'] as $mirror)
                                            <li class="{{ $mirror['linked'] ? 'text-neutral-300' : 'text-neutral-400' }}">{{ $mirror['name'] }}: {{ $mirror['linked'] ? 'holds it, linked' : 'holds it, not linked until the mirror passes its health check' }}</li>
                                        @endforeach
                                    </ul>
                                @endif
                                @if ($upload['mine'])
                                    <a class="self-start text-sm text-neutral-300 underline-offset-4 hover:underline active:underline" href="/account/uploads">Withdraw it from your uploads</a>
                                @endif
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>

        <section class="flex flex-col gap-3">
            <h2 class="{{ $heading }}">Downloads</h2>
            <div class="flex flex-col gap-3" data-list-editor="map-downloads" @if ($mirrored) data-empty="No other sources. The hub mirror copy above is the download." @endif data-control-class="{{ Ui::FIELD }}" data-row-button-class="{{ Ui::button('quiet', 'xs') }}">
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
