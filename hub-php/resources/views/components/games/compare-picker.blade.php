{{-- components/UnitComparePicker.tsx. A GET form with a datalist, so it works
     with no script and the URL is the state. Each option's value is the unit
     def, which is what `with` carries. --}}
@props(['game', 'unit', 'current' => null, 'release' => null, 'label' => 'Compare with another unit'])
@use('App\Domain\Games\Cached')
@use('App\Domain\Games\Js')
@use('App\Support\Ui')
@php
    $options = array_values(array_filter(Cached::unitNameLabels($game), fn (array $entry) => $entry['name'] !== $unit));
    usort($options, fn (array $a, array $b) => Js::localeCompare($a['label'], $b['label']) ?: Js::localeCompare($a['name'], $b['name']));
@endphp
<form action="/games/{{ $game }}/units/{{ Js::encodeURIComponent($unit) }}/compare" method="get" class="flex flex-wrap items-end gap-3">
    <div class="flex min-w-48 flex-1 flex-col gap-1.5">
        <label for="compare-with" class="text-xs uppercase tracking-wide text-neutral-400">{{ $label }}</label>
        <input id="compare-with" name="with" type="text" list="compare-with-units" @if ($current !== null) value="{{ $current }}" @endif autocomplete="off" placeholder="Unit name or id" class="w-full rounded-md border border-neutral-800 bg-card px-3 py-2 text-sm text-neutral-100 focus-visible:border-neutral-600 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-400"/>
        <datalist id="compare-with-units">
            @foreach ($options as $entry)
                <option value="{{ $entry['name'] }}" label="{{ $entry['label'] }}"></option>
            @endforeach
        </datalist>
    </div>
    @if ($release)
        <input type="hidden" name="release" value="{{ $release }}"/>
    @endif
    <button type="submit" class="{{ Ui::button('ghost', 'base') }}">Compare units</button>
</form>
