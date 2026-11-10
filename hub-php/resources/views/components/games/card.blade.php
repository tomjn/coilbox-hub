{{-- One game in the catalog listing, components/GameCard.tsx. Card art on
     top where the game has it. Only the name is inside the link, stretched
     over the card, so a screen reader does not announce the whole blurb as
     the link's name. --}}
@props(['game', 'sides' => null, 'eager' => false])
@use('App\Domain\Games\GameArt')
@use('App\Domain\Games\Labels')
@use('App\Domain\Games\RichText')
@php
    $logo = GameArt::fromRow($game, 'logo');
    $card = GameArt::fromRow($game, 'card');
    $describes = $game['description'] !== null && Labels::saysMoreThanName($game, RichText::toPlainText($game['description']));
    $playAs = $sides ? Labels::playAsLabel(array_map(fn ($f) => $f['name'], $sides['factions'])) : null;
    $commanders = [];
    if ($sides) {
        foreach ($sides['factions'] as $faction) {
            if (isset($sides['commanders'][$faction['key']])) {
                $commanders[] = $sides['commanders'][$faction['key']];
            }
        }
    }
    // A game that never reported its start units falls back to a few of its own.
    $units = $commanders !== [] ? $commanders : ($sides['sample'] ?? []);
    $blurb = 'line-clamp-3 text-sm leading-6 text-neutral-400';
@endphp
<li class="group relative flex h-full flex-col gap-4 rounded-md border border-neutral-800 bg-card p-4 transition-colors hover:border-neutral-600 has-[a:active]:border-neutral-500 has-[a:focus-visible]:outline-2 has-[a:focus-visible]:outline-offset-2 has-[a:focus-visible]:outline-neutral-300">
    @if ($card)
        <img src="{{ $card }}" alt="" class="-mx-4 -mt-4 aspect-video w-[calc(100%_+_2rem)] max-w-none rounded-t-md object-cover"/>
    @endif
    <div class="flex items-start gap-4">
        <x-games.logo :src="$logo" alt="" :eager="$eager" />
        <div class="flex min-w-0 flex-col gap-1">
            <h2 class="break-words text-lg font-semibold leading-snug tracking-tight text-balance text-neutral-100 transition-colors group-hover:text-white">
                <a class="after:absolute after:inset-0 after:rounded-md focus-visible:outline-none" href="/games/{{ $game['shortname'] }}">{{ Labels::gameTitle($game) }}</a>
            </h2>
            <p class="text-sm tabular-nums text-neutral-400">
                @foreach (Labels::gameCountParts($game) as $index => $part){{ $index > 0 ? ', ' : '' }}<span class="font-medium text-neutral-100">{{ $part['count'] }}</span> {{ $part['noun'] }}@endforeach
            </p>
        </div>
    </div>
    @if ($describes)
        <p class="{{ $blurb }}">{!! RichText::html(RichText::inline($game['description'])) !!}</p>
    @elseif ($playAs)
        <p class="{{ $blurb }}">{{ $playAs }}</p>
    @endif
    @if ($units !== [])
        <ul aria-hidden="true" class="mt-auto flex -space-x-2.5">
            @foreach ($units as $commander)
                <li><x-games.commander-picture :commander="$commander" class="size-9 bg-black ring-2 ring-[#1e1e23]" /></li>
            @endforeach
        </ul>
    @endif
</li>
