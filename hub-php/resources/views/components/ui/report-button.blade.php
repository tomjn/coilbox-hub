@props(['itemId'])
{{-- components/ReportButton.tsx. "Report this" opens a short form. Anyone can
     report, signed in or not. After sending, the page comes back saying so. --}}
@if (session('reported') === $itemId)
    <p class="text-sm text-neutral-400">Reported. Thank you.</p>
@else
    <div data-report>
        <button type="button" data-report-open class="self-start text-sm text-neutral-400 transition-colors hover:text-neutral-200 active:text-neutral-200">Report this</button>
        <form method="post" action="/item/{{ $itemId }}/report" class="flex flex-col gap-2 rounded-md border border-neutral-800 bg-black p-3" data-report-form hidden>
            @csrf
            <input type="hidden" name="item_id" value="{{ $itemId }}">
            <label class="text-sm text-neutral-400" for="reason">What is wrong with it? No account needed.</label>
            <textarea id="reason" name="reason" rows="3" required maxlength="1000" class="w-full rounded border border-neutral-800 bg-card px-3 py-2 text-sm text-neutral-100 focus-visible:border-neutral-600 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-400"></textarea>
            <div class="flex gap-2">
                <button type="submit" class="rounded border border-neutral-800 px-3 py-1.5 text-sm text-neutral-300 transition-colors hover:border-neutral-600 active:border-neutral-500 hover:text-white active:text-white disabled:opacity-60">Send</button>
                <button type="button" data-report-cancel class="px-3 py-1.5 text-sm text-neutral-400 hover:text-neutral-200 active:text-neutral-200">Cancel</button>
            </div>
        </form>
    </div>
    @once
        @push('scripts')
            <script>
                for (const root of document.querySelectorAll('[data-report]')) {
                    const open = root.querySelector('[data-report-open]');
                    const form = root.querySelector('[data-report-form]');
                    open.addEventListener('click', () => { open.hidden = true; form.hidden = false; form.querySelector('textarea').focus(); });
                    root.querySelector('[data-report-cancel]').addEventListener('click', () => { form.hidden = true; open.hidden = false; });
                    form.addEventListener('submit', () => {
                        const send = form.querySelector('[type=submit]');
                        send.textContent = 'Sending…';
                        setTimeout(() => { send.disabled = true; });
                    });
                }
            </script>
        @endpush
    @endonce
@endif
