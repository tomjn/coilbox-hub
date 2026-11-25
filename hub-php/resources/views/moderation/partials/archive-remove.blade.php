@use('App\Domain\Mirrors\Removal')
@use('App\Support\Ui')
<form method="post" action="/moderation/archives/remove" class="flex flex-wrap items-end gap-3">
    @csrf
    <input type="hidden" name="archive_id" value="{{ $a['id'] }}">
    @if (! empty($return))
        <input type="hidden" name="return" value="{{ $return }}">
    @endif
    <label class="flex flex-col gap-1.5 text-sm">
        <span class="text-neutral-400">Reason</span>
        <select name="reason" required class="{{ Ui::FIELD }}">
            <option value="">Pick a reason</option>
            @foreach (Removal::MODERATOR_REASONS as $code => $label)
                <option value="{{ $code }}">{{ $label }}</option>
            @endforeach
        </select>
    </label>
    <label class="flex flex-col gap-1.5 text-sm">
        <span class="text-neutral-400">Note, only moderators see it</span>
        <input name="note" maxlength="2000" class="{{ Ui::FIELD }}">
    </label>
    <button type="submit" class="{{ Ui::button() }}">Remove {{ $a['filename'] }}</button>
</form>
