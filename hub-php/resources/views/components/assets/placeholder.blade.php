{{--
    A picture the hub does not have, drawn rather than fetched. components/AssetPlaceholder.tsx in the Next app.

    `of` is a placeholder from AssetResolver::resolveAsset() or Placeholder::missingPicture().
    `quiet` drops the caption, for callers that print the name right below.
    `class` lands on the outer box. The caller sizes it.

    Drawn in the same graphite as the blueprint plan, dashed because the shape is real and the picture is not.
    A picture waiting for a moderator gets an hourglass in the middle, so it does not read as missing.
--}}
@props(['of', 'quiet' => false])
@use('App\Domain\Assets\Placeholder')
@php
    $box = Placeholder::box($of['footprint']);
    // The stroke inset, so a non-scaling stroke is not clipped by the viewBox edge.
    $inset = 2;
    $width = Placeholder::jsNumber($box['width']);
    $height = Placeholder::jsNumber($box['height']);
    $class = trim((string) $attributes->get('class', ''));
    $pending = Placeholder::pending($of);
    // The hourglass is 40 percent of the shorter side, centred.
    $glass = min($box['width'], $box['height']) * 0.4;
@endphp
<div class="flex flex-col items-center justify-center gap-3 rounded-md border border-neutral-800 bg-black p-4{{ $class !== '' ? ' '.$class : '' }}">
    <svg viewBox="0 0 {{ $width }} {{ $height }}" class="w-full max-w-32 text-neutral-300" style="aspect-ratio:{{ $width }} / {{ $height }}" role="img" aria-label="{{ Placeholder::label($of) }}">
        <rect x="{{ $inset }}" y="{{ $inset }}" width="{{ Placeholder::jsNumber($box['width'] - $inset * 2) }}" height="{{ Placeholder::jsNumber($box['height'] - $inset * 2) }}" rx="4" fill="currentColor" fill-opacity="0.15" stroke="currentColor" stroke-opacity="0.62" stroke-width="1.25" stroke-dasharray="4 4" vector-effect="non-scaling-stroke"></rect>
        @if ($pending)
            <title>{{ Placeholder::label($of) }}</title>
            <svg x="{{ Placeholder::jsNumber(($box['width'] - $glass) / 2) }}" y="{{ Placeholder::jsNumber(($box['height'] - $glass) / 2) }}" width="{{ Placeholder::jsNumber($glass) }}" height="{{ Placeholder::jsNumber($glass) }}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M5 22h14" /><path d="M5 2h14" /><path d="M17 22v-4.17a2 2 0 0 0-.59-1.42L12 12l-4.41 4.41A2 2 0 0 0 7 17.83V22" /><path d="M7 2v4.17a2 2 0 0 0 .59 1.42L12 12l4.41-4.41A2 2 0 0 0 17 6.17V2" />
            </svg>
        @endif
    </svg>
    @unless ($quiet)
        <p class="flex max-w-full flex-col items-center gap-0.5 text-center text-sm">
            <span class="break-all text-neutral-300">{{ $of['name'] }}</span>
            <span class="break-all text-neutral-400">{{ Placeholder::caption($of) }}</span>
        </p>
    @endunless
</div>
