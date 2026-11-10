@props(['commands'])
{{-- components/LobbyCommands.tsx. The way to use an item without coilbox
     (issue #418): the autohost commands, copied from one block. A project's
     lines each set one option, so their order is only for reading. A preset's
     rebuild a game setup step by step, and the map has to come first. --}}
@php
    $lines = $commands['lines'];
    $preset = $commands['kind'] === 'preset';
    $chunks = $commands['chunks'];
@endphp
<section id="lobby" class="flex min-w-0 flex-col gap-4 rounded-md border border-neutral-800 bg-card p-5">
    <div class="flex flex-col gap-1.5">
        <h2 class="text-lg font-semibold tracking-tight">Autohost commands</h2>
        @if (count($lines) > 0)
            <p class="text-sm text-neutral-400">Paste {{ count($lines) === 1 ? 'this command' : 'these '.count($lines).' commands' }} into a lobby&rsquo;s chat.</p>
        @endif
    </div>

    @if (count($lines) > 0)
        <x-ui.copy-commands :lines="$lines" />
        @if ($commands['typedValuesNote'])
            <p class="text-sm text-neutral-400">{{ $commands['typedValuesNote'] }}</p>
        @endif
    @else
        <div class="flex flex-col gap-1.5 text-sm text-neutral-400">
            <p>This {{ $preset ? 'preset' : 'project' }} cannot be {{ $preset ? 'rebuilt in a lobby' : 'pasted into a lobby whole' }}, so no commands are offered.</p>
            <ul class="list-disc pl-5">
                @foreach ($commands['withheld'] as $reason)
                    <li>{{ $reason }}</li>
                @endforeach
            </ul>
            <p>Import it into Coilbox to play {{ $preset ? 'it' : 'with it' }}.</p>
        </div>
    @endif

    @if (count($lines) > 0 && count($commands['notCarried']) > 0)
        <div class="flex flex-col gap-1.5 text-sm text-neutral-400">
            <p>What these commands could not set:</p>
            <ul class="list-disc pl-5">
                @foreach ($commands['notCarried'] as $note)
                    <li>{{ $note }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    {{-- A preset carries no Lua at all, so there is nothing behind its commands to read. --}}
    @if (count($chunks) > 0)
        <details class="group text-sm">
            <summary class="cursor-pointer self-start text-neutral-300 underline-offset-4 hover:text-white hover:underline">Read the Lua {{ count($lines) > 0 ? 'these commands carry' : 'it compiles to' }}</summary>
            <div class="mt-3 flex flex-col gap-4">
                @foreach ($chunks as $chunk)
                    <figure class="flex flex-col gap-1.5">
                        <figcaption class="flex flex-col gap-0.5">
                            <span class="text-neutral-100">{{ $chunk['title'] }}</span>
                            <span class="text-xs text-neutral-400">{{ $chunk['reason'] }}</span>
                        </figcaption>
                        <pre class="max-h-96 overflow-auto rounded border border-neutral-800 bg-black p-3 text-xs text-neutral-300">{{ $chunk['lua'] }}</pre>
                    </figure>
                @endforeach
            </div>
        </details>
    @endif
</section>
