{{-- components/Breadcrumb.tsx. `crumbs` is a list of ['label', 'href'?]. The
     last crumb is the current page and is never a link. Long names are
     clipped, with the full name in the title. --}}
@props(['crumbs'])
<nav class="text-sm text-neutral-400" aria-label="Breadcrumb">
    @foreach ($crumbs as $index => $crumb)
        @php $last = $index === count($crumbs) - 1; @endphp
        @if ($index > 0)<span aria-hidden="true"> / </span>@endif
        @if (isset($crumb['href']) && ! $last)
            <a title="{{ $crumb['label'] }}" class="inline-block max-w-xs truncate align-bottom underline-offset-4 hover:underline active:underline" href="{{ $crumb['href'] }}">{{ $crumb['label'] }}</a>
        @else
            <span title="{{ $crumb['label'] }}" @if ($last) aria-current="page" @endif class="inline-block max-w-xs truncate align-bottom text-neutral-300">{{ $crumb['label'] }}</span>
        @endif
    @endforeach
</nav>
