@extends('layouts.app', ['title' => 'Pictures - Moderation'])
@use('App\Support\Ui')
@php
    $waiting = $queue['waiting'];
    $total = $queue['total'];
    // Unticked is grey and faint, ticked is full colour and framed, so what is
    // about to be approved reads across the whole sheet at a glance.
    $tile = 'flex cursor-pointer flex-col gap-2 rounded-md border border-neutral-800 bg-card p-2 opacity-40 grayscale transition-all hover:border-neutral-600 active:border-neutral-500 has-checked:border-neutral-400 has-checked:opacity-100 has-checked:grayscale-0 has-[:focus-visible]:outline has-[:focus-visible]:outline-2 has-[:focus-visible]:outline-offset-2 has-[:focus-visible]:outline-neutral-300';
    // Quiet for the everyday rejection, red for the one that cannot be undone.
    $reject = 'rounded-sm bg-black/70 px-2 py-1.5 text-sm text-neutral-400 transition-colors hover:bg-black active:bg-black hover:text-neutral-100 active:text-neutral-100 focus-visible:text-neutral-100';
    $rejectUnsafe = 'rounded-sm bg-black/70 px-2 py-1.5 text-sm text-red-500 transition-colors hover:bg-black active:bg-black hover:text-red-300 active:text-red-300 focus-visible:text-red-300';
    // Outside the label, so a greyed unticked tile does not grey the warning too.
    $flagged = 'relative flex flex-col gap-1 rounded-md p-0.5 ring-2 ring-amber-500/80';
    $flag = 'rounded-sm bg-amber-950/80 px-1.5 py-1 text-[0.65rem] leading-tight font-medium text-amber-300';
    $waitingLine = $total > count($waiting)
        ? 'The '.count($waiting).' oldest of '.$total.' waiting.'
        : ($total === 1 ? 'One picture waiting.' : $total.' pictures waiting.');
    $conflicts = $disagreements['conflicts'];
    $disagreementsLine = $disagreements['total'] > count($conflicts)
        ? 'The '.count($conflicts).' oldest of '.$disagreements['total'].'.'
        : ($disagreements['total'] === 1 ? 'One entry.' : $disagreements['total'].' entries.');
    $accountLink = 'font-mono text-neutral-300 underline decoration-neutral-700 underline-offset-2 transition-colors hover:text-neutral-100 active:text-neutral-100';
@endphp

