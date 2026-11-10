@extends('layouts.app', ['title' => 'Allowances - Ops'])
@use('App\Domain\Moderation\Meters')
@use('App\Domain\Moderation\Orphans')
@use('App\Domain\Moderation\Promotion')
@use('App\Support\Dates')
@php
    $card = 'rounded-md border border-neutral-800 bg-card p-5';
    $basisLabel = ['counted' => 'counted from the database', 'estimated' => 'estimated from the database', 'dashboard' => 'dashboard only'];
    $alertPercent = round(Meters::ALERT_FRACTION * 100);
    $stamp = fn (string $at) => Dates::format($at, true);
    $figure = fn (?int $value) => $value === null ? 'no figure' : number_format($value);
    // The words carry the state. The colour is never the only signal.
    $standing = fn (float $full) => match (true) {
        $full >= 1 => ['label' => 'Full', 'bar' => 'bg-red-400', 'ink' => 'text-red-300'],
        $full >= Meters::ALERT_FRACTION => ['label' => "Past {$alertPercent}%, the daily job is failing", 'bar' => 'bg-amber-400', 'ink' => 'text-amber-300'],
        default => ['label' => 'Room to spare', 'bar' => 'bg-neutral-300', 'ink' => 'text-neutral-400'],
    };
    $durableTotal = array_sum(array_column($report['durable'], 'bytes'));
    $p = $promotion;
    $promotionRows = [
        ['label' => 'Waiting for review', 'value' => $figure($p['pending'])],
        ['label' => 'Waiting for Coilbox to upload again', 'value' => $figure($p['missing']),
            'detail' => $p['missing'] ? 'Their bytes were lost with the old staging store. Pages and promotion skip them, and Coilbox uploads each one into the bucket the next time it offers that picture.' : null],
        ['label' => 'Approved, in the '.Promotion::AGE_DAYS.' day hold', 'value' => $figure($p['waiting'])],
        ['label' => 'Due to move now', 'value' => $figure($p['due']),
            'detail' => $p['dueSince'] ? ($p['stalled']
                ? 'Due since '.$stamp($p['dueSince']).' UTC, which is longer than one daily run. Check the Promote workflow in tomjn/coilbox-assets.'
                : 'Due since '.$stamp($p['dueSince']).' UTC. The next daily run moves it.') : null,
            'alarm' => $p['stalled']],
        ['label' => 'Staging copies still to delete', 'value' => $figure($p['leftover'])],
        ['label' => 'Bucket deletions stuck', 'value' => $figure($stuckDeletions),
            'detail' => $stuckDeletions > 0 ? 'Reserved for deletion more than '.Orphans::ABANDONED_RESERVATION_MINUTES.' minutes ago, so the run that reserved them has died. The next sweep finishes the delete.' : null,
            'alarm' => $stuckDeletions > 0],
        ['label' => 'Promoted in the last 30 days', 'value' => $figure($p['promotedRecently'])],
        ['label' => 'Last promoted', 'value' => $p['lastPromotedAt'] ? $stamp($p['lastPromotedAt']).' UTC' : 'never'],
    ];
@endphp

