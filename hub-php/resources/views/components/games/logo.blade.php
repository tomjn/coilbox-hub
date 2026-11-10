{{-- A game's logo in a fixed 3:2 tile, components/GameLogo.tsx. A game with
     no logo gets the same tile with the games icon, so names still line up. --}}
@props(['src', 'alt' => '', 'eager' => false])
@if (! $src)
    <div aria-hidden="true" class="h-16 w-24 shrink-0 overflow-hidden rounded-md border border-neutral-800 flex items-center justify-center bg-neutral-900">
        <x-icon name="games" class="w-7 text-neutral-600" />
    </div>
@else
    <div class="h-16 w-24 shrink-0 overflow-hidden rounded-md border border-neutral-800 bg-black p-1">
        <img src="{{ $src }}" alt="{{ $alt }}" width="96" height="64" @unless ($eager) loading="lazy" @endunless decoding="async" class="size-full object-contain"/>
    </div>
@endif
