@extends('layouts.app')

{{-- People reach for Publish first, which shares presets and packs, not games.
     The Coilbox menu and button names below are Coilbox's own words, so a
     rename there needs the same change here. --}}
@php
    $link = 'text-neutral-100 underline underline-offset-4 transition-colors hover:text-white active:text-white';
@endphp
@section('content')
<main id="main-content" tabindex="-1" class="mx-auto flex w-full max-w-2xl flex-1 flex-col gap-10 px-6 py-12">
    <header class="flex flex-col gap-3">
        <h1 class="text-3xl font-semibold tracking-tight">Add your game or map</h1>
        <p class="text-neutral-300">
            Games and maps come to the hub from Coilbox, not from the <a class="{{ $link }}" href="/publish">Publish</a> page.
            Coilbox reads them from your computer and sends their details. Once your game is here, you can claim it so you can edit its page.
        </p>
    </header>

    <section aria-labelledby="send-heading" class="flex flex-col gap-4">
        <h2 id="send-heading" class="text-xl font-semibold">Send it from Coilbox</h2>
        <ol class="flex list-decimal flex-col gap-2 pl-6 text-neutral-300">
            <li>Install <a class="{{ $link }}" href="{{ config('hub.coilbox_url') }}" rel="noreferrer">Coilbox</a> and make sure your game or map is in it.</li>
            <li>Open the hub page in Coilbox, choose Share, then Share your maps and games.</li>
            <li>Sign in to your hub account.</li>
            <li>Turn on Send pictures of your games and maps to the hub.</li>
            <li>Press Send what your games say, or Send what your maps say.</li>
        </ol>
        <p class="text-neutral-300">Coilbox only sends released games, packed as an archive. A game in a loose folder stays on your computer.</p>
    </section>

    <section aria-labelledby="claim-heading" class="flex flex-col gap-4">
        <h2 id="claim-heading" class="text-xl font-semibold">Claim your game</h2>
        <ol class="flex list-decimal flex-col gap-2 pl-6 text-neutral-300">
            <li><a class="{{ $link }}" href="/sign-in">Sign in</a> to the hub.</li>
            <li>Find your game on the <a class="{{ $link }}" href="/games">games</a> page and open it.</li>
            <li>Under the game&rsquo;s details, open &ldquo;Are you this game&rsquo;s author?&rdquo;. Say who you are, then press Request ownership.</li>
        </ol>
        <p class="text-neutral-300">A moderator checks the request. Once it is approved, you can edit the game&rsquo;s page and hide it.</p>
    </section>
</main>
@endsection
