@extends('layouts.app', ['title' => 'Mirrors - Moderation'])
@use('App\Domain\Mirrors\Registry')
@use('App\Support\Ui')
@php
    $card = 'flex flex-col gap-3 rounded-md border border-neutral-800 bg-card p-5';
    $state = session('mirrorsState');
    $tones = [
        'good' => 'border-green-900 text-green-300',
        'bad' => 'border-red-900 text-red-300',
        'waiting' => 'border-amber-900 text-amber-300',
        'off' => 'border-neutral-800 text-neutral-400',
    ];
@endphp

@section('content')
<main id="main-content" tabindex="-1" class="relative flex-1">
    <x-ui.art-backdrop drawing="archives" :strength="0.08" />
    <x-moderation.nav current="mirrors" />
    <div class="relative z-10 mx-auto flex w-full max-w-3xl flex-col gap-6 px-6 py-12">
        <div class="flex flex-col gap-2">
            <h1 class="text-3xl font-semibold tracking-tight">Mirrors</h1>
            <p class="text-sm text-neutral-400">Servers that hold map and game archives for the hub. Each one syncs the signed catalogue and reports what it holds. The hub only sends players to a mirror whose last report passed its check.</p>
        </div>

        @if ($state)
            <p role="{{ $state['ok'] ? 'status' : 'alert' }}" class="text-sm {{ $state['ok'] ? 'text-green-400' : 'text-red-400' }}">{{ $state['message'] }}</p>
        @endif

        @if ($error)
            <p role="alert" class="rounded-md border border-red-950 bg-card p-6 text-sm text-red-300">The mirrors could not be read. Reload the page to try again.</p>
        @else
            <section class="flex flex-col gap-4" aria-labelledby="mirrors-list">
                <h2 id="mirrors-list" class="text-sm uppercase tracking-wide text-neutral-400">Mirrors</h2>
                @if ($mirrors === [])
                    <p class="rounded-md border border-neutral-800 bg-card p-6 text-sm text-neutral-400">There are no mirrors yet.</p>
                @else
                    <ul class="flex flex-col gap-4">
                        @foreach ($mirrors as $m)
                            @php $health = Registry::health($m); @endphp
                            <li class="{{ $card }}">
                                <div class="flex flex-wrap items-baseline justify-between gap-3">
                                    <h3 class="text-lg font-medium">{{ $m['name'] }}</h3>
                                    <span class="rounded-full border px-2.5 py-0.5 text-xs {{ $tones[$health['tone']] }}">{{ $health['label'] }}</span>
                                </div>
                                <dl class="grid grid-cols-[max-content_1fr] gap-x-4 gap-y-1 text-sm">
                                    <dt class="text-neutral-400">ID</dt>
                                    <dd class="break-all font-mono">{{ $m['id'] }}</dd>
                                    <dt class="text-neutral-400">Address</dt>
                                    <dd class="break-all"><a href="{{ $m['base_url'] }}" class="text-neutral-300 underline underline-offset-4 hover:text-neutral-100 active:text-neutral-100">{{ $m['base_url'] }}</a></dd>
                                    <dt class="text-neutral-400">Public key</dt>
                                    <dd class="break-all font-mono">{{ $m['public_key'] }}</dd>
                                    <dt class="text-neutral-400">Last report</dt>
                                    <dd>@if ($m['last_report_at']) <x-ui.date-time :value="$m['last_report_at']" /> @else Never @endif</dd>
                                    <dt class="text-neutral-400">Last checked</dt>
                                    <dd>@if ($m['last_check_at']) <x-ui.date-time :value="$m['last_check_at']" /> @else Never @endif</dd>
                                    <dt class="text-neutral-400">Catalogue</dt>
                                    <dd>
                                        @if ($m['last_catalogue_seq'] === null)
                                            Not synced yet
                                        @elseif ($seq !== null && (int) $m['last_catalogue_seq'] >= (int) $seq)
                                            Up to date, number {{ $m['last_catalogue_seq'] }}
                                        @else
                                            Synced number {{ $m['last_catalogue_seq'] }}{{ $seq !== null ? ', the hub is on '.$seq : '' }}
                                        @endif
                                    </dd>
                                    <dt class="text-neutral-400">Holds</dt>
                                    <dd>{{ $m['copies'] === 1 ? '1 archive' : $m['copies'].' archives' }}</dd>
                                    <dt class="text-neutral-400">Added</dt>
                                    <dd><x-ui.date-time :value="$m['created_at']" /></dd>
                                </dl>
                                @if ($m['enabled'])
                                    <details class="text-sm">
                                        <summary class="cursor-pointer text-neutral-400 hover:text-neutral-200">Remove {{ $m['name'] }}</summary>
                                        <div class="mt-3 flex flex-col gap-3">
                                            <p class="text-neutral-400">
                                                @if ($m['uploads'] > 0)
                                                    Uploads went through this mirror, so it is switched off rather than deleted. It gets no downloads or uploads, and you can switch it back on here.
                                                @else
                                                    The hub stops sending players to it at once, and it leaves the catalogue on the next sync. To bring it back, add it again.
                                                @endif
                                            </p>
                                            <form method="post" action="/moderation/mirrors/remove">
                                                @csrf
                                                <input type="hidden" name="mirror_id" value="{{ $m['id'] }}">
                                                <button type="submit" class="{{ Ui::button('destructive') }}">{{ $m['uploads'] > 0 ? 'Switch off' : 'Remove' }} {{ $m['name'] }}</button>
                                            </form>
                                        </div>
                                    </details>
                                @else
                                    <form method="post" action="/moderation/mirrors/enable">
                                        @csrf
                                        <input type="hidden" name="mirror_id" value="{{ $m['id'] }}">
                                        <button type="submit" class="{{ Ui::button() }}">Switch {{ $m['name'] }} back on</button>
                                    </form>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>

            <section class="flex flex-col gap-4 border-t border-neutral-900 pt-6" aria-labelledby="mirrors-add">
                <h2 id="mirrors-add" class="text-sm uppercase tracking-wide text-neutral-400">Add a mirror</h2>
                <p class="text-sm text-neutral-400">Ask the operator for their mirror's ID, address and public key. They make the key with <code>php bin/keygen.php</code> in the mirror repository, and the ID must match the one in their mirror's config. A new mirror gets no downloads until its first report passes the hub's check.</p>
                <form method="post" action="/moderation/mirrors/add" class="flex flex-col gap-4">
                    @csrf
                    <label class="flex flex-col gap-1.5 text-sm">
                        <span class="text-neutral-400">ID, lowercase letters, digits and hyphens</span>
                        <input name="id" required maxlength="64" pattern="[a-z0-9][a-z0-9\-]*" value="{{ old('id') }}" autocomplete="off" class="{{ Ui::FIELD }} font-mono">
                    </label>
                    <label class="flex flex-col gap-1.5 text-sm">
                        <span class="text-neutral-400">Name players see</span>
                        <input name="name" required maxlength="128" value="{{ old('name') }}" placeholder="mirror.example.org" autocomplete="off" class="{{ Ui::FIELD }}">
                    </label>
                    <label class="flex flex-col gap-1.5 text-sm">
                        <span class="text-neutral-400">Address</span>
                        <input name="base_url" type="url" required value="{{ old('base_url') }}" placeholder="https://mirror.example.org" autocomplete="off" class="{{ Ui::FIELD }}">
                    </label>
                    <label class="flex flex-col gap-1.5 text-sm">
                        <span class="text-neutral-400">The mirror's public key, not the hub's</span>
                        <input name="public_key" required minlength="43" maxlength="43" value="{{ old('public_key') }}" autocomplete="off" spellcheck="false" class="{{ Ui::FIELD }} font-mono">
                    </label>
                    <div>
                        <button type="submit" class="{{ Ui::button('primary') }}">Add the mirror</button>
                    </div>
                </form>
            </section>
        @endif
    </div>
</main>
@endsection
