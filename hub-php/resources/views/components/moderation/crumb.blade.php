@props(['parent'])
{{-- ModerationCrumb: names the section a sub-page belongs to, and links back up. --}}
@php $section = \App\Http\Controllers\Moderation\ModerationController::SECTIONS[$parent]; @endphp
<p class="text-sm text-neutral-400"><a href="{{ $section['href'] }}" class="transition-colors hover:text-neutral-300 active:text-neutral-300">{{ $section['label'] }}</a> / {{ $slot }}</p>
