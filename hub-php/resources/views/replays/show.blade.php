@php
    $title = $replay['map_title'];
    $gameLine = trim($replay['game'].' '.$replay['game_version']);
@endphp
@extends('layouts.app', [
    'title' => 'Replay on '.$title,
    'description' => $gameLine.' on '.$title.', '.App\Domain\Replays\ReplayPage::duration($replay['duration']).'. '.$replay['result'].'.',
])
@use('App\Domain\Replays\ReplayPage')
@use('App\Support\Dates')
@php
    $base = '/replays/'.$replay['game_id'];
    $card = 'rounded-lg border border-neutral-800 bg-card';
    $h2 = 'text-sm font-medium';
    $muted = 'text-neutral-400';
    $focus = 'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-400';
    $pill = 'rounded-full border border-neutral-800 px-2.5 py-0.5 text-xs text-neutral-400';
    $button = 'inline-flex min-h-9 items-center gap-2 rounded-md border border-neutral-700 px-3 py-1.5 text-sm text-neutral-200 transition-colors hover:border-neutral-500 hover:text-white active:border-neutral-400 '.$focus;
    $tabs = array_filter(ReplayPage::TABS, fn ($label, $key) => $key !== 'economy' || $replay['history'] !== null, ARRAY_FILTER_USE_BOTH);
    $tabHref = fn (string $key) => $key === 'summary' ? $base : $base.'?tab='.$key;
@endphp

