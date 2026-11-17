// The map listing's small enhancements. The listing works without them.

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

// Picking a sort order applies it at once, so the bar needs no Filter button.
for (const select of document.querySelectorAll('select[data-submit-on-change]')) {
    select.addEventListener('change', () => select.form?.requestSubmit());
}

// A card's download button fetches from a random mirror, so no one mirror
// carries every download. Without this script it uses the first mirror.
for (const link of document.querySelectorAll('a[data-mirror-urls]')) {
    let urls;
    try {
        urls = JSON.parse(link.dataset.mirrorUrls);
    } catch {
        continue;
    }
    if (!Array.isArray(urls) || urls.length < 2) continue;
    // Before the browser follows the link, for a left click, a middle click and Enter alike.
    const choose = () => { link.href = urls[Math.floor(Math.random() * urls.length)]; };
    link.addEventListener('click', choose);
    link.addEventListener('auxclick', choose);
}

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
