{{-- StageStats in components/UnitStages.tsx. The stats across the stages. A
     row that never changes prints quiet, so what moves stands out. --}}
@props(['stages', 'rows'])
<div class="overflow-x-auto">
    <table class="w-full min-w-max text-sm">
        <thead>
            <tr>
                <th scope="col" class="border-b border-neutral-800 px-2 py-1 text-left font-normal text-neutral-400">Stat</th>
                @foreach ($stages as $stage)
                    <th scope="col" class="border-b border-neutral-800 px-2 py-1 text-left font-normal {{ $stage['current'] ? 'text-neutral-100' : 'text-neutral-400' }}">{{ $stage['label'] }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @foreach ($rows as $row)
                <tr>
                    <th scope="row" class="px-2 py-1 text-left font-normal text-neutral-400">{{ $row['label'] }}</th>
                    @foreach ($row['values'] as $value)
                        <td class="px-2 py-1 {{ $row['changed'] ? 'text-neutral-100' : 'text-neutral-400' }}">{{ $value }}</td>
                    @endforeach
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
