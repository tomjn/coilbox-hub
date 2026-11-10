{{-- A unit's name linking to its page, ChangesView.tsx UnitLink. --}}
@props(['game', 'unit'])
@use('App\Domain\Games\Js')
<a href="/games/{{ $game }}/units/{{ Js::encodeURIComponent($unit['unit_name']) }}" class="text-neutral-100 underline-offset-4 hover:underline active:underline">{{ $unit['full_name'] ?? $unit['unit_name'] }}</a>
