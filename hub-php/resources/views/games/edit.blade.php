@extends('layouts.app')

@use('App\Domain\Games\ImageUpload')
@use('App\Support\Ui')

{{-- app/games/[shortname]/edit/page.tsx. One form per kind of write, so a
     failed upload never takes the description with it. Every form is a plain
     post. The two ordered lists and the picture uploads need the script below:
     the lists are reordered in the browser, and a picture is shrunk or
     converted there before it is sent. --}}
@php
    $base = '/games/'.$shortname.'/edit';
    $label = 'text-sm uppercase tracking-wide text-neutral-400';
    $heading = 'text-sm uppercase tracking-wide text-neutral-400';
    $section = 'flex flex-col gap-3 border-t border-neutral-900 pt-6';
    $links = old('label') !== null
        ? array_map(fn ($l, $u) => ['label' => (string) $l, 'url' => (string) $u], (array) old('label'), (array) old('url'))
        : $page['links'];
    // A refused save keeps the list as it was sent, as useActionState did.
    $downloads = old('downloads', json_encode(array_map(fn ($d) => [
        'kind' => $d['kind'], 'value' => $d['value'], 'asset' => $d['asset'] ?? '', 'filename' => $d['filename'] ?? '',
    ], $page['downloads'])));
    $factions = old('factions', json_encode($page['conquest_factions']));
    $pictures = [
        'logo' => 'Square, PNG or WebP, up to 512 KB.',
        'banner' => 'Wide, PNG or WebP, up to 512 KB.',
        'card' => '16:9, PNG or WebP, up to 512 KB. The games listing draws this above the name, and a lobby shows it on its own card for the game.',
    ];
    $offerCount = count($offers);
@endphp

