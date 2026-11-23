@extends('layouts.app', ['title' => 'Add a mirror', 'robots' => 'noindex'])
@use('App\Support\Ui')
@php
    $state = session('mirrorRequestState');
@endphp

@section('content')
<main id="main-content" tabindex="-1" class="relative flex-1">
    <x-ui.art-backdrop drawing="archives" :strength="0.08" />
    <div class="relative z-10 mx-auto flex w-full max-w-2xl flex-col gap-6 px-6 py-12">
        <div class="flex flex-col gap-2">
            <h1 class="text-3xl font-semibold tracking-tight">Add a mirror</h1>
            <p class="text-neutral-400">Mirrors hold map and game archives for the hub. Check these details came from your mirror's setup page, then send the request. The hub asks your mirror to prove it holds its key, and a moderator reviews the request.</p>
        </div>

        @if ($state)
            <p role="{{ $state['ok'] ? 'status' : 'alert' }}" class="text-sm {{ $state['ok'] ? 'text-green-400' : 'text-red-400' }}">{{ $state['message'] }}</p>
        @endif

        @unless ($state && $state['ok'])
            <form method="post" action="/mirrors/new" class="flex flex-col gap-4">
                @csrf
                <label class="flex flex-col gap-1.5 text-sm">
                    <span class="text-neutral-400">ID, lowercase letters, digits and hyphens</span>
                    <input name="id" required maxlength="64" value="{{ $values['id'] }}" autocomplete="off" spellcheck="false" class="{{ Ui::FIELD }} font-mono">
                </label>
                <label class="flex flex-col gap-1.5 text-sm">
                    <span class="text-neutral-400">Name players see</span>
                    <input name="name" required maxlength="128" value="{{ $values['name'] }}" autocomplete="off" class="{{ Ui::FIELD }}">
                </label>
                <label class="flex flex-col gap-1.5 text-sm">
                    <span class="text-neutral-400">Address</span>
                    <input name="url" type="url" required value="{{ $values['url'] }}" placeholder="https://mirror.example.org" autocomplete="off" class="{{ Ui::FIELD }}">
                </label>
                <label class="flex flex-col gap-1.5 text-sm">
                    <span class="text-neutral-400">The mirror's public key</span>
                    <input name="key" required minlength="43" maxlength="43" value="{{ $values['key'] }}" autocomplete="off" spellcheck="false" class="{{ Ui::FIELD }} font-mono">
                </label>
                <div>
                    <button type="submit" class="{{ Ui::button('primary') }}">Send the request</button>
                </div>
            </form>
        @endunless
    </div>
</main>
@endsection
