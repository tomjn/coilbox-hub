@props(['offers', 'action', 'showGame' => false])
@use('App\Support\Ui')
{{-- components/DownloadOffers.tsx: the download sources coilbox has offered
     and nobody has decided. An offer is a stranger's suggestion, so it is named
     as one and held apart from the game's own list. `showGame` draws the
     shortname on each row, for the queue that spans every game. --}}
@php
    $kindLabels = ['rapid' => 'Rapid tag', 'url' => 'Address', 'github' => 'GitHub repo'];
    // A rapid tag and a repo path are shown as stored, not dressed as links.
    $describe = fn (array $offer) => match ($offer['kind']) {
        'github' => $offer['asset'] ? $offer['value'].', picking '.$offer['asset'] : $offer['value'],
        'url' => $offer['filename'] ? $offer['value'].', saved as '.$offer['filename'] : $offer['value'],
        default => $offer['value'],
    };
@endphp
@if ($offers !== [])
    <ul class="flex flex-col gap-3">
        @foreach ($offers as $offer)
            @php $key = substr(sha1($action.'|'.$offer['id']), 0, 16); @endphp
            <li class="flex flex-col gap-2 rounded-md border border-neutral-900 p-4">
                <div class="flex flex-col gap-1">
                    <p class="text-sm uppercase tracking-wide text-neutral-400">{{ $showGame ? $offer['shortname'].' · ' : '' }}{{ $kindLabels[$offer['kind']] ?? $offer['kind'] }}</p>
                    <p class="break-all font-mono text-sm text-neutral-200">{{ $describe($offer) }}</p>
                </div>
                {{-- One decision with two answers, so one form. --}}
                <form method="post" action="{{ $action }}" class="flex flex-wrap items-center gap-2">
                    @csrf
                    <input type="hidden" name="_form" value="{{ $key }}">
                    <input type="hidden" name="offer" value="{{ $offer['id'] }}">
                    <input type="hidden" name="shortname" value="{{ $offer['shortname'] }}">
                    <button type="submit" name="accept" value="true" class="{{ Ui::button('ghost', 'md') }}">Add it</button>
                    <button type="submit" name="accept" value="false" class="{{ Ui::button('quiet', 'md') }}">Turn it down</button>
                    <x-games.form-answer :form="$key" />
                </form>
            </li>
        @endforeach
    </ul>
@endif