@section('content')
<main id="main-content" tabindex="-1" class="relative flex-1">
    {{-- Fainter than the reports page, so the backdrop does not compete with the pictures. --}}
    <x-ui.art-backdrop drawing="archives" :strength="0.05" />
    <x-moderation.nav current="pictures" />
    <div class="relative z-10 mx-auto flex w-full max-w-6xl flex-col gap-6 px-6 py-12">
        <div class="flex flex-wrap items-baseline gap-4">
            <h1 class="text-3xl font-semibold tracking-tight">Pictures</h1>
            <a href="/moderation/trail" class="text-sm text-neutral-400 transition-colors hover:text-neutral-300 active:text-neutral-300">Trail</a>
        </div>

        @if ($waiting === [])
            <p class="rounded-md border border-neutral-800 bg-card p-6 text-sm text-neutral-400">Nothing waiting.</p>
        @else
            {{-- The tile reject buttons belong to these two forms through the
                 form attribute, so rejecting one picture does not carry every
                 other tick with it. One form per kind, because a button spends
                 its name and value on saying which picture. --}}
            <form id="reject-editorial" method="post" action="/moderation/assets/reject">
                @csrf
                <input type="hidden" name="kind" value="editorial">
            </form>
            <form id="reject-safety" method="post" action="/moderation/assets/reject">
                @csrf
                <input type="hidden" name="kind" value="safety">
            </form>

            <form method="post" action="/moderation/assets/approve" class="flex flex-col gap-5">
                @csrf
                <div class="flex flex-wrap items-center justify-between gap-4">
                    <p class="text-sm text-neutral-400">{{ $waitingLine }} Untick anything you are not sure about. Reject what does not belong here, and mark as unsafe anything illegal or seriously harmful, which is final. Anything ringed in amber came out of an archive that produced different bytes for somebody else, and starts unticked.</p>
                    <button type="submit" class="{{ Ui::button() }}">Approve the ticked</button>
                </div>

                <ul class="grid grid-cols-[repeat(auto-fill,minmax(9rem,1fr))] gap-3">
                    @foreach ($waiting as $picture)
                        <li class="{{ $picture['sourceConflict'] ? $flagged : 'relative' }}">
                            <label class="{{ $tile }}">
                                <input type="checkbox" name="asset[]" value="{{ $picture['id'] }}" @checked(! $picture['sourceConflict']) class="sr-only">
                                {{-- Lazy, so a sheet scrolled halfway fetches half the bytes. --}}
                                <img src="/moderation/assets/{{ $picture['id'] }}" alt="{{ $picture['name'] }}, {{ $picture['detail'] }}" width="{{ $picture['width'] }}" height="{{ $picture['height'] }}" loading="lazy" decoding="async" class="aspect-square w-full rounded-sm bg-black object-contain">
                                <span class="flex flex-col gap-0.5 text-sm leading-tight">
                                    <span class="truncate text-neutral-300" title="{{ $picture['name'] }}">{{ $picture['name'] }}</span>
                                    <span class="truncate text-neutral-400">{{ $picture['detail'] }}</span>
                                    <span class="truncate text-neutral-400" title="{{ $picture['sourceArchive'] }}">{{ $picture['origin'] }}, {{ round($picture['bytes'] / 1024) }} kB</span>
                                    @if ($picture['sourceConflict'])
                                        <span class="sr-only">Another upload reported different source bytes out of the same archive, {{ $picture['sourceArchive'] }}. Unticked until you have looked at it.</span>
                                    @endif
                                </span>
                            </label>
                            @if ($picture['sourceConflict'])
                                <p class="{{ $flag }}" title="Another upload reported different source bytes out of {{ $picture['sourceArchive'] }}. Either a modified client or a corrupted install.">Same archive, different bytes</p>
                            @endif
                            <div class="absolute top-3 right-3 flex flex-col items-end gap-1">
                                <button type="submit" form="reject-editorial" name="asset" value="{{ $picture['id'] }}" title="Out of scope, the wrong game, a duplicate or junk. Can be put back." class="{{ $reject }}">Reject<span class="sr-only"> {{ $picture['name'] }} as not belonging here</span></button>
                                <button type="submit" form="reject-safety" name="asset" value="{{ $picture['id'] }}" title="Illegal or seriously harmful. Nothing in the hub can undo this." class="{{ $rejectUnsafe }}">Unsafe<span class="sr-only"> {{ $picture['name'] }} on safety grounds, which cannot be undone</span></button>
                            </div>
                        </li>
                    @endforeach
                </ul>

                {{-- Again at the foot, because a full sheet is longer than a screen. --}}
                <div>
                    <button type="submit" class="{{ Ui::button() }}">Approve the ticked</button>
                </div>
            </form>
        @endif

        {{-- Approved pictures a second account reported different bytes for
             (#149). A list, not a state change: a moderator looks, and rejects
             if it deserves it. --}}
        @if ($disagreements['error'] || $conflicts !== [])
            <section class="flex flex-col gap-3" aria-labelledby="disagreements-heading">
                <h2 id="disagreements-heading" class="text-xl font-semibold tracking-tight">Approved pictures with a source disagreement</h2>
                @if ($disagreements['error'])
                    <p class="rounded-md border border-neutral-800 bg-card p-4 text-sm text-red-400">The list did not load: {{ $disagreements['error'] }}</p>
                @else
                    <p class="text-sm text-neutral-400">{{ $disagreementsLine }} Each is a picture already on the site that another account reported different bytes for, out of the same archive. Nothing has changed on the site. Reject the picture if it deserves it, or mark the entry as looked at.</p>
                    <ul class="flex flex-col gap-3">
                        @foreach ($conflicts as $conflict)
                            <li class="flex flex-col gap-3 rounded-md border border-neutral-800 bg-card p-4 text-sm">
                                <div class="flex flex-wrap items-baseline justify-between gap-3">
                                    <a href="/moderation/assets/{{ $conflict['assetId'] }}" class="font-medium text-neutral-200 hover:underline active:underline">{{ $conflict['name'] }}@if ($conflict['detail'])<span class="text-neutral-400"> {{ $conflict['detail'] }}</span>@endif</a>
                                    <span class="text-sm text-neutral-400">{{ substr(str_replace('T', ' ', $conflict['at']), 0, 16) }}</span>
                                </div>
                                <dl class="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1 text-sm">
                                    <dt class="text-neutral-400">Archive</dt>
                                    <dd class="break-all text-neutral-300">{{ $conflict['sourceArchive'] }}</dd>
                                    <dt class="text-neutral-400">Held</dt>
                                    <dd class="font-mono break-all text-neutral-300">{{ $conflict['heldSourceHash'] }}</dd>
                                    <dt class="text-neutral-400">Reported</dt>
                                    <dd class="font-mono break-all text-neutral-300">{{ $conflict['reportedSourceHash'] }}</dd>
                                    @foreach (['Uploaded by' => $conflict['uploadedBy'], 'Reported by' => $conflict['reportedBy']] as $term => $account)
                                        <dt class="text-neutral-400">{{ $term }}</dt>
                                        <dd>
                                            @if ($account)
                                                <a href="/moderation/trail?account={{ $account }}" title="{{ $account }}" class="{{ $accountLink }}">{{ substr($account, 0, 8) }}</a>
                                            @else
                                                <span class="text-neutral-400">an account that has closed</span>
                                            @endif
                                        </dd>
                                    @endforeach
                                </dl>
                                <div class="flex flex-wrap items-center gap-2">
                                    <form method="post" action="/moderation/assets/reject">
                                        @csrf
                                        <input type="hidden" name="asset" value="{{ $conflict['assetId'] }}">
                                        <input type="hidden" name="kind" value="editorial">
                                        <button type="submit" title="Out of scope, the wrong game, a duplicate or junk. Can be put back." class="{{ Ui::button('ghost', 'sm') }}">Reject<span class="sr-only"> {{ $conflict['name'] }} as not belonging here</span></button>
                                    </form>
                                    <form method="post" action="/moderation/assets/reject">
                                        @csrf
                                        <input type="hidden" name="asset" value="{{ $conflict['assetId'] }}">
                                        <input type="hidden" name="kind" value="safety">
                                        <button type="submit" title="Illegal or seriously harmful. Nothing in the hub can undo this." class="{{ Ui::button('ghost', 'sm') }}">Unsafe<span class="sr-only"> {{ $conflict['name'] }} on safety grounds, which cannot be undone</span></button>
                                    </form>
                                    <form method="post" action="/moderation/assets/acknowledge">
                                        @csrf
                                        <input type="hidden" name="conflict" value="{{ $conflict['conflictId'] }}">
                                        <button type="submit" class="{{ Ui::button('ghost', 'sm') }}">Looked at<span class="sr-only"> {{ $conflict['name'] }}, leave it approved</span></button>
                                    </form>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>
        @endif
    </div>
</main>
@endsection
