{{-- UnitRenders in components/UnitPictures.tsx. Every angle the hub holds, or
     nothing when it holds none. A substituted render is the buildpic, which the
     portrait already shows. --}}
@props(['label', 'renders'])
@php
    $angles = ['top' => 'Top down', 'front' => 'Front', 'side' => 'Side', 'angled' => 'Angled'];
    // From xl, three or four angles straighten into one row.
    $wide = [
        3 => ' xl:mx-0 xl:grid xl:max-w-none xl:grid-cols-3 xl:items-stretch',
        4 => ' xl:-mx-24 xl:grid xl:max-w-none xl:grid-cols-4 xl:items-stretch',
    ];
    $shown = array_values(array_filter($renders, fn (array $render) => $render['asset']['from'] !== 'placeholder' && ! $render['asset']['substituted']));
@endphp
@if ($shown !== [])
    <section class="flex flex-col gap-3" aria-labelledby="unit-renders">
        <h2 id="unit-renders" class="text-sm uppercase tracking-wide text-neutral-400">Renders</h2>
        <div class="mx-auto flex max-w-2xl flex-wrap items-start justify-center gap-4{{ $wide[count($shown)] ?? '' }}">
            @foreach ($shown as $render)
                @php $caption = $angles[$render['angle']] ?? $render['angle']; @endphp
                <figure class="flex flex-col items-center gap-2 rounded-md border border-neutral-900 bg-black p-6">
                    <img src="{{ $render['asset']['url'] }}" alt="{{ $caption }} render of {{ $label }}" width="{{ $render['asset']['width'] }}" height="{{ $render['asset']['height'] }}" decoding="async" class="my-auto h-auto max-w-full object-contain"/>
                    <figcaption class="text-xs text-neutral-400">{{ $caption }}</figcaption>
                </figure>
            @endforeach
        </div>
    </section>
@endif
