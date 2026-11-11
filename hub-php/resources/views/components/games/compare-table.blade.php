{{-- compare/CompareTable.tsx. The side by side table both comparisons draw.
     The `left` and `right` slots are the column heads. The scroll container is
     there because a weapons list prints as one long line. --}}
@props(['rows'])
<div class="overflow-x-auto">
    <table class="w-full border-collapse text-sm">
        <thead>
            <tr class="border-b border-neutral-800 text-left text-sm uppercase tracking-wide text-neutral-400">
                <th scope="col" class="py-2 pr-4 align-bottom">Stat</th>
                <th scope="col" class="py-2 pr-4 align-bottom">{{ $left }}</th>
                <th scope="col" class="py-2 pr-4 align-bottom">{{ $right }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($rows as $row)
                <tr class="border-b border-neutral-900{{ $row['changed'] ? ' bg-neutral-900/60' : '' }}">
                    <th scope="row" class="py-2 pr-4 text-left align-top font-normal text-neutral-400">{{ $row['label'] }}@if ($row['changed'])<span class="sr-only"> (differs)</span>@endif</th>
                    <td class="break-all py-2 pr-4 align-top text-neutral-100">{{ $row['left'] }}</td>
                    <td class="break-all py-2 pr-4 align-top text-neutral-100">{{ $row['right'] }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
