@extends('layouts.app', ['title' => 'Users - Moderation'])
@use('App\Domain\Moderation\Accounts')
@use('App\Support\Ui')
@php
    $input = 'rounded-md border border-neutral-800 bg-black px-3 py-2 text-sm text-neutral-200 placeholder:text-neutral-400 focus-visible:border-neutral-500 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-400';
@endphp

@section('content')
<main id="main-content" tabindex="-1" class="relative flex-1">
    <x-ui.art-backdrop drawing="archives" :strength="0.08" />
    <x-moderation.nav current="users" />
    <div class="relative z-10 mx-auto flex w-full max-w-3xl flex-col gap-6 px-6 py-12">
        <h1 class="text-3xl font-semibold tracking-tight">Users</h1>

        <form class="flex flex-wrap gap-3">
            <label class="sr-only" for="find-an-account">Find an account by id or name</label>
            <input id="find-an-account" name="q" value="{{ $term }}" placeholder="Find an account by id or name" class="{{ $input }} flex-1">
            <button type="submit" class="{{ Ui::button() }}">Find them</button>
        </form>

        @if ($error)
            <p role="alert" class="rounded-md border border-red-950 bg-card p-6 text-sm text-red-300">The directory could not be read: {{ $error }}</p>
        @elseif ($accounts === [])
            <p class="rounded-md border border-neutral-800 bg-card p-6 text-sm text-neutral-400">{{ $term === '' ? 'No account has signed in yet.' : 'No account matches that.' }}</p>
        @else
            <ul class="flex flex-col gap-3">
                @foreach ($accounts as $account)
                    <li class="flex flex-col gap-2 rounded-md border border-neutral-800 bg-card p-4 text-sm">
                        <div class="flex flex-wrap items-baseline justify-between gap-3">
                            <span class="font-medium text-neutral-100">{{ $account['display_name'] === '' ? 'Unknown' : $account['display_name'] }}</span>
                            <span class="text-sm text-neutral-400"><x-ui.date-time :value="$account['created_at']" /></span>
                        </div>
                        <p class="font-mono text-sm text-neutral-400">{{ $account['id'] }}</p>
                        @if (($account['capabilities'] ?? []) !== [])
                            <p class="text-sm text-neutral-400">{{ implode(', ', array_map(fn ($c) => Accounts::CAPABILITY_LABELS[$c] ?? $c, $account['capabilities'])) }}</p>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif

        @if ($previous !== null || $next !== null)
            <nav class="flex items-center justify-between text-sm" aria-label="Directory pages">
                @if ($previous !== null)
                    <a href="{{ $previous }}" class="text-neutral-300 hover:underline active:underline">Newer</a>
                @else
                    <span></span>
                @endif
                @if ($next !== null)
                    <a href="{{ $next }}" class="text-neutral-300 hover:underline active:underline">Older</a>
                @else
                    <span></span>
                @endif
            </nav>
        @endif
    </div>
</main>
@endsection
