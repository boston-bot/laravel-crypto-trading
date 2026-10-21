// Progressive enhancement for the operator console. Every control still works without JavaScript.
const root = document.documentElement;

function storeTheme(theme) {
    try {
        localStorage.setItem('ops-theme', theme);
    } catch {
        // Private windows may block storage; the theme then lasts for this page only.
    }
}

document.querySelectorAll('[data-theme-toggle]').forEach((button) => {
    button.addEventListener('click', () => {
        const current = root.dataset.theme || (matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
        const next = current === 'dark' ? 'light' : 'dark';
        root.dataset.theme = next;
        storeTheme(next);
    });
});

// Mobile navigation drawer.
const shell = document.querySelector('.shell');
const toggles = document.querySelectorAll('[data-nav-toggle]');
function setNav(open) {
    if (!shell) return;
    shell.dataset.nav = open ? 'open' : '';
    toggles.forEach((t) => t.hasAttribute('aria-expanded') && t.setAttribute('aria-expanded', String(open)));
}
toggles.forEach((t) => t.addEventListener('click', () => setNav(shell?.dataset.nav !== 'open')));
document.addEventListener('keydown', (event) => event.key === 'Escape' && setNav(false));

// Relative ages beside absolute timestamps; the server-rendered time remains authoritative.
const units = [['y', 31536000], ['mo', 2592000], ['d', 86400], ['h', 3600], ['m', 60]];
function relative(date) {
    const seconds = (Date.now() - date.getTime()) / 1000;
    const magnitude = Math.abs(seconds);
    if (magnitude < 60) return seconds >= 0 ? 'just now' : 'in <1m';
    for (const [unit, size] of units) {
        if (magnitude >= size) {
            const value = Math.floor(magnitude / size);
            return seconds >= 0 ? `${value}${unit} ago` : `in ${value}${unit}`;
        }
    }
    return '';
}
function tick() {
    document.querySelectorAll('time[data-relative]').forEach((el) => {
        const date = new Date(el.getAttribute('datetime'));
        if (!Number.isNaN(date.getTime())) el.dataset.rel = relative(date);
    });
}
tick();
setInterval(tick, 30000);

// Guard against double submission; idempotency keys still protect the server.
document.addEventListener('submit', (event) => {
    const form = event.target;
    if (event.defaultPrevented || form.hasAttribute('data-read')) return;
    const button = event.submitter || form.querySelector('button[type="submit"], button:not([type])');
    if (!button) return;
    button.dataset.busy = '';
    setTimeout(() => { button.disabled = true; }, 0);
});
window.addEventListener('pageshow', () => {
    document.querySelectorAll('[data-busy]').forEach((button) => {
        button.disabled = false;
        delete button.dataset.busy;
    });
});

// Dismissible callouts.
document.addEventListener('click', (event) => {
    const dismiss = event.target.closest('[data-dismiss]');
    if (dismiss) dismiss.closest('.callout')?.remove();
});

// Copy helpers.
async function copyText(text, button) {
    try {
        await navigator.clipboard.writeText(text);
        button.classList.add('is-copied');
        setTimeout(() => button.classList.remove('is-copied'), 1400);
    } catch {
        button.title = 'Copy unavailable; select the text instead';
    }
}
document.addEventListener('click', (event) => {
    const direct = event.target.closest('[data-copy]');
    if (direct) return copyText(direct.dataset.copy, direct);
    const targeted = event.target.closest('[data-copy-target]');
    if (targeted) {
        const field = document.querySelector(targeted.dataset.copyTarget);
        if (field) copyText(field.value ?? field.textContent, targeted);
    }
});

// Manual broker read checks render inline instead of navigating to raw JSON.
const output = document.getElementById('read-output');
async function showRead(url, label) {
    if (!output) return false;
    const pre = output.querySelector('pre');
    const title = output.querySelector('.json-title');
    output.hidden = false;
    output.classList.remove('is-error');
    title.textContent = `${label} · loading…`;
    pre.textContent = '';
    try {
        const response = await fetch(url, { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' });
        const body = await response.json().catch(() => null);
        if (!response.ok || body === null) output.classList.add('is-error');
        title.textContent = `${label} · HTTP ${response.status}${body && 'complete' in body ? (body.complete ? ' · complete' : ' · incomplete') : ''}`;
        pre.textContent = body === null ? 'The response was not JSON. Open the link directly to inspect it.' : JSON.stringify(body, null, 2);
    } catch {
        output.classList.add('is-error');
        title.textContent = `${label} · request failed`;
        pre.textContent = 'The local application did not respond.';
    }
    output.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    return true;
}
document.querySelectorAll('a[data-read]').forEach((link) => {
    link.addEventListener('click', (event) => {
        if (event.metaKey || event.ctrlKey || event.shiftKey || !output) return;
        event.preventDefault();
        showRead(link.href, link.textContent.trim());
    });
});
document.querySelectorAll('form[data-read]').forEach((form) => {
    form.addEventListener('submit', (event) => {
        if (!output) return;
        event.preventDefault();
        const url = new URL(form.action, location.href);
        new FormData(form).forEach((value, key) => url.searchParams.set(key, value));
        showRead(url.toString(), form.querySelector('button')?.textContent.trim() || 'Read');
    });
});
document.querySelector('[data-dismiss-json]')?.addEventListener('click', () => { if (output) output.hidden = true; });


// Historical experiment variant editor. Values remain strings; all validation/calculation is server-side.
document.querySelectorAll('[data-experiment-form]').forEach((form) => {
    const list = form.querySelector('[data-variant-list]');
    const template = form.querySelector('[data-variant-template]');
    const add = form.querySelector('[data-add-variant]');
    if (!list || !template || !add) return;
    let next = Math.max(-1, ...Array.from(list.querySelectorAll('input[name="baseline_index"]'), (r) => Number(r.value))) + 1;
    function update() {
        const rows = list.querySelectorAll('[data-variant]');
        add.hidden = false;
        add.disabled = rows.length >= 8;
        rows.forEach((row) => {
            const remove = row.querySelector('[data-remove-variant]');
            remove.hidden = false;
            remove.disabled = rows.length <= 1;
        });
    }
    add.addEventListener('click', () => {
        if (list.querySelectorAll('[data-variant]').length >= 8) return;
        const holder = document.createElement('template');
        holder.innerHTML = template.innerHTML.replaceAll('__INDEX__', String(next++));
        list.append(holder.content.cloneNode(true));
        update();
        list.lastElementChild?.querySelector('input')?.focus();
    });
    list.addEventListener('click', (event) => {
        const remove = event.target.closest('[data-remove-variant]');
        if (!remove || list.querySelectorAll('[data-variant]').length <= 1) return;
        const row = remove.closest('[data-variant]');
        const wasBaseline = row.querySelector('[name="baseline_index"]').checked;
        row.remove();
        if (wasBaseline) list.querySelector('[name="baseline_index"]').checked = true;
        update();
    });
    update();
});
