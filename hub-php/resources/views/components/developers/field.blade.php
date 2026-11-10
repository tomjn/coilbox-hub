@props(['name', 'type'])
<div class="flex flex-col gap-0.5">
<dt class="font-mono text-neutral-100">{{ $name }} <span class="text-neutral-400">{{ $type }}</span></dt>
<dd class="text-neutral-300">{{ $slot }}</dd>
</div>
