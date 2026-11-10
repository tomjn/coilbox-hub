@props(['value'])
{{-- components/DateTime.tsx. The full date is drawn here, and the browser
     turns a recent one into "3 days ago", as the Next app did after load. --}}
<time datetime="{{ $value }}" title="{{ App\Support\Dates::format($value, true) }} UTC" data-relative>{{ App\Support\Dates::format($value) }}</time>
@once
    @push('scripts')
        <script>
            (() => {
                const minute = 60000, hour = 60 * minute, day = 24 * hour;
                const words = new Intl.RelativeTimeFormat('en-GB', { numeric: 'always' });
                const now = Math.floor(Date.now() / minute) * minute;
                for (const time of document.querySelectorAll('time[data-relative]')) {
                    const at = new Date(time.getAttribute('datetime')).getTime();
                    if (Number.isNaN(at)) continue;
                    const age = now - at;
                    if (age < minute) time.textContent = 'just now';
                    else if (age < hour) time.textContent = words.format(-Math.floor(age / minute), 'minute');
                    else if (age < day) time.textContent = words.format(-Math.floor(age / hour), 'hour');
                    else if (age < 30 * day) time.textContent = words.format(-Math.floor(age / day), 'day');
                }
            })();
        </script>
    @endpush
@endonce
