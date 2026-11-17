<div class="flex flex-wrap items-baseline justify-between gap-3">
    <span class="break-all text-base font-medium">{{ $a['filename'] }}</span>
    @if ($a['listed_at'])
        <span class="text-sm text-neutral-400">Listed <x-ui.date-time :value="$a['listed_at']" /></span>
    @endif
</div>
<p class="text-sm text-neutral-400">{{ $a['kind'] === 'game' ? 'Game: ' : 'Map: ' }}@if ($a['page'] ?? null)<a href="{{ $a['page'] }}" class="text-neutral-300 underline underline-offset-4 hover:text-neutral-100 active:text-neutral-100">{{ $a['internal_name'] }}</a>@else{{ $a['internal_name'] }}@endif{{ ($a['version'] !== '' ? ', version '.$a['version'] : '').($a['shortname'] ? ', shortname '.$a['shortname'] : '').($a['author'] ? ', by '.$a['author'] : '') }}</p>
<p class="break-all font-mono text-sm text-neutral-400">{{ $a['sha256'] }}</p>
@if ($a['copies'] !== [])
    <ul class="flex flex-wrap gap-x-4 gap-y-1 text-sm">
        @foreach ($a['copies'] as $url)
            <li><a href="{{ $url }}" class="text-neutral-300 underline-offset-4 hover:underline active:underline">Download from {{ parse_url($url, PHP_URL_HOST) }}</a></li>
        @endforeach
    </ul>
@else
    <p class="text-sm text-neutral-400">No healthy mirror holds a copy right now.</p>
@endif
