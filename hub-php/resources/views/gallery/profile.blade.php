@extends('layouts.app')

{{-- app/profile/[id]/page.tsx with components/ContributorSections.tsx. --}}
@php
    $count = fn (int $n, string $one, string $many) => $n.' '.($n === 1 ? $one : $many);
    $link = 'text-sm text-neutral-200 underline-offset-4 hover:underline active:underline';
    ['maps' => $maps, 'pictures' => $pictures, 'games' => $games, 'items' => $items] = $contributions;
    $sections = [
        ['title' => 'Maps', 'summary' => $maps['count'] === 0 ? 'No maps yet.' : 'Sent the facts for '.$count($maps['count'], 'map', 'maps').". The mapper is credited on each map's own page.",
            'links' => array_map(fn ($m) => ['key' => $m['slug'], 'href' => '/map/'.$m['slug'], 'label' => $m['label']], $maps['recent'])],
        ['title' => 'Pictures', 'summary' => $pictures['count'] === 0 ? 'No approved pictures yet.' : 'Uploaded '.$count($pictures['count'], 'picture', 'pictures').' of units and maps that a moderator has approved.',
            'links' => null],
        ['title' => 'Games', 'summary' => $games['count'] === 0 ? 'No games yet.' : 'Sent the facts for '.$count($games['count'], 'game', 'games').'.',
            'links' => array_map(fn ($g) => ['key' => $g['shortname'], 'href' => '/games/'.$g['shortname'], 'label' => $g['label']], $games['recent'])],
        ['title' => 'Gallery', 'summary' => $items['count'] === 0 ? 'Nothing published yet.' : 'Published '.$count($items['count'], 'item', 'items').'.',
            'links' => array_map(fn ($i) => ['key' => $i['id'], 'href' => '/item/'.$i['id'], 'label' => $i['title']], $items['recent']), 'all' => true],
    ];
@endphp

@section('content')
<main id="main-content" tabindex="-1" class="relative flex-1">
    <x-ui.art-backdrop drawing="games" :strength="0.09" />
    <div class="relative z-10 mx-auto flex w-full max-w-2xl flex-col gap-8 px-6 py-12">
        <h1 class="break-words text-3xl font-semibold tracking-tight">{{ $contributions['name'] }}</h1>
        <div class="flex flex-col gap-8">
            @foreach ($sections as $section)
                <section class="flex flex-col gap-3 border-t border-neutral-900 pt-6">
                    <div class="flex flex-col gap-1">
                        <h2 class="text-lg font-medium">{{ $section['title'] }}</h2>
                        <p class="text-sm text-neutral-400">{{ $section['summary'] }}</p>
                    </div>
                    @if (! empty($section['links']))
                        <ul class="flex flex-col gap-1.5">
                            @foreach ($section['links'] as $entry)
                                <li class="break-words"><a href="{{ $entry['href'] }}" class="{{ $link }}">{{ $entry['label'] }}</a></li>
                            @endforeach
                        </ul>
                        @if (! empty($section['all']))
                            <a href="/gallery?account={{ $accountId }}" class="{{ $link }}">See everything they have published</a>
                        @endif
                    @endif
                </section>
            @endforeach
        </div>
    </div>
</main>
@endsection
