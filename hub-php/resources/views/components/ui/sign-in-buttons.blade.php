@props(['next' => '/publish'])
{{-- components/SignInButtons.tsx. One form post per provider, so it works
     with scripting off. `next` is where to come back to. --}}
@php
    $button = 'inline-flex items-center gap-2.5 rounded-md px-5 py-2.5 text-sm font-medium text-white transition-colors';
    $colours = [
        'discord' => 'bg-[#5865F2] hover:bg-[#4752c4] active:bg-[#3b45a3]',
        'github' => 'border border-neutral-600 bg-[#24292f] hover:bg-[#32383f] active:bg-[#1b1f24]',
        'twitch' => 'bg-[#9146FF] hover:bg-[#772ce8] active:bg-[#5c16c5]',
    ];
@endphp
<div class="flex flex-wrap gap-3">
    @foreach (\App\Auth\GoTrue::PROVIDERS as $id => $label)
        <form action="/auth/signin" method="post">
            @csrf
            <input type="hidden" name="next" value="{{ $next }}">
            <input type="hidden" name="provider" value="{{ $id }}">
            <button type="submit" class="{{ $button }} {{ $colours[$id] }}">
                <x-provider-logo :provider="$id" />
                Sign in with {{ $label }}
            </button>
        </form>
    @endforeach
</div>
