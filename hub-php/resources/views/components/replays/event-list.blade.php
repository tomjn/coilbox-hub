{{--
    ReplayEventList: events and chat in match order, narrowed by links so it
    works without JavaScript. Chat to one player was never kept.
--}}
@props(['timeline', 'show', 'base', 'moderator' => false, 'gameId' => null])
@use('App\Domain\Replays\ReplayPage')
@php
    $rows = array_values(array_filter($timeline, fn ($r) => $show === 'all' || ($show === 'chat') === ($r['kind'] === 'Chat')));
    $focus = 'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-400';
@endphp
<section class="flex flex-col gap-3">
    <h2 class="text-sm font-medium">Events and chat</h2>
    <nav aria-label="Show" class="w-fit rounded-lg bg-neutral-900 p-1">
        <ul class="flex gap-1 text-sm">
            @foreach (ReplayPage::SHOW as $key => $label)
                <li><a href="{{ $base }}?tab=events{{ $key === 'all' ? '' : '&show='.$key }}" @if ($show === $key) aria-current="page" @endif class="block rounded-md px-3 py-1 transition-colors {{ $focus }} {{ $show === $key ? 'bg-neutral-700 text-white' : 'text-neutral-400 hover:text-neutral-100 active:text-neutral-100' }}">{{ $label }}</a></li>
            @endforeach
        </ul>
    </nav>
    <p class="text-xs text-neutral-400">{{ count($rows) }} of {{ count($timeline) }} {{ count($timeline) === 1 ? 'entry matches' : 'entries match' }}.</p>
    @if ($rows !== [])
        <ol class="max-h-[36rem] overflow-y-auto rounded-lg border border-neutral-800 bg-card text-sm">
            @foreach ($rows as $row)
                <li class="grid {{ $moderator ? 'grid-cols-[3.5rem_3.5rem_minmax(0,1fr)_auto]' : 'grid-cols-[3.5rem_3.5rem_minmax(0,1fr)]' }} gap-2 border-b border-neutral-900 px-3 py-1.5 last:border-b-0">
                    <span class="tabular-nums text-neutral-400">{{ $row['time'] }}</span>
                    <span class="text-xs leading-5 text-neutral-400">{{ $row['kind'] }}</span>
                    <span class="break-words">{{ $row['text'] }}</span>
                    @if ($moderator)
                        @if ($row['seq'] !== null)
                            <form method="post" action="/moderation/replays/chat">
                                @csrf
                                <input type="hidden" name="game_id" value="{{ $gameId }}">
                                <input type="hidden" name="seq" value="{{ $row['seq'] }}">
                                <button type="submit" class="min-h-6 rounded px-2 text-xs text-neutral-400 underline-offset-4 hover:text-neutral-100 hover:underline active:text-neutral-100 {{ $focus }}">Hide<span class="sr-only"> this message</span></button>
                            </form>
                        @else
                            <span></span>
                        @endif
                    @endif
                </li>
            @endforeach
        </ol>
    @endif
</section>
