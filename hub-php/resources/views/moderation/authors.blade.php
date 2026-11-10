@extends('layouts.app', ['title' => 'Authors - Moderation'])
@use('App\Domain\Moderation\AuthorMerge')
@use('App\Support\Ui')
@php
    $input = 'rounded-md border border-neutral-800 bg-black px-3 py-2 text-sm text-neutral-200 placeholder:text-neutral-400 focus-visible:border-neutral-500 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-400';
    $rowClass = 'flex flex-col gap-1 rounded-md border border-neutral-800 bg-card p-4 text-sm';
    $choice = 'flex min-w-0 items-baseline gap-3 py-1 text-sm text-neutral-300';
    $pageHref = $search === '' ? '/moderation/authors' : '/moderation/authors?'.http_build_query(['q' => $search]);
    $tickedSet = array_flip($ticked);
    $mapsLabel = fn (int $maps) => $maps.' '.($maps === 1 ? 'map' : 'maps');
    if ($selected !== []) {
        // The key kept defaults to the one with the most maps.
        $kept = array_reduce($selected, fn ($best, $author) => $best === null || $author['maps'] > $best['maps'] ? $author : $best);
        $renaming = count($selected) === 1;
        $names = array_values(array_unique([$kept['name'], ...$choices]));
    }
@endphp

