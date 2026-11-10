{{-- One edge of the build graph as catalog cells. `units` is a list of
     ['name', 'label'], `pictures` is keyed by unit name. --}}
@props(['game', 'units', 'pictures'])
<ul class="grid grid-cols-3 gap-3 sm:grid-cols-4 md:grid-cols-6">
    @foreach ($units as $index => $entry)
        <x-games.unit-cell
            :game="$game"
            :unit="['unit_name' => $entry['name'], 'full_name' => $entry['label']]"
            :picture="$pictures[$entry['name']] ?? ['from' => 'placeholder', 'keyedOn' => 'unit', 'name' => $entry['name'], 'footprint' => null]"
            :eager="$index < 16" />
    @endforeach
</ul>
