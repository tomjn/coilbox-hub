@extends('layouts.app', ['title' => 'Sign in', 'robots' => 'noindex'])

@section('content')
<main id="main-content" tabindex="-1" class="relative flex-1">
    <x-ui.art-backdrop drawing="games" :strength="0.09" />
    <div class="relative z-10 mx-auto flex w-full max-w-md flex-col gap-6 px-6 py-12">
        <div class="flex flex-col gap-2">
            <h1 class="text-3xl font-semibold tracking-tight">Sign in</h1>
            <p class="text-neutral-400">Signing in is only needed to publish, so your name is on it and you can change or withdraw it later.</p>
        </div>
        <x-ui.sign-in-buttons :next="$next" />
    </div>
</main>
@endsection
