@extends('layouts.app', ['title' => 'Engine uploaders - Moderation'])
@use('App\Support\Ui')
@php
    $state = session('engineUploadersState');
    $input = 'rounded-md border border-neutral-800 bg-black px-3 py-2 text-sm text-neutral-200 placeholder:text-neutral-400 focus-visible:border-neutral-500 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-400';
    $card = 'flex flex-col gap-2 rounded-md border border-neutral-800 bg-card p-4 text-sm';
@endphp

@section('content')
<main id="main-content" tabindex="-1" class="relative flex-1">
    <x-ui.art-backdrop drawing="archives" :strength="0.08" />
    <x-moderation.nav current="engines" />
    <div class="relative z-10 mx-auto flex w-full max-w-3xl flex-col gap-6 px-6 py-12">
        <div class="flex flex-col gap-2">
            <h1 class="text-3xl font-semibold tracking-tight">Engine uploaders</h1>
            <p class="text-sm text-neutral-400">People with this grant can upload test engine builds from Coilbox. Moderators can upload without it.</p>
        </div>

        @if ($state)
            <p role="{{ $state['ok'] ? 'status' : 'alert' }}" class="text-sm {{ $state['ok'] ? 'text-green-400' : 'text-red-400' }}">{{ $state['message'] }}</p>
        @endif

        <section class="flex flex-col gap-4" aria-labelledby="engines-grants">
            <h2 id="engines-grants" class="text-sm uppercase tracking-wide text-neutral-400">Granted</h2>
            @if ($grants === [])
                <p class="rounded-md border border-neutral-800 bg-card p-6 text-sm text-neutral-400">Nobody has the grant yet.</p>
            @else
                <ul class="flex flex-col gap-3">
                    @foreach ($grants as $g)
                        @php $name = $names[$g['user_id']] ?? $g['user_id']; @endphp
                        <li class="{{ $card }}">
                            <div class="flex flex-wrap items-baseline justify-between gap-3">
                                <span class="font-medium text-neutral-100">{{ $name }}</span>
                                <span class="text-neutral-400"><x-ui.date-time :value="$g['granted_at']" /></span>
                            </div>
                            <p class="break-all font-mono text-neutral-400">{{ $g['user_id'] }}</p>
                            <p class="text-neutral-400">Granted by {{ $names[$g['granted_by']] ?? $g['granted_by'] }}</p>
                            <form method="post" action="/moderation/engines/revoke">
                                @csrf
                                <input type="hidden" name="user_id" value="{{ $g['user_id'] }}">
                                <button type="submit" class="{{ Ui::button('destructive') }}">Revoke {{ $name }}</button>
                            </form>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>

        <section class="flex flex-col gap-4 border-t border-neutral-900 pt-6" aria-labelledby="engines-find">
            <h2 id="engines-find" class="text-sm uppercase tracking-wide text-neutral-400">Grant to someone</h2>
            <form method="get" action="/moderation/engines" class="flex flex-wrap gap-3">
                <label class="sr-only" for="find-an-uploader">Find an account by id or name</label>
                <input id="find-an-uploader" name="q" value="{{ $term }}" placeholder="Find an account by id or name" class="{{ $input }} flex-1">
                <button type="submit" class="{{ Ui::button() }}">Find them</button>
            </form>

            @if ($found !== null)
                @if ($found['error'])
                    <p role="alert" class="rounded-md border border-red-950 bg-card p-6 text-sm text-red-300">The directory could not be read: {{ $found['error'] }}</p>
                @elseif ($found['accounts'] === [])
                    <p class="rounded-md border border-neutral-800 bg-card p-6 text-sm text-neutral-400">No account matches that.</p>
                @else
                    <ul class="flex flex-col gap-3">
                        @foreach ($found['accounts'] as $account)
                            @php $label = $account['display_name'] === '' ? 'Unknown' : $account['display_name']; @endphp
                            <li class="{{ $card }}">
                                <span class="font-medium text-neutral-100">{{ $label }}</span>
                                <p class="break-all font-mono text-neutral-400">{{ $account['id'] }}</p>
                                @if (isset($granted[$account['id']]))
                                    <p class="text-neutral-400">Already granted.</p>
                                @else
                                    <form method="post" action="/moderation/engines/grant">
                                        @csrf
                                        <input type="hidden" name="user_id" value="{{ $account['id'] }}">
                                        <button type="submit" class="{{ Ui::button('primary') }}">Grant {{ $label }}</button>
                                    </form>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endif
            @endif
        </section>
    </div>
</main>
@endsection
