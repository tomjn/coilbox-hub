{{-- One heading's groups on the release changes page, ChangesView.tsx
     Groups. A lone group opens, because there is nothing else to choose
     between. `mode` is 'ref' for a plain link per unit or 'changed' for the
     full diff of each. --}}
@props(['groups', 'game', 'mode' => 'ref', 'from' => null, 'to' => null, 'labels' => [], 'factionNames' => []])
<div class="flex flex-col gap-2">
    @foreach ($groups as $group)
        <details @if (count($groups) === 1)open="" @endif class="rounded-md border border-neutral-800 bg-card">
            <summary class="cursor-pointer px-4 py-3 text-sm text-neutral-200">{{ $group['name'] }} <span class="text-neutral-400">({{ count($group['units']) }})</span></summary>
            <ul class="flex flex-col divide-y divide-neutral-900 border-t border-neutral-900">
                @foreach ($group['units'] as $unit)
                    <li class="px-4 py-3">
                        @if ($mode === 'changed')
                            <x-games.change-unit :game="$game" :unit="$unit" :from="$from" :to="$to" :labels="$labels" :faction-names="$factionNames" />
                        @else
                            <x-games.change-unit-link :game="$game" :unit="$unit" />
                        @endif
                    </li>
                @endforeach
            </ul>
        </details>
    @endforeach
</div>
