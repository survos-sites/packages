// Search cards render client-side, so one delegated listener serves every "A" button.
// The server returns AGENTS.md already rendered and escaped (PackageController::agents).
let dialog;

function ensureDialog() {
    if (dialog) return dialog;
    dialog = document.createElement('dialog');
    dialog.className = 'pk-agents';
    dialog.innerHTML = '<header><strong></strong><button type="button" data-close aria-label="Close">✕</button></header><div class="pk-agents-body"></div>';
    dialog.addEventListener('click', (event) => {
        if (event.target === dialog || event.target.closest('[data-close]')) dialog.close();
    });
    document.body.append(dialog);
    return dialog;
}

document.addEventListener('click', async (event) => {
    const button = event.target.closest('.pk-agents-btn');
    if (!button) return;
    event.preventDefault();
    const d = ensureDialog();
    d.querySelector('strong').textContent = `${button.dataset.agentsTitle} · AGENTS.md`;
    const body = d.querySelector('.pk-agents-body');
    body.textContent = 'Loading…';
    if (!d.open) d.showModal();
    try {
        const response = await fetch(button.dataset.agentsUrl);
        if (!response.ok) throw new Error(`${response.status} ${response.statusText}`);
        body.innerHTML = await response.text();
    } catch (error) {
        body.textContent = `Could not load AGENTS.md: ${error.message}`;
    }
});
