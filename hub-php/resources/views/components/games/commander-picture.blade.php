{{-- One start unit's buildpic, components/CommanderPicture.tsx. With no
     picture held it draws the dashed outline at icon size, without the
     placeholder's caption, which would not fit. --}}
@props(['commander', 'class' => ''])
@if ($commander['picture']['from'] === 'placeholder')
    <span aria-hidden="true" class="flex shrink-0 items-center justify-center rounded {{ $class }}">
        <svg viewBox="0 0 24 24" class="w-1/2 text-neutral-300"><rect x="2" y="2" width="20" height="20" rx="4" fill="currentColor" fill-opacity="0.15" stroke="currentColor" stroke-opacity="0.62" stroke-width="1.25" stroke-dasharray="4 4" vector-effect="non-scaling-stroke"></rect></svg>
    </span>
@else
    <img src="{{ $commander['picture']['url'] }}" alt="" width="{{ $commander['picture']['width'] }}" height="{{ $commander['picture']['height'] }}" loading="lazy" decoding="async" class="shrink-0 rounded object-cover {{ $class }}"/>
@endif
