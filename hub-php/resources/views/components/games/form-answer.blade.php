@props(['form'])
{{-- A form's own answer, flashed back under its key by ModerationController::answer(). --}}
@php
    $answer = session('toggle');
    $state = is_array($answer) && ($answer['form'] ?? null) === $form ? $answer : null;
@endphp
@if ($state)
    <p role="{{ $state['ok'] ? 'status' : 'alert' }}" {{ $attributes->merge(['class' => 'text-sm '.($state['ok'] ? 'text-neutral-300' : 'text-red-400')]) }}>{{ $state['message'] }}</p>
@endif
