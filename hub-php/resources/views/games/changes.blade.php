@extends('layouts.app', [
    'title' => $title,
    'description' => $metaDescription,
])

@use('App\Support\Ui')

@php
    $control = 'w-full rounded-md border border-neutral-800 bg-card px-3 py-2 text-sm text-neutral-100 focus-visible:border-neutral-600 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-400';
    $heading = 'text-sm uppercase tracking-wide text-neutral-400';
@endphp

@section('content')
<main id="main-content" tabindex="-1" class="relative flex-1">
    <x-games.backdrop.archives />
    <div class="relative z-10 mx-auto flex w-full max-w-5xl flex-col gap-8 px-6 py-12">
        <x-games.breadcrumb :crumbs="[['label' => $gameTitle, 'href' => '/games/'.$shortname], ['label' => 'Release changes']]" />

        <div class="flex flex-col gap-2">
            <h1 class="text-3xl font-semibold tracking-tight">Release changes</h1>
            <p class="text-neutral-400">Which units one release added, removed or changed compared with another.</p>
        </div>

        <div class="flex flex-col gap-4 border-b border-neutral-900 pb-6">
            @if (count($releases) < 2)
                <p class="text-sm text-neutral-400">{{ count($releases) === 0 ? 'The hub holds no releases of this game yet.' : 'The hub holds one release of this game, '.$releases[0].', so there is nothing to compare it with yet.' }}</p>
            @else
                <form action="/games/{{ $shortname }}/changes" class="flex flex-wrap items-end gap-3">
                    @foreach (['from' => ['changes-from', 'From', $from], 'to' => ['changes-to', 'To', $to]] as $name => [$id, $label, $chosen])
                        <div class="flex min-w-40 flex-1 flex-col gap-1.5">
                            <label for="{{ $id }}" class="text-xs uppercase tracking-wide text-neutral-400">{{ $label }}</label>
                            <select id="{{ $id }}" name="{{ $name }}" class="{{ $control }}">
                                @foreach ($releases as $release)
                                    <option value="{{ $release }}"@if ($release === $chosen) selected=""@endif>{{ $release }}</option>
                                @endforeach
                            </select>
                        </div>
                    @endforeach
                    <button type="submit" class="{{ Ui::button('ghost', 'base') }}">Show changes</button>
                </form>
            @endif
        </div>

        @if ($from !== null && $to !== null)
            @if ($notices !== [])
                <ul class="flex flex-col gap-1 text-sm text-neutral-300">
                    @foreach ($notices as $note)
                        <li>{{ $note }}</li>
                    @endforeach
                </ul>
            @endif

            @if ($sameRelease)
                <p class="text-sm text-neutral-400">Both pickers name {{ $from }}. Choose two different releases to see what changed.</p>
            @elseif ($failed)
                <p class="text-sm text-red-400">The catalog could not be read just now. Try again in a moment.</p>
            @else
                <p class="text-neutral-300">From {{ $from }} to {{ $to }}: {{ $changes['addedKnown'] ? count($changes['added']).' added' : 'added not known' }}, {{ $changes['removedKnown'] ? count($changes['removed']).' removed' : 'removed not known' }}, {{ count($changes['changed']) }} changed, {{ $changes['unchanged'] }} unchanged.</p>

                <section class="flex flex-col gap-3" aria-labelledby="changes-added">
                    <h2 id="changes-added" class="{{ $heading }}">Added in {{ $to }}{{ $changes['addedKnown'] ? ' ('.count($changes['added']).')' : '' }}</h2>
                    @if (! $changes['addedKnown'])
                        <p class="text-sm text-neutral-400">The hub does not know that {{ $from }} was reported in full, so a unit {{ $to }} holds and {{ $from }} does not may only be missing from the report. Added units are not listed.</p>
                    @elseif ($added === [])
                        <p class="text-sm text-neutral-400">No unit was added.</p>
                    @else
                        <x-games.change-groups :groups="$added" :game="$shortname" />
                    @endif
                </section>

                <section class="flex flex-col gap-3" aria-labelledby="changes-removed">
                    <h2 id="changes-removed" class="{{ $heading }}">Removed in {{ $to }}{{ $changes['removedKnown'] ? ' ('.count($changes['removed']).')' : '' }}</h2>
                    @if (! $changes['removedKnown'])
                        <p class="text-sm text-neutral-400">The hub does not know that {{ $to }} was reported in full, so a unit {{ $from }} holds and {{ $to }} does not may only be missing from the report. Removed units are not listed.</p>
                    @elseif ($removed === [])
                        <p class="text-sm text-neutral-400">No unit was removed.</p>
                    @else
                        <x-games.change-groups :groups="$removed" :game="$shortname" />
                    @endif
                </section>

                <section class="flex flex-col gap-3" aria-labelledby="changes-changed">
                    <h2 id="changes-changed" class="{{ $heading }}">Changed ({{ count($changes['changed']) }})</h2>
                    @if ($changed === [])
                        <p class="text-sm text-neutral-400">No unit that both releases hold has changed.</p>
                    @else
                        <x-games.change-groups :groups="$changed" :game="$shortname" mode="changed" :from="$from" :to="$to" :labels="$labels" :faction-names="$factionNames" />
                    @endif
                </section>

                <p class="text-sm text-neutral-400">A unit counts as added or removed when one release holds a record of it and the other does not. Added units are listed only when the older release was reported in full, and removed units only when the newer one was.</p>
            @endif
        @endif
    </div>
</main>
@endsection
