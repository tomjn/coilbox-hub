@php
    use App\Support\Nav;
    $nav = Nav::current();
    // On a narrow screen the label sits under the icon and the link is at least
    // 44 pixels square.
    $navItem = 'flex min-h-11 min-w-11 flex-col items-center justify-center gap-0.5 rounded-md px-2 py-1.5 transition-colors hover:text-white active:bg-neutral-900 active:text-white sm:min-h-0 sm:min-w-0 sm:flex-row sm:gap-2';
    $navCurrent = 'bg-neutral-900 text-white';
    $sections = [
        ['href' => '/gallery', 'icon' => 'gallery', 'label' => 'Gallery'],
        ['href' => '/maps', 'icon' => 'maps', 'label' => 'Maps'],
        ['href' => '/games', 'icon' => 'games', 'label' => 'Games'],
        ['href' => '/publish', 'icon' => 'publish', 'label' => 'Publish'],
    ];
    $path = '/'.ltrim(request()->path(), '/');
    $description = $description ?? 'A place to share the presets, challenges, setup packs, scenarios, blueprints and mod projects you make in Coilbox.';
    $fullTitle = isset($title) && $title !== '' ? $title.' - Coilbox Hub' : 'Coilbox Hub';
    $ogImage = $ogImage ?? \App\Domain\Og\Cards::urlFor(request()->path());
@endphp
<!DOCTYPE html>
<html lang="en" class="h-full antialiased">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $fullTitle }}</title>
    <meta name="description" content="{{ $description }}">
    <link rel="icon" href="/icon.svg" type="image/svg+xml">
    <link rel="alternate" type="application/atom+xml" href="{{ url('/feed.xml') }}">
    @isset($canonical)<link rel="canonical" href="{{ $canonical }}">@endisset
    <meta property="og:title" content="{{ $ogTitle ?? $fullTitle }}">
    <meta property="og:description" content="{{ $description }}">
    <meta property="og:type" content="{{ $ogType ?? 'website' }}">
    @isset($ogImage)<meta property="og:image" content="{{ $ogImage }}"><meta name="twitter:card" content="summary_large_image">@endisset
    @isset($robots)<meta name="robots" content="{{ $robots }}">@endisset
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @stack('head')
</head>
<body class="min-h-full flex flex-col">
    <a href="#main-content" class="sr-only focus:not-sr-only focus:fixed focus:left-4 focus:top-4 focus:z-50 focus:rounded-md focus:bg-neutral-100 focus:px-4 focus:py-2 focus:text-sm focus:font-medium focus:text-neutral-900">Skip to content</a>
    <header class="flex flex-wrap items-center gap-y-2 px-6 py-4 sm:flex-nowrap sm:gap-3">
        <a href="/" class="mr-auto flex items-center gap-2 text-sm font-medium text-neutral-300 transition-colors hover:text-white active:text-white">
            <x-coil-logo class="w-5" />
            Coilbox Hub
        </a>
        <nav class="order-3 -mx-6 grid w-[calc(100%+3rem)] grid-cols-5 gap-y-1 text-[11px] text-neutral-400 has-[[data-moderator]]:grid-cols-3 min-[360px]:has-[[data-moderator]]:grid-cols-6 max-sm:*:px-0 sm:order-none sm:mx-0 sm:flex sm:w-auto sm:items-center sm:gap-3 sm:text-sm">
            @foreach ($sections as $section)
                @php $current = $path === $section['href'] || str_starts_with($path, $section['href'].'/'); @endphp
                <a href="{{ $section['href'] }}" class="{{ $navItem }} {{ $current ? $navCurrent : '' }}" @if ($current) aria-current="page" @endif>
                    <x-icon :name="$section['icon']" class="w-4" />
                    <span>{{ $section['label'] }}</span>
                </a>
            @endforeach
            {{-- The only outbound link in the nav. A new tab, so following it
                 from an item page does not lose the item being imported. --}}
            <a href="{{ config('hub.coilbox_url') }}" target="_blank" rel="noreferrer" class="{{ $navItem }}">
                <x-icon name="download" class="w-4" />
                <span>Get Coilbox</span>
            </a>
            @if (Nav::moderates())
                @php $current = str_starts_with($path, '/moderation'); @endphp
                <a href="/moderation" class="{{ $navItem }} {{ $current ? $navCurrent : '' }}" data-moderator>
                    <x-icon name="moderation" class="w-4" />
                    <span>Moderation</span>
                </a>
            @endif
        </nav>
        <div class="order-2 -mr-2 flex items-center gap-3 text-[11px] text-neutral-400 sm:order-none sm:text-sm">
            @if ($nav)
                <a href="/account" class="{{ $navItem }}">
                    <x-icon name="account" class="w-4" />
                    <span class="block max-w-20 truncate sm:max-w-32">{{ $nav['name'] ?? 'Your account' }}</span>
                </a>
                <form action="/auth/signout" method="post">
                    @csrf
                    <button type="submit" class="{{ $navItem }}">
                        <x-icon name="sign-out" class="w-4" />
                        <span>Sign out</span>
                    </button>
                </form>
            @else
                <form action="/auth/signin" method="post">
                    @csrf
                    <button type="submit" class="{{ $navItem }}">
                        <x-icon name="sign-in" class="w-4" />
                        <span>Sign in</span>
                    </button>
                </form>
            @endif
        </div>
    </header>
    @yield('content')
    <footer class="flex flex-wrap gap-x-6 px-6 py-6 text-xs text-neutral-400">
        <a href="/developers" class="py-2 transition-colors hover:text-white active:text-white">API</a>
        <a href="/feed.xml" class="py-2 transition-colors hover:text-white active:text-white">Atom feed</a>
    </footer>
    @stack('scripts')
</body>
</html>