@section('content')
<main id="main-content" tabindex="-1" class="relative flex-1">
    <x-ui.art-backdrop drawing="archives" :strength="0.08" />
    <x-moderation.nav current="authors" />
    <div class="relative z-10 mx-auto flex w-full max-w-3xl flex-col gap-6 px-6 py-12">
        <h1 class="text-3xl font-semibold tracking-tight">Authors</h1>

        <p class="text-sm text-neutral-400">Each line is one author, under the key their credits fold to once case and clan tags are removed. A credit like <span class="font-mono">original from Beherith</span> folds to a key of its own. Search for a name, tick every key that is that person, and merge them.</p>

        @if ($answer)
            <p class="text-sm text-neutral-300">{{ $answer }}</p>
        @endif

        @if ($selected !== [])
            {{-- With one key ticked there is nothing to merge, so this renames that author. --}}
            <form method="post" action="/moderation/authors/merge" class="{{ $rowClass }} gap-5">
                @csrf
                <h2 class="text-lg font-semibold text-neutral-200">{{ $renaming ? 'Rename this author' : 'Merge '.count($selected).' keys into one author' }}</h2>
                <input type="hidden" name="q" value="{{ $search }}">
                @foreach ($selected as $author)
                    <input type="hidden" name="key[]" value="{{ $author['key'] }}">
                @endforeach

                @if ($renaming)
                    <input type="hidden" name="into" value="{{ $kept['key'] }}">
                @else
                    <fieldset class="flex flex-col gap-1">
                        <legend class="mb-1 text-sm text-neutral-400">Keep this key. The others point at it. A credit that names two people, like <span class="font-mono">beherith/the_yak</span>, counts for whichever person it is merged into.</legend>
                        @foreach ($selected as $author)
                            <label class="{{ $choice }}">
                                <input type="radio" name="into" value="{{ $author['key'] }}" @checked($author['key'] === $kept['key']) class="size-4 shrink-0 self-center">
                                <span class="min-w-0 break-words font-mono">{{ $author['key'] }}</span>
                                <span class="ml-auto shrink-0 text-xs text-neutral-400">{{ $mapsLabel($author['maps']) }}</span>
                            </label>
                        @endforeach
                    </fieldset>
                @endif

                <fieldset class="flex flex-col gap-1">
                    <legend class="mb-1 text-sm text-neutral-400">Show them as</legend>
                    @foreach ($names as $name)
                        <label class="{{ $choice }}">
                            <input type="radio" name="name" value="{{ $name }}" @checked($name === $kept['name']) class="size-4 shrink-0 self-center">
                            <span class="min-w-0 break-words">{{ $name }}</span>
                        </label>
                    @endforeach
                    <label class="mt-2 flex flex-col gap-1 text-sm text-neutral-400" for="typed-name">Or type a name, which wins over the one picked above</label>
                    <input id="typed-name" name="typed_name" maxlength="256" placeholder="Beherith" class="{{ $input }}">
                </fieldset>

                <div class="flex flex-col gap-1">
                    <label class="text-sm text-neutral-400" for="merge-note">Why they are one person, for whoever reads this in a year</label>
                    <input id="merge-note" name="note" class="{{ $input }}">
                </div>

                <div class="flex flex-wrap items-center gap-3">
                    <button type="submit" class="{{ Ui::button() }}">{{ $renaming ? 'Rename' : 'Merge them' }}</button>
                    <a href="{{ $pageHref }}" class="{{ Ui::button('ghost') }}">Cancel</a>
                </div>
            </form>
        @endif

        <form action="/moderation/authors" class="flex flex-wrap items-end gap-3">
            <div class="flex min-w-48 flex-1 flex-col gap-1.5">
                <label for="author-search" class="text-sm text-neutral-400">Find keys containing</label>
                <input id="author-search" type="search" name="q" value="{{ $search }}" placeholder="beherith" class="{{ $input }}">
            </div>
            <button type="submit" class="{{ Ui::button('ghost') }}">Search</button>
            @if ($search !== '')
                <a href="/moderation/authors" class="{{ Ui::button('ghost') }}">Clear</a>
            @endif
        </form>

        <h2 class="text-sm text-neutral-400">
            @if ($search !== '')
                {{ count($authors) === AuthorMerge::PAGE_SIZE ? 'The '.AuthorMerge::PAGE_SIZE.' keys containing "'.$search.'" with the most maps.' : 'Every key containing "'.$search.'", by how many maps they made.' }}
            @else
                {{ count($authors) === AuthorMerge::PAGE_SIZE ? 'The '.AuthorMerge::PAGE_SIZE.' authors with the most maps. Search to find the rest.' : 'Every author in the catalog, by how many maps they made.' }}
            @endif
        </h2>

        @if ($authors === [])
            <p class="rounded-md border border-neutral-800 bg-card p-6 text-sm text-neutral-400">{{ $search !== '' ? 'No key contains "'.$search.'".' : 'The catalog credits nobody yet.' }}</p>
        @else
            {{-- A GET back to this page with the ticked keys, which opens the merge form above. --}}
            <form action="/moderation/authors" class="flex flex-col gap-3">
                @if ($search !== '')
                    <input type="hidden" name="q" value="{{ $search }}">
                @endif
                <ul class="flex flex-col rounded-md border border-neutral-800 bg-card p-5">
                    @foreach ($authors as $author)
                        @php $aliasesHere = $merged[$author['key']] ?? []; @endphp
                        <li class="flex items-start gap-3 py-1.5 text-sm">
                            <label class="flex min-w-0 flex-1 items-start gap-3">
                                <input type="checkbox" name="merge" value="{{ $author['key'] }}" @checked(isset($tickedSet[$author['key']])) class="mt-0.5 size-4 shrink-0">
                                <span class="flex min-w-0 flex-col gap-0.5">
                                    <span class="break-words text-neutral-200">{{ $author['name'] }}</span>
                                    <span class="break-words font-mono text-xs text-neutral-400">{{ $author['key'] }}</span>
                                    @if ($aliasesHere !== [])
                                        <span class="break-words text-xs text-neutral-400">Merged in: <span class="font-mono">{{ implode(', ', array_column($aliasesHere, 'fromKey')) }}</span></span>
                                    @endif
                                </span>
                            </label>
                            <a href="/maps?author={{ rawurlencode($author['key']) }}" class="shrink-0 text-xs text-neutral-400 hover:underline active:underline">{{ $mapsLabel($author['maps']) }}</a>
                        </li>
                    @endforeach
                </ul>
                <div>
                    <button type="submit" class="{{ Ui::button() }}">Merge selected</button>
                </div>
            </form>
        @endif

        <h2 class="text-sm text-neutral-400">{{ $aliases === [] ? 'Nothing has been merged yet.' : 'Merged so far. Unmerging a key makes it an author of its own again.' }}</h2>

        @if ($aliases !== [])
            <ul class="flex flex-col gap-3">
                @foreach ($merged as $toKey => $group)
                    <li class="{{ $rowClass }}">
                        <p class="break-words text-neutral-300">Merged into <span class="font-mono">{{ $toKey }}</span></p>
                        <ul class="flex flex-col gap-1">
                            @foreach ($group as $alias)
                                <li class="flex flex-col gap-1">
                                    <div class="flex flex-wrap items-baseline justify-between gap-3">
                                        <span class="min-w-0 break-words font-mono text-neutral-300">{{ $alias['fromKey'] }}</span>
                                        <form method="post" action="/moderation/authors/unmerge">
                                            @csrf
                                            <button type="submit" name="from" value="{{ $alias['fromKey'] }}" class="{{ Ui::button('ghost', 'sm') }}">Unmerge<span class="sr-only"> {{ $alias['fromKey'] }} from {{ $alias['toKey'] }}</span></button>
                                        </form>
                                    </div>
                                    @if ($alias['note'])
                                        <p class="text-xs text-neutral-400">{{ $alias['note'] }}</p>
                                    @endif
                                    @if ($alias['chained'])
                                        {{-- Marked rather than hidden: the maps under it count under a key nobody sees. --}}
                                        <p class="text-xs text-amber-400">{{ $alias['toKey'] }} is itself merged, and an alias resolves once and stops. Merge this key into the person instead.</p>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
</main>
@endsection
