{{--
    One figure over the match for every team, as a line chart drawn on the
    server. `history` is ReplayPage's history.
--}}
@props(['label', 'column', 'history'])
@use('App\Domain\Replays\ReplayPage')
@php
    $width = 320;
    $height = 140;
    $frames = max(1, $history['frames']);
    $peak = 0.0;
    foreach ($history['teams'] as $team) {
        foreach ($team['series'][$column] as [, $value]) {
            $peak = max($peak, (float) $value);
        }
    }
    $peak = $peak > 0 ? $peak : 1.0;
    $line = fn (array $series) => implode(' ', array_map(fn ($p) => round($p[0] / $frames * $width, 1).','.round($height - (float) $p[1] / $peak * $height, 1), $series));
    $finals = array_map(fn ($team) => $team['name'].' '.ReplayPage::total((float) (end($team['series'][$column])[1] ?? 0)), $history['teams']);
@endphp
<figure class="flex flex-col gap-1.5 rounded-lg border border-neutral-800 bg-card p-3">
    <figcaption class="flex items-baseline justify-between gap-2 text-xs">
        <span class="font-medium">{{ $label }}</span>
        <span class="tabular-nums text-neutral-400">up to {{ ReplayPage::total($peak) }}</span>
    </figcaption>
    <svg viewBox="0 0 {{ $width }} {{ $height }}" preserveAspectRatio="none" class="h-32 w-full overflow-visible" role="img" aria-label="{{ $label }} over the match. At the end: {{ implode(', ', $finals) }}.">
        <line x1="0" y1="{{ $height }}" x2="{{ $width }}" y2="{{ $height }}" stroke="rgb(64 64 64)" vector-effect="non-scaling-stroke" />
        <line x1="0" y1="0" x2="{{ $width }}" y2="0" stroke="rgb(38 38 38)" stroke-dasharray="3 3" vector-effect="non-scaling-stroke" />
        @foreach ($history['teams'] as $team)
            <polyline points="{{ $line($team['series'][$column]) }}" fill="none" stroke="{{ $team['colour'] ?? '#94a3b8' }}" stroke-width="2" stroke-linejoin="round" vector-effect="non-scaling-stroke" />
        @endforeach
    </svg>
    <div class="flex justify-between text-[10px] tabular-nums text-neutral-400" aria-hidden="true">
        <span>0:00</span><span>{{ ReplayPage::time($history['frames']) }}</span>
    </div>
</figure>
