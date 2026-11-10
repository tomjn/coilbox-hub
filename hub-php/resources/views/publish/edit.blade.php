@extends('layouts.app')

@php
    use App\Support\Ui;
    $state = session('edit', []);
    $id = $item['id'];
    $submit = 'self-start rounded-md bg-neutral-100 px-5 py-2.5 text-sm font-medium text-neutral-900 transition-colors hover:bg-white active:bg-neutral-300 disabled:opacity-60';
@endphp

@section('content')
<main id="main-content" tabindex="-1" class="mx-auto flex w-full max-w-2xl flex-1 flex-col gap-8 px-6 py-12">
    <div class="flex flex-col gap-2">
        <a href="{{ $withdrawn ? '/item/'.$id.'/withdrawn' : '/item/'.$id }}" class="self-start text-sm text-neutral-400 transition-colors hover:text-neutral-300 active:text-neutral-300">Back to the item</a>
        <h1 class="text-3xl font-semibold tracking-tight">Edit</h1>
        <p class="text-sm text-neutral-400">The words around it, not the thing itself. Sharing a changed version means publishing it again, so the link people already have keeps meaning what it meant.</p>
    </div>

    <form action="/item/{{ $id }}/edit" method="post" class="flex flex-col gap-5">
        @csrf
        <input type="hidden" name="id" value="{{ $id }}">

        <label class="flex flex-col gap-2">
            <span class="text-sm font-medium">Title</span>
            <input name="title" required maxlength="120" value="{{ $item['title'] }}" class="{{ Ui::FIELD }}">
        </label>

        <label class="flex flex-col gap-2">
            <span class="text-sm font-medium">Description</span>
            <textarea name="description" rows="5" maxlength="2000" class="{{ Ui::FIELD }}">{{ $item['description'] }}</textarea>
        </label>

        <label class="flex flex-col gap-2">
            <span class="text-sm font-medium">Tags</span>
            <input name="tags" value="{{ implode(', ', $item['tags'] ?? []) }}" class="{{ Ui::FIELD }}" placeholder="eco, 8v8, beginner">
        </label>

        @isset($state['error'])
            <p role="alert" class="text-sm text-red-400">{{ $state['error'] }}</p>
        @endisset

        <button type="submit" class="{{ $submit }}" data-busy-label="Saving…">Save</button>
    </form>

    <div class="flex flex-col gap-3 border-t border-neutral-900 pt-6">
        <h2 class="text-sm font-medium">{{ $withdrawn ? 'Withdrawn' : 'Withdraw' }}</h2>
        <p class="text-sm text-neutral-400">{{ $withdrawn ? 'Nobody else can see this and its import link returns nothing. You can put it back.' : 'It stops appearing and its import link stops working. Nothing is destroyed and you can put it back.' }}</p>
        <form action="/item/{{ $id }}/withdraw" method="post">
            @csrf
            <input type="hidden" name="id" value="{{ $id }}">
            <input type="hidden" name="withdrawn" value="{{ $withdrawn ? 'false' : 'true' }}">
            <button type="submit" class="{{ Ui::button() }}">{{ $withdrawn ? 'Put it back' : 'Withdraw it' }}</button>
        </form>
    </div>
</main>
@endsection

@push('scripts')
    @include('publish.busy-script')
@endpush
