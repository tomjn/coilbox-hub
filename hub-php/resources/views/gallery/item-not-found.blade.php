@extends('layouts.app')

{{-- app/item/[id]/not-found.tsx: the site's not found page, plus a pointer to
     the withdrawn page for a signed in visitor, who may be its author or a
     moderator. --}}
@section('content')
<main id="main-content" tabindex="-1" class="mx-auto flex w-full max-w-5xl flex-1 flex-col items-start gap-4 px-6 py-16">
    <h1 class="text-3xl font-semibold tracking-tight">Page not found</h1>
    <p class="max-w-md text-neutral-400">This page does not exist, or it has been removed.</p>
    <div class="flex flex-wrap gap-3">
        <a href="/" class="{{ App\Support\Ui::button('primary', 'lg', 'font-medium') }}">Go home</a>
        <a href="/gallery" class="{{ App\Support\Ui::button('ghost', 'lg', 'font-medium') }}">Browse the gallery</a>
    </div>
</main>
@if (App\Support\Nav::current() !== null && ! request()->is('item/*/withdrawn'))
    <p class="mx-auto w-full max-w-5xl px-6 pb-16 text-sm text-neutral-400">If you withdrew this, or you moderate the hub, it is still <a href="/item/{{ $id }}/withdrawn" class="text-neutral-300 underline underline-offset-4 hover:text-white active:text-white">here</a>.</p>
@endif
@endsection
