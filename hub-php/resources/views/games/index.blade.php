@extends('layouts.app', [
    'title' => 'Games',
    'description' => 'Browse the games the hub holds facts about: their factions, their units and their build trees.',
])

@section('content')
<main id="main-content" tabindex="-1" class="relative flex-1">
    <x-games.backdrop.games />
    <div class="relative z-10 mx-auto flex w-full max-w-5xl flex-col gap-8 px-6 py-12">
        <div class="flex flex-col gap-2">
            <h1 class="text-3xl font-semibold tracking-tight">Games</h1>
            <p class="text-neutral-400">The games people play, what they are called, and every unit in them.</p>
        </div>

        @if ($error)
            <p class="text-sm text-red-400">The catalog could not be read just now. Try again in a moment.</p>
        @elseif ($rows === [])
            <div class="rounded-md border border-neutral-800 bg-card p-8 text-center">
                <p class="text-sm text-neutral-400">The hub holds facts about no games yet.</p>
            </div>
        @else
            {{-- Featured above the rule, everything else below it. Two lists
                 rather than one with a divider item, which would be a lie to a
                 screen reader. --}}
            <div class="flex flex-col gap-8">
                @foreach ([['Featured', $featured, false], [$featured !== [] ? 'All games' : 'Games', $rest, $featured !== []]] as [$heading, $block, $ruled])
                    @if ($block !== [])
                        <section class="flex flex-col gap-4{{ $ruled ? ' border-t border-neutral-800 pt-8' : '' }}">
                            <h2 class="text-sm uppercase tracking-wide text-neutral-400">{{ $heading }}</h2>
                            <ul class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                                @foreach ($block as $index => $game)
                                    <x-games.card :game="$game" :sides="$sides[$game['shortname']] ?? null" :eager="! $ruled && $index < 6" />
                                @endforeach
                            </ul>
                        </section>
                    @endif
                @endforeach
            </div>
        @endif

        <p class="text-sm text-neutral-400">Looking for a map instead? <a class="text-neutral-300 underline-offset-4 hover:underline active:underline" href="/maps">Every map the hub knows about</a>.</p>
    </div>
</main>
@endsection
