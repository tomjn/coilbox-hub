{{--
    The filter bar above the map listing: quick chips, a search box, the sort
    order and a "More filters" panel for the rest. Laid out like the map page
    in the Coilbox app.

    The chips are links, so they work with scripting off. The form is a GET to
    the same route and keeps the chips by sending them as hidden fields. The
    page is not in the form, because a filter change starts at page one.
--}}
@props(['filters'])
@use('App\Domain\Maps\Listing')
@use('App\Support\Ui')
@php
    $control = Ui::FIELD;
    $label = 'text-sm uppercase tracking-wide text-neutral-400';
    $chevron = '<svg aria-hidden="true" viewBox="0 0 16 16" class="pointer-events-none absolute right-3 top-1/2 size-4 -translate-y-1/2 text-neutral-400"><path d="M4 6l4 4 4-4" fill="none" stroke="currentColor" stroke-width="1.5"></path></svg>';
    $focus = 'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-400';
    $chipOff = 'border-neutral-800 text-neutral-300 hover:border-neutral-600 active:border-neutral-500 hover:text-neutral-100 active:text-neutral-100';
    $chipOn = 'border-amber-500 text-amber-400';
    $sizeLabels = ['small' => 'Small, up to 12 squares', 'medium' => 'Medium, up to 20 squares', 'large' => 'Large, over 20 squares'];
    $sortLabels = ['name' => 'Name', 'size' => 'Longer edge largest first', 'players' => 'Players most first', 'added' => 'Recently added', 'uploaded' => 'Upload date'];
    $chipTags = ['water map', 'tidal', 'windy'];
    $keptTags = array_values(array_intersect($filters['tag'], $chipTags));
    $freeTag = array_values(array_diff($filters['tag'], $chipTags))[0] ?? null;
    $more = $filters['author'] !== null || $filters['size'] !== null || $filters['players'] !== null || $freeTag !== null;
@endphp
<div class="flex flex-wrap items-center gap-2">
    @foreach (Listing::chips($filters) as $chip)
        <a href="{{ $chip['href'] }}" @if ($chip['active']) aria-current="true" @endif class="inline-flex min-h-9 items-center rounded-md border bg-card px-3 text-sm font-medium transition-colors {{ $focus }} {{ $chip['active'] ? $chipOn : $chipOff }}">{{ $chip['label'] }}</a>
    @endforeach

    {{-- A GET to this same route, so it works with scripting off. --}}
    <form class="group flex min-w-0 flex-1 flex-wrap items-center gap-2" action="/maps" data-busy-form>
        @foreach ($filters['play'] as $type)
            <input type="hidden" name="play" value="{{ $type }}">
        @endforeach
        @foreach ($keptTags as $tag)
            <input type="hidden" name="tag" value="{{ $tag }}">
        @endforeach
        @if ($filters['featured'])
            <input type="hidden" name="featured" value="1">
        @endif

        <input type="search" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Search maps..." aria-label="Search maps" aria-keyshortcuts="/" class="{{ $control }} min-h-9 min-w-48 flex-1 sm:max-w-sm" data-search-shortcut>

        <div class="relative">
            <select name="sort" aria-label="Sort by" class="{{ $control }} min-h-9 w-auto appearance-none pr-9" data-submit-on-change>
                @foreach (Listing::SORTS as $sort)
                    <option value="{{ $sort }}" @selected($filters['sort'] === $sort)>{{ $sortLabels[$sort] }}</option>
                @endforeach
            </select>{!! $chevron !!}
        </div>

        @if (Listing::isFiltered($filters))
            <a class="inline-flex min-h-9 items-center rounded-md px-2 text-sm text-neutral-400 hover:text-neutral-200 active:text-neutral-200 {{ $focus }}" href="/maps">Clear</a>
        @endif

        <details{{ $more ? ' open' : '' }} class="open:w-full">
            <summary class="inline-flex min-h-9 cursor-pointer items-center rounded-md px-2 text-sm text-neutral-300 hover:text-neutral-100 active:text-neutral-100 {{ $focus }}">More filters</summary>
            <div class="mt-2 grid gap-4 border-b border-neutral-900 pb-4 sm:grid-cols-2 lg:grid-cols-4">
                <div class="flex flex-col gap-1.5">
                    <label for="maps-author" class="{{ $label }}">Made by</label>
                    <input id="maps-author" type="text" class="{{ $control }} min-h-9" name="author" value="{{ $filters['author'] ?? '' }}">
                </div>
                {{-- A text box rather than a list: the chips on the cards are how a reader finds a tag. --}}
                <div class="flex flex-col gap-1.5">
                    <label for="maps-tag" class="{{ $label }}">Tag</label>
                    <input id="maps-tag" type="text" class="{{ $control }} min-h-9" name="tag" value="{{ $freeTag ?? '' }}">
                </div>
                <div class="flex flex-col gap-1.5">
                    <label for="maps-size" class="{{ $label }}">Size</label>
                    <div class="relative">
                        <select id="maps-size" name="size" class="{{ $control }} min-h-9 appearance-none pr-9">
                            <option value="" @selected($filters['size'] === null)>Any size</option>
                            @foreach (Listing::SIZES as $size)
                                <option value="{{ $size }}" @selected($filters['size'] === $size)>{{ $sizeLabels[$size] }}</option>
                            @endforeach
                        </select>{!! $chevron !!}
                    </div>
                </div>
                <div class="flex flex-col gap-1.5">
                    <label for="maps-players" class="{{ $label }}">Players, at least</label>
                    <input id="maps-players" type="number" min="1" class="{{ $control }} min-h-9" name="players" value="{{ $filters['players'] ?? '' }}">
                </div>
                <div class="flex items-center">
                    <button type="submit" class="{{ Ui::button() }}">Apply</button>
                </div>
            </div>
        </details>
    </form>
</div>
