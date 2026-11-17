{{--
    One row above the first page of the listing, such as Featured. Shows one
    row of cards at any width and renders nothing when there are no maps.

    The row is a size container, and the rules in app.css hide the cards that
    do not fit in it, which also keeps them out of the tab order. Only the first
    two cards load their minimap with the page. Lazy images in hidden cards are
    never fetched.
--}}
@props(['title', 'moreHref', 'moreLabel', 'maps', 'pictures'])
@if ($maps !== [])
    @php $id = 'maps-section-'.\Illuminate\Support\Str::slug($title); @endphp
    <section class="flex flex-col gap-3" aria-labelledby="{{ $id }}">
        <div class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
            <h2 id="{{ $id }}" class="text-xl font-semibold">{{ $title }}</h2>
            <a class="inline-flex min-h-9 items-center text-sm text-neutral-300 underline-offset-4 hover:underline active:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-400" href="{{ $moreHref }}">{{ $moreLabel }}</a>
        </div>
        <div class="map-row @container">
            <ul class="grid gap-4 grid-cols-[repeat(auto-fill,minmax(min(200px,calc(50%_-_8px)),1fr))]">
                @foreach ($maps as $index => $map)
                    @if (isset($pictures[$map['map_name']]))
                        <li><x-maps.card :map="$map" :picture="$pictures[$map['map_name']]" :eager="$index < 2" :heading-level="3" /></li>
                    @endif
                @endforeach
            </ul>
        </div>
    </section>
@endif
