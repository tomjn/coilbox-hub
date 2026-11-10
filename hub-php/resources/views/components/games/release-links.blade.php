{{-- ReleaseLinks in compare/page.tsx. The releases that hold both units, the
     current one marked and not linked. --}}
@props(['shortname', 'unit', 'other', 'releases', 'current' => null])
@use('App\Domain\Games\Js')
@use('App\Support\Ui')
<ul class="flex flex-wrap gap-2">
    @foreach ($releases as $item)
        <li>
            @if ($item === $current)
                <span class="rounded-md border border-neutral-600 bg-neutral-900 px-3 py-1.5 text-sm text-neutral-100" aria-current="true">{{ $item }}</span>
            @else
                <a href="/games/{{ $shortname }}/units/{{ Js::encodeURIComponent($unit) }}/compare?with={{ Js::encodeURIComponent($other) }}&release={{ Js::encodeURIComponent($item) }}" class="{{ Ui::button('ghost', 'md') }}">{{ $item }}</a>
            @endif
        </li>
    @endforeach
</ul>
