@extends('layouts.app', ['title' => 'Map packs - Moderation'])
@use('App\Domain\Moderation\MapPacks')
@use('App\Support\Ui')
@php
    $input = 'rounded-md border border-neutral-800 bg-black px-3 py-2 text-sm text-neutral-200 placeholder:text-neutral-400 focus-visible:border-neutral-500 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-400';
@endphp

@section('content')
<main id="main-content" tabindex="-1" class="relative flex-1">
    <x-ui.art-backdrop drawing="archives" :strength="0.08" />
    <x-moderation.nav current="mapPacks" />
    <div class="relative z-10 mx-auto flex w-full max-w-3xl flex-col gap-6 px-6 py-12">
        <h1 class="text-3xl font-semibold tracking-tight">Map packs</h1>

        <p class="text-sm text-neutral-400">A featured pack shows in the Map packs menu on Coilbox's Downloads page, where a player can download every map in it at once. A pack with no maps in it is never shown.</p>

        <x-moderation.toggle-form action="/moderation/map-packs" label="Start a pack" form-class="flex flex-wrap items-center gap-3" :button-class="Ui::button()">
            <label class="sr-only" for="new-pack-title">Title of the new pack</label>
            <input id="new-pack-title" name="title" required maxlength="{{ MapPacks::TITLE_MAX }}" value="{{ old('title') }}" placeholder="Title of the new pack" class="{{ $input }} flex-1">
        </x-moderation.toggle-form>

        @if ($error)
            <p role="alert" class="text-sm text-red-400">The packs could not be read just now.</p>
        @elseif ($packs === [])
            <p class="rounded-md border border-neutral-800 bg-card p-6 text-sm text-neutral-400">There are no map packs yet.</p>
        @else
            <ul class="flex flex-col gap-1.5">
                @foreach ($packs as $pack)
                    <li class="flex items-baseline justify-between gap-3 text-sm">
                        <a href="/moderation/map-packs/{{ $pack['id'] }}" class="min-w-0 break-words text-neutral-300 underline-offset-4 hover:underline active:underline">{{ $pack['title'] }}</a>
                        <span class="shrink-0 text-sm text-neutral-400">{{ count($pack['maps']) === 1 ? '1 map' : count($pack['maps']).' maps' }}{{ $pack['featuredAt'] ? ', featured' : ', not featured' }}</span>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
</main>
@endsection
