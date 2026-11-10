@extends('layouts.app', ['title' => 'Trail - Moderation'])
@use('App\Domain\Moderation\Trail')
@use('App\Support\Ui')
@php
    $row = 'flex flex-col gap-1 rounded-md border border-neutral-800 bg-card p-4 text-sm';
    $empty = 'rounded-md border border-neutral-800 bg-card p-6 text-sm text-neutral-400';
    $when = fn (string $at) => substr(str_replace('T', ' ', $at), 0, 16);
    $accountLink = fn (string $id, string $label) => '<a href="/moderation/trail?account='.e($id).'" title="'.e($label.' '.$id).'" class="text-neutral-400 underline decoration-neutral-700 underline-offset-2 transition-colors hover:text-neutral-200 active:text-neutral-200">'.e($label.' '.substr($id, 0, 8)).'</a>';
    $pictureLink = fn (string $assetId, string $name, string $detail) => '<a href="/moderation/assets/'.e($assetId).'" class="font-medium text-neutral-200 hover:underline active:underline">'.e($name).($detail !== '' ? '<span class="text-neutral-400"> '.e($detail).'</span>' : '').'</a>';
    // What state a picture is in now, which is what the button acts on.
    $assetState = fn (array $asset) => $asset['moderation'] !== 'rejected'
        ? ($asset['approvalSource'] ? $asset['moderation'].', '.$asset['approvalSource'] : $asset['moderation'])
        : 'rejected, '.$asset['rejectionKind'];
    $events = $trail['events'] ?? $recent;
@endphp

@section('content')
<main id="main-content" tabindex="-1" class="relative flex-1">
    <x-ui.art-backdrop drawing="archives" :strength="0.08" />
    <x-moderation.nav current="pictures" />
    <div class="relative z-10 mx-auto flex w-full max-w-3xl flex-col gap-6 px-6 py-12">
        <div class="flex flex-col gap-1">
            <x-moderation.crumb parent="pictures">Trail</x-moderation.crumb>
            <h1 class="text-3xl font-semibold tracking-tight">Trail</h1>
        </div>

        @if ($trail && $focus)
            <div class="flex flex-wrap items-baseline justify-between gap-3">
                <p class="text-sm text-neutral-400">Everything account <span class="font-mono text-neutral-200">{{ $focus }}</span> is behind, as the uploader or as the one who decided.</p>
                <a href="/moderation/trail" class="text-sm text-neutral-400 transition-colors hover:text-neutral-300 active:text-neutral-300">Everything instead</a>
            </div>

            <h2 class="text-sm text-neutral-400">
                @if ($trail['uploadedTotal'] > count($trail['uploaded']))
                    The {{ count($trail['uploaded']) }} newest of {{ $trail['uploadedTotal'] }} pictures this account uploaded.
                @else
                    {{ $trail['uploadedTotal'] === 1 ? 'One picture this account uploaded.' : $trail['uploadedTotal'].' pictures this account uploaded.' }}
                @endif
            </h2>
            @if ($trail['uploaded'] === [])
                <p class="{{ $empty }}">This account has uploaded nothing.</p>
            @else
                <ul class="flex flex-col gap-3">
                    @foreach ($trail['uploaded'] as $asset)
                        @php $safety = $asset['rejectionKind'] === 'safety'; @endphp
                        <li class="{{ $row }}">
                            <div class="flex flex-wrap items-baseline justify-between gap-3">
                                {!! $pictureLink($asset['id'], $asset['name'], $asset['detail']) !!}
                                <span class="text-xs text-neutral-400">{{ $asset['origin'] }}, {{ $when($asset['createdAt']) }}</span>
                            </div>
                            <div class="flex flex-wrap items-center justify-between gap-3">
                                <p class="{{ $safety ? 'text-red-400' : 'text-neutral-400' }}">{{ $assetState($asset) }}</p>
                                @if ($asset['moderation'] === 'rejected')
                                    @if ($safety)
                                        {{-- No button. A safety rejection is not a call to revisit, and the database refuses one anyway. --}}
                                        <span class="text-xs text-red-400">Final</span>
                                    @else
                                        <form method="post" action="/moderation/trail/return">
                                            @csrf
                                            <button type="submit" name="asset" value="{{ $asset['id'] }}" class="{{ Ui::button('ghost', 'sm') }}">Return to the queue<span class="sr-only"> {{ $asset['name'] }}</span></button>
                                        </form>
                                    @endif
                                @endif
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif

            <h2 class="text-sm text-neutral-400">And what it did, newest first.</h2>
        @else
            <p class="text-sm text-neutral-400">Every moderation action, newest first, up to {{ Trail::PAGE_SIZE }}. Follow an account to see everything behind it.</p>
        @endif

        @if ($events === [])
            <p class="{{ $empty }}">{{ $trail ? 'Nothing this account did has changed what anybody can see.' : 'Nothing has been decided yet.' }}</p>
        @else
            <ul class="flex flex-col gap-3">
                @foreach ($events as $event)
                    @php $uploaderName = Trail::uploaderNameLine($event); @endphp
                    <li class="{{ $row }}">
                        <div class="flex flex-wrap items-baseline justify-between gap-3">
                            {!! $pictureLink($event['assetId'], $event['name'], $event['detail']) !!}
                            <span class="text-xs text-neutral-400">{{ $when($event['at']) }}</span>
                        </div>
                        <p class="{{ $event['rejectionKind'] === 'safety' ? 'text-red-400' : 'text-neutral-400' }}">{{ Trail::eventLine($event) }}</p>
                        <p class="flex flex-wrap gap-x-4 text-xs">
                            @if ($event['actor'])
                                {!! $accountLink($event['actor'], 'by') !!}
                            @else
                                <span class="text-neutral-400">by nobody signed in</span>
                            @endif
                            @if ($event['uploader'])
                                {!! $accountLink($event['uploader'], 'uploaded by') !!}
                            @endif
                        </p>
                        @if ($uploaderName)
                            <p class="text-xs text-neutral-300">{{ $uploaderName }}</p>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
</main>
@endsection
