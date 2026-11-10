// The map listing's two small enhancements. The listing works without them.

// "/" focuses the search box, as components/SearchShortcut.tsx did, unless
// the reader is typing somewhere or holding a modifier.
const TEXT_FIELDS = new Set(['INPUT', 'TEXTAREA', 'SELECT']);

export function shouldFocusSearch(event) {
    if (event.key !== '/') return false;
    if (event.defaultPrevented || event.isComposing) return false;
    if (event.ctrlKey || event.metaKey || event.altKey) return false;
    const target = event.target instanceof HTMLElement ? event.target : null;
    if (target?.isContentEditable) return false;
    return !TEXT_FIELDS.has((target?.tagName ?? '').toUpperCase());
}

document.addEventListener('keydown', (event) => {
    if (!shouldFocusSearch(event)) return;
    const input = document.querySelector('[data-search-shortcut]');
    if (!(input instanceof HTMLInputElement)) return;
    event.preventDefault();
    input.focus();
    input.select();
});

// A submitted form dims its button until the next page arrives, as
// components/BusyForm.tsx did. Restored when the page comes back from the
// back and forward cache.
for (const form of document.querySelectorAll('form[data-busy-form]')) {
    form.addEventListener('submit', () => form.setAttribute('aria-busy', 'true'));
}
window.addEventListener('pageshow', (event) => {
    if (!event.persisted) return;
    for (const form of document.querySelectorAll('form[data-busy-form]')) form.removeAttribute('aria-busy');
});
