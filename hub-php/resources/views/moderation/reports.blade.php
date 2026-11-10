@extends('layouts.app', ['title' => 'Reports - Moderation'])
@use('App\Domain\Maps\Labels')
@use('App\Domain\Maps\Report')
@use('App\Support\Ui')
@php
    $card = 'flex flex-col gap-3 rounded-md border border-neutral-800 bg-card p-5';
@endphp

@section('content')
<main id="main-content" tabindex="-1" class="relative flex-1">
    {{-- A handful of reports, so a little stronger than the gallery. --}}
    <x-ui.art-backdrop drawing="archives" :strength="0.08" />
    <x-moderation.nav current="reports" />
    <div class="relative z-10 mx-auto flex w-full max-w-3xl flex-col gap-6 px-6 py-12">
        <h1 class="text-3xl font-semibold tracking-tight">Reports</h1>

        @if ($reports === [])
            <p class="rounded-md border border-neutral-800 bg-card p-6 text-sm text-neutral-400">Nothing waiting.</p>
        @else
            <ul class="flex flex-col gap-4">
                @foreach ($reports as $r)
                    @php $item = $r['item'] ?? null; $map = $r['map'] ?? null; @endphp
                    @if ($r['map_id'])
                        <li class="{{ $card }}">
                            <div class="flex items-baseline justify-between gap-3">
                                @if ($map)
                                    <a href="/map/{{ $map['slug'] }}" class="break-words text-base font-medium hover:underline active:underline">{{ Labels::title($map['display_name'], $map['map_name']) }}</a>
                                @else
                                    <span class="text-base font-medium">A deleted map</span>
                                @endif
                                <span class="text-xs text-neutral-400"><x-ui.date-time :value="$r['created_at']" /></span>
                            </div>
                            <p class="text-xs uppercase tracking-wide text-neutral-400">Map: {{ Report::KINDS[$r['kind']] ?? 'Unknown kind' }}</p>
                            <p class="whitespace-pre-wrap text-sm text-neutral-400">{{ $r['reason'] }}</p>
                            <form method="post" action="/moderation/reports">
                                @csrf
                                <input type="hidden" name="report_id" value="{{ $r['id'] }}">
                                <button type="submit" class="{{ Ui::button() }}">Mark handled</button>
                            </form>
                        </li>
                    @else
                        <li class="{{ $card }}">
                            <div class="flex items-baseline justify-between gap-3">
                                <a href="{{ $item && $item['deleted_at'] ? '/item/'.$item['id'].'/withdrawn' : '/item/'.($item['id'] ?? '') }}" class="text-base font-medium hover:underline active:underline">{{ $item['title'] ?? 'A deleted item' }}</a>
                                <span class="text-xs text-neutral-400"><x-ui.date-time :value="$r['created_at']" /></span>
                            </div>
                            <p class="whitespace-pre-wrap text-sm text-neutral-400">{{ $r['reason'] }}</p>
                            <div class="flex gap-3">
                                @unless ($item && $item['deleted_at'])
                                    <form method="post" action="/moderation/reports">
                                        @csrf
                                        <input type="hidden" name="report_id" value="{{ $r['id'] }}">
                                        <input type="hidden" name="item_id" value="{{ $r['item_id'] }}">
                                        <input type="hidden" name="withdraw" value="true">
                                        <button type="submit" class="{{ Ui::button() }}">Withdraw it</button>
                                    </form>
                                @endunless
                                <form method="post" action="/moderation/reports">
                                    @csrf
                                    <input type="hidden" name="report_id" value="{{ $r['id'] }}">
                                    <input type="hidden" name="item_id" value="{{ $r['item_id'] }}">
                                    <button type="submit" class="rounded-md px-4 py-2 text-sm text-neutral-400 transition-colors hover:text-neutral-300 active:text-neutral-300">Leave it</button>
                                </form>
                            </div>
                        </li>
                    @endif
                @endforeach
            </ul>
        @endif

        <section class="flex flex-col gap-3 border-t border-neutral-900 pt-6" aria-labelledby="mod-featured">
            <h2 id="mod-featured" class="text-sm uppercase tracking-wide text-neutral-400">Featured</h2>
            <p class="text-sm text-neutral-400">A featured item sits above the rest of the gallery, whatever the reader has sorted or filtered by. Feature one from its own page.</p>
            @if (session('featureState'))
                <p role="{{ session('featureState')['ok'] ? 'status' : 'alert' }}" class="w-full text-sm {{ session('featureState')['ok'] ? 'text-neutral-300' : 'text-red-400' }}">{{ session('featureState')['message'] }}</p>
            @endif
            @if ($featured !== [])
                <ul class="flex flex-col gap-1.5">
                    @foreach ($featured as $row)
                        <li class="flex items-center justify-between gap-3 text-sm">
                            <a href="/item/{{ $row['id'] }}" class="min-w-0 break-words text-neutral-300 underline-offset-4 hover:underline active:underline">{{ $row['title'] }}</a>
                            {{-- The item page's own feature action, which says how it went. --}}
                            <form method="post" action="/item/{{ $row['id'] }}/feature" class="flex flex-col gap-1.5">
                                @csrf
                                <input type="hidden" name="id" value="{{ $row['id'] }}">
                                <input type="hidden" name="featured" value="false">
                                <button type="submit" class="{{ Ui::button('quiet', 'sm') }}">Unfeature</button>
                            </form>
                        </li>
                    @endforeach
                </ul>
            @else
                <p class="text-sm text-neutral-400">Nothing is featured right now.</p>
            @endif
        </section>
    </div>
</main>
@endsection
