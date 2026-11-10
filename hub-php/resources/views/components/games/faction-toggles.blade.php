{{-- Factions as a row of toggles rather than a dropdown,
     components/FactionToggles.tsx. Each option is a link, so the URL carries
     the choice and the back button walks back through sides. `options` is a
     list of ['key', 'label', 'href', 'active']. --}}
@props(['options', 'label' => 'Faction'])
@use('App\Support\Ui')
<div role="group" aria-label="{{ $label }}" class="flex flex-wrap items-center gap-1.5">
    @foreach ($options as $option)
        @if ($option['active'])
            <span aria-current="true" class="rounded-md border border-neutral-600 bg-neutral-900 px-3 py-1.5 text-sm text-neutral-100">{{ $option['label'] }}</span>
        @else
            <a class="{{ Ui::button('ghost', 'md') }}" href="{{ $option['href'] }}">{{ $option['label'] }}</a>
        @endif
    @endforeach
</div>
