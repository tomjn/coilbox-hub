{{--
    Asking to own this map or one of its authors, for a signed in visitor who
    cannot edit it yet. Owning a map or an author lets you say where the map
    can be downloaded. Rare, so it folds down to one quiet line.
--}}
@use('App\Domain\Maps\MapEdits')
@props(['slug', 'targets'])
@php $asked = session('mapOwnership'); @endphp
@if (is_string($asked) && isset(MapEdits::OWNERSHIP_MESSAGES[$asked]))
    @php $ok = in_array($asked, ['sent', 'already'], true); @endphp
    <p role="{{ $ok ? 'status' : 'alert' }}" class="text-sm {{ $ok ? 'text-neutral-300' : 'text-red-400' }}">{{ MapEdits::OWNERSHIP_MESSAGES[$asked] }}</p>
@endif
@if ($targets !== [] && ! (is_string($asked) && in_array($asked, ['sent', 'already'], true)))
    <details>
        <summary class="w-fit cursor-pointer list-none text-sm text-neutral-400 underline-offset-4 transition-colors hover:text-neutral-200 hover:underline active:text-neutral-200 active:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-400">Did you make this map?</summary>
        <form method="post" action="/map/{{ $slug }}/ownership" class="mt-3 flex max-w-xl flex-col gap-3">
            @csrf
            <fieldset class="flex flex-col gap-1.5">
                <legend class="text-sm uppercase tracking-wide text-neutral-400">What to own</legend>
                @foreach ($targets as $i => $target)
                    <label class="flex items-center gap-2 text-sm text-neutral-200">
                        <input type="radio" name="target" value="{{ $target['value'] }}" @checked($i === 0)>
                        {{ $target['label'] }}
                    </label>
                @endforeach
            </fieldset>
            <p class="text-sm text-neutral-400">An owner can say where the map can be downloaded. Owning an author covers every map credited to them.</p>
            <label for="map-ownership-note" class="text-sm uppercase tracking-wide text-neutral-400">Say who you are and how you&rsquo;re involved</label>
            <textarea id="map-ownership-note" name="note" rows="4" maxlength="2000" class="w-full resize-none rounded-md border border-neutral-800 bg-card px-3 py-2 text-sm text-neutral-100 focus-visible:border-neutral-600 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-400"></textarea>
            <button type="submit" class="self-start rounded-md bg-neutral-100 px-5 py-2.5 text-sm font-medium text-neutral-900 transition-colors hover:bg-white active:bg-neutral-300">Request ownership</button>
        </form>
    </details>
@endif
