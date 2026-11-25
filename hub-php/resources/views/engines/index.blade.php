@extends('layouts.app', ['title' => 'Engines', 'robots' => 'noindex'])
@use('App\Domain\Engines\Engines')
@php
    $link = 'text-neutral-300 underline underline-offset-4 hover:text-neutral-100 active:text-neutral-100';
@endphp

@section('content')
<main id="main-content" tabindex="-1" class="relative flex-1">
    <div class="relative z-10 mx-auto flex w-full max-w-3xl flex-col gap-8 px-6 py-12">
        <div class="flex flex-col gap-3">
            <h1 class="text-3xl font-semibold tracking-tight">Engines</h1>
            <p role="note" class="rounded-md border border-amber-900 bg-card p-4 text-sm text-amber-200">{{ Engines::WARNING }}</p>
        </div>

        @if ($state)
            <p role="{{ $state['ok'] ? 'status' : 'alert' }}" class="text-sm {{ $state['ok'] ? 'text-green-400' : 'text-red-400' }}">{{ $state['message'] }}</p>
        @endif

        @forelse ($byPlatform as $platform => $engines)
            <section class="flex flex-col gap-3" aria-labelledby="platform-{{ $platform }}">
                <h2 id="platform-{{ $platform }}" class="text-sm uppercase tracking-wide text-neutral-400">{{ $platform }}</h2>
                <ul class="flex flex-col gap-3">
                    @foreach ($engines as $engine)
                        <li class="flex flex-col gap-1 rounded-md border border-neutral-800 bg-card p-4">
                            <div class="flex flex-wrap items-baseline justify-between gap-3">
                                <a href="{{ Engines::pagePath($engine['version'], $engine['platform']) }}" class="break-all text-base font-medium {{ $link }}">{{ $engine['version'] }}</a>
                                <span class="text-sm text-neutral-400">Listed <x-ui.date-time :value="$engine['listed_at']" /></span>
                            </div>
                            <p class="whitespace-pre-line break-words text-sm text-neutral-400">{{ $engine['note'] }}</p>
                        </li>
                    @endforeach
                </ul>
            </section>
        @empty
            <p class="rounded-md border border-neutral-800 bg-card p-6 text-sm text-neutral-400">No engines are listed.</p>
        @endforelse
    </div>
</main>
@endsection
