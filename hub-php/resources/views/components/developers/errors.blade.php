@props(['rows'])
<ul class="flex flex-col gap-2 text-sm text-neutral-300">
@foreach ($rows as $row)
<li><x-developers.inline>{{ $row['status'] }}</x-developers.inline> {{ $row['text'] }} Body: <x-developers.inline>{{ $row['body'] }}</x-developers.inline></li>
@endforeach
</ul>
