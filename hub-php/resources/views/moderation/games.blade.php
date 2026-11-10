@extends('layouts.app', ['title' => 'Ownership requests - Moderation'])
@use('App\Support\Ui')
@php
    $field = 'rounded-md border border-neutral-800 bg-card px-3 py-2 text-sm text-neutral-100 placeholder:text-neutral-400 focus-visible:border-neutral-600 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-400';
    $small = 'text-xs uppercase tracking-wide text-neutral-400';
    $kindLabels = ['rapid' => 'Rapid tag', 'url' => 'Address', 'github' => 'GitHub repo'];
    // A rapid tag and a repo path are shown as stored, not dressed as links.
    $describe = fn (array $offer) => match ($offer['kind']) {
        'github' => $offer['asset'] ? $offer['value'].', picking '.$offer['asset'] : $offer['value'],
        'url' => $offer['filename'] ? $offer['value'].', saved as '.$offer['filename'] : $offer['value'],
        default => $offer['value'],
    };
@endphp

@section('content')
<main id="main-content" tabindex="-1" class="relative flex-1">
    <x-moderation.nav current="games" />
    <div class="mx-auto w-full max-w-3xl flex flex-col gap-6 px-6 py-12">
        <div class="flex flex-col gap-2">
            <h1 class="text-2xl font-semibold tracking-tight">Ownership requests</h1>
            <p class="text-sm text-neutral-400">People asking to hold the pen on a game&rsquo;s page. Approving hands them the display name, description, links, snippets and images.</p>
        </div>

        @if ($queue === [])
            <p class="text-sm text-neutral-400">Nobody is asking right now.</p>
        @else
            <ul class="flex flex-col gap-4">
                @foreach ($queue as $request)
                    <li class="flex flex-col gap-3 rounded-md border border-neutral-900 p-4">
                        <div class="flex flex-col gap-1">
                            <a href="/games/{{ $request['game']['shortname'] }}" class="font-medium text-neutral-100 underline-offset-4 hover:underline active:underline">{{ $request['game']['shortname'] }}</a>
                            <p class="text-sm text-neutral-400">{{ $request['requested_by_name'] }}, <x-ui.date-time :value="$request['created_at']" /></p>
                            @if ($request['note'])
                                <p class="text-sm text-neutral-300">{{ $request['note'] }}</p>
                            @endif
                        </div>
                        <form method="post" action="/moderation/games/decide" class="flex gap-2">
                            @csrf
                            <input type="hidden" name="request_id" value="{{ $request['id'] }}">
                            <button type="submit" name="approve" value="true" class="{{ Ui::button('ghost', 'md') }}">Approve</button>
                            <button type="submit" name="approve" value="false" class="{{ Ui::button('quiet', 'md') }}">Decline</button>
                        </form>
                    </li>
                @endforeach
            </ul>
        @endif

        <section class="flex flex-col gap-3 border-t border-neutral-900 pt-6" aria-labelledby="mod-downloads">
            <h2 id="mod-downloads" class="text-sm uppercase tracking-wide text-neutral-400">Offered downloads</h2>
            <p class="text-sm text-neutral-400">Where a coilbox client says a game can be fetched from. A reader sees none of these until somebody adds one, and adding puts it at the end of that game&rsquo;s list rather than over anything already there.</p>
            @if ($offers === [])
                <p class="text-sm text-neutral-400">Nothing offered right now.</p>
            @else
                {{-- components/DownloadOffers.tsx. An offer is a stranger's suggestion, so it is named as one. --}}
                <ul class="flex flex-col gap-3">
                    @foreach ($offers as $offer)
                        @php
                            $key = substr(sha1('/moderation/games/offer|'.$offer['id']), 0, 16);
                            $answer = session('toggle');
                            $state = is_array($answer) && ($answer['form'] ?? null) === $key ? $answer : null;
                        @endphp
                        <li class="flex flex-col gap-2 rounded-md border border-neutral-900 p-4">
                            <div class="flex flex-col gap-1">
                                <p class="text-xs uppercase tracking-wide text-neutral-400">{{ $offer['shortname'] }} · {{ $kindLabels[$offer['kind']] ?? $offer['kind'] }}</p>
                                <p class="break-all font-mono text-sm text-neutral-200">{{ $describe($offer) }}</p>
                            </div>
                            {{-- One decision with two answers, so one form. --}}
                            <form method="post" action="/moderation/games/offer" class="flex flex-wrap items-center gap-2">
                                @csrf
                                <input type="hidden" name="_form" value="{{ $key }}">
                                <input type="hidden" name="offer" value="{{ $offer['id'] }}">
                                <input type="hidden" name="shortname" value="{{ $offer['shortname'] }}">
                                <button type="submit" name="accept" value="true" class="{{ Ui::button('ghost', 'md') }}">Add it</button>
                                <button type="submit" name="accept" value="false" class="{{ Ui::button('quiet', 'md') }}">Turn it down</button>
                                @if ($state)
                                    <p role="{{ $state['ok'] ? 'status' : 'alert' }}" class="text-sm {{ $state['ok'] ? 'text-neutral-300' : 'text-red-400' }}">{{ $state['message'] }}</p>
                                @endif
                            </form>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>

        <section class="flex flex-col gap-3 border-t border-neutral-900 pt-6" aria-labelledby="mod-visibility">
            <h2 id="mod-visibility" class="text-sm uppercase tracking-wide text-neutral-400">Visibility</h2>
            @if ($flash)
                <p role="status" class="text-sm text-neutral-300">{{ $flash }}</p>
            @endif

            <x-moderation.toggle-form action="/moderation/games/visibility" :fields="['hidden' => 'true']" label="Hide" form-class="flex flex-wrap items-end gap-2" :button-class="Ui::button('ghost', 'base')">
                <div class="flex flex-col gap-1.5">
                    <label for="hide-shortname" class="{{ $small }}">Hide a game</label>
                    <input id="hide-shortname" name="shortname" placeholder="Shortname, e.g. BA" required maxlength="64" class="w-48 {{ $field }}">
                </div>
            </x-moderation.toggle-form>

            <x-moderation.toggle-form action="/moderation/games/version-visibility" :fields="['hidden' => 'true']" label="Hide" form-class="flex flex-wrap items-end gap-2" :button-class="Ui::button('ghost', 'base')">
                <div class="flex flex-col gap-1.5">
                    <label for="hide-version-game" class="{{ $small }}">Hide a release</label>
                    <div class="flex gap-2">
                        <input id="hide-version-game" name="shortname" placeholder="Shortname" required maxlength="64" class="w-36 {{ $field }}">
                        <input name="version" placeholder="Release, e.g. 1.9.0" required maxlength="64" aria-label="Release to hide" class="w-44 {{ $field }}">
                    </div>
                </div>
            </x-moderation.toggle-form>

            @if ($hiddenGames !== [] || $hiddenVersions !== [])
                <div class="flex flex-col gap-3 pt-2">
                    @if ($hiddenGames !== [])
                        <div class="flex flex-col gap-1.5">
                            <p class="{{ $small }}">Hidden games</p>
                            <ul class="flex flex-col gap-1.5">
                                @foreach ($hiddenGames as $row)
                                    <li class="flex items-center justify-between gap-3 text-sm">
                                        <span class="font-mono text-neutral-300">{{ $row['shortname'] }}</span>
                                        <x-moderation.toggle-form action="/moderation/games/visibility" :fields="['shortname' => $row['shortname'], 'hidden' => 'false', 'onSuccess' => 'moderation']" label="Unhide" :button-class="Ui::button('quiet', 'sm')" />
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endif
                    @if ($hiddenVersions !== [])
                        <div class="flex flex-col gap-1.5">
                            <p class="{{ $small }}">Hidden releases</p>
                            <ul class="flex flex-col gap-1.5">
                                @foreach ($hiddenVersions as $row)
                                    <li class="flex items-center justify-between gap-3 text-sm">
                                        <span class="font-mono text-neutral-300">{{ $row['game']['shortname'] }} {{ $row['version'] }}</span>
                                        <x-moderation.toggle-form action="/moderation/games/version-visibility" :fields="['shortname' => $row['game']['shortname'], 'version' => $row['version'], 'hidden' => 'false', 'onSuccess' => 'moderation']" label="Unhide" :button-class="Ui::button('quiet', 'sm')" />
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endif
                </div>
            @endif
        </section>

        <section class="flex flex-col gap-3 border-t border-neutral-900 pt-6" aria-labelledby="mod-featured">
            <h2 id="mod-featured" class="text-sm uppercase tracking-wide text-neutral-400">Featured</h2>
            <p class="text-sm text-neutral-400">A featured game sits above the rule on the games listing. Inside that block the order is alphabetical, so there is no rank to keep in order.</p>

            <x-moderation.toggle-form action="/moderation/games/feature" :fields="['featured' => 'true']" label="Feature" form-class="flex flex-wrap items-end gap-2" :button-class="Ui::button('ghost', 'base')">
                <div class="flex flex-col gap-1.5">
                    <label for="feature-shortname" class="{{ $small }}">Feature a game</label>
                    <input id="feature-shortname" name="shortname" placeholder="Shortname, e.g. BA" required maxlength="64" class="w-48 {{ $field }}">
                </div>
            </x-moderation.toggle-form>

            @if ($featured !== [])
                <ul class="flex flex-col gap-1.5 pt-2">
                    @foreach ($featured as $row)
                        <li class="flex items-center justify-between gap-3 text-sm">
                            <span class="font-mono text-neutral-300">{{ $row['shortname'] }}</span>
                            <x-moderation.toggle-form action="/moderation/games/feature" :fields="['shortname' => $row['shortname'], 'featured' => 'false']" label="Unfeature" :button-class="Ui::button('quiet', 'sm')" />
                        </li>
                    @endforeach
                </ul>
            @else
                <p class="text-sm text-neutral-400">No game is featured right now.</p>
            @endif
        </section>
    </div>
</main>
@endsection
