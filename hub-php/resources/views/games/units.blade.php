@extends('layouts.app', ['title' => $title, 'description' => $description])

@use('App\Support\Ui')

@section('content')
<main id="main-content" tabindex="-1" class="relative flex-1">
    <x-games.backdrop.archives />
    <div class="relative z-10 mx-auto flex w-full max-w-5xl flex-col gap-8 px-6 py-12">
        <x-games.breadcrumb :crumbs="[['label' => $gameTitle, 'href' => '/games/'.$shortname], ['label' => 'Units']]" />

        <div class="flex flex-col gap-2">
            <h1 class="text-3xl font-semibold tracking-tight">Units</h1>
            <p class="text-neutral-400">Every unit this game ships, as the hub holds it.</p>
        </div>

        {{-- A GET to this same route, so submitting it produces the URL the
             filters describe. The faction is a set of links above, not a form
             field, since a side is what you are looking at. --}}
        <div class="flex flex-col gap-4 border-b border-neutral-900 pb-6">
            @if (count($game['factions']) > 1)
                <x-games.faction-toggles :options="$factionOptions" />
            @endif
            <form action="/games/{{ $shortname }}/units" class="flex flex-wrap items-end gap-3">
                <div class="flex min-w-48 flex-1 flex-col gap-1.5">
                    <label for="units-q" class="text-sm uppercase tracking-wide text-neutral-400">Search</label>
                    <input id="units-q" type="search" name="q" value="{{ $filters['q'] ?? '' }}" class="{{ Ui::FIELD }}"/>
                </div>
                @if ($retiredHeld)
                    <label class="flex items-center gap-2 pb-2 text-sm text-neutral-400">
                        <input type="checkbox" name="retired" value="1" @checked($filters['retired']) class="size-4"/>Show retired units
                    </label>
                @endif
                @if ($filters['faction'])
                    <input type="hidden" name="faction" value="{{ $filters['faction'] }}"/>
                @endif
                <button type="submit" class="{{ Ui::button() }}">Filter</button>
            </form>
        </div>

        @if ($grid['error'])
            <p class="text-sm text-red-400">The catalog could not be read just now. Try again in a moment.</p>
        @elseif ($grid['units'] === [])
            <div class="rounded-md border border-neutral-800 bg-card p-8 text-center">
                <p class="text-sm text-neutral-400">
                    @if ($grid['count'] > 0)
                        That page is past the last unit.
                    @elseif ($filters['q'] || $filters['retired'] || $filters['faction'])
                        No unit matches that.
                    @else
                        Nobody has reported this game's units yet.
                    @endif
                </p>
                <a class="mt-3 inline-block text-sm text-neutral-300 underline-offset-4 hover:underline active:underline" href="/games/{{ $shortname }}/units">Back to the first page</a>
            </div>
        @else
            <ul class="grid grid-cols-3 gap-3 sm:grid-cols-4 md:grid-cols-6 lg:grid-cols-8">
                @foreach ($grid['units'] as $index => $entry)
                    <x-games.unit-card :game="$shortname" :unit="$entry['unit']" :picture="$entry['picture']" :eager="$index < 16" />
                @endforeach
            </ul>

            @if ($pages !== [])
                <nav aria-label="Pages" class="flex flex-wrap items-center justify-center gap-1.5 text-sm text-neutral-400">
                    @foreach ($pages as $step)
                        @if (isset($step['gap']))
                            <span aria-hidden="true" class="px-1">&hellip;</span>
                        @elseif ($step['current'])
                            <span aria-current="page" class="px-2 py-1 text-neutral-200">{{ $step['number'] }}</span>
                        @else
                            <a class="px-2 py-1 underline-offset-4 hover:underline active:underline" href="{{ $step['href'] }}">{{ $step['number'] }}</a>
                        @endif
                    @endforeach
                </nav>
            @endif
        @endif
    </div>
</main>
@endsection
