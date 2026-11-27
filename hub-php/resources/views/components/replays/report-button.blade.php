{{--
    The report control for a replay page (#671). A disclosure, so it opens
    without a script. After a post, `state` is the answer and the form stays
    open with it when it was refused.
--}}
@props(['gameId', 'signedIn', 'state' => null])
@php $refused = $state !== null && ! $state['ok']; @endphp
<div id="replay-report" class="flex flex-col">
    @if ($state['ok'] ?? false)
        <p role="status" class="text-sm text-neutral-400">{{ $state['message'] }}</p>
    @elseif (! $signedIn)
        <p class="text-sm text-neutral-400"><a href="/sign-in?next={{ rawurlencode('/replays/'.$gameId) }}" class="underline underline-offset-4 hover:text-neutral-200 active:text-neutral-200">Sign in</a> to report this replay.</p>
    @else
        <details class="group flex flex-col" @if ($refused) open @endif>
            <summary class="w-fit cursor-pointer text-sm text-neutral-400 transition-colors hover:text-neutral-200 active:text-neutral-200 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-400">Report this replay</summary>
            <form action="/replays/{{ rawurlencode($gameId) }}/report" method="post" class="mt-3 flex max-w-xl flex-col gap-3 rounded-md border border-neutral-800 bg-black p-3">
                @csrf
                <div class="flex flex-col gap-1.5">
                    <label class="text-sm text-neutral-400" for="replay-report-reason">What is wrong with it? If you played in this match and want it taken down, say so. Moderators read every report.</label>
                    <textarea id="replay-report-reason" name="reason" rows="3" required maxlength="1000" class="w-full rounded border border-neutral-800 bg-card px-3 py-2 text-sm text-neutral-100 focus-visible:border-neutral-600 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-400">{{ old('reason') }}</textarea>
                </div>
                @if ($refused)
                    <p role="alert" class="text-sm text-red-400">{{ $state['message'] }}</p>
                @endif
                <button type="submit" class="self-start rounded border border-neutral-800 px-3 py-1.5 text-sm text-neutral-300 transition-colors hover:border-neutral-600 active:border-neutral-500 hover:text-white active:text-white">Send report</button>
            </form>
        </details>
    @endif
</div>
