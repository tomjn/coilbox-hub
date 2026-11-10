@props(['item', 'units' => [], 'names' => [], 'roles' => []])
{{-- components/ItemPreview.tsx: what an item looks like, drawn from its own
     payload. A kind with no preview draws nothing, since an empty frame reads
     as broken. A galaxy, a run and a blueprint plan are drawn by
     resources/js/gallery from the payload in their data attribute. --}}
@php
    use App\Domain\Gallery\Previews;
    $payload = $item['container']['payload'] ?? null;
    $kind = $item['kind'];
    $json = fn (array $value) => json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $dt = 'text-xs uppercase tracking-wide text-neutral-400';
@endphp
@if (is_array($payload))
    @if ($kind === 'preset')
        @php $composition = Previews::presetComposition($payload); @endphp
        @if ($composition !== null)
            <div class="flex flex-col gap-3">
                <div class="flex flex-wrap items-stretch gap-2">
                    @foreach ($composition['teams'] as $index => $team)
                        {{-- The separator trails its team, so a wrap never starts a line with it. --}}
                        <div class="flex items-stretch gap-2">
                            <div class="flex flex-col gap-1.5 rounded-md border border-neutral-800 bg-black p-3">
                                @foreach ($team['members'] as $p)
                                    @php $side = Previews::participantSideLabel($p['side'] ?? null); @endphp
                                    <div class="flex items-center gap-2 text-xs text-neutral-300">
                                        <span aria-hidden="true" class="size-2.5 shrink-0 rounded-sm" style="background:{{ Previews::participantColorCss($p['color'] ?? null) }}"></span>
                                        <span>{{ Previews::participantLabel($p) }}</span>
                                        @if ($side)<span class="text-neutral-400">{{ $side }}</span>@endif
                                    </div>
                                @endforeach
                            </div>
                            @if ($index < count($composition['teams']) - 1)
                                <span class="self-center text-xs text-neutral-400">v</span>
                            @endif
                        </div>
                    @endforeach
                </div>
                <p class="text-xs text-neutral-400">{{ $composition['playingCount'] }} playing across {{ count($composition['teams']) }} {{ count($composition['teams']) === 1 ? 'team' : 'teams' }}</p>
            </div>
        @endif
    @elseif ($kind === 'challenge')
        @php $stats = Previews::challengeStats($payload); @endphp
        <div class="flex flex-col gap-3">
            <div data-challenge-drawing="{{ $json(['payload' => $item['payload']]) }}"></div>
            @if ($stats !== [])
                <dl class="grid gap-3 rounded-md border border-neutral-800 bg-black p-4 sm:grid-cols-3">
                    @foreach ($stats as [$label, $value])
                        <div class="flex flex-col gap-1"><dt class="{{ $dt }}">{{ $label }}</dt><dd class="text-sm text-neutral-100">{{ $value }}</dd></div>
                    @endforeach
                </dl>
            @endif
        </div>
    @elseif ($kind === 'scenario')
        @php $stats = Previews::scenarioStats($payload); @endphp
        @if ($stats !== [])
            <dl class="grid grid-cols-2 gap-3 rounded-md border border-neutral-800 bg-black p-4 sm:grid-cols-3">
                @foreach ($stats as [$label, $n])
                    <div class="flex flex-col gap-1"><dt class="{{ $dt }}">{{ $label }}</dt><dd class="text-sm text-neutral-100">{{ $n }}</dd></div>
                @endforeach
            </dl>
        @endif
    @elseif ($kind === 'mod-project')
        <x-gallery.project-changes :payload="$item['payload']" :units="$units" :names="$names" />
    @elseif ($kind === 'blueprint')
        <div data-blueprint-preview="{{ $json(['payload' => $item['payload'], 'units' => (object) $units, 'names' => (object) $names, 'roles' => (object) $roles]) }}"></div>
    @endif
@endif
