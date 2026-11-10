@props(['id', 'title', 'path' => null])
<section id="{{ $id }}" aria-labelledby="{{ $id }}-heading" class="flex flex-col gap-4 scroll-mt-6">
<h2 id="{{ $id }}-heading" class="text-xl font-semibold">{{ $title }}</h2>
@if ($path)
<p class="break-all font-mono text-sm text-neutral-100">{{ $path }}</p>
@endif
{{ $slot }}
</section>