@section('content')
<main id="main-content" tabindex="-1" class="mx-auto w-full max-w-3xl flex-1 px-6 py-10">
    <x-games.breadcrumb :crumbs="[['label' => $gameTitle, 'href' => '/games/'.$shortname], ['label' => 'Edit']]" />

    <div class="flex flex-col gap-10 pt-8">
        <div class="flex flex-col gap-2">
            <h1 class="text-2xl font-semibold tracking-tight">Edit {{ $page['shortname'] }}</h1>
            <p class="text-sm text-neutral-400">Your words sit on top of whatever was backfilled from the archives, and a reader sees them where they exist.</p>
        </div>

        <form method="post" action="{{ $base }}/details" class="flex flex-col gap-4">
            @csrf
            <input type="hidden" name="_form" value="details">
            <div class="flex flex-col gap-1.5">
                <label for="display_name" class="{{ $label }}">Display name</label>
                <input id="display_name" name="display_name" value="{{ old('display_name', $page['display_name'] ?? '') }}" maxlength="256" class="{{ Ui::FIELD }}">
            </div>
            <div class="flex flex-col gap-1.5">
                <label for="description" class="{{ $label }}">Description</label>
                <textarea id="description" name="description" maxlength="4000" rows="5" class="{{ Ui::FIELD }}" aria-describedby="description-help">{{ old('description', $page['description'] ?? '') }}</textarea>
                <p id="description-help" class="text-sm text-neutral-400">A blank line starts a new paragraph, one line break keeps text on the next line. Wrap words in **two asterisks** for bold, and *one asterisk* or _underscores_ for italic. Nothing else formats - no links, headings or HTML.</p>
            </div>
            <fieldset class="flex flex-col gap-2">
                <legend class="{{ $label }}">Links</legend>
                @foreach ([0, 1, 2, 3, 4] as $row)
                    <div class="flex gap-2">
                        <input name="label[]" placeholder="Label" value="{{ $links[$row]['label'] ?? '' }}" aria-label="Link {{ $row + 1 }} label" class="{{ Ui::FIELD }} w-40">
                        <input name="url[]" placeholder="https://" type="url" value="{{ $links[$row]['url'] ?? '' }}" aria-label="Link {{ $row + 1 }} URL" class="{{ Ui::FIELD }}">
                    </div>
                @endforeach
            </fieldset>
            <div class="flex items-center gap-3">
                <button type="submit" class="{{ Ui::button('ghost', 'base', 'self-start') }}">Save</button>
                <x-games.form-answer form="details" />
            </div>
        </form>

        <section class="{{ $section }}">
            <h2 class="{{ $heading }}">Downloads</h2>
            @if ($offerCount > 0)
                <div class="flex flex-col gap-3">
                    <p class="text-sm text-neutral-400">Coilbox has offered {{ $offerCount === 1 ? 'a place' : 'these places' }} to fetch {{ $shortname }} from. Nobody sees {{ $offerCount === 1 ? 'it' : 'them' }} until you add {{ $offerCount === 1 ? 'it' : 'them' }}, and adding puts {{ $offerCount === 1 ? 'it' : 'them' }} at the end of the list below.</p>
                    <x-games.download-offers :offers="$offers" :action="$base.'/offer'" />
                </div>
            @endif
            {{-- The rows sit outside the form and carry no names. The list travels
                 as one hidden JSON value, kept up to date by the script. --}}
            <div class="flex flex-col gap-3" data-list-editor="downloads" data-control-class="{{ Ui::FIELD }}" data-row-button-class="{{ Ui::button('quiet', 'xs') }}">
                <p class="text-sm text-neutral-400">Every place this game can be fetched from, best first. Coilbox tries them in this order and stops at the first that works, so a repo with no release archives is worth keeping below a rapid tag rather than instead of it.</p>
                <div class="flex flex-col gap-2" data-rows></div>
                <noscript><p class="text-sm text-neutral-400">Changing this list needs JavaScript.</p></noscript>
                <button type="button" data-add class="{{ Ui::button('ghost', 'sm', 'self-start') }}">Add source</button>
                <form method="post" action="{{ $base }}/downloads" class="flex items-center gap-3">
                    @csrf
                    <input type="hidden" name="_form" value="downloads">
                    <input type="hidden" name="downloads" value="{{ $downloads }}" data-payload>
                    <button type="submit" class="{{ Ui::button('ghost', 'base', 'self-start') }}">Save downloads</button>
                    <x-games.form-answer form="downloads" />
                </form>
            </div>
        </section>

        <section class="{{ $section }}">
            <h2 class="{{ $heading }}">Conquest factions</h2>
            <form method="post" action="{{ $base }}/factions" class="flex flex-col gap-3" data-list-editor="factions" data-control-class="{{ Ui::FIELD }}" data-row-button-class="{{ Ui::button('quiet', 'xs') }}">
                @csrf
                <input type="hidden" name="_form" value="factions">
                <input type="hidden" name="factions" value="{{ $factions }}" data-payload>
                <p class="text-sm text-neutral-400">Named in the order a galaxy fills them: the first is the player&apos;s own faction, the rest are enemies. Two factions can name the same in-game side.</p>
                <div class="flex flex-col gap-2" data-rows></div>
                <noscript><p class="text-sm text-neutral-400">Changing this list needs JavaScript.</p></noscript>
                <button type="button" data-add class="{{ Ui::button('ghost', 'sm', 'self-start') }}">Add faction</button>
                <div class="flex items-center gap-3">
                    <button type="submit" class="{{ Ui::button('ghost', 'base', 'self-start') }}">Save factions</button>
                    <x-games.form-answer form="factions" />
                </div>
            </form>
        </section>

        <section class="{{ $section }}">
            <h2 class="{{ $heading }}">Visibility</h2>
            @if ($flash)
                <p role="status" class="text-sm text-neutral-300">{{ $flash }}</p>
            @endif
            <p class="text-sm text-neutral-400">Hidden means off the site for everybody but the owner and moderators. Facts keep flowing; unhiding brings everything back.</p>
            <div class="flex items-center gap-3">
                <x-moderation.toggle-form :action="$base.'/visibility'" :fields="['shortname' => $shortname, 'hidden' => $page['hidden_at'] ? 'false' : 'true']" :label="$page['hidden_at'] ? 'Unhide this game' : 'Hide this game'" :button-class="Ui::button()" />
                @if ($page['hidden_at'])
                    <span class="text-sm text-neutral-400">This game is hidden right now.</span>
                @endif
            </div>
            <ul class="flex flex-col gap-1.5 text-sm">
                @foreach ($versions as $row)
                    <li class="flex items-center justify-between gap-3">
                        <span class="font-mono text-neutral-300">{{ $row['version'] }}</span>
                        <x-moderation.toggle-form :action="$base.'/version-visibility'" :fields="['shortname' => $shortname, 'version' => $row['version'], 'hidden' => $row['hidden_at'] ? 'false' : 'true']" :label="$row['hidden_at'] ? 'Unhide release' : 'Hide release'" :button-class="Ui::button('quiet', 'sm')" />
                    </li>
                @endforeach
            </ul>
        </section>

        @foreach ($pictures as $kind => $help)
            @php $lower = ImageUpload::LOWER_LABELS[$kind]; @endphp
            <section class="{{ $section }}">
                <h2 class="{{ $heading }}">{{ ImageUpload::LABELS[$kind] }}</h2>
                <p class="text-sm text-neutral-400">{{ $help }}</p>
                {{-- The script shrinks or converts a picture before it is sent and
                     writes what it did into `note`. --}}
                <form method="post" action="{{ $base }}/image" enctype="multipart/form-data" class="flex flex-col gap-3" data-game-image>
                    @csrf
                    <input type="hidden" name="_form" value="image-{{ $kind }}">
                    <input type="hidden" name="kind" value="{{ $kind }}">
                    <input type="hidden" name="note" value="">
                    <div class="flex items-end gap-3">
                        <input type="file" name="image" accept="image/png,image/webp,image/jpeg" required aria-label="{{ ImageUpload::LABELS[$kind] }} file" class="text-sm text-neutral-300">
                        <button type="submit" class="{{ Ui::button() }}" data-label="Upload {{ $lower }}">Upload {{ $lower }}</button>
                    </div>
                    <p role="alert" class="hidden text-sm text-red-400" data-refusal></p>
                    <x-games.form-answer :form="'image-'.$kind" />
                </form>
                <div class="flex flex-col gap-3">
                    @if ($page[$kind.'_path'] !== null)
                        <details>
                            <summary class="{{ Ui::button('ghost', 'base', 'w-fit cursor-pointer list-none focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-400 [&::-webkit-details-marker]:hidden') }}">Remove {{ $lower }}</summary>
                            <form method="post" action="{{ $base }}/image/remove" class="mt-3 flex flex-col gap-3 rounded-md border border-neutral-800 p-4">
                                @csrf
                                <input type="hidden" name="_form" value="remove-{{ $kind }}">
                                <input type="hidden" name="kind" value="{{ $kind }}">
                                <p class="text-sm text-neutral-300">Remove this game&apos;s {{ $lower }}? The game page, the games list and link previews stop showing it straight away. To show {{ $lower }} again, upload a picture.</p>
                                <button type="submit" class="{{ Ui::button('destructive', 'base', 'self-start') }}">Yes, remove the {{ $lower }}</button>
                            </form>
                        </details>
                    @endif
                    <x-games.form-answer :form="'remove-'.$kind" />
                </div>
            </section>
        @endforeach
    </div>
</main>
@vite('resources/js/games/edit.ts')
@endsection
