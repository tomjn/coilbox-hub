{{--
    One replay in the list: the minimap, the match and its sides. `map` is the
    catalogue's entry for the map, or null when it publishes none.
--}}
@props(['replay', 'map' => null])
@use('App\Domain\Maps\Js')
@use('App\Domain\Replays\ReplayList')
@php
    $href = '/replays/'.$replay['game_id'];
    $picture = $map['picture'] ?? ReplayList::placeholder($replay['map']);
    $title = $map['title'] ?? $replay['map'];
    $seconds = $replay['duration'];
    $length = $seconds >= 3600
        ? sprintf('%d:%02d:%02d', intdiv($seconds, 3600), intdiv($seconds, 60) % 60, $seconds % 60)
        : sprintf('%d:%02d', intdiv($seconds, 60), $seconds % 60);
    $focus = 'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-400';
@endphp
<article class="grid grid-cols-[5rem_minmax(0,1fr)] content-start gap-x-4 gap-y-3 rounded-md border border-neutral-800 bg-card p-3 sm:grid-cols-[8rem_minmax(0,1fr)]">
    {{-- The heading is the same link, so this one is not read out or tabbed to. --}}
    <a class="flex aspect-square w-full items-center justify-center self-start overflow-hidden rounded-md bg-black sm:row-span-2" href="{{ $href }}" tabindex="-1" aria-hidden="true">
        @if ($picture['from'] === 'placeholder')
            <x-assets.placeholder :of="$picture" :quiet="true" class="size-full !p-2" />
        @else
            <img src="{{ $picture['url'] }}" alt="Minimap of {{ $replay['map'] }}" loading="lazy" decoding="async" class="max-h-full max-w-full object-fill" style="aspect-ratio:{{ Js::number($map['width']) }} / {{ Js::number($map['height']) }}">
        @endif
    </a>

    <div class="flex min-w-0 flex-col gap-1">
        <h2 class="min-w-0 break-words text-base font-medium leading-snug"><a class="hover:underline active:underline {{ $focus }}" href="{{ $href }}">{{ $title }}</a></h2>
        <p class="break-words text-sm text-neutral-300">{{ trim($replay['game'].' '.$replay['game_version']) }}</p>
        <ul class="flex flex-wrap items-center gap-x-3 gap-y-1 text-sm text-neutral-400">
            <li><x-ui.date-time :value="$replay['started_at']" /></li>
            <li><span class="sr-only">Length </span>{{ $length }}</li>
            <li>{{ $replay['player_count'] === 1 ? '1 player' : $replay['player_count'].' players' }}</li>
            @unless ($replay['decided'])
                <li>No winner</li>
            @endunless
            @if ($replay['analysis'])
                <li class="rounded border border-neutral-700 px-2 text-neutral-200">Analysis</li>
            @endif
            @if ($replay['track'])
                <li class="rounded border border-neutral-700 px-2 text-neutral-200">Track</li>
            @endif
        </ul>
    </div>

    <div class="col-span-2 grid grid-cols-[repeat(auto-fill,minmax(min(15rem,100%),1fr))] content-start gap-3 sm:col-span-1 sm:col-start-2">
        @foreach ($replay['sides'] as $side)
            <section class="flex min-w-0 flex-col border-l-2 pl-2 {{ $side['won'] ? 'border-amber-500' : 'border-neutral-800' }}">
                <h3 class="flex flex-wrap items-baseline gap-x-2 text-sm uppercase tracking-wide text-neutral-400">
                    {{ $side['number'] === null ? 'No team' : 'Team '.$side['number'] }}
                    @if ($side['won'])
                        <span class="text-amber-400">Winner</span>
                    @endif
                </h3>
                <ul class="flex flex-col">
                    @foreach ($side['players'] as $player)
                        <li class="flex min-w-0 flex-wrap items-baseline gap-x-2 text-sm">
                            @if ($player['ai'])
                                <span class="break-all py-0.5 text-neutral-100">{{ $player['name'] }} (AI)</span>
                            @else
                                <a class="break-all py-0.5 text-neutral-100 underline-offset-4 hover:underline active:underline {{ $focus }}" href="{{ ReplayList::playerHref($player['name']) }}">{{ $player['name'] }}</a>
                            @endif
                            @if ($player['faction'] !== null)
                                <span class="break-all text-neutral-400">{{ $player['faction'] }}</span>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </section>
        @endforeach
    </div>
</article>
