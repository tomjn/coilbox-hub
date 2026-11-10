@props(['lines'])
{{-- components/CopyCommands.tsx. Every autohost command in one block, with one
     button that copies the lot. The button sits over the commands rather than
     beside them, because a tweakdef line is thousands of characters wide. The
     pre has select-all so dragging across it by hand still takes every line. --}}
<div class="flex flex-col gap-2" data-copy-commands>
    <div class="relative rounded border border-neutral-800 bg-black p-3">
        <pre class="max-h-80 select-all overflow-auto pr-24 text-xs leading-relaxed text-neutral-100" data-copy-text>{{ implode("\n", $lines) }}</pre>
        <button type="button" data-copy-button class="shrink-0 rounded border border-neutral-800 px-3 py-1.5 text-xs text-neutral-300 transition-colors hover:border-neutral-600 hover:text-white active:border-neutral-500 active:text-white absolute right-3 top-3 bg-black"><span aria-live="polite">{{ count($lines) === 1 ? 'Copy' : 'Copy all' }}</span></button>
    </div>
    <p class="text-xs text-neutral-400" data-copy-refused hidden>Your browser refused the copy. Click the commands to select them, then copy them yourself.</p>
</div>
@once
    @push('scripts')
        <script>
            document.addEventListener('click', async (event) => {
                const button = event.target instanceof Element ? event.target.closest('[data-copy-button]') : null;
                if (!button) return;
                const root = button.closest('[data-copy-commands]');
                try {
                    await navigator.clipboard.writeText(root.querySelector('[data-copy-text]').textContent);
                    button.firstElementChild.textContent = 'Copied';
                } catch {
                    root.querySelector('[data-copy-refused]').removeAttribute('hidden');
                }
            });
        </script>
    @endpush
@endonce
