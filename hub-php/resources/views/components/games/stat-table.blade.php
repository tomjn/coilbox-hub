{{-- components/StatTable.tsx. A unit's stats, with a list of records drawn as
     its own table rather than a JSON blob. --}}
@props(['stats'])
@use('App\Domain\Games\Stats')
@php $rows = Stats::statRows($stats); @endphp
@if ($rows === [])
    <p class="text-sm text-neutral-400">No stats reported for this unit yet.</p>
@else
    <dl class="grid grid-cols-[minmax(8rem,1fr)_2fr] gap-x-4 gap-y-1.5 text-sm">
        @foreach ($rows as $row)
            @php $tabular = Stats::tabularStatRows($stats[$row['key']]); @endphp
            <div class="col-span-2 grid grid-cols-subgrid">
                <dt class="text-neutral-400">{{ $row['label'] }}</dt>
                <dd class="text-neutral-100">
                    @if ($tabular !== null)
                        @php $columns = Stats::tabularColumns($tabular); @endphp
                        <table class="w-full max-w-xl text-sm">
                            <thead>
                                <tr>
                                    @foreach ($columns as $column)
                                        <th scope="col" class="border-b border-neutral-800 px-2 py-1 text-left font-normal text-neutral-400">{{ Stats::statLabel($column) }}</th>
                                    @endforeach
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($tabular as $record)
                                    <tr>
                                        @foreach ($columns as $column)
                                            <td class="px-2 py-1 text-neutral-100">{{ Stats::formatStatValue($record[$column] ?? null) }}</td>
                                        @endforeach
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    @else
                        {{ Stats::formatStatValue($stats[$row['key']]) }}
                    @endif
                </dd>
            </div>
        @endforeach
    </dl>
@endif
