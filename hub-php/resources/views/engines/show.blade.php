@extends('layouts.app', ['title' => 'Engine '.$engine['version'].' for '.$engine['platform'], 'robots' => 'noindex'])
@use('App\Domain\Engines\Engines')
@use('App\Support\Ui')
@php
    $link = 'text-neutral-300 underline underline-offset-4 hover:text-neutral-100 active:text-neutral-100';
    $term = 'text-sm uppercase tracking-wide text-neutral-400';
@endphp

@section('content')
<main id="main-content" tabindex="-1" class="relative flex-1">
    <div class="relative z-10 mx-auto flex w-full max-w-3xl flex-col gap-8 px-6 py-12">
        <div class="flex flex-col gap-3">
            <a href="/engines" class="w-fit text-sm {{ $link }}">All engines</a>
            <h1 class="break-all text-3xl font-semibold tracking-tight">{{ $engine['version'] }}</h1>
            <p class="text-sm text-neutral-400">{{ $engine['platform'] }}</p>
            <p role="note" class="rounded-md border border-amber-900 bg-card p-4 text-sm text-amber-200">{{ Engines::WARNING }}</p>
        </div>

        @if ($state)
            <p role="{{ $state['ok'] ? 'status' : 'alert' }}" class="text-sm {{ $state['ok'] ? 'text-green-400' : 'text-red-400' }}">{{ $state['message'] }}</p>
        @endif

        <p class="whitespace-pre-line break-words text-neutral-300">{{ $engine['note'] }}</p>

        <dl class="grid grid-cols-1 gap-6 border-t border-neutral-900 pt-6 sm:grid-cols-2">
            <div class="flex flex-col gap-1"><dt class="{{ $term }}">File</dt><dd class="break-all text-sm text-neutral-100">{{ $engine['filename'] }}</dd></div>
            <div class="flex flex-col gap-1"><dt class="{{ $term }}">Size</dt><dd class="text-sm text-neutral-100">{{ number_format($engine['size']) }} bytes</dd></div>
            <div class="flex flex-col gap-1"><dt class="{{ $term }}">Listed</dt><dd class="text-sm text-neutral-100"><x-ui.date-time :value="$engine['listed_at']" /></dd></div>
            <div class="flex flex-col gap-1 sm:col-span-2"><dt class="{{ $term }}">SHA-256</dt><dd class="break-all font-mono text-sm text-neutral-100">{{ $engine['sha256'] }}</dd></div>
        </dl>

        <section class="flex flex-col gap-3" aria-labelledby="engine-downloads">
            <h2 id="engine-downloads" class="{{ $term }}">Download</h2>
            @if ($engine['sources'] !== [])
                <ul class="flex flex-col gap-1 text-sm">
                    @foreach ($engine['sources'] as $url)
                        <li><a href="{{ $url }}" class="{{ $link }}">Download from {{ parse_url($url, PHP_URL_HOST) }}</a></li>
                    @endforeach
                </ul>
            @else
                <p class="text-sm text-neutral-400">No mirror holds this build right now.</p>
            @endif
        </section>

        @if ($canEdit || $moderator)
            <form method="post" action="{{ Engines::pagePath($engine['version'], $engine['platform']) }}/note" class="flex flex-col gap-3">
                @csrf
                <label class="flex flex-col gap-1.5 text-sm">
                    <span class="text-neutral-400">Note, up to {{ Engines::NOTE_MAX }} characters</span>
                    <textarea name="note" rows="4" required maxlength="{{ Engines::NOTE_MAX }}" class="{{ Ui::FIELD }}">{{ $engine['note'] }}</textarea>
                </label>
                <button type="submit" class="w-fit {{ Ui::button() }}">Save note</button>
            </form>
        @endif

        @if ($canEdit)
            <form method="post" action="{{ Engines::pagePath($engine['version'], $engine['platform']) }}/withdraw">
                @csrf
                <button type="submit" class="{{ Ui::button('destructive') }}">Withdraw this engine</button>
            </form>
        @endif

        @if ($moderator)
            <section class="flex flex-col gap-3 border-t border-neutral-900 pt-6" aria-labelledby="engine-moderation">
                <h2 id="engine-moderation" class="{{ $term }}">Moderation</h2>
                @include('moderation.partials.archive-remove')
            </section>
        @endif
    </div>
</main>
@endsection