@section('content')
<main id="main-content" tabindex="-1" class="relative flex-1">
    {{-- Fainter than the text pages, because a drawing behind a chart competes with the columns. --}}
    <x-ui.art-backdrop drawing="archives" :strength="0.05" />
    <x-moderation.nav current="allowances" />
    <div class="relative z-10 flex w-full flex-col gap-10 px-6 py-10">
        <header class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <h1 class="text-3xl font-semibold tracking-tight">Allowances</h1>
                <p class="mt-2 max-w-[70ch] text-sm text-neutral-400">What the hub holds and serves, against the free plan allowances. The daily sweep fails on purpose once a counted meter passes {{ $alertPercent }}%.</p>
            </div>
            <p class="text-xs text-neutral-400">Read {{ $stamp($report['at']) }} UTC</p>
        </header>

        <div class="grid items-start gap-6 lg:grid-cols-2">
            <section class="{{ $card }}" aria-labelledby="promotion">
                <h2 id="promotion" class="text-lg font-semibold tracking-tight">Promotion</h2>
                <p class="mt-2 max-w-[70ch] text-sm text-neutral-400">Approved pictures move out of the staging bucket and into the durable tier. The bucket only stays small while this keeps moving.</p>
                <dl class="mt-4 flex flex-col">
                    @foreach ($promotionRows as $row)
                        @php $alarm = $row['alarm'] ?? false; @endphp
                        <div class="border-t border-neutral-900 py-2.5">
                            <div class="flex items-baseline justify-between gap-3 text-sm">
                                <dt class="text-neutral-400">{{ $row['label'] }}</dt>
                                <dd class="tabular-nums {{ $alarm ? 'text-amber-300' : 'text-neutral-100' }}">{{ $row['value'] }}</dd>
                            </div>
                            @if ($row['detail'] ?? null)
                                <p class="mt-1 text-xs {{ $alarm ? 'text-amber-300' : 'text-neutral-400' }}">{{ $row['detail'] }}</p>
                            @endif
                        </div>
                    @endforeach
                </dl>
            </section>
            <section class="{{ $card }}" aria-labelledby="swept">
                <h2 id="swept" class="text-lg font-semibold tracking-tight">Waiting to be swept</h2>
                <p class="mt-2 mb-4 max-w-[70ch] text-sm text-neutral-400">Bucket objects no row points at: bytes superseded by a newer archive, and uploads whose row was never written. The daily sweep deletes them.</p>
                @if ($swept['objects'] === 0)
                    <p class="text-sm text-neutral-400">Nothing unclaimed.</p>
                @else
                    <table class="w-full text-sm tabular-nums">
                        <thead class="text-left text-neutral-400">
                            <tr><th class="pb-2 font-normal">Objects</th><th class="pb-2 font-normal text-right">Size</th><th class="pb-2 font-normal text-right">Oldest</th></tr>
                        </thead>
                        <tbody>
                            <tr class="border-t border-neutral-900">
                                {{-- The listing stops at one sweep's worth, so a full one is a floor. --}}
                                <td class="py-2">{{ $swept['objects'] === Orphans::CLEANUP_BATCH ? 'at least ' : '' }}{{ number_format($swept['objects']) }}</td>
                                <td class="py-2 text-right text-neutral-300">{{ Meters::formatBytes($swept['bytes']) }}</td>
                                <td class="py-2 text-right text-neutral-300">{{ $swept['since'] ? Dates::format(substr($swept['since'], 0, 10)) : '' }}</td>
                            </tr>
                        </tbody>
                    </table>
                @endif
            </section>
        </div>

        <section class="flex flex-col gap-4" aria-labelledby="storage">
            <h2 id="storage" class="text-xl font-semibold tracking-tight">Storage</h2>
            <ul class="grid gap-6 lg:grid-cols-2">
                @foreach ($measured as $meter)
                    @php $full = Meters::headroom($meter); $state = $full === null ? null : $standing($full); @endphp
                    <li class="{{ $card }}">
                        <div class="flex flex-wrap items-baseline justify-between gap-3">
                            <h3 class="text-base font-medium">{{ $meter['name'] }}</h3>
                            <span class="text-xs text-neutral-400">{{ $basisLabel[$meter['basis']] }}</span>
                        </div>
                        <p class="mt-2 text-sm text-neutral-300">
                            <span class="text-xl font-semibold tabular-nums text-neutral-100">{{ $meter['used'] === null ? 'no figure' : Meters::formatBytes($meter['used']) }}</span>
                            of {{ Meters::formatBytes($meter['allowance']) }}@if ($full !== null) ({{ round($full * 100) }}%)@endif
                        </p>
                        @if ($state)
                            {{-- A fill bar with a tick where the daily job starts failing. --}}
                            <div class="relative mt-3 h-2 rounded-full bg-neutral-800">
                                <div class="h-full rounded-full {{ $state['bar'] }}" style="width: {{ min($full, 1) * 100 }}%"></div>
                                <div title="The daily job fails from {{ $alertPercent }}%" class="absolute -top-1 h-4 w-px bg-neutral-500" style="left: {{ Meters::ALERT_FRACTION * 100 }}%"></div>
                            </div>
                        @endif
                        <p class="mt-3 max-w-[70ch] text-sm text-neutral-400">{{ $meter['note'] }}</p>
                    </li>
                @endforeach
            </ul>
        </section>

        <section class="flex flex-col gap-4" aria-labelledby="dashboard">
            <div>
                <h2 id="dashboard" class="text-xl font-semibold tracking-tight">Only on the dashboards</h2>
                <p class="mt-1 max-w-[70ch] text-sm text-neutral-400">Nothing here can measure these, so there is no figure rather than a zero.</p>
            </div>
            <ul class="grid gap-x-6 gap-y-4 md:grid-cols-2 xl:grid-cols-3">
                @foreach ($dashboard as $meter)
                    <li class="border-t border-neutral-800 pt-3">
                        <p class="flex flex-wrap items-baseline justify-between gap-2 text-sm">
                            <span class="font-medium">{{ $meter['name'] }}</span>
                            <span class="text-neutral-400">{{ Meters::formatBytes($meter['allowance']) }}</span>
                        </p>
                        <p class="mt-1 text-xs text-neutral-400">{{ $meter['note'] }}</p>
                    </li>
                @endforeach
            </ul>
        </section>

        <div class="grid items-start gap-6 lg:grid-cols-2">
            <section class="{{ $card }}" aria-labelledby="durable">
                <h2 id="durable" class="text-lg font-semibold tracking-tight">Durable tier by class</h2>
                <p class="mt-2 mb-4 max-w-[70ch] text-sm text-neutral-400">Not one total. Buildpics are negligible and the map corpus is fixed at about 3,575, so renders are the only class that can move, and a total that is growing does not say which one did.</p>
                @if ($report['durable'] === [])
                    <p class="text-sm text-neutral-400">Nothing promoted yet.</p>
                @else
                    <table class="w-full text-sm tabular-nums">
                        <thead class="text-left text-neutral-400">
                            <tr>
                                <th class="pb-2 font-normal">Class</th>
                                <th class="pb-2 font-normal text-right">Objects</th>
                                <th class="pb-2 font-normal text-right">Size</th>
                                <th class="pb-2 font-normal text-right">Share</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($report['durable'] as $held)
                                <tr class="border-t border-neutral-900">
                                    <td class="py-2">{{ $held['name'] }}</td>
                                    <td class="py-2 text-right text-neutral-300">{{ number_format($held['objects']) }}</td>
                                    <td class="py-2 text-right text-neutral-300">{{ Meters::formatBytes($held['bytes']) }}</td>
                                    <td class="py-2 text-right text-neutral-300">{{ $durableTotal > 0 ? round($held['bytes'] / $durableTotal * 100) : 0 }}%</td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot>
                            <tr class="border-t border-neutral-700 text-neutral-100">
                                <td class="pt-2">Total</td>
                                <td class="pt-2 text-right">{{ number_format(array_sum(array_column($report['durable'], 'objects'))) }}</td>
                                <td class="pt-2 text-right">{{ Meters::formatBytes($durableTotal) }}</td>
                                <td class="pt-2 text-right"></td>
                            </tr>
                        </tfoot>
                    </table>
                @endif
            </section>
        </div>
    </div>
</main>
@endsection
