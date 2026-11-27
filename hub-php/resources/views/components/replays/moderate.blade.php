{{--
    What a moderator can do on a replay page (#671): hide all of its chat, or
    take the replay down. Single messages are hidden from the chat list.
--}}
@props(['gameId', 'chatCount'])
@use('App\Domain\Replays\ReplayModeration')
@use('App\Support\Ui')
<section aria-labelledby="replay-moderate" class="flex flex-col gap-3 rounded-lg border border-amber-500/30 bg-card p-4">
    <h2 id="replay-moderate" class="text-sm font-medium">Moderate</h2>
    @if (session('replayModeration'))
        <p role="alert" class="text-sm text-red-400">{{ session('replayModeration')['message'] }}</p>
    @endif
    <div class="flex flex-wrap items-end gap-x-6 gap-y-3">
        @if ($chatCount > 0)
            <form method="post" action="/moderation/replays/chat">
                @csrf
                <input type="hidden" name="game_id" value="{{ $gameId }}">
                <button type="submit" class="{{ Ui::button('quiet') }}">Hide all {{ $chatCount === 1 ? 'chat' : number_format($chatCount).' chat messages' }}</button>
            </form>
        @else
            <p class="text-sm text-neutral-400">No chat is showing.</p>
        @endif
        <form method="post" action="/moderation/replays/remove" class="flex flex-wrap items-end gap-3">
            @csrf
            <input type="hidden" name="game_id" value="{{ $gameId }}">
            <label class="flex flex-col gap-1 text-sm text-neutral-400">
                Why it is coming down
                <select name="reason" required class="rounded-md border border-neutral-700 bg-neutral-950 px-3 py-2 text-sm text-neutral-200">
                    @foreach (ReplayModeration::REASONS as $code => $label)
                        <option value="{{ $code }}">{{ $label }}</option>
                    @endforeach
                </select>
            </label>
            <button type="submit" class="{{ Ui::button() }}">Remove the replay</button>
        </form>
    </div>
    <p class="text-xs text-neutral-400">Removing deletes the replay's players, chat and analyses, takes it out of map and game totals and tells mirrors to delete its files. Nobody can send the same match again. It cannot be undone.</p>
</section>
