@extends('layouts.app', ['title' => 'Uploads - Moderation'])
@use('App\Domain\Mirrors\Removal')
@use('App\Support\Ui')
@php
    $card = 'flex flex-col gap-3 rounded-md border border-neutral-800 bg-card p-5';
    $state = session('archivesState');
    $facts = fn (array $a) => [
        'kind' => $a['kind'],
        'shortname' => $a['shortname'],
        'internal_name' => $a['internal_name'],
        'version' => $a['version'],
    ];
@endphp

@section('content')
<main id="main-content" tabindex="-1" class="relative flex-1">
    <x-ui.art-backdrop drawing="archives" :strength="0.08" />
    <x-moderation.nav current="archives" />
    <div class="relative z-10 mx-auto flex w-full max-w-3xl flex-col gap-6 px-6 py-12">
        <div class="flex flex-col gap-2">
            <h1 class="text-3xl font-semibold tracking-tight">Uploads</h1>
            <p class="text-sm text-neutral-400">Maps and games uploaded from Coilbox to the hub's mirrors. Removing one stops the hub linking it at once, and every mirror deletes it on its next sync. Its file and file name can never be uploaded again.</p>
        </div>

        @if ($state)
            <p role="{{ $state['ok'] ? 'status' : 'alert' }}" class="text-sm {{ $state['ok'] ? 'text-green-400' : 'text-red-400' }}">{{ $state['message'] }}</p>
        @endif

        @if ($error)
            <p role="alert" class="rounded-md border border-red-950 bg-card p-6 text-sm text-red-300">The uploads could not be read. Reload the page to try again.</p>
        @else
            <section class="flex flex-col gap-4" aria-labelledby="archives-reported">
                <h2 id="archives-reported" class="text-sm uppercase tracking-wide text-neutral-400">Reported</h2>
                <p class="text-sm text-neutral-400">A player's copy matched the hub's hash, but the archive inside said something different. Download it, look, then remove it or dismiss the reports.</p>
                @if ($reported === [])
                    <p class="rounded-md border border-neutral-800 bg-card p-6 text-sm text-neutral-400">Nothing waiting.</p>
                @else
                    <ul class="flex flex-col gap-4">
                        @foreach ($reported as $a)
                            <li class="{{ $card }}">
                                @include('moderation.partials.archive-facts', ['a' => $a])
                                <table class="w-full text-left text-sm">
                                    <caption class="sr-only">What reporters found, against what the hub says</caption>
                                    <thead class="text-neutral-400">
                                        <tr><th scope="col" class="py-1 font-normal">Field</th><th scope="col" class="py-1 font-normal">Hub says</th><th scope="col" class="py-1 font-normal">Reporter found</th><th scope="col" class="py-1 font-normal">When</th></tr>
                                    </thead>
                                    <tbody>
                                        @foreach ($a['reports'] as $report)
                                            @foreach ($report['found'] as $field => $value)
                                                <tr class="border-t border-neutral-900">
                                                    <td class="py-1 text-neutral-400">{{ Removal::fieldLabel($field) }}</td>
                                                    <td class="py-1 break-all">{{ $facts($a)[$field] ?? 'none' }}</td>
                                                    <td class="py-1 break-all">{{ $value ?? 'none' }}</td>
                                                    <td class="py-1 text-neutral-400"><x-ui.date-time :value="$report['created_at']" /></td>
                                                </tr>
                                            @endforeach
                                        @endforeach
                                    </tbody>
                                </table>
                                <div class="flex flex-wrap items-start gap-3">
                                    @include('moderation.partials.archive-remove', ['a' => $a])
                                    <form method="post" action="/moderation/archives/dismiss">
                                        @csrf
                                        <input type="hidden" name="archive_id" value="{{ $a['id'] }}">
                                        <button type="submit" class="{{ Ui::button('quiet') }}">Dismiss the reports</button>
                                    </form>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>

            <section class="flex flex-col gap-4 border-t border-neutral-900 pt-6" aria-labelledby="archives-recent">
                <h2 id="archives-recent" class="text-sm uppercase tracking-wide text-neutral-400">Recent uploads</h2>
                <form class="flex flex-wrap gap-3">
                    <label class="sr-only" for="find-an-upload">Find an upload by part of its file name</label>
                    <input id="find-an-upload" name="q" value="{{ $term }}" placeholder="Find an upload by part of its file name" class="{{ Ui::FIELD }} flex-1">
                    <button type="submit" class="{{ Ui::button() }}">Find it</button>
                </form>
                @if ($recent === [])
                    <p class="rounded-md border border-neutral-800 bg-card p-6 text-sm text-neutral-400">{{ $term === '' ? 'Nothing has been uploaded yet.' : 'No listed upload matches that.' }}</p>
                @else
                    <ul class="flex flex-col gap-4">
                        @foreach ($recent as $a)
                            <li class="{{ $card }}">
                                @include('moderation.partials.archive-facts', ['a' => $a])
                                @include('moderation.partials.archive-remove', ['a' => $a])
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>
        @endif
    </div>
</main>
@endsection
