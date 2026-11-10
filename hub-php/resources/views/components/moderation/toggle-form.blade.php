@props(['action', 'fields' => [], 'label', 'buttonClass', 'formClass' => 'flex flex-col gap-1.5'])
{{-- components/VisibilityToggleForm.tsx as a plain form post. The answer is
     flashed back with this form's key, see ModerationController::answer(), so
     it shows under the form that was sent and no other. --}}
@php
    // Keyed without the switch itself, so a feature button that has just
    // become an unfeature button still shows what happened.
    $key = substr(sha1($action.'|'.json_encode(array_diff_key($fields, ['featured' => 1, 'hidden' => 1]))), 0, 16);
    $answer = session('toggle');
    $state = is_array($answer) && ($answer['form'] ?? null) === $key ? $answer : null;
@endphp
<form method="post" action="{{ $action }}" class="{{ $formClass }}">
    @csrf
    <input type="hidden" name="_form" value="{{ $key }}">
    @foreach ($fields as $name => $value)
        <input type="hidden" name="{{ $name }}" value="{{ $value }}">
    @endforeach
    {{ $slot }}
    <button type="submit" class="{{ $buttonClass }}">{{ $label }}</button>
    @if ($state)
        <p role="{{ $state['ok'] ? 'status' : 'alert' }}" class="w-full text-sm {{ $state['ok'] ? 'text-neutral-300' : 'text-red-400' }}">{{ $state['message'] }}</p>
    @endif
</form>
