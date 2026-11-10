{{-- One changed unit and what moved on it, ChangesView.tsx ChangedUnit. --}}
@props(['game', 'unit', 'from', 'to', 'labels', 'factionNames'])
@use('App\Domain\Games\Js')
@php
    $link = 'underline-offset-4 hover:underline active:underline';
    $compare = '/games/'.$game.'/units/'.Js::encodeURIComponent($unit['unit_name']).'/compare?left='.Js::encodeURIComponent($from).'&right='.Js::encodeURIComponent($to);
    $side = fn (?string $key) => $key ? ($factionNames[$key] ?? $key) : 'none';
    $builds = function (array $names) use ($labels, $game, $link) {
        $parts = [];
        foreach ($names as $index => $name) {
            $known = $labels[$name] ?? null;
            $parts[] = '<span>'.($index > 0 ? ', ' : '').($known
                ? '<a href="/games/'.e($game).'/units/'.e(Js::encodeURIComponent($known['name'])).'" class="'.$link.'">'.e($known['label']).'</a>'
                : e($name)).'</span>';
        }

        return new Illuminate\Support\HtmlString(implode('', $parts));
    };
@endphp
<div class="flex flex-col gap-2">
    <div class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
        <x-games.change-unit-link :game="$game" :unit="$unit" />
        <a href="{{ $compare }}" class="text-xs text-neutral-400 {{ $link }}">Compare releases<span class="sr-only"> of {{ $unit['full_name'] ?? $unit['unit_name'] }}</span></a>
    </div>
    <dl class="grid grid-cols-[minmax(7rem,max-content)_1fr] gap-x-4 gap-y-1 text-sm">
        @if ($unit['renamed'])
            <dt class="text-neutral-400">Name</dt>
            <dd class="break-words text-neutral-100">{{ $unit['renamed']['from'] ?? '-' }} to {{ $unit['renamed']['to'] ?? '-' }}</dd>
        @endif
        @if ($unit['faction'])
            <dt class="text-neutral-400">Faction</dt>
            <dd class="break-words text-neutral-100">{{ $side($unit['faction']['from']) }} to {{ $side($unit['faction']['to']) }}</dd>
        @endif
        @foreach ($unit['stats'] as $stat)
            <div class="contents">
                <dt class="text-neutral-400">{{ $stat['label'] }}</dt>
                <dd class="break-all text-neutral-100">{{ $stat['from'] }} to {{ $stat['to'] }}</dd>
            </div>
        @endforeach
        @if ($unit['builds']['gained'] !== [])
            <dt class="text-neutral-400">Can now build</dt>
            <dd class="text-neutral-100">{{ $builds($unit['builds']['gained']) }}</dd>
        @endif
        @if ($unit['builds']['lost'] !== [])
            <dt class="text-neutral-400">Can no longer build</dt>
            <dd class="text-neutral-100">{{ $builds($unit['builds']['lost']) }}</dd>
        @endif
    </dl>
</div>
