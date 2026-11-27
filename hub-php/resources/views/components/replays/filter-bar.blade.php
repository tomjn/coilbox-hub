{{--
    The filters above the replay list. The form is a GET to the same route, so
    it works with scripting off. The page is not in the form, because a filter
    change starts at page one.
--}}
@props(['filters', 'options'])
@use('App\Domain\Replays\ReplayList')
@use('App\Support\Ui')
@php
    $control = Ui::FIELD.' min-h-9';
    $label = 'text-sm uppercase tracking-wide text-neutral-400';
    $chevron = '<svg aria-hidden="true" viewBox="0 0 16 16" class="pointer-events-none absolute right-3 top-1/2 size-4 -translate-y-1/2 text-neutral-400"><path d="M4 6l4 4 4-4" fill="none" stroke="currentColor" stroke-width="1.5"></path></svg>';
    $focus = 'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-400';
    $more = array_filter(array_intersect_key($filters, array_flip(['min_players', 'max_players', 'min_length', 'max_length', 'from', 'to', 'analysis'])), fn ($value) => $value !== null) !== [];
    // A linked game or map with no replay stays chosen, so the next search keeps it.
    $choices = fn (array $held, ?string $chosen) => $chosen === null || in_array($chosen, $held, true) ? $held : [$chosen, ...$held];
    $numbers = [
        'min_players' => ['Players, at least', 1],
        'max_players' => ['Players, at most', 1],
        'min_length' => ['Minutes, at least', 0],
        'max_length' => ['Minutes, at most', 0],
    ];
@endphp
<form class="group flex flex-wrap items-center gap-2" action="/replays" role="search" aria-label="Filter replays" data-busy-form>
    <input type="search" name="player" value="{{ $filters['player'] ?? '' }}" placeholder="Player name..." aria-label="Player name" aria-keyshortcuts="/" class="{{ $control }} min-w-0 sm:w-auto sm:flex-1" data-search-shortcut>

    @foreach ([['game', 'Game', 'Any game', $options['games']], ['map', 'Map', 'Any map', $options['maps']]] as [$name, $title, $any, $held])
        <div class="relative w-full sm:w-52">
            <select name="{{ $name }}" aria-label="{{ $title }}" class="{{ $control }} appearance-none pr-9">
                <option value="">{{ $any }}</option>
                @foreach ($choices($held, $filters[$name]) as $value)
                    <option value="{{ $value }}" @selected($filters[$name] === $value)>{{ $value }}</option>
                @endforeach
            </select>{!! $chevron !!}
        </div>
    @endforeach

    <button type="submit" class="{{ Ui::button() }} min-h-9">Apply</button>

    @if (ReplayList::isFiltered($filters))
        <a class="inline-flex min-h-9 items-center rounded-md px-2 text-sm text-neutral-400 hover:text-neutral-200 active:text-neutral-200 {{ $focus }}" href="/replays">Clear</a>
    @endif

    <details{{ $more ? ' open' : '' }} class="open:w-full">
        <summary class="inline-flex min-h-9 cursor-pointer items-center rounded-md px-2 text-sm text-neutral-300 hover:text-neutral-100 active:text-neutral-100 {{ $focus }}">More filters</summary>
        <div class="mt-2 grid grid-cols-2 gap-4 border-b border-neutral-900 pb-4 lg:grid-cols-4">
            @foreach ($numbers as $name => [$title, $lowest])
                <div class="flex min-w-0 flex-col gap-1.5">
                    <label for="replays-{{ $name }}" class="{{ $label }}">{{ $title }}</label>
                    <input id="replays-{{ $name }}" type="number" min="{{ $lowest }}" class="{{ $control }}" name="{{ $name }}" value="{{ $filters[$name] ?? '' }}">
                </div>
            @endforeach
            @foreach (['from' => 'Played from', 'to' => 'Played until'] as $name => $title)
                <div class="flex min-w-0 flex-col gap-1.5">
                    <label for="replays-{{ $name }}" class="{{ $label }}">{{ $title }}</label>
                    <input id="replays-{{ $name }}" type="date" class="{{ $control }} [color-scheme:dark]" name="{{ $name }}" value="{{ $filters[$name] ?? '' }}">
                </div>
            @endforeach
            <div class="flex min-w-0 flex-col gap-1.5">
                <label for="replays-analysis" class="{{ $label }}">Analysis</label>
                <div class="relative">
                    <select id="replays-analysis" name="analysis" class="{{ $control }} appearance-none pr-9">
                        <option value="">With or without</option>
                        <option value="yes" @selected($filters['analysis'] === 'yes')>With an analysis</option>
                        <option value="no" @selected($filters['analysis'] === 'no')>Without an analysis</option>
                    </select>{!! $chevron !!}
                </div>
            </div>
        </div>
    </details>
</form>
