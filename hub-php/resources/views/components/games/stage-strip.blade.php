{{-- StageStrip in components/UnitStages.tsx. The levels of a unit that turns
     into another, each linking to its own page. Conditions print as the game
     spelled them. --}}
@props(['game', 'stages', 'pictures'])
@use('App\Domain\Games\Stats')
@php
    $nameOf = [];
    foreach ($stages as $stage) {
        $nameOf[$stage['unit_name']] = $stage['label'];
    }
    $link = 'underline-offset-4 hover:underline active:underline';
@endphp
<ol class="flex flex-col gap-2">
    @foreach ($stages as $index => $stage)
        @php
            $picture = $pictures[$stage['unit_name']] ?? null;
            $conditions = $stage['conditions'];
            $previous = $stage['from'] ? ($nameOf[$stage['from']] ?? $stage['from']) : null;
        @endphp
        <li class="flex flex-col gap-2 rounded-md border p-3 sm:flex-row sm:items-start sm:gap-4 {{ $stage['current'] ? 'border-neutral-600 bg-neutral-900' : 'border-neutral-900 bg-card' }}">
            @if ($picture && $picture['from'] !== 'placeholder')
                <img src="{{ $picture['url'] }}" alt="" width="{{ $picture['width'] }}" height="{{ $picture['height'] }}" loading="lazy" decoding="async" class="size-12 shrink-0 object-contain"/>
            @endif

            <div class="flex min-w-0 flex-1 flex-col gap-1.5">
                <div class="flex flex-wrap items-baseline gap-x-2 gap-y-1">
                    <span class="text-sm text-neutral-400">Stage {{ $index + 1 }}</span>
                    <a href="/games/{{ $game }}/units/{{ \App\Domain\Games\UnitRelease::unitSegment($stage['unit_name']) }}" class="text-sm text-neutral-100 {{ $link }}">{{ $stage['label'] }}</a>
                    @if ($stage['current'])
                        <span class="text-sm text-neutral-400">showing this one</span>
                    @endif
                    @if ($stage['removed_at'])
                        <span class="text-sm text-neutral-400">retired</span>
                    @endif
                    @if (! $stage['found'])
                        <span class="text-sm text-neutral-400">not in this release</span>
                    @endif
                </div>

                @if ($previous && $conditions !== [])
                    <p class="text-sm text-neutral-400">Reached from {{ $previous }} for {{ collect($conditions)->map(fn ($value, $name) => Stats::statLabel((string) $name).' '.Stats::formatStatValue($value))->implode(', ') }}.</p>
                @elseif ($previous)
                    <p class="text-sm text-neutral-400">Reached from {{ $previous }}, on terms the extraction did not report.</p>
                @endif

                @if ($stage['unlocks'] !== [])
                    <p class="text-sm text-neutral-400">Unlocks @foreach ($stage['unlocks'] as $position => $unlocked)<span>@if ($position > 0), @endif<a href="/games/{{ $game }}/units/{{ \App\Domain\Games\UnitRelease::unitSegment($unlocked['name']) }}" class="text-neutral-300 {{ $link }}">{{ $unlocked['label'] }}</a></span>@endforeach.</p>
                @endif
            </div>
        </li>
    @endforeach
</ol>
