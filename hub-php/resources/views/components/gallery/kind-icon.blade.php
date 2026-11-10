@props(['kind', 'mode' => null])
{{-- components/KindIcon.tsx: one glyph per kind, the mode first for a
     challenge. A kind with no glyph draws nothing. --}}
@php
    $glyph = match (true) {
        $mode === 'warpath' => '<path d="M4 17l5-5 5 3 4.5-6"></path><circle cx="20" cy="7" r="2.5"></circle>',
        $mode === 'conquest' => '<circle cx="6" cy="7" r="2.5"></circle><circle cx="18" cy="8" r="2.5"></circle><circle cx="12" cy="18" r="2.5"></circle><path d="M8.5 7.2l7 0.6"></path><path d="M7.2 9.2l3.6 6.6"></path><path d="M16.7 10.1l-3.4 5.8"></path>',
        $kind === 'preset' => '<rect x="3" y="5" width="7" height="14" rx="2"></rect><rect x="14" y="5" width="7" height="14" rx="2"></rect><path d="M12 3v18"></path>',
        $kind === 'setup-pack' => '<path d="M12 3l9 4.5-9 4.5-9-4.5L12 3z"></path><path d="M3 12.5l9 4.5 9-4.5"></path><path d="M3 17l9 4.5 9-4.5"></path>',
        $kind === 'scenario' => '<path d="M6 21V3"></path><path d="M6 3h12l-3 3.5L18 10H6"></path>',
        $kind === 'blueprint' => '<rect x="3" y="3" width="9" height="9" rx="1.5"></rect><rect x="15" y="3" width="6" height="6" rx="1.5"></rect><rect x="3" y="15" width="18" height="6" rx="1.5"></rect>',
        default => null,
    };
@endphp
@if ($glyph !== null)
<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" {{ $attributes }}>{!! $glyph !!}</svg>
@endif
