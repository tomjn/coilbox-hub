{{--
    The report control for a map page. components/MapReportButton.tsx.

    A button that opens the form. The form is in the page from the start and
    hidden, and resources/js/maps/page.js swaps it in and posts it. After a post
    without a script, `state` is the answer and the form is shown with it.
--}}
@props(['mapId', 'slug', 'state' => null])
@use('App\Domain\Maps\Report')
@php $open = $state !== null; @endphp
<div id="map-report" class="flex flex-col" data-map-report>
    @if ($state['ok'] ?? false)
        <p role="status" class="text-xs text-neutral-400">{{ $state['message'] }}</p>
    @else
        <button type="button" class="self-start text-xs text-neutral-400 transition-colors hover:text-neutral-200 active:text-neutral-200" data-map-report-open @if ($open) hidden @endif>Report a problem with this map</button>
        <form action="/map/{{ rawurlencode($slug) }}/report" method="post" class="flex flex-col gap-3 rounded-md border border-neutral-800 bg-black p-3" data-map-report-form @unless ($open) hidden @endunless>
            @csrf
            <input type="hidden" name="map_id" value="{{ $mapId }}">
            <fieldset class="flex flex-col gap-1.5">
                <legend class="mb-1 text-xs text-neutral-400">What is wrong with it? No account needed.</legend>
                @foreach (Report::KINDS as $value => $label)
                    <label class="flex items-center gap-2 py-0.5 text-sm text-neutral-200"><input type="radio" name="kind" value="{{ $value }}" required>{{ $label }}</label>
                @endforeach
            </fieldset>
            <div class="flex flex-col gap-1.5">
                <label class="text-xs text-neutral-400" for="map-report-reason">Tell us more</label>
                <textarea id="map-report-reason" name="reason" rows="3" required maxlength="1000" class="w-full rounded border border-neutral-800 bg-card px-3 py-2 text-sm text-neutral-100 focus-visible:border-neutral-600 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-400"></textarea>
            </div>
            <p role="alert" class="text-sm text-red-400" data-map-report-error @unless ($open) hidden @endunless>{{ $state['message'] ?? '' }}</p>
            <div class="flex gap-2">
                <button type="submit" class="rounded border border-neutral-800 px-3 py-1.5 text-xs text-neutral-300 transition-colors hover:border-neutral-600 active:border-neutral-500 hover:text-white active:text-white disabled:opacity-60">Send</button>
                <button type="button" class="px-3 py-1.5 text-xs text-neutral-400 hover:text-neutral-200 active:text-neutral-200" data-map-report-cancel>Cancel</button>
            </div>
        </form>
    @endif
</div>
