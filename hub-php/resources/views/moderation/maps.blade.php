@extends('layouts.app', ['title' => 'Maps - Moderation'])
@use('App\Domain\Moderation\MapModeration')
@use('App\Support\Ui')
@php
    $input = 'rounded-md border border-neutral-800 bg-black px-3 py-2 text-sm text-neutral-200 placeholder:text-neutral-400 focus-visible:border-neutral-500 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-400';
    $when = fn (string $at) => substr(str_replace('T', ' ', $at), 0, 16);
@endphp

@section('content')
<main id="main-content" tabindex="-1" class="relative flex-1">
    <x-ui.art-backdrop drawing="archives" :strength="0.08" />
    <x-moderation.nav current="maps" />
    <div class="relative z-10 mx-auto flex w-full max-w-3xl flex-col gap-6 px-6 py-12">
        <h1 class="text-3xl font-semibold tracking-tight">Maps</h1>

        {{-- The way to reach one map's view, which is where the curated tags are edited. --}}
        <form class="flex flex-wrap gap-3">
            <label class="sr-only" for="find-a-map">Find a map by name</label>
            <input id="find-a-map" name="q" value="{{ $term }}" placeholder="Find a map by name" class="{{ $input }} flex-1">
            <button type="submit" class="{{ Ui::button() }}">Find it</button>
        </form>

        @if ($term !== '')
            @if ($matches === [])
                <p class="text-sm text-neutral-400">No map is called that.</p>
            @else
                <ul class="flex flex-col gap-1">
                    @foreach ($matches as $match)
                        <li><a href="/moderation/maps/{{ $match['slug'] }}" class="text-sm text-neutral-300 hover:underline active:underline">{{ $match['mapName'] }}</a></li>
                    @endforeach
                    @if (count($matches) === MapModeration::SEARCH_LIMIT)
                        <li class="text-xs text-neutral-400">The first {{ MapModeration::SEARCH_LIMIT }}. Type more of the name to narrow it.</li>
                    @endif
                </ul>
            @endif
        @endif

        <h2 class="text-sm text-neutral-400">Maps two clients disagree about, the most reported first. Two installs holding different bytes under one name is a corrupt or modified install rather than a new release. Forget what the hub holds only when the stored facts are the odd one out.</h2>

        @if ($conflicts === [])
            <p class="rounded-md border border-neutral-800 bg-card p-6 text-sm text-neutral-400">Nobody disagrees about anything.</p>
        @else
            <ul class="flex flex-col gap-4">
                @foreach ($conflicts as $map)
                    <li class="flex flex-col gap-3 rounded-md border border-neutral-800 bg-card p-5">
                        <div class="flex flex-wrap items-baseline justify-between gap-3">
                            <a href="/moderation/maps/{{ $map['slug'] }}" class="text-base font-medium hover:underline active:underline">{{ $map['mapName'] }}</a>
                            <span class="text-xs text-neutral-400">{{ count($map['reports']) === 1 ? 'One report.' : count($map['reports']).' reports.' }}</span>
                        </div>
                        <ul class="flex flex-col gap-1">
                            @foreach ($map['reports'] as $report)
                                <li class="flex flex-wrap items-baseline gap-x-4 gap-y-1 text-xs text-neutral-400">
                                    <span class="text-neutral-400" title="{{ $report['sourceArchive'] }}">{{ $report['sourceArchive'] }}</span>
                                    <span title="{{ $report['heldSourceHash'] }}">held {{ substr($report['heldSourceHash'], 0, 12) }}</span>
                                    <span class="text-amber-400" title="{{ $report['reportedSourceHash'] }}">reported {{ substr($report['reportedSourceHash'], 0, 12) }}</span>
                                    @if ($report['reportedBy'])
                                        {{-- The picture trail is the one place that shows everything one account is behind. --}}
                                        <a href="/moderation/trail?account={{ $report['reportedBy'] }}" title="{{ $report['reportedBy'] }}" class="underline decoration-neutral-700 underline-offset-2 transition-colors hover:text-neutral-300 active:text-neutral-300">by {{ substr($report['reportedBy'], 0, 8) }}</a>
                                    @else
                                        <span>by an account that has closed</span>
                                    @endif
                                    <span>{{ $when($report['at']) }}</span>
                                </li>
                            @endforeach
                        </ul>
                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <a href="/map/{{ $map['slug'] }}" class="text-xs text-neutral-400 transition-colors hover:text-neutral-300 active:text-neutral-300">What the hub says about it</a>
                            <form method="post" action="/moderation/maps/clear">
                                @csrf
                                {{-- Red, because the map leaves the catalog and nothing puts its curated tags back. --}}
                                <button type="submit" name="map" value="{{ $map['id'] }}" title="Only when the facts the hub holds are the wrong ones. The map leaves the catalog until a client reports it again, and its curated tags go with it." class="{{ Ui::button('destructive', 'sm') }}">Forget what the hub holds<span class="sr-only"> about {{ $map['mapName'] }}</span></button>
                            </form>
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif

        <section class="flex flex-col gap-3 border-t border-neutral-900 pt-6" aria-labelledby="mod-featured">
            <h2 id="mod-featured" class="text-sm uppercase tracking-wide text-neutral-400">Featured</h2>
            <p class="text-sm text-neutral-400">A featured map sits above the rest of the catalog listing, whatever the reader has sorted by. Feature one from its own page, above.</p>
            @if ($featured !== [])
                <ul class="flex flex-col gap-1.5">
                    @foreach ($featured as $row)
                        <li class="flex items-center justify-between gap-3 text-sm">
                            <a href="/moderation/maps/{{ $row['slug'] }}" class="min-w-0 break-words text-neutral-300 underline-offset-4 hover:underline active:underline">{{ $row['display_name'] ?? $row['map_name'] }}</a>
                            <x-moderation.toggle-form action="/moderation/maps/feature" :fields="['id' => $row['id'], 'slug' => $row['slug'], 'featured' => 'false']" label="Unfeature" :button-class="Ui::button('quiet', 'sm')" />
                        </li>
                    @endforeach
                </ul>
            @else
                <p class="text-sm text-neutral-400">Nothing is featured right now.</p>
            @endif
        </section>
    </div>
</main>
@endsection
