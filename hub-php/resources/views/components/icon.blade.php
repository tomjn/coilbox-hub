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
@endswitch
</svg>
