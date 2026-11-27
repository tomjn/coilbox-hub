@extends('layouts.app', [
    'title' => 'Replay data',
    'description' => 'Download the shared replays as CSV files of matches, players and build orders, updated every week.',
])
@use('App\Domain\Replays\ReplayData')
@use('App\Support\Site')
@php
    $link = 'text-neutral-100 underline underline-offset-4 transition-colors hover:text-white active:text-white';
    $latest = $weeks[0] ?? null;
    $date = fn (string $week) => \Illuminate\Support\Carbon::parse($week)->format('j F Y');
    $size = fn (int $bytes) => $bytes < 1024 * 1024 ? max(1, (int) round($bytes / 1024)).' KB' : number_format($bytes / 1024 / 1024, 1).' MB';
    $rows = fn (int $n) => number_format($n).($n === 1 ? ' row' : ' rows');
@endphp

@section('content')
<main id="main-content" tabindex="-1" class="mx-auto flex w-full max-w-3xl flex-1 flex-col gap-10 px-6 py-12">
    <header class="flex flex-col gap-3">
        <a class="text-sm text-neutral-400 underline-offset-4 hover:underline active:underline" href="/replays">Replays</a>
        <h1 class="text-3xl font-semibold tracking-tight">Replay data</h1>
        <p class="text-neutral-300">
            Every replay listed on the hub, as files you can open in a spreadsheet or load into a database such as DuckDB.
            A new set is written every Monday. The hub keeps the last {{ ReplayData::KEEP }} sets.
        </p>
        <p class="text-neutral-300">
            Chat is left out, and so are replays a moderator took down.
        </p>
    </header>

    <section aria-labelledby="latest-heading" class="flex flex-col gap-4">
        <h2 id="latest-heading" class="text-xl font-semibold">Latest files</h2>
        @if ($latest === null)
            <p class="text-neutral-300">The first set has not been written yet. Check back after Monday.</p>
        @else
            <p class="text-neutral-300">Written {{ $date($latest['week']) }}.</p>
            <ul class="flex flex-col gap-2 text-neutral-300">
                @foreach (ReplayData::FILES as $file => $what)
                    @isset($latest['files'][$file])
                        <li>
                            <a class="{{ $link }}" href="/replays/data/{{ $latest['week'] }}/{{ $file }}.csv">{{ $file }}.csv</a>
                            <span class="text-sm text-neutral-400">{{ $rows($latest['files'][$file]['rows']) }}, {{ $size($latest['files'][$file]['bytes']) }}</span>
                        </li>
                    @endisset
                @endforeach
            </ul>
            <p class="text-neutral-300">
                For a script, <code class="text-sm">/replays/data/latest/</code> followed by the file's name always gives the newest. In DuckDB:
            </p>
            <pre class="overflow-x-auto rounded-lg border border-neutral-800 bg-card p-4 text-sm"><code>select * from read_csv('{{ Site::absolute('/replays/data/latest/matches.csv') }}');</code></pre>
        @endif
        @if (count($weeks) > 1)
            <h3 class="font-semibold">Earlier sets</h3>
            <ul class="flex flex-col gap-2 text-neutral-300">
                @foreach (array_slice($weeks, 1) as $week)
                    <li>
                        {{ $date($week['week']) }}:
                        @foreach (array_keys(ReplayData::FILES) as $file)
                            <a class="{{ $link }}" href="/replays/data/{{ $week['week'] }}/{{ $file }}.csv">{{ $file }}.csv</a>@if (! $loop->last), @endif
                        @endforeach
                    </li>
                @endforeach
            </ul>
        @endif
    </section>

    <section aria-labelledby="format-heading" class="flex flex-col gap-4">
        <h2 id="format-heading" class="text-xl font-semibold">How the files are written</h2>
        <ul class="flex list-disc flex-col gap-2 pl-6 text-neutral-300">
            <li>Text in UTF-8, fields separated by commas, with the column names on the first line.</li>
            <li>An empty field means the value is not known or does not apply.</li>
            <li>Yes or no values are written as true or false. Times are in UTC.</li>
            <li><code class="text-sm">game_id</code> joins the files together. In players and build orders, a match and <code class="text-sm">team</code> together name one team.</li>
            <li>Names are as players typed them. A spreadsheet may read a name that starts with = as a formula, so check before you run one.</li>
        </ul>
    </section>

    @foreach (ReplayData::FILES as $file => $what)
        <section aria-labelledby="{{ $file }}-heading" class="flex flex-col gap-4">
            <h2 id="{{ $file }}-heading" class="text-xl font-semibold">{{ $file }}.csv</h2>
            <p class="text-neutral-300">{{ $what }}</p>
            <div class="overflow-x-auto rounded-lg border border-neutral-800 bg-card">
                <table class="w-full text-sm" data-columns="{{ $file }}">
                    <thead class="text-left text-xs text-neutral-400">
                        <tr class="border-b border-neutral-800">
                            <th scope="col" class="px-3 py-2 font-medium">Column</th>
                            <th scope="col" class="px-3 py-2 font-medium">What it holds</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($columns[$file] as $column => $about)
                            <tr class="border-b border-neutral-800 last:border-b-0 align-top">
                                <th scope="row" class="whitespace-nowrap px-3 py-2 text-left font-normal"><code>{{ $column }}</code></th>
                                <td class="px-3 py-2 text-neutral-300">{{ $about }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endforeach
</main>
@endsection
