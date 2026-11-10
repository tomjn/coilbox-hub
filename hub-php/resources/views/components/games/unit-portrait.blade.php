{{-- UnitPortrait in components/UnitPictures.tsx. The buildpic, or whatever the
     render resolution found. The caption follows the bytes served, not the slot. --}}
@props(['label', 'asset'])
@use('App\Domain\Assets\Asset')
@php
    $angles = ['top' => 'Top down', 'front' => 'Front', 'side' => 'Side', 'angled' => 'Angled'];
    $caption = 'Buildpic';
    if ($asset['from'] !== 'placeholder') {
        $variant = $asset['served']['variant'];
        $prefix = Asset::unitRenderVariantPrefix();
        if (str_starts_with($variant, $prefix)) {
            $angle = substr($variant, strlen($prefix));
            $caption = $angles[$angle] ?? $angle;
        }
    }
@endphp
<figure class="flex w-40 shrink-0 flex-col items-center justify-center gap-2 rounded-md border border-neutral-900 bg-black p-4">
    @if ($asset['from'] === 'placeholder')
        <x-assets.placeholder :of="$asset" class="w-full" />
    @else
        <img src="{{ $asset['url'] }}" alt="Picture of {{ $label }}" width="{{ $asset['width'] }}" height="{{ $asset['height'] }}" decoding="async" class="h-auto w-full object-contain"/>
    @endif
    <figcaption class="text-sm text-neutral-400">{{ $caption }}</figcaption>
</figure>
