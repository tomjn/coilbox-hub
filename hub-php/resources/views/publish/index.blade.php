@extends('layouts.app')

@php
    use App\Support\Ui;
    $state = session('publish', []);
    $values = $state['values'] ?? [];
    $submit = 'self-start rounded-md bg-neutral-100 px-5 py-2.5 text-sm font-medium text-neutral-900 transition-colors hover:bg-white active:bg-neutral-300 disabled:opacity-60';
@endphp

@section('content')
<main id="main-content" tabindex="-1" class="relative flex-1">
    {{-- This drawing fills the canvas, so it sits well back from the
         gallery's strength. At 0.07 it competed with the title field. --}}
    <x-ui.art-backdrop drawing="setup-packs" :strength="0.045" />
    <div class="relative z-10 mx-auto flex w-full max-w-2xl flex-col gap-8 px-6 py-16">
        <div class="flex flex-col gap-2">
            <h1 class="text-3xl font-semibold tracking-tight">Publish</h1>
            <p class="text-neutral-400">Share something you have made in Coilbox. Anyone can browse and import it without an account.</p>
        </div>

        @if (! $signedIn)
            <div class="flex flex-col items-start gap-4 rounded-md border border-neutral-800 bg-card p-6">
                <p class="text-sm text-neutral-400">Signing in is only needed to publish, so your name is on it and you can change or withdraw it later.</p>
                <x-ui.sign-in-buttons next="/publish" />
            </div>
        @else
            <div class="flex items-center justify-between border-b border-neutral-900 pb-4 text-sm text-neutral-400">
                <span>Publishing as <span class="text-neutral-300">{{ $name ?? 'you' }}</span></span>
                <a href="/account" class="mr-4 transition-colors hover:text-neutral-300 active:text-neutral-300">Your account</a>
                <form action="/auth/signout" method="post">
                    @csrf
                    <button type="submit" class="transition-colors hover:text-neutral-300 active:text-neutral-300">Sign out</button>
                </form>
            </div>

            @isset($state['publishedId'])
                {{-- The share URL is what people paste into Discord, and what
                     Coilbox fetches when somebody clicks Import. --}}
                <div class="flex flex-col gap-4 rounded-md border border-neutral-800 bg-card p-6">
                    <h2 class="text-lg font-medium">Published</h2>
                    <p class="text-sm text-neutral-400">It is in the gallery, and anyone can import it without an account.</p>
                    <div class="flex flex-col gap-2">
                        <span class="text-sm text-neutral-400">Share this link. It opens in Coilbox.</span>
                        <code class="break-all rounded border border-neutral-800 bg-black px-3 py-2 text-sm text-neutral-300">{{ $state['shareUrl'] }}</code>
                    </div>
                    <div class="flex flex-wrap items-center gap-3">
                        <a href="/item/{{ $state['publishedId'] }}" class="rounded-md bg-neutral-100 px-5 py-2.5 text-sm font-medium text-neutral-900 transition-colors hover:bg-white active:bg-neutral-300">See it in the gallery</a>
                        <a href="/publish" class="{{ Ui::button('ghost', 'lg', 'font-medium') }}">Publish another</a>
                    </div>
                </div>
            @else
                <form action="/publish" method="post" class="flex flex-col gap-5" data-publish-form>
                    @csrf
                    <label class="flex flex-col gap-2">
                        <span class="text-sm font-medium">What you are sharing</span>
                        <span class="text-sm text-neutral-400">Whatever Coilbox gave you. A share link, a bare code from a setup pack, or an exported JSON file.</span>
                        <textarea name="code" rows="4" required spellcheck="false" placeholder="coilbox://import?code=…" class="{{ Ui::FIELD }} font-mono" data-publish-code>{{ $values['code'] ?? '' }}</textarea>
                        {{-- Scenarios can only be exported as a file. Reading it
                             into the box keeps one validation path on the server. --}}
                        <span class="flex items-center gap-3 text-sm text-neutral-400">
                            <label class="{{ Ui::button('ghost', 'sm', 'cursor-pointer') }}">
                                Or choose an exported file
                                <input type="file" accept="application/json,.json" class="hidden" data-publish-file>
                            </label>
                            <span data-publish-file-name hidden></span>
                        </span>
                    </label>

                    <label class="flex flex-col gap-2">
                        <span class="text-sm font-medium">Title</span>
                        <input name="title" required maxlength="120" value="{{ $values['title'] ?? '' }}" class="{{ Ui::FIELD }}">
                    </label>

                    <label class="flex flex-col gap-2">
                        <span class="text-sm font-medium">Description</span>
                        <span class="text-sm text-neutral-400">What it is for, and why somebody would want it.</span>
                        <textarea name="description" rows="4" maxlength="2000" class="{{ Ui::FIELD }}">{{ $values['description'] ?? '' }}</textarea>
                    </label>

                    <label class="flex flex-col gap-2">
                        <span class="text-sm font-medium">Tags</span>
                        <span class="text-sm text-neutral-400">Comma separated, up to eight. For the things a filter cannot work out on its own.</span>
                        <input name="tags" value="{{ $values['tags'] ?? '' }}" class="{{ Ui::FIELD }}" placeholder="eco, 8v8, beginner">
                    </label>

                    @isset($state['error'])
                        <p role="alert" class="text-sm text-red-400">{{ $state['error'] }}</p>
                    @endisset

                    <button type="submit" class="{{ $submit }}" data-busy-label="Publishing…">Publish</button>
                </form>
            @endisset
        @endif
    </div>
</main>
@endsection

@push('scripts')
    <script>
        for (const input of document.querySelectorAll('[data-publish-file]')) {
            input.addEventListener('change', async () => {
                const file = input.files?.[0];
                const form = input.closest('form');
                if (!file || !form) return;
                form.querySelector('[data-publish-code]').value = await file.text();
                const name = form.querySelector('[data-publish-file-name]');
                name.textContent = 'Loaded ' + file.name;
                name.hidden = false;
            });
        }
    </script>
    @include('publish.busy-script')
@endpush
