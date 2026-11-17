@extends('layouts.app', ['title' => 'Your uploads', 'robots' => 'noindex'])
@use('App\Domain\Mirrors\Removal')
@use('App\Support\Ui')
@php
    $card = 'flex flex-col gap-3 rounded-md border border-neutral-800 bg-card p-5';
    $state = session('uploadsState');
    $states = ['uploading' => 'Uploading', 'listed' => 'Listed', 'removed' => 'Removed'];
@endphp

@section('content')
<main id="main-content" tabindex="-1" class="relative flex-1">
    <x-ui.art-backdrop drawing="games" :strength="0.09" />
    <div class="relative z-10 mx-auto flex w-full max-w-2xl flex-col gap-6 px-6 py-12">
        <div class="flex flex-col gap-2">
            <h1 class="text-3xl font-semibold tracking-tight">Your uploads</h1>
            <p class="text-neutral-400">Maps and games you uploaded from Coilbox, and every upload of a game you own. Mirrors hold the files and the hub links to them.</p>
            <a href="/account" class="text-sm text-neutral-300 underline-offset-4 hover:underline active:underline">Back to your account</a>
        </div>

        @if ($state)
            <p role="{{ $state['ok'] ? 'status' : 'alert' }}" class="text-sm {{ $state['ok'] ? 'text-green-400' : 'text-red-400' }}">{{ $state['message'] }}</p>
        @endif

        @if ($error)
            <p role="alert" class="rounded-md border border-red-950 bg-card p-6 text-sm text-red-300">Your uploads could not be read. Reload the page to try again.</p>
        @elseif ($archives === [])
            <p class="rounded-md border border-neutral-800 bg-card p-6 text-sm text-neutral-400">Nothing yet. Upload a map or game from its page in Coilbox.</p>
        @else
            <ul class="flex flex-col gap-4">
                @foreach ($archives as $a)
                    <li class="{{ $card }}">
                        <div class="flex flex-wrap items-baseline justify-between gap-3">
                            <span class="break-all text-base font-medium">{{ $a['filename'] }}</span>
                            <span class="text-sm text-neutral-400">{{ $states[$a['state']] ?? $a['state'] }}</span>
                        </div>
                        <p class="text-sm text-neutral-400">{{ ($a['kind'] === 'game' ? 'Game: ' : 'Map: ').$a['internal_name'].($a['version'] !== '' ? ', version '.$a['version'] : '').'.' }}</p>
                        @unless ($a['mine'])
                            <p class="text-sm text-neutral-400">Somebody else uploaded this to a game you own.</p>
                        @endunless
                        @if ($a['state'] === 'removed')
                            <p class="text-sm text-neutral-400">{{ Removal::LABELS[$a['removal_reason']] ?? 'Removed' }}, <x-ui.date-time :value="$a['removed_at']" />.</p>
                        @elseif ($a['state'] === 'listed')
                            <form method="post" action="/account/uploads/withdraw" class="flex flex-col gap-2">
                                @csrf
                                <input type="hidden" name="archive_id" value="{{ $a['id'] }}">
                                <p class="text-sm text-neutral-400">Withdrawing stops the hub linking it at once, and every mirror deletes it on its next sync. This file, and its file name, can never be uploaded again.</p>
                                <button type="submit" class="{{ Ui::button('ghost', 'base', 'self-start') }}">Withdraw {{ $a['filename'] }}</button>
                            </form>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
</main>
@endsection
