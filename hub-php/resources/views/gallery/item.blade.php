@extends('layouts.app')

{{-- app/item/[id]/ItemBody.tsx. Drawn from the cached public row for
     everybody, and from the visitor's own read on the withdrawn page. The
     owner note and the feature switch are only for the people they name. --}}
@php
    use App\Domain\Gallery\Labels;
    use App\Domain\Gallery\Previews;
    $shareUrl = $origin.'/i/'.$item['id'];
    $project = $item['kind'] === 'mod-project';
    $link = 'hover:text-white active:text-white';
    $dt = 'text-xs uppercase tracking-wide text-neutral-400';
    $minimap = $pictures['map'] !== null;
@endphp

@push('head')
    @vite('resources/js/gallery/index.ts')
@endpush

@section('content')
<main id="main-content" tabindex="-1" class="relative flex-1">
    <x-ui.art-backdrop :drawing="$art['drawing']" :strength="$art['strength']" />
    <div class="relative z-10 mx-auto flex w-full max-w-3xl flex-col gap-8 px-6 py-12">
        <div class="flex flex-col gap-3">
            <a href="/gallery?kind={{ $item['kind'] }}" class="flex items-center gap-1.5 self-start rounded border border-neutral-800 px-2 py-1 text-xs text-neutral-300 transition-colors hover:border-neutral-600 active:border-neutral-500 hover:text-white active:text-white"><x-gallery.kind-icon :kind="$item['kind']" :mode="$item['mode']" class="w-3.5" />{{ Labels::item($item['kind'], $item['mode']) }}</a>
            <h1 class="break-words text-3xl font-semibold tracking-tight">{{ $item['title'] }}</h1>
            @if ($item['description'])
                <p class="whitespace-pre-wrap text-neutral-400">{{ $item['description'] }}</p>
            @endif
            @if ($project)
                {{-- A project's changes can run to two hundred rows, so these jump past them. --}}
                <p class="flex flex-wrap gap-x-4 gap-y-1 text-sm">
                    <a href="#coilbox" class="py-1 text-neutral-300 underline underline-offset-4 hover:text-white active:text-white">Open it in Coilbox</a>
                    @if ($commands !== null && ($commands['lines'] ?? []) !== [])
                        <a href="#lobby" class="py-1 text-neutral-300 underline underline-offset-4 hover:text-white active:text-white">Autohost commands</a>
                    @endif
                </p>
            @endif
        </div>

        @if ($item['kind'] === 'setup-pack')
            <x-gallery.setup-pack-contents :item="$item" :maps="$packMaps" :units="$pictures['units']" :roles="$roles" />
        @elseif ($minimap)
            {{-- Where and who side by side. empty:hidden because a kind can draw no preview. --}}
            <div class="flex flex-col gap-6 sm:flex-row sm:items-start">
                <div class="w-full shrink-0 sm:w-80">
                    <x-gallery.map-minimap :name="$item['map_name'] ?? ''" :picture="$pictures['map']" :note="Previews::startPosNote($item['kind'], $item['container'])" :catalog="$item['map_name'] ? ($pictures['catalog'][$item['map_name']] ?? null) : null" />
                </div>
                <div class="min-w-0 flex-1 empty:hidden"><x-gallery.item-preview :item="$item" :units="$pictures['units']" :names="$names" :roles="$roles" /></div>
            </div>
        @else
            <x-gallery.item-preview :item="$item" :units="$pictures['units']" :names="$names" :roles="$roles" />
        @endif

        {{-- Coilbox first: it applies every edit, the commands only what a lobby carries. --}}
        <div @if ($commands !== null) class="relative left-1/2 grid w-[calc(100vw-3rem)] -translate-x-1/2 grid-cols-[repeat(auto-fit,minmax(min(100%,28rem),1fr))] items-start gap-8" @endif>
            <div id="coilbox" class="flex min-w-0 flex-col gap-4 rounded-md border border-neutral-800 bg-card p-5">
                @if ($commands !== null)
                    <div class="flex flex-col gap-1.5">
                        <h2 class="text-lg font-semibold tracking-tight">Open it in Coilbox</h2>
                        <p class="text-sm text-neutral-400">{{ $project ? 'Coilbox applies every edit, to any game it runs, and lets you change them.' : 'Coilbox sets the whole thing up, colours and factions included, and lets you change it.' }}</p>
                    </div>
                @endif
                <x-ui.import-link :share-url="$shareUrl" variant="solid" />
                <div class="flex flex-col gap-1.5">
                    <span class="text-xs text-neutral-400">Or share this link. It opens in Coilbox and needs no account.</span>
                    <code class="break-all rounded border border-neutral-800 bg-black px-3 py-2 text-xs text-neutral-400">{{ $shareUrl }}</code>
                </div>
            </div>
            @if ($commands !== null)
                <x-gallery.lobby-commands :commands="$commands" />
            @endif
        </div>

        @if ($controls['owner'])
            <div class="flex items-center justify-between rounded-md border border-neutral-800 bg-card px-5 py-3 text-sm">
                <span class="text-neutral-400">{{ $controls['withdrawn'] ? 'You have withdrawn this.' : 'This is yours.' }}</span>
                <a href="/item/{{ $item['id'] }}/edit" class="text-neutral-300 underline-offset-4 hover:underline active:underline">Edit or withdraw</a>
            </div>
        @endif
        @if ($controls['moderator'])
            <div class="flex items-center justify-between gap-3 rounded-md border border-neutral-800 bg-card px-5 py-3 text-sm">
                <span class="text-neutral-400">{{ $item['featured_at'] ? 'Featured, so the gallery lists this first.' : 'Not featured.' }}</span>
                <form method="post" action="/item/{{ $item['id'] }}/feature" class="flex flex-wrap items-center justify-end gap-2">
                    @csrf
                    <input type="hidden" name="id" value="{{ $item['id'] }}">
                    <input type="hidden" name="featured" value="{{ $item['featured_at'] ? 'false' : 'true' }}">
                    <button type="submit" class="{{ App\Support\Ui::button('ghost', 'md') }}">{{ $item['featured_at'] ? 'Unfeature' : 'Feature' }}</button>
                    @if (session('featureState'))
                        <p role="{{ session('featureState')['ok'] ? 'status' : 'alert' }}" class="w-full text-sm {{ session('featureState')['ok'] ? 'text-neutral-300' : 'text-red-400' }}">{{ session('featureState')['message'] }}</p>
                    @endif
                </form>
            </div>
        @endif

        <dl class="grid grid-cols-2 gap-6 border-t border-neutral-900 pt-6 sm:grid-cols-4">
            <div class="flex flex-col gap-1"><dt class="{{ $dt }}">Published by</dt><dd class="text-sm text-neutral-100"><a href="/gallery?author={{ rawurlencode($item['author_name']) }}" class="{{ $link }}">{{ $item['author_name'] }}</a></dd></div>
            <div class="flex flex-col gap-1"><dt class="{{ $dt }}">Published</dt><dd class="text-sm text-neutral-100"><x-ui.date-time :value="$item['created_at']" /></dd></div>
            @if ($item['import_count'] > 0)
                {{-- Zero is left out (#51): only recent Coilbox releases send the ping. --}}
                <div class="flex flex-col gap-1"><dt class="{{ $dt }}">Imported via hub link</dt><dd class="text-sm text-neutral-100">{{ $item['import_count'] }}</dd></div>
            @endif
            @if ($item['game_name'])
                {{-- "Made with" on a project: the build the author had, not one a player needs (#419). --}}
                <div class="flex flex-col gap-1"><dt class="{{ $dt }}">{{ $project ? 'Made with' : 'Game' }}</dt><dd class="text-sm text-neutral-100">
                    @if ($item['game_key'])<a href="/gallery?game={{ rawurlencode($item['game_key']) }}" class="{{ $link }}">{{ $item['game_name'] }}</a>@else{{ $item['game_name'] }}@endif
                </dd></div>
            @endif
            @if ($item['map_name'])
                <div class="flex flex-col gap-1"><dt class="{{ $dt }}">Map</dt><dd class="text-sm text-neutral-100"><a href="/gallery?map={{ rawurlencode($item['map_name']) }}" class="{{ $link }}">{{ $item['map_name'] }}</a></dd></div>
            @endif
        </dl>

        @if ($item['tags'] !== [])
            <ul class="flex flex-wrap gap-1.5">
                @foreach ($item['tags'] as $tag)
                    <li><a href="/gallery?tag={{ rawurlencode($tag) }}" class="inline-block rounded bg-neutral-900 px-2 py-1 text-xs text-neutral-400 transition-colors hover:text-neutral-200 active:text-neutral-200">{{ $tag }}</a></li>
                @endforeach
            </ul>
        @endif
        <x-ui.report-button :item-id="$item['id']" />
    </div>
</main>
@endsection
