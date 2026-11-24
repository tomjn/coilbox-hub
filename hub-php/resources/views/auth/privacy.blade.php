@extends('layouts.app')

{{-- What the hub keeps about people. Each line here describes what the code
     does, so a change to sign in, sessions, download counting or account
     deletion needs a matching change here, and a new "last updated" date. --}}
@php
    $link = 'text-neutral-100 underline underline-offset-4 transition-colors hover:text-white active:text-white';
@endphp
@section('content')
<main id="main-content" tabindex="-1" class="mx-auto flex w-full max-w-2xl flex-1 flex-col gap-10 px-6 py-12">
    <header class="flex flex-col gap-3">
        <h1 class="text-3xl font-semibold tracking-tight">Privacy</h1>
        <p class="text-neutral-300">
            Coilbox Hub is run by Tom J Nowell. This page says what the hub keeps about you, why, and how to have it removed.
            For anything about your data, email <a class="{{ $link }}" href="mailto:admin@tomjn.com">admin@tomjn.com</a>.
        </p>
        <p class="text-sm text-neutral-400">Last updated 24 November 2026.</p>
    </header>

    <section aria-labelledby="browsing-heading" class="flex flex-col gap-4">
        <h2 id="browsing-heading" class="text-xl font-semibold">If you only look around</h2>
        <p class="text-neutral-300">You do not need an account to browse or download. The hub has no adverts, no analytics and no tracking cookies.</p>
        <ul class="flex list-disc flex-col gap-2 pl-6 text-neutral-300">
            <li>The hub sets two cookies. One keeps your session working, and the hub stores your IP address and browser details with the session until it ends. The other stops other websites sending forms to the hub as you.</li>
            <li>To count downloads without counting the same person twice, the hub keeps a scrambled code made from the day, your IP address and what you downloaded. It cannot be turned back into your address, and it is deleted after a day.</li>
            <li>The web server keeps logs, which include IP addresses, for a short time to keep the hub running and secure.</li>
        </ul>
    </section>

    <section aria-labelledby="account-heading" class="flex flex-col gap-4">
        <h2 id="account-heading" class="text-xl font-semibold">If you sign in</h2>
        <p class="text-neutral-300">You sign in with Discord, GitHub, Google or Twitch. The hub never sees your password for those. When you sign in, the hub keeps:</p>
        <ul class="flex list-disc flex-col gap-2 pl-6 text-neutral-300">
            <li>your email address, and whether that service says you have confirmed it</li>
            <li>your account ID, name and profile picture from each service you sign in with</li>
            <li>the display name you choose, which other people see</li>
            <li>the secret key for your authenticator app, if you set one up</li>
            <li>when you last signed in</li>
        </ul>
        <p class="text-neutral-300">
            Your email address is used to join a new sign in to the account that already has it, so you do not end up with two accounts.
            The hub only does this when the service says the address is confirmed. Your email is never shown to other people.
        </p>
        <p class="text-neutral-300">
            If you sign in from Coilbox, the hub also keeps the codes that let Coilbox stay signed in for you, until you sign out or they expire.
        </p>
    </section>

    <section aria-labelledby="sharing-heading" class="flex flex-col gap-4">
        <h2 id="sharing-heading" class="text-xl font-semibold">What you share</h2>
        <p class="text-neutral-300">
            The presets, packs, games, maps and pictures you send to the hub are public, with your display name on them.
            The hub also keeps the reports you make, the community points you earn and, for moderators, the decisions you make, so moderation has a record.
        </p>
    </section>

    <section aria-labelledby="others-heading" class="flex flex-col gap-4">
        <h2 id="others-heading" class="text-xl font-semibold">Other services involved</h2>
        <ul class="flex list-disc flex-col gap-2 pl-6 text-neutral-300">
            <li>Discord, GitHub, Google and Twitch, when you choose to sign in with one of them. Their own privacy policies cover what they keep.</li>
            <li>DigitalOcean, which hosts the server the hub runs on.</li>
            <li>Supabase, which held hub accounts before they moved to the hub's own server. It still holds copies of accounts made before the move, until those copies are deleted.</li>
        </ul>
        <p class="text-neutral-300">Nothing about you is sold or shared with anyone else.</p>
    </section>

    <section aria-labelledby="delete-heading" class="flex flex-col gap-4">
        <h2 id="delete-heading" class="text-xl font-semibold">Delete your account</h2>
        <p class="text-neutral-300">You can delete your account at any time from your <a class="{{ $link }}" href="/account">account page</a>. This removes:</p>
        <ul class="flex list-disc flex-col gap-2 pl-6 text-neutral-300">
            <li>your account, your email address and the services you signed in with</li>
            <li>your display name and profile</li>
            <li>the items you published, such as presets and packs, and any reports about them</li>
            <li>your community points and any moderator rights</li>
        </ul>
        <p class="text-neutral-300">Some things stay, without your name on them:</p>
        <ul class="flex list-disc flex-col gap-2 pl-6 text-neutral-300">
            <li>pictures you uploaded, which other people's pages may use</li>
            <li>reports you made, and moderation decisions you took</li>
        </ul>
        <p class="text-neutral-300">If a moderator removed a picture you uploaded for safety reasons, your display name and your username from the service you signed in with stay with that record, so it still says who uploaded it.</p>
    </section>

    <section aria-labelledby="rights-heading" class="flex flex-col gap-4">
        <h2 id="rights-heading" class="text-xl font-semibold">Your rights</h2>
        <p class="text-neutral-300">
            The hub keeps this information to run the account you asked for, and to keep the hub safe from abuse.
            Under UK data protection law you can ask for a copy of what the hub holds about you, ask for it to be corrected or deleted, and object to how it is used.
            Email <a class="{{ $link }}" href="mailto:admin@tomjn.com">admin@tomjn.com</a> to ask.
        </p>
        <p class="text-neutral-300">
            If you are not happy with the answer, you can complain to the Information Commissioner's Office at <a class="{{ $link }}" href="https://ico.org.uk" rel="noreferrer">ico.org.uk</a>.
        </p>
    </section>
</main>
@endsection