@section('content')
<main id="main-content" tabindex="-1" class="relative flex-1">
    <div class="mx-auto flex w-full max-w-7xl flex-col gap-4 px-4 py-8 sm:px-6">
        {{-- The header: ReplayDetailPage's title block, with the downloads where Coilbox has Watch. --}}
        <header class="flex flex-wrap items-start justify-between gap-4">
            <div class="flex min-w-0 flex-col gap-2">
                <a href="/replays" class="w-fit text-xs {{ $muted }} underline-offset-4 hover:text-neutral-200 hover:underline active:text-neutral-200 {{ $focus }}">&larr; Replays</a>
                <h1 class="break-words text-2xl font-semibold tracking-tight">{{ $title }}</h1>
                <ul aria-label="Replay facts" class="flex flex-wrap gap-1.5">
                    <li class="{{ $pill }}">{{ $gameLine }}</li>
                    <li class="{{ $pill }}"><span class="sr-only">Engine </span>{{ $replay['engine'] }}</li>
                    <li class="{{ $pill }}"><x-ui.date-time :value="$replay['started_at']" /></li>
                    <li class="{{ $pill }}"><span class="sr-only">Length </span>{{ ReplayPage::duration($replay['duration']) }}</li>
                    <li class="{{ $pill }}">{{ $replay['result'] }}</li>
                </ul>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                @foreach ($replay['downloads']['tracks'] as $track)
                    <a href="{{ $track['url'] }}" class="{{ $button }}" download><x-icon name="download" class="size-4" />Download track</a>
                @endforeach
                @if ($replay['downloads']['replay'] !== null)
                    <a href="{{ $replay['downloads']['replay'] }}" class="{{ $button }} border-amber-500/60 bg-amber-400 font-medium text-neutral-950 hover:border-amber-300 hover:bg-amber-300 hover:text-neutral-950 active:bg-amber-500" download><x-icon name="download" class="size-4" />Download replay</a>
                @else
                    <p class="text-sm {{ $muted }}">The replay file is not available to download just now.</p>
                @endif
            </div>
        </header>

        <div class="flex flex-col gap-4 xl:grid xl:grid-cols-2 xl:items-start">
            <x-replays.map-card :card="$replay['map_card']" :title="$title" :heat="$heat" :base="$base" />

            <section aria-label="Match" class="flex min-w-0 flex-col gap-4">
                {{-- Coilbox's tabs, as links so each works without JavaScript and keeps its place in the address. --}}
                <nav aria-label="Replay sections" class="w-fit max-w-full overflow-x-auto rounded-lg bg-neutral-900 p-1">
                    <ul class="flex gap-1 text-sm">
                        @foreach ($tabs as $key => $label)
                            <li><a href="{{ $tabHref($key) }}" @if ($tab === $key) aria-current="page" @endif class="block whitespace-nowrap rounded-md px-3 py-1.5 transition-colors {{ $focus }} {{ $tab === $key ? 'bg-neutral-700 text-white' : 'text-neutral-400 hover:text-neutral-100 active:text-neutral-100' }}">{{ $label }}</a></li>
                        @endforeach
                    </ul>
                </nav>

                @if ($tab === 'summary')
                    <div class="flex flex-col gap-5">
                        <ul class="grid grid-cols-[repeat(auto-fit,minmax(11rem,1fr))] gap-3" aria-label="Headline figures">
                            @foreach ($replay['tiles'] as $tile)
                                <li class="flex flex-col gap-1 rounded-lg border border-neutral-800 bg-card p-4">
                                    <span class="flex items-center gap-1.5 text-xs font-medium uppercase tracking-wide {{ $muted }}"><x-icon :name="$tile['icon']" class="size-3.5" />{{ $tile['label'] }}</span>
                                    <span class="text-2xl font-semibold tabular-nums">{{ $tile['value'] }}</span>
                                    @if ($tile['sub'] !== null)
                                        <span class="text-xs {{ $muted }}">{{ $tile['sub'] }}</span>
                                    @endif
                                </li>
                            @endforeach
                        </ul>

                        <x-replays.roster :sides="$replay['sides']" :spectators="$replay['spectators']" />

                        @if ($replay['awards'] !== [])
                            <section class="flex flex-col gap-2">
                                <h2 class="{{ $h2 }}">Awards</h2>
                                <dl class="{{ $card }} grid gap-3 p-3 text-sm sm:grid-cols-2">
                                    @foreach ($replay['awards'] as $award)
                                        <div class="flex flex-col gap-1">
                                            <dt class="text-xs {{ $muted }}">{{ $award['title'] }}</dt>
                                            @foreach ($award['places'] as $place)
                                                <dd class="flex flex-wrap items-baseline justify-between gap-x-3">
                                                    <span class="min-w-0 flex-[1_1_8rem] break-words"><span class="tabular-nums {{ $muted }}">{{ $place['place'] }}.</span> {{ $place['team'] }}</span>
                                                    @if ($place['value'] !== null)
                                                        <span class="shrink-0 tabular-nums {{ $muted }}">{{ $place['value'] }}</span>
                                                    @endif
                                                </dd>
                                            @endforeach
                                        </div>
                                    @endforeach
                                </dl>
                                @if ($replay['awards_source'] === 'recorded')
                                    <p class="text-xs {{ $muted }}">As {{ $replay['game'] }} recorded them in the replay.</p>
                                @else
                                    <p class="text-xs {{ $muted }}">Worked out from the analysis below, as Coilbox works them out. Amounts are in metal.@if ($replay['analysis']['unpriced_kills'] > 0) {{ $replay['analysis']['unpriced_kills'] === 1 ? 'One enemy unit destroyed has no known cost and counts nothing.' : number_format($replay['analysis']['unpriced_kills']).' enemy units destroyed have no known cost and count nothing.' }}@endif</p>
                                @endif
                            </section>
                        @endif

                        <section class="flex flex-col gap-2">
                            <h2 class="{{ $h2 }}">Details</h2>
                            <dl class="{{ $card }} grid grid-cols-[auto_minmax(0,1fr)] gap-x-6 gap-y-1.5 p-3 text-sm">
                                <dt class="{{ $muted }}">Game id</dt><dd class="break-all font-mono text-xs leading-5">{{ $replay['game_id'] }}</dd>
                                <dt class="{{ $muted }}">Map</dt><dd class="break-words">{{ $replay['map'] }}</dd>
                                <dt class="{{ $muted }}">Played</dt><dd>{{ Dates::format($replay['started_at'], true) }} UTC</dd>
                                <dt class="{{ $muted }}">File size</dt><dd>{{ number_format($replay['size'] / 1048576, 1) }} MB</dd>
                            </dl>
                        </section>
                        {{-- The analysis parts, after Details, where Coilbox's Summary tab has them. --}}
                        @if ($replay['analysis'] !== null)
                            <x-replays.analysis :analysis="$replay['analysis']" :value="$value" :base="$base" />
                        @endif
                    </div>
                @elseif ($tab === 'economy')
                    <section class="flex flex-col gap-3">
                        <h2 class="{{ $h2 }}">Match statistics</h2>
                        <ul class="flex flex-wrap gap-x-4 gap-y-1 text-xs {{ $muted }}" aria-label="Teams">
                            @foreach ($replay['history']['teams'] as $team)
                                <li class="flex items-center gap-1.5"><span class="size-3 rounded-sm border border-neutral-700" style="background: {{ $team['colour'] ?? '#94a3b8' }}"></span>{{ $team['name'] }}</li>
                            @endforeach
                        </ul>
                        <div class="grid gap-3 sm:grid-cols-2">
                            @foreach (ReplayPage::CHARTS as $column => $label)
                                <x-replays.chart :label="$label" :column="$column" :history="$replay['history']" />
                            @endforeach
                        </div>
                    </section>
                @elseif ($tab === 'builds')
                    <x-replays.build-orders :builds="$replay['builds']" :units="$replay['units']" :minutes="$minutes" :action="$base" />
                @else
                    <x-replays.event-list :timeline="$replay['timeline']" :show="$show" :base="$base" />
                @endif
            </section>
        </div>
    </div>
</main>
@endsection

@if ($replay['map_card']['track'] !== null)
    @push('scripts')
        @vite('resources/js/replays/units.ts')
    @endpush
@endif
