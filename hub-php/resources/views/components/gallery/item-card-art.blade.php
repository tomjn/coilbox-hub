@props(['item', 'picture' => null, 'shape' => null])
{{-- components/ItemCardArt.tsx. Every card is the same 3:2 box whatever fills
     it, so the grid does not reflow. A challenge's galaxy or run and a
     blueprint's layout are drawn by resources/js/gallery from the parts in
     data-card-shape. Until then, and when nothing can be drawn, the kind
     plate shows. Decorative, because the card's badge names the kind. --}}
@php
    $frame = 'aspect-[3/2] w-full shrink-0 overflow-hidden rounded-md border border-neutral-800 bg-black';
    $choice = App\Domain\Gallery\Cards::artChoice($item, $picture, $shape);
@endphp
@if ($choice === 'map' && $picture['from'] === 'placeholder')
    {{-- Quiet, because the card prints the map name right below. --}}
    <x-assets.placeholder :of="$picture" :class="$frame" quiet />
@elseif ($choice === 'map')
    <div class="{{ $frame }}"><img src="{{ $picture['url'] }}" alt="Minimap of {{ $item['map_name'] }}" loading="lazy" decoding="async" class="size-full object-cover"></div>
@else
    <div aria-hidden="true" class="{{ $frame }} flex items-center justify-center bg-neutral-900" @if ($choice === 'art') data-card-shape="{{ json_encode($shape, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}" @endif><x-gallery.kind-icon :kind="$item['kind']" :mode="$item['mode']" class="w-10 text-neutral-700" /></div>
@endif
