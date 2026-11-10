// The map page's scripts: the report form, and the 3D terrain where the hub
// holds a height overlay. The page reads fully without either.
//
// three.js sits behind a dynamic import, so it is its own chunk, fetched only
// on a map page that has a preview, and only after the page has painted.

const report = document.querySelector('[data-map-report]');
if (report) {
    const open = report.querySelector('[data-map-report-open]');
    const form = report.querySelector('[data-map-report-form]');
    const error = report.querySelector('[data-map-report-error]');
    const cancel = report.querySelector('[data-map-report-cancel]');

    open?.addEventListener('click', () => {
        open.hidden = true;
        form.hidden = false;
    });
    cancel?.addEventListener('click', () => {
        form.hidden = true;
        open.hidden = false;
    });

    // Posted in place, so the reader stays where they are. A failure to reach
    // the hub at all says so in the form, the same words the Next app used.
    form?.addEventListener('submit', async (event) => {
        event.preventDefault();
        const send = form.querySelector('button[type="submit"]');
        send.disabled = true;
        send.textContent = 'Sending…';
        let state;
        try {
            const response = await fetch(form.action, {
                method: 'POST',
                body: new FormData(form),
                headers: { Accept: 'application/json' },
            });
            state = response.ok ? await response.json() : null;
        } catch {
            state = null;
        }
        state ??= { ok: false, message: 'The form did not reach the hub. Reload the page and try again.' };

        if (state.ok) {
            const done = document.createElement('p');
            done.setAttribute('role', 'status');
            done.className = 'text-xs text-neutral-400';
            done.textContent = state.message;
            report.replaceChildren(done);
            return;
        }
        error.textContent = state.message;
        error.hidden = false;
        send.disabled = false;
        send.textContent = 'Send';
    });
}

const figure = document.querySelector('[data-map-preview]');
if (figure) {
    const flat = document.querySelector('[data-map-preview-flat]');
    const host = figure.querySelector('[data-map-preview-host]');
    const preview = JSON.parse(figure.dataset.mapPreview);
    // Read once as the scene is built. Rebuilding a WebGL scene under somebody
    // who changed a system setting is worse than a drift they can stop.
    const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    const layers = { metal: true, geo: true };

    import('./terrain')
        .then(({ drawTerrain }) => drawTerrain(host, preview, reduceMotion))
        .then((terrain) => {
            // The frame measures nothing while hidden. The terrain's resize
            // observer notices when it is shown.
            flat.classList.add('hidden');
            figure.classList.remove('hidden');
            figure.classList.add('flex', 'flex-col', 'gap-2');
            terrain.setLayers(layers);

            for (const button of figure.querySelectorAll('[data-map-layer]')) {
                button.addEventListener('click', () => {
                    const layer = button.dataset.mapLayer;
                    layers[layer] = !layers[layer];
                    button.setAttribute('aria-pressed', String(layers[layer]));
                    terrain.setLayers({ ...layers });
                });
            }
        })
        // No WebGL, a picture that will not load: the reader keeps the flat
        // figure, which is the whole map correctly drawn, and nothing apologises.
        .catch(() => {});
}
