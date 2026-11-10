{{-- UnitHead in compare/page.tsx. A column head: the unit's picture and name,
     linking to its page at the release being compared. --}}
@props(['shortname', 'unit', 'label', 'picture' => null, 'release'])
@use('App\Domain\Games\UnitRelease')
<a href="/games/{{ $shortname }}/units/{{ UnitRelease::unitSegment($unit, $release) }}" class="flex w-24 flex-col items-start gap-2 text-sm normal-case tracking-normal text-neutral-100 sm:w-36">
    <span class="flex h-16 w-16 items-center justify-center">
        @if (! $picture || $picture['from'] === 'placeholder')
            <x-assets.placeholder :of="['name' => $unit, 'keyedOn' => 'unit', 'footprint' => null]" quiet class="size-16" />
        @else
            <img src="{{ $picture['url'] }}" alt="Buildpic of {{ $label }}" width="64" height="64" decoding="async" class="max-h-16 w-auto object-contain"/>
        @endif
    </span>
    <span class="break-words underline-offset-4 hover:underline active:underline">{{ $label }}</span>
    <span class="break-all font-mono text-sm text-neutral-400">{{ $unit }}</span>
</a>
