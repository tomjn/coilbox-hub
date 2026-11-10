@extends('layouts.app')

{{-- app/not-found.tsx. --}}
@section('content')
<main id="main-content" tabindex="-1" class="mx-auto flex w-full max-w-5xl flex-1 flex-col items-start gap-4 px-6 py-16">
    <h1 class="text-3xl font-semibold tracking-tight">Page not found</h1>
    <p class="max-w-md text-neutral-400">This page does not exist, or it has been removed.</p>
    <div class="flex flex-wrap gap-3">
        <a href="/" class="{{ App\Support\Ui::button('primary', 'lg', 'font-medium') }}">Go home</a>
        <a href="/gallery" class="{{ App\Support\Ui::button('ghost', 'lg', 'font-medium') }}">Browse the gallery</a>
    </div>
</main>
@endsection
