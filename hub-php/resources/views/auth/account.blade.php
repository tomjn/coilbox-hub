@extends('layouts.app', ['title' => 'Your account', 'robots' => 'noindex'])

@php
    use App\Domain\Account\AccountRules;
    use App\Http\Controllers\Auth\AccountController;
    use App\Support\Ui;
    $rename = session('rename', []);
    $delete = session('delete', []);
    $unlink = session('unlink', []);
    $enrol = session('enrol', []);
    $confirm = session('confirm', []);
    $remove = session('remove', []);
    $mayChange = $authenticators === [] || $verified;
    $last = count($methods) <= 1;
    $link = 'text-sm text-neutral-300 underline-offset-4 hover:underline active:underline';
    $card = 'flex flex-col gap-3 rounded-md border border-neutral-800 bg-card p-5';
@endphp

@section('content')
<main id="main-content" tabindex="-1" class="relative flex-1">
    <x-ui.art-backdrop drawing="games" :strength="0.09" />
    <div class="relative z-10 mx-auto flex w-full max-w-2xl flex-col gap-8 px-6 py-12">
        <div class="flex flex-col gap-2">
            <h1 class="text-3xl font-semibold tracking-tight">Your account</h1>
            <p class="text-neutral-400">Signed in as {{ $name }}, with {{ $published }} {{ $published === 1 ? 'thing' : 'things' }} published.</p>
        </div>

        <div class="flex flex-col gap-3">
            <a href="/profile/{{ $userId }}" class="{{ $link }}">Your public profile</a>
            <a href="/gallery?account={{ $userId }}" class="{{ $link }}">Everything you have published</a>
            <form action="/auth/signout" method="post">
                @csrf
                <button type="submit" class="text-sm text-neutral-400 transition-colors hover:text-white active:text-white">Sign out</button>
            </form>
        </div>

        <div class="{{ $card }}">
            <h2 class="text-sm font-medium">Change your display name</h2>
            <form action="/account/rename" method="post" class="flex flex-col gap-3">
                @csrf
                <label class="flex flex-col gap-2">
                    <span class="text-sm text-neutral-400">Display name</span>
                    <input name="displayName" required value="{{ $rename['typed'] ?? $name }}" autocomplete="nickname" spellcheck="false" aria-describedby="display-name-help" class="{{ Ui::FIELD }}">
                </label>
                <p id="display-name-help" class="text-sm text-neutral-400">{{ AccountRules::DISPLAY_NAME_MIN }} to {{ AccountRules::DISPLAY_NAME_MAX }} characters. Nobody else can hold the same name, whatever the capital letters.</p>
                @isset($rename['error'])<p role="alert" class="text-sm text-red-400">{{ $rename['error'] }}</p>@endisset
                @isset($rename['saved'])<p role="status" class="text-sm text-green-400">Saved. You are now {{ $rename['saved'] }}.</p>@endisset
                <button type="submit" class="{{ Ui::button('ghost', 'base', 'self-start') }}">Save name</button>
            </form>
        </div>

        <div id="sign-in-methods" class="{{ $card }} scroll-mt-24">
            <h2 class="text-sm font-medium">How you sign in</h2>
            <p class="text-sm text-neutral-400">Any of these signs you in to this same account. With a second one linked, losing access to the first does not lose you your account or what you published.</p>
            @if ($linkFailure)<p role="alert" class="text-sm text-red-400">{{ $linkFailure }}</p>@endif
            <div class="flex flex-col gap-4">
                <ul class="flex flex-col gap-3">
                    @foreach ($methods as $method)
                        <li class="flex flex-col gap-1 text-sm">
                            <div class="flex items-center justify-between gap-3">
                                <span class="flex items-center gap-2">
                                    @if (isset(\App\Auth\GoTrue::PROVIDERS[$method['provider']]))<x-provider-logo :provider="$method['provider']" />@endif
                                    <span>{{ $method['label'] }}@if ($method['account'])<span class="text-neutral-400"> as {{ $method['account'] }}</span>@endif</span>
                                </span>
                                @if ($mayChange)
                                    <form action="/account/unlink" method="post" class="flex items-center gap-3">
                                        @csrf
                                        <input type="hidden" name="identityId" value="{{ $method['identityId'] }}">
                                        @if (($unlink['identityId'] ?? null) === $method['identityId'] && isset($unlink['error']))<span role="alert" class="text-red-400">{{ $unlink['error'] }}</span>@endif
                                        <button type="submit" @disabled($last) @if ($last) aria-describedby="last-{{ $method['identityId'] }}" @endif class="{{ Ui::button('quiet', 'sm') }}">Remove<span class="sr-only"> {{ $method['label'] }}</span></button>
                                    </form>
                                @endif
                            </div>
                            @if ($mayChange && $last)<p id="last-{{ $method['identityId'] }}" class="text-neutral-400">{{ AccountRules::LAST_METHOD_REASON }}</p>@endif
                        </li>
                    @endforeach
                </ul>
                @if (! $mayChange)
                    <p class="text-sm text-neutral-400">To add or remove a way to sign in, <a href="{{ AccountController::stepUpPath('/account#sign-in-methods') }}" class="text-neutral-300 underline underline-offset-4">enter a code from your authenticator</a>.</p>
                @elseif ($linkable !== [])
                    <div class="flex flex-wrap gap-3">
                        @foreach ($linkable as $id => $label)
                            <form action="/auth/link" method="post">
                                @csrf
                                <input type="hidden" name="provider" value="{{ $id }}">
                                <button type="submit" class="{{ Ui::button('ghost', 'sm', 'inline-flex items-center gap-2') }}"><x-provider-logo :provider="$id" />Link {{ $label }}</button>
                            </form>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>

        @if ($showAuthenticators)
            <div id="second-factor" class="{{ $card }} scroll-mt-24">
                <h2 class="text-sm font-medium">Authenticator app</h2>
                @if (in_array('verify', $access, true))
                    <p role="status" class="text-sm text-amber-300">What you have been trusted with here, such as moderation, needs a code from your authenticator in this session. <a href="{{ AccountController::stepUpPath('/account') }}" class="underline underline-offset-4">Enter your code</a>.</p>
                @elseif (in_array('enrol', $access, true))
                    <p role="status" class="text-sm text-amber-300">What you have been trusted with here, such as moderation, now needs an authenticator. Set one up below to use it again.</p>
                @elseif ($holdsCapability && $authenticators === [])
                    <p class="text-sm text-amber-300">You have been trusted with things here, such as moderation. Set up an authenticator so that somebody who gets hold of your sign in cannot use them.</p>
                @endif
                <p class="text-sm text-neutral-400">An authenticator app gives you a code to type after signing in. Once you have one, moderation and anything else you have been trusted with needs that code as well as your sign in. If you lose your only authenticator, the hub&apos;s maintainer has to remove it before you can use those again, so adding a spare is worth it.</p>
                <div class="flex flex-col gap-4">
                    @if ($authenticators === [])
                        <p class="text-sm text-neutral-400">You have no authenticator set up.</p>
                    @else
                        <ul class="flex flex-col gap-2">
                            @foreach ($authenticators as $factor)
                                <li class="flex items-center justify-between gap-3 text-sm">
                                    <span>Authenticator added <x-ui.date-time :value="$factor['createdAt']" /></span>
                                    @if ($verified)
                                        <form action="/account/factor/remove" method="post" class="flex items-center gap-3">
                                            @csrf
                                            <input type="hidden" name="factorId" value="{{ $factor['id'] }}">
                                            @if (($remove['factorId'] ?? null) === $factor['id'] && isset($remove['error']))<span role="alert" class="text-red-400">{{ $remove['error'] }}</span>@endif
                                            <button type="submit" class="{{ Ui::button('quiet', 'sm') }}">Remove</button>
                                        </form>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    @endif
                    @if (! $mayChange)
                        <p class="text-sm text-neutral-400">To add or remove an authenticator, <a href="{{ AccountController::stepUpPath('/account#second-factor') }}" class="text-neutral-300 underline underline-offset-4">enter a code from one you already have</a>.</p>
                    @endif
                    @if ($confirm['done'] ?? false)
                        <p role="status" class="text-sm text-green-400">Done. Your authenticator is set up, and any other place you were signed in has been signed out.</p>
                    @endif
                    @if ($enrolment)
                        <div class="flex flex-col gap-4 rounded-md border border-neutral-800 p-4">
                            <p class="text-sm text-neutral-400">Scan this with an authenticator app, then type the code it shows.</p>
                            <img src="{{ $enrolment['qrCode'] }}" alt="QR code for your authenticator app" class="w-48 self-start rounded bg-white p-2">
                            <p class="text-sm text-neutral-400">If you cannot scan it, type this key into the app instead:</p>
                            <code class="select-all break-all rounded bg-neutral-900 px-2 py-1 text-sm text-neutral-100">{{ $enrolment['secret'] }}</code>
                            <form action="/account/factor/confirm" method="post" class="flex flex-col gap-3">
                                @csrf
                                <input type="hidden" name="factorId" value="{{ $enrolment['factorId'] }}">
                                @include('auth.code-field')
                                @isset($confirm['error'])<p role="alert" class="text-sm text-red-400">{{ $confirm['error'] }}</p>@endisset
                                <button type="submit" class="{{ Ui::button('ghost', 'base', 'self-start') }}">Finish setting up</button>
                            </form>
                        </div>
                    @elseif ($mayChange)
                        <form action="/account/factor/start" method="post" class="flex flex-col gap-3">
                            @csrf
                            @isset($enrol['error'])<p role="alert" class="text-sm text-red-400">{{ $enrol['error'] }}</p>@endisset
                            <button type="submit" class="{{ Ui::button('ghost', 'base', 'self-start') }}">{{ $authenticators === [] ? 'Set up an authenticator' : 'Add a spare authenticator' }}</button>
                        </form>
                    @endif
                </div>
            </div>
        @endif

        <div class="flex flex-col gap-3 rounded-md border border-red-950 bg-card p-5">
            <h2 class="text-sm font-medium">Delete your account</h2>
            <p class="text-sm text-neutral-400">This removes your account and everything you have published, including anything you had already withdrawn. Import links for those items stop working. It cannot be undone, and it is not the same as withdrawing something, which is reversible.</p>
            <p class="text-sm text-neutral-400">If a moderator rejected a picture you uploaded for safety reasons, your display name and Discord username are kept with that record after your account is deleted, so it still says who uploaded it.</p>
            @if ($published > 0)<p class="text-sm text-neutral-400">{{ $published }} {{ $published === 1 ? 'item' : 'items' }} will go with it.</p>@endif
            <form action="/account/delete" method="post" class="flex flex-col gap-3">
                @csrf
                <label class="flex flex-col gap-2">
                    <span class="text-sm text-neutral-400">To confirm, type <span class="text-neutral-100">{{ $name }}</span></span>
                    <input name="confirm" required autocomplete="off" spellcheck="false" class="{{ Ui::FIELD }}">
                </label>
                @isset($delete['error'])<p role="alert" class="text-sm text-red-400">{{ $delete['error'] }}</p>@endisset
                <button type="submit" class="{{ Ui::button('destructive', 'base', 'self-start') }}">Delete everything</button>
            </form>
        </div>
    </div>
</main>
@endsection
