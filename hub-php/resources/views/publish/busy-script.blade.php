{{-- The pending label `useActionState` gave the submit button, put back when
     the browser restores this page from its history cache. --}}
<script>
    for (const button of document.querySelectorAll('[data-busy-label]')) {
        const idle = button.textContent;
        button.form?.addEventListener('submit', () => {
            button.disabled = true;
            button.textContent = button.dataset.busyLabel;
        });
        window.addEventListener('pageshow', (event) => {
            if (!event.persisted) return;
            button.disabled = false;
            button.textContent = idle;
        });
    }
</script>
