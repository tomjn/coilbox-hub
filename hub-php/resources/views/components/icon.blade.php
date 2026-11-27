@props(['name'])
{{-- The hub's icon set, from components/icons.tsx: one 24px grid and stroke
     weight, the same as the coil mark. Every icon sits beside its own text
     label, so it is hidden from assistive technology. --}}
<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" {{ $attributes }}>
@switch($name)
    @case('gallery')
        <rect x="3" y="3" width="7" height="7" rx="1" /><rect x="14" y="3" width="7" height="7" rx="1" /><rect x="3" y="14" width="7" height="7" rx="1" /><rect x="14" y="14" width="7" height="7" rx="1" />
        @break
    @case('maps')
        <path d="M9 3 3 6v15l6-3 6 3 6-3V3l-6 3-6-3z" /><path d="M9 3v15" /><path d="M15 6v15" />
        @break
    @case('games')
        <path d="M6 9h4" /><path d="M8 7v4" /><path d="M15 8h0.01" /><path d="M18 11h0.01" /><path d="M17.3 5H6.7a4.7 4.7 0 0 0-4.66 5.28l.72 5.76A2.94 2.94 0 0 0 7.87 18l1.63-2h5l1.63 2a2.94 2.94 0 0 0 5.11-1.96l.72-5.76A4.7 4.7 0 0 0 17.3 5z" />
        @break
    @case('replays')
        <circle cx="12" cy="12" r="9" /><path d="M10 8l6 4-6 4V8z" />
        @break
    @case('publish')
        <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4" /><path d="M17 8l-5-5-5 5" /><path d="M12 3v12" />
        @break
    @case('download')
        <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4" /><path d="M7 10l5 5 5-5" /><path d="M12 15V3" />
        @break
    @case('external')
        <path d="M14 4h6v6" /><path d="M20 4 11 13" /><path d="M18 14v5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h5" />
        @break
    @case('moderation')
        <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z" />
        @break
    @case('account')
        <path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2" /><circle cx="12" cy="7" r="4" />
        @break
    @case('sign-in')
        <path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4" /><path d="M10 17l5-5-5-5" /><path d="M15 12H3" />
        @break
    @case('sign-out')
        <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4" /><path d="M16 17l5-5-5-5" /><path d="M21 12H9" />
        @break
    {{-- The replay page's headline tiles, the lucide icons Coilbox's tiles use. --}}
    @case('clock')
        <circle cx="12" cy="12" r="10" /><path d="M12 6v6l4 2" />
        @break
    @case('trophy')
        <path d="M6 9H4.5a2.5 2.5 0 0 1 0-5H6" /><path d="M18 9h1.5a2.5 2.5 0 0 0 0-5H18" /><path d="M4 22h16" /><path d="M10 14.66V17c0 .55-.47.98-.97 1.21C7.85 18.75 7 20.24 7 22" /><path d="M14 14.66V17c0 .55.47.98.97 1.21C16.15 18.75 17 20.24 17 22" /><path d="M18 2H6v7a6 6 0 0 0 12 0V2Z" />
        @break
    @case('users')
        <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2" /><circle cx="9" cy="7" r="4" /><path d="M22 21v-2a4 4 0 0 0-3-3.87" /><path d="M16 3.13a4 4 0 0 1 0 7.75" />
        @break
    @case('swords')
        <path d="M14.5 17.5 3 6V3h3l11.5 11.5" /><path d="M13 19l6-6" /><path d="M16 16l4 4" /><path d="M19 21l2-2" /><path d="M14.5 6.5 18 3h3v3l-3.5 3.5" /><path d="M5 14l4 4" /><path d="M7 17l-3 3" /><path d="M3 19l2 2" />
        @break
    @case('factory')
        <path d="M2 20a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V8l-7 5V8l-7 5V4a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2Z" /><path d="M17 18h1" /><path d="M12 18h1" /><path d="M7 18h1" />
        @break
@endswitch
</svg>
