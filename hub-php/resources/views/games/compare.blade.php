@extends('layouts.app')

@use('App\Domain\Games\Js')
@use('App\Domain\Games\UnitRelease')
@use('App\Support\Ui')

@php
    $link = 'underline-offset-4 hover:underline active:underline';
    $h1 = 'text-3xl font-semibold tracking-tight';
    $countLabel = fn (int $n, string $one, string $many) => $n.' '.($n === 1 ? $one : $many);
    $compareHref = fn (string $other, string $of, ?string $release = null) => '/games/'.$shortname.'/units/'.Js::encodeURIComponent($of).'/compare?with='.Js::encodeURIComponent($other).($release ? '&release='.Js::encodeURIComponent($release) : '');

    if ($mode !== 'releases') {
        $kind = $resolved['kind'];
        $picker = fn () => view('components.games.compare-picker', [
            'game' => $shortname, 'unit' => $unit, 'current' => $pickerCurrent,
            'release' => $askedRelease ?: null, 'label' => $pickerLabel,
        ])->render();
    }
    if (in_array($mode, ['units', 'units-no-release'], true)) {
        $nameOf = fn (array $side) => $side['full_name'] ?? $side['unit_name'];
        $swap = '<a href="'.e($compareHref($unit, $other['name'], $pick['release'])).'" class="'.e(Ui::button('ghost', 'md')).'">Swap sides</a>';
    }
@endphp

@section('content')
<main id="main-content" tabindex="-1" class="relative flex-1">
    <div class="mx-auto flex w-full max-w-5xl flex-col gap-8 px-6 py-12">
        <x-games.breadcrumb :crumbs="[
            ['label' => 'Games', 'href' => '/games'],
            ['label' => $gameName, 'href' => '/games/'.$shortname],
            ['label' => 'Units', 'href' => '/games/'.$shortname.'/units'],
            ['label' => $unitLabel, 'href' => '/games/'.$shortname.'/units/'.$unit],
            ['label' => 'Compare'],
        ]" />

        @if ($mode === 'releases')
            <div class="flex flex-col gap-2">
                <h1 class="{{ $h1 }}">{{ $left }} vs {{ $right }}</h1>
                <p class="text-neutral-400">{{ $changed === 0 ? 'Every stat these two releases share reads the same.' : $countLabel($changed, 'stat differs', 'stats differ').' between these releases.' }}</p>
            </div>

            <x-games.compare-table :rows="$comparison['rows']">
                <x-slot:left>{{ $left }}</x-slot:left>
                <x-slot:right>{{ $right }}</x-slot:right>
            </x-games.compare-table>

            @if (! $comparison['left']['found'] || ! $comparison['right']['found'])
                <p class="text-sm text-neutral-400">A dash means this release has no record of the unit. It may not have shipped yet, or the hub may never have been told about it.</p>
            @endif

        @elseif ($mode === 'units-unresolved')
            <h1 class="{{ $h1 }}">Compare {{ $unitLabel }} with another unit</h1>
            <div class="flex flex-col gap-3 text-neutral-300">
                @if ($kind === 'none')
                    <p>Choose a unit from {{ $gameName }} to compare with {{ $unitLabel }}.</p>
                @endif
                @if ($kind === 'self')
                    <p>That is the unit you are already looking at. Choose a different one.</p>
                @endif
                @if ($kind === 'unknown')
                    <p>{{ $gameName }} has no unit called &quot;{{ $resolved['asked'] }}&quot;. Check the spelling or pick one from the list.</p>
                @endif
                @if ($kind === 'ambiguous')
                    <p>More than one unit has that name. Choose one.</p>
                    <ul class="flex flex-col gap-1">
                        @foreach ($resolved['choices'] as $choice)
                            <li><a href="{{ $compareHref($choice['name'], $unit, $askedRelease) }}" class="{{ $link }}">{{ $choice['label'] }}</a> <span class="font-mono text-sm text-neutral-400">{{ $choice['name'] }}</span></li>
                        @endforeach
                    </ul>
                @endif
            </div>
            {!! $picker() !!}

        @elseif ($mode === 'units-unread')
            <p class="text-sm text-red-400">The catalog could not be read just now. Try again in a moment.</p>

        @elseif ($mode === 'units-no-release')
            <h1 class="{{ $h1 }}">{{ $nameOf($a) }} vs {{ $nameOf($b) }}</h1>
            <div class="flex flex-col gap-3 text-neutral-300">
                @if ($pick['unknownRelease'])
                    <p>The hub holds no release called &quot;{{ $askedRelease }}&quot;.</p>
                @endif
                @php
                    $lacking = $pick['missing'] ? array_values(array_filter([$pick['missing']['a'] ? $nameOf($a) : null, $pick['missing']['b'] ? $nameOf($b) : null])) : [];
                @endphp
                @if ($lacking !== [])
                    <p>{{ implode(' and ', $lacking) }} {{ count($lacking) === 1 ? 'is' : 'are' }} not in release {{ $askedRelease }}.</p>
                @endif
                @if ($pick['both'] === [])
                    <p>No release holds both {{ $nameOf($a) }} and {{ $nameOf($b) }}, so there is nothing to compare them at.</p>
                @else
                    <p>Releases that hold both:</p>
                    <x-games.release-links :shortname="$shortname" :unit="$unit" :other="$other['name']" :releases="$pick['both']" />
                @endif
            </div>
            <div class="flex flex-col gap-4 border-t border-neutral-900 pt-6">
                {!! $picker() !!}
                <div>{!! $swap !!}</div>
            </div>

        @else
            <div class="flex flex-col gap-2">
                <h1 class="{{ $h1 }}">{{ $nameOf($a) }} vs {{ $nameOf($b) }}</h1>
                <p class="text-neutral-400">At release {{ $release }}. {{ $changed === 0 ? 'Every stat these two units share reads the same.' : $countLabel($changed, 'stat differs', 'stats differ').' between them.' }}</p>
            </div>

            @php
                // A unit's name at the release: that revision's, then its current one.
                $nameAt = fn (array $side) => collect($side['revisions'])->firstWhere('version', $release)['full_name'] ?? $side['full_name'] ?? $side['unit_name'];
            @endphp
            <x-games.compare-table :rows="$rows">
                <x-slot:left><x-games.compare-head :shortname="$shortname" :unit="$a['unit_name']" :label="$nameAt($a)" :picture="$pictures[$a['unit_name']] ?? null" :release="$release" /></x-slot:left>
                <x-slot:right><x-games.compare-head :shortname="$shortname" :unit="$b['unit_name']" :label="$nameAt($b)" :picture="$pictures[$b['unit_name']] ?? null" :release="$release" /></x-slot:right>
            </x-games.compare-table>
            <p class="text-sm text-neutral-400">A dash means that unit has no value for the stat in this release.</p>

            @if (count($pick['both']) > 1)
                <section class="flex flex-col gap-3" aria-labelledby="compare-release">
                    <h2 id="compare-release" class="text-sm uppercase tracking-wide text-neutral-400">Release</h2>
                    <x-games.release-links :shortname="$shortname" :unit="$unit" :other="$other['name']" :releases="$pick['both']" :current="$release" />
                </section>
            @endif

            <div class="flex flex-col gap-4 border-t border-neutral-900 pt-6">
                {!! $picker() !!}
                <div>{!! $swap !!}</div>
            </div>
        @endif
    </div>
</main>
@endsection
