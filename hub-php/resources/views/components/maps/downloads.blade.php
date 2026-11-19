{{--
    Where the map's owner, an author's owner or a moderator says it can be
    downloaded, in the order to try. Nothing at all when there are none, and
    the mirror guesses below stand on their own.
--}}
@use('App\Domain\Games\Download')
@props(['downloads', 'slug'])
@if ($downloads !== [])
    <section class="flex flex-col gap-3 border-t border-neutral-900 pt-6">
        <h2 class="text-xl font-semibold tracking-tight">Download</h2>
        <ul class="flex flex-wrap gap-3">
            @foreach ($downloads as $i => $download)
                <li><a href="/map/{{ $slug }}/download/{{ $i }}" rel="nofollow noreferrer" class="inline-block rounded bg-neutral-100 px-3 py-2 text-sm font-medium text-neutral-900 transition-colors hover:bg-white active:bg-neutral-300">{{ $download['kind'] === 'github' ? 'Releases on GitHub' : 'Download '.$download['filename'].(isset($download['sha256']) ? ' from '.parse_url($download['value'], PHP_URL_HOST) : '') }}</a></li>
            @endforeach
        </ul>
    </section>
@endif
