@extends('layouts.app', [
    'title' => $title,
    'description' => $metaDescription,
])

@php
    $link = 'text-neutral-300 underline-offset-4 hover:underline active:underline';
@endphp

@section('content')
<main id="main-content" tabindex="-1" class="relative flex-1">
    <div class="mx-auto flex w-full max-w-5xl flex-col gap-8 px-6 py-12">
        <x-games.breadcrumb :crumbs="[['label' => $gameTitle, 'href' => '/games/'.$shortname], ['label' => 'Build tree']]" />

        <div class="flex flex-col gap-2">
            <h1 class="text-3xl font-semibold tracking-tight">Build tree</h1>
            <p class="text-neutral-400">What each faction can reach, starting from its start unit. Open a unit to see what it builds.</p>
        </div>

        @if ($toggles)
            <x-games.faction-toggles :options="$toggles" />
        @endif

        @if ($tree['factions'] === [] && $tree['ungrouped'] === [])
            <p class="text-sm text-neutral-400">Nobody has reported this game&rsquo;s units yet.</p>
        @else
            <div class="flex flex-col gap-8">
                @foreach ($tree['factions'] as $block)
                    @php $rootNode = null; foreach ($block['units'] as $unit) { if ($unit['name'] === $block['root']) { $rootNode = $unit; break; } } @endphp
                    @if ($rootNode)
                        <x-games.tree-block :game="$shortname" :heading="$block['label']" :note="count($block['units']).' units'" :roots="[$rootNode]" :by-name="$byName" :pictures="$pictures" :claims="$claims" />
                    @endif
                @endforeach
                @if ($tree['ungrouped'] !== [])
                    <x-games.tree-block :game="$shortname" heading="No faction reaches these" :note="count($tree['ungrouped']).' units'" :roots="$tree['ungrouped']" :by-name="$byName" :pictures="$pictures" :claims="$claims" />
                @endif
            </div>
        @endif

        <p class="text-sm text-neutral-400">Facts as of @if ($version)release {{ $version }}.@else{{ '' }}the latest reports. <a href="/games/{{ $shortname }}" class="{{ $link }}">Back to the game</a>.@endif</p>
    </div>
</main>
@endsection
