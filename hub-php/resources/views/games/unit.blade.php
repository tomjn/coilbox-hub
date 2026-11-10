@extends('layouts.app', ['ogType' => 'website'])

@use('App\Domain\Games\UnitRelease')
@use('App\Support\Ui')

@php
    $link = 'underline-offset-4 hover:underline active:underline';
    $chipOn = 'rounded-md border border-neutral-600 bg-neutral-900 px-3 py-1.5 text-sm text-neutral-100';
    $heading = 'text-sm uppercase tracking-wide text-neutral-400';
    $select = 'rounded-md border border-neutral-800 bg-card px-3 py-2 text-sm text-neutral-100';
@endphp

{{-- The author snippet form and the owner and moderator controls are left out.
     They write, and need web sign in. --}}
@section('content')
<main id="main-content" tabindex="-1" class="relative flex-1">
    <div class="mx-auto flex w-full max-w-5xl flex-col gap-8 px-6 py-12">
        <x-games.breadcrumb :crumbs="[
            ['label' => 'Games', 'href' => '/games'],
            ['label' => $gameName, 'href' => '/games/'.$shortname],
            ['label' => 'Units', 'href' => '/games/'.$shortname.'/units'],
            ['label' => $label],
        ]" />

        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:gap-8">
            <x-games.unit-portrait :label="$label" :asset="$buildpic" />

            <div class="flex flex-col gap-2">
                <h1 class="text-3xl font-semibold tracking-tight">{{ $label }}</h1>
                <p class="font-mono text-sm text-neutral-400">{{ $page['unit_name'] }}</p>
                <div class="flex flex-wrap items-center gap-2 text-sm text-neutral-400">
                    @if ($page['faction_name'])
                        <span class="rounded bg-neutral-900 px-2 py-1">{{ $page['faction_name'] }}</span>
                    @endif
                    @if ($page['removed_at'])
                        <span class="rounded bg-neutral-900 px-2 py-1 text-neutral-400">Retired</span>
                    @endif
                    @if ($page['shown_version'])
                        <span class="rounded bg-neutral-900 px-2 py-1">Showing release {{ $page['shown_version'] }}</span>
                    @endif
                </div>
                @if ($page['source_version'] && ! $page['shown_version'])
                    <p class="text-sm text-neutral-400">Facts as of release {{ $page['source_version'] }}.</p>
                @endif
                @if ($page['shown_version'] === null && $v && $v !== $page['source_version'])
                    <p class="text-sm text-neutral-400">No record of this unit in release {{ $v }}; showing current facts.</p>
                @endif
            </div>
        </div>

        <x-games.unit-renders :label="$label" :renders="$renders" />

        {{-- Above the stats, because the stages are the frame everything below
             is read in. --}}
        @if ($page['stages'] !== [])
            <section class="flex flex-col gap-3" aria-labelledby="unit-stages">
                <h2 id="unit-stages" class="{{ $heading }}">Stages</h2>
                <p class="text-sm text-neutral-400">This unit turns into another. The catalog holds a row for each stage, and every one of them has its own page.</p>
                <x-games.stage-strip :game="$shortname" :stages="$page['stages']" :pictures="$buildPictures" />
            </section>
        @endif

        <section class="flex flex-col gap-3" aria-labelledby="unit-stats">
            <h2 id="unit-stats" class="{{ $heading }}">Stats</h2>
            <x-games.stat-table :stats="$page['stats']" />
        </section>

        @if ($page['stage_stats'] !== [])
            <section class="flex flex-col gap-3" aria-labelledby="unit-stage-stats">
                <h2 id="unit-stage-stats" class="{{ $heading }}">Stats across the stages</h2>
                <x-games.stage-stats :stages="$page['stages']" :rows="$page['stage_stats']" />
                <p class="text-sm text-neutral-400">A stat that draws as its own table, such as a weapons summary, is on each stage&rsquo;s own page rather than in this one.</p>
            </section>
        @endif

        @if ($page['snippet'] && ! $page['shown_version'])
            <section class="flex flex-col gap-2 rounded-md border border-neutral-900 bg-card p-4" aria-labelledby="unit-snippet">
                <h2 id="unit-snippet" class="text-xs uppercase tracking-wide text-neutral-400">From the author</h2>
                <p class="text-sm text-neutral-200">{{ $page['snippet'] }}</p>
            </section>
        @endif

        {{-- Upstream before downstream: what makes this thing, then what it makes. --}}
        <section class="flex flex-col gap-3" aria-labelledby="unit-built-by">
            <h2 id="unit-built-by" class="{{ $heading }}">Built by</h2>
            @if ($page['built_by'] === [])
                <p class="text-sm text-neutral-400">Nothing builds this.</p>
            @else
                <x-games.unit-grid :game="$shortname" :units="$page['built_by']" :pictures="$buildPictures" />
            @endif
        </section>

        <section class="flex flex-col gap-3" aria-labelledby="unit-builds">
            <h2 id="unit-builds" class="{{ $heading }}">Builds</h2>
            @if ($page['builds'] === [])
                <p class="text-sm text-neutral-400">Nothing, or nothing reported yet.</p>
            @else
                <x-games.unit-grid :game="$shortname" :units="$page['builds']" :pictures="$buildPictures" />
            @endif
        </section>

        <x-games.compare-picker :game="$shortname" :unit="$page['unit_name']" />

        @if ($page['versions'] !== [])
            <section class="flex flex-col gap-3" aria-labelledby="unit-versions">
                <h2 id="unit-versions" class="{{ $heading }}">Releases</h2>
                <ul class="flex flex-wrap gap-2">
                    <li>
                        <a href="/games/{{ $shortname }}/units/{{ UnitRelease::unitSegment($page['unit_name']) }}" class="{{ $page['shown_version'] ? Ui::button('ghost', 'md') : $chipOn }}">Latest</a>
                    </li>
                    @foreach ($page['versions'] as $version)
                        <li>
                            <a href="/games/{{ $shortname }}/units/{{ UnitRelease::unitSegment($page['unit_name'], $version) }}" class="{{ $page['shown_version'] === $version ? $chipOn : Ui::button('ghost', 'md') }}">{{ $version }}</a>
                        </li>
                    @endforeach
                </ul>
                @if (count($page['versions']) >= 2)
                    <form action="/games/{{ $shortname }}/units/{{ $page['unit_name'] }}/compare" method="get" class="flex flex-wrap items-end gap-3 pt-2">
                        <div class="flex flex-col gap-1.5">
                            <label for="compare-left" class="text-xs uppercase tracking-wide text-neutral-400">Compare</label>
                            <select id="compare-left" name="left" class="{{ $select }}">
                                @foreach ($page['versions'] as $version)
                                    <option value="{{ $version }}" @selected($version === $page['versions'][1])>{{ $version }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="flex flex-col gap-1.5">
                            <label for="compare-right" class="text-xs uppercase tracking-wide text-neutral-400">With</label>
                            <select id="compare-right" name="right" class="{{ $select }}">
                                @foreach ($page['versions'] as $version)
                                    <option value="{{ $version }}" @selected($version === $page['versions'][0])>{{ $version }}</option>
                                @endforeach
                            </select>
                        </div>
                        <button type="submit" class="{{ Ui::button('ghost', 'base') }}">Compare releases</button>
                    </form>
                @endif
            </section>
        @endif

        <p class="text-sm text-neutral-400"><a href="/games/{{ $shortname }}/units" class="text-neutral-300 {{ $link }}">Every unit this game ships</a>.</p>
    </div>
</main>
@endsection
