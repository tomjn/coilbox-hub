@props(['current'])
{{-- components/ModerationNav.tsx. One bar on every moderation page, full width
     under the site header so it never moves between pages of different
     widths. The current section is a tab and aria-current, not just a colour.
     Each state sets its own border colour, because two on one element resolve
     by stylesheet order. --}}
@php
    $sections = \App\Http\Controllers\Moderation\ModerationController::SECTIONS;
    $link = '-mb-px block border-b-2 px-2 py-2 text-sm transition-colors';
@endphp
<nav aria-label="Moderation" class="relative z-10 border-b border-neutral-900 px-6">
    <ul class="-mx-2 flex flex-wrap items-center">
        @foreach ($sections as $id => $section)
            <li>
                <a href="{{ $section['href'] }}" @if ($id === $current) aria-current="page" @endif class="{{ $link }} {{ $id === $current ? 'border-neutral-500 text-neutral-100' : 'border-transparent text-neutral-400 hover:text-neutral-300 active:text-neutral-300' }}">{{ $section['label'] }}</a>
            </li>
        @endforeach
    </ul>
</nav>
