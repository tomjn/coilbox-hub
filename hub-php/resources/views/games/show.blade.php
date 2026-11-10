@extends('layouts.app', [
    'title' => $title,
    'description' => $metaDescription,
    'ogType' => 'article',
])

@use('App\Domain\Assets\Cdn')
@use('App\Domain\Games\Download')
@use('App\Domain\Games\Js')
@use('App\Domain\Games\Labels')
@use('App\Domain\Games\RichText')
@use('App\Support\Ui')

@php
    // The card style the games listing uses, so the ways on from this page
    // read as the same kind of thing as the cards that led here.
    $cardClass = 'group flex h-full rounded-md border border-neutral-800 bg-card transition-colors hover:border-neutral-600 active:border-neutral-500';
    $control = Ui::button('ghost', 'md');
    $slot = 'size-12 shrink-0 rounded';
    $link = 'text-neutral-300 underline-offset-4 hover:underline active:underline';
@endphp

@section('content')
<main id="main-content" tabindex="-1" class="relative flex-1">
    @if ($banner)
        <img src="{{ $banner }}" alt="" decoding="async" class="h-40 w-full object-cover sm:h-56"/>
        <x-games.backdrop.games-blank />
    @else
        <x-games.backdrop.games />
    @endif
    <div class="relative z-10 mx-auto flex w-full max-w-5xl flex-col gap-8 px-6 py-12">
        <div class="flex flex-col gap-3">
            <x-games.breadcrumb :crumbs="[['label' => 'Games', 'href' => '/games'], ['label' => $title]]" />
            <div class="flex items-center gap-4">
                <x-games.logo :src="$logo" :alt="$title.' logo'" eager />
                <h1 class="min-w-0 break-words text-3xl font-semibold tracking-tight text-balance">{{ $title }}</h1>
            </div>
            @if ($describes)
                <div class="flex flex-col gap-3">{!! RichText::paragraphsHtml($page['description'], 'max-w-prose text-neutral-300') !!}</div>
            @endif
            @if ($page['base_game'])
                <p class="text-sm text-neutral-400" data-testid="base-game">Mutator of @if ($page['base'])<a class="{{ $link }}" href="/games/{{ $page['base']['shortname'] }}">{{ $page['base']['title'] }}</a>@else<span class="text-neutral-300">{{ $page['base_game'] }}</span>@endif</p>
            @endif
            @if ($page['release'])
                <p class="text-sm text-neutral-400">Game version {{ $page['release'] }}</p>
            @endif
            @if ($page['downloads'] !== [])
                {{-- In the order to try them, the order coilbox walks them. A
                     rapid tag is text, because there is nowhere for it to go. --}}
                <ul class="flex flex-wrap items-center gap-3">
                    @foreach ($page['downloads'] as $download)
                        @php $href = Download::downloadHref($download); @endphp
                        @if ($href === null)
                            <li class="text-sm text-neutral-400">Install with rapid: <code class="rounded bg-neutral-900 px-2 py-1 font-mono text-neutral-200">{{ $download['value'] }}</code></li>
                        @else
                            <li class="text-sm"><a href="{{ $href }}" class="{{ $control }}">{{ $download['kind'] === 'github' ? 'Releases on GitHub' : 'Download this game' }}</a></li>
                        @endif
                    @endforeach
                </ul>
            @endif
        </div>

        <section class="flex flex-col gap-3" aria-labelledby="game-factions">
            <h2 id="game-factions" class="text-sm uppercase tracking-wide text-neutral-400">Factions</h2>
            @if ($page['factions'] !== [])
                <ul class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach ($page['factions'] as $faction)
                        @php
                            $commander = $commanders[$faction['key']] ?? null;
                            $hasPicture = $faction['logo_path'] !== null || $commander !== null || $factionSlots;
                        @endphp
                        <li>
                            <a class="{{ $cardClass }} items-center gap-3 {{ $hasPicture ? 'p-2 pr-4' : 'px-4 py-3' }}" href="/games/{{ $page['shortname'] }}/units?faction={{ Js::encodeURIComponent($faction['key']) }}">
                                @if ($faction['logo_path'] !== null)
                                    <img src="{{ Cdn::staticTierUrl($faction['logo_path']) }}" alt="" width="48" height="48" loading="lazy" decoding="async" class="{{ $slot }} bg-black object-contain"/>
                                @elseif ($commander !== null)
                                    <x-games.commander-picture :commander="$commander" :class="$slot.' bg-black'" />
                                @elseif ($factionSlots)
                                    <span aria-hidden="true" class="{{ $slot }} flex items-center justify-center bg-neutral-900"><x-icon name="games" class="w-5 text-neutral-600" /></span>
                                @endif
                                <span class="min-w-0 break-words font-medium text-neutral-100 transition-colors group-hover:text-white group-active:text-white">{{ $faction['name'] }}</span>
                            </a>
                        </li>
                    @endforeach
                </ul>
            @else
                <p class="text-sm text-neutral-400">Nobody has reported this game&rsquo;s sides yet.</p>
            @endif
        </section>

        <section class="flex flex-col gap-3" aria-labelledby="game-explore">
            <h2 id="game-explore" class="text-sm uppercase tracking-wide text-neutral-400">Explore</h2>
            <ul class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($onward as $way)
                    <li>
                        <a class="{{ $cardClass }} flex-col gap-1 p-4" href="{{ $way['href'] }}">
                            <span class="font-medium text-neutral-100 transition-colors group-hover:text-white group-active:text-white">{{ $way['name'] }}</span>
                            <span class="text-sm text-pretty text-neutral-400">{{ $way['detail'] }}</span>
                        </a>
                    </li>
                @endforeach
            </ul>
        </section>

        @if ($page['mutators'] !== [])
            <section class="flex flex-col gap-3" aria-labelledby="game-mutators">
                <h2 id="game-mutators" class="text-sm uppercase tracking-wide text-neutral-400">Mutators</h2>
                <ul class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach ($page['mutators'] as $mutator)
                        <li>
                            <a class="{{ $cardClass }} items-center px-4 py-3" href="/games/{{ $mutator['shortname'] }}">
                                <span class="min-w-0 break-words font-medium text-neutral-100 transition-colors group-hover:text-white group-active:text-white">{{ $mutator['title'] }}</span>
                            </a>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif

        {{-- Last, because they leave the hub. Each carries the outbound arrow. --}}
        @if ($page['links'] !== [])
            <section class="flex flex-col gap-3" aria-labelledby="game-links">
                <h2 id="game-links" class="text-sm uppercase tracking-wide text-neutral-400">Links</h2>
                <ul class="flex flex-wrap gap-2">
                    @foreach ($page['links'] as $gameLink)
                        <li>
                            <a href="{{ $gameLink['url'] }}" rel="noopener noreferrer" class="{{ Ui::button('ghost', 'md', 'flex items-center gap-2') }}">{{ $gameLink['label'] }}<x-icon name="external" class="w-3.5 text-neutral-400" /></a>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif

        <p class="text-sm text-neutral-400"><a class="{{ $link }}" href="/games">Every game the hub knows about</a>.</p>
    </div>
</main>
@endsection
