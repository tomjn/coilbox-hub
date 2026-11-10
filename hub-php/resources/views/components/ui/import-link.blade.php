@props(['shareUrl', 'variant' => 'quiet'])
{{-- components/ImportLink.tsx. The link opens Coilbox. If nothing happens,
     Coilbox is probably not installed, so a click also shows a way to get it
     and the link to copy. That help is drawn hidden here and shown by the
     script below. --}}
@php
    $fallbackAction = 'rounded border border-neutral-800 px-2 py-1 text-neutral-300 transition-colors hover:border-neutral-600 active:border-neutral-500 hover:text-white active:text-white';
    $solid = $variant === 'solid';
    $class = $solid
        ? 'inline-flex items-center gap-2 self-start rounded-md bg-neutral-100 px-5 py-2.5 text-sm font-medium text-neutral-900 transition-colors hover:bg-white active:bg-neutral-300'
        : '-my-1 inline-flex items-center gap-1.5 self-start py-1 text-sm text-neutral-300 underline-offset-4 hover:underline active:underline';
@endphp
<div class="flex flex-col gap-2" data-import-link data-share-url="{{ $shareUrl }}">
    <a href="coilbox://import?url={{ rawurlencode($shareUrl) }}" class="{{ $class }}"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" class="{{ $solid ? 'size-4' : 'size-3.5' }}"><path d="M12 5v14M5 12h14"></path></svg>Import into Coilbox</a>
    @if ($solid)
        <div class="flex flex-col gap-2 rounded-md border border-neutral-800 bg-black p-3" data-import-help hidden>
            <p class="text-xs text-neutral-400">Nothing happened? Coilbox is not installed, or has never been opened on this machine. Copy this and paste it into Coilbox.</p>
            <div class="flex items-center gap-2">
                <code class="min-w-0 flex-1 overflow-hidden text-ellipsis whitespace-nowrap text-xs text-neutral-400">{{ $shareUrl }}</code>
                <button type="button" data-import-copy data-done="Copied" class="shrink-0 text-xs {{ $fallbackAction }}">Copy</button>
            </div>
            <p class="text-xs text-neutral-400">Do not have Coilbox? <a href="{{ config('hub.coilbox_url') }}" target="_blank" rel="noreferrer" class="text-neutral-300 underline underline-offset-4 transition-colors hover:text-white active:text-white">Get it for your platform</a></p>
        </div>
    @else
        <div class="flex flex-col gap-2 text-xs text-neutral-400" data-import-help hidden>
            <p>Nothing happened? Coilbox is not installed here.</p>
            <div class="flex flex-wrap items-center gap-2">
                <a href="{{ config('hub.coilbox_url') }}" target="_blank" rel="noreferrer" class="{{ $fallbackAction }}">Get Coilbox</a>
                <button type="button" data-import-copy data-done="Copied" class="{{ $fallbackAction }}">Copy the link</button>
            </div>
            <code class="block break-all text-neutral-400" data-import-failed hidden>{{ $shareUrl }}</code>
        </div>
    @endif
</div>
@once
    @push('scripts')
        <script>
            document.addEventListener('click', async (event) => {
                const root = event.target instanceof Element ? event.target.closest('[data-import-link]') : null;
                if (!root) return;
                if (event.target.closest('a[href^="coilbox:"]')) {
                    root.querySelector('[data-import-help]')?.removeAttribute('hidden');
                    return;
                }
                const button = event.target.closest('[data-import-copy]');
                if (!button) return;
                try {
                    await navigator.clipboard.writeText(root.dataset.shareUrl);
                    button.textContent = button.dataset.done;
                } catch {
                    root.querySelector('[data-import-failed]')?.removeAttribute('hidden');
                }
            });
        </script>
    @endpush
@endonce
