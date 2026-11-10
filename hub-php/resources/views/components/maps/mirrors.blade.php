{{--
    Where to look for a map's archive. components/MapMirrors.tsx.

    Nothing at all when there are no links. "Look for it on", never "download":
    nothing has asked the host whether it holds this map.
--}}
@props(['links'])
@if ($links !== [])
    <section class="flex flex-col gap-3 border-t border-neutral-900 pt-6">
        <h2 class="text-xl font-semibold tracking-tight">Where to look for this map</h2>
        <p class="text-sm text-neutral-400">The hub holds facts about maps, not the maps themselves. These sites host map archives and may have this one.</p>
        <ul class="flex flex-wrap gap-3">
            @foreach ($links as $link)
                <li><a href="{{ $link['url'] }}" target="_blank" rel="noreferrer" class="inline-block rounded bg-neutral-900 px-3 py-2 text-sm text-neutral-300 transition-colors hover:text-white active:text-white">Look for it on {{ $link['name'] }}</a></li>
            @endforeach
        </ul>
    </section>
@endif
