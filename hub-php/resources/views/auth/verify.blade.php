@extends('layouts.app', ['title' => 'Enter your code', 'robots' => 'noindex'])

@php $verify = session('verify', []); @endphp

@section('content')
<main id="main-content" tabindex="-1" class="relative flex-1">
    <x-ui.art-backdrop drawing="games" :strength="0.09" />
    <div class="relative z-10 mx-auto flex w-full max-w-md flex-col gap-6 px-6 py-12">
        <div class="flex flex-col gap-2">
            <h1 class="text-3xl font-semibold tracking-tight">Enter your code</h1>
            <p class="text-neutral-400">Your account has an authenticator, so moderation and the other things you have been trusted with need a code from it as well as your sign in. You only need to do this once per sign in.</p>
        </div>
        <form action="/account/verify" method="post" class="flex flex-col gap-4">
            @csrf
            <input type="hidden" name="next" value="{{ $next }}">
            @if (count($factors) === 1)
                <input type="hidden" name="factorId" value="{{ $factors[0]['id'] }}">
            @else
                <fieldset class="flex flex-col gap-2">
                    <legend class="mb-2 text-sm text-neutral-400">Which authenticator?</legend>
                    @foreach ($factors as $index => $factor)
                        <label class="flex items-center gap-2 text-sm">
                            <input type="radio" name="factorId" value="{{ $factor['id'] }}" @checked($index === 0)>
                            <span>Added <x-ui.date-time :value="$factor['createdAt']" /></span>
                        </label>
                    @endforeach
                </fieldset>
            @endif
            @include('auth.code-field')
            @isset($verify['error'])<p role="alert" class="text-sm text-red-400">{{ $verify['error'] }}</p>@endisset
            <button type="submit" class="{{ \App\Support\Ui::button('ghost', 'base', 'self-start') }}">Continue</button>
        </form>
    </div>
</main>
@endsection
