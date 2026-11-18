/*
 * Mapping Unit HRIS: the "Petakan" forms (form[data-hris-map]) are sent in
 * place, so the page keeps its scroll position. The server answers with the
 * placement's mapped units and the page summaries, which replace the old
 * ones; the result is shown as a toast. Without JavaScript the form is a
 * plain POST that redirects back, as before.
 *
 * Registered in the capture phase, so it runs before the page-wide loading
 * handler in app.js, which skips events that were already prevented.
 */

function toast(type, message) {
    window.dispatchEvent(new CustomEvent('toast', { detail: { type, message } }));
}

function setBusy(form, button, busy) {
    const select = form.querySelector('select[name="unit_id"]');

    if (busy) {
        form.dataset.submitting = '1';
        button.dataset.originalHtml = button.innerHTML;
        // Keep the width so the layout does not jump.
        button.style.minWidth = `${button.offsetWidth}px`;
        button.innerHTML = `<span class="spinner" aria-hidden="true"></span><span>${button.dataset.loadingText || 'Memproses…'}</span>`;
    } else {
        delete form.dataset.submitting;
        button.innerHTML = button.dataset.originalHtml;
        button.style.minWidth = '';
    }

    button.disabled = busy;
    button.toggleAttribute('aria-busy', busy);
    if (select) {
        select.disabled = busy;
    }
}

function errorMessage(status, body) {
    if (status === 419) {
        return 'Sesi Anda telah berakhir. Muat ulang halaman lalu coba lagi.';
    }
    if (status === 403) {
        return 'Anda tidak memiliki akses untuk memetakan unit.';
    }

    // Laravel validation: the first message of the first invalid field.
    const fieldErrors = body?.errors ? Object.values(body.errors)[0] : null;

    return fieldErrors?.[0] || body?.message || 'Pemetaan gagal disimpan. Coba lagi beberapa saat lagi.';
}

function applyResult(form, data) {
    const placement = form.closest('[data-hris-placement]');
    const mapped = placement?.querySelector('[data-hris-mapped]');

    if (mapped) {
        mapped.innerHTML = data.mapped_html;
    }

    // Units already mapped to this placement cannot be chosen again.
    const taken = new Set(data.mapped_unit_ids.map(String));
    form.querySelectorAll('select[name="unit_id"] option').forEach((option) => {
        if (option.value !== '') {
            option.disabled = taken.has(option.value);
        }
    });
    const select = form.querySelector('select[name="unit_id"]');
    if (select) {
        select.value = '';
    }

    const summary = form.closest('[data-hris-placements]')?.querySelector('.card-header h3 + p');
    if (summary) {
        summary.textContent = data.summary;
    }

    const unmapped = document.querySelector('[data-hris-unmapped]');
    if (unmapped) {
        unmapped.innerHTML = data.unmapped_html;
        unmapped.hidden = data.unmapped_count === 0;
    }
}

async function submit(form, button) {
    // Read the fields before they are disabled: disabled fields are not sent.
    const data = new FormData(form);
    setBusy(form, button, true);

    let succeeded = false;

    try {
        const response = await fetch(form.action, {
            method: 'POST',
            body: data,
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
        });
        const body = await response.json().catch(() => null);

        if (response.ok && body) {
            applyResult(form, body);
            toast('success', body.message);
            succeeded = true;
        } else {
            toast('error', errorMessage(response.status, body));
        }
    } catch {
        toast('error', 'Server tidak dapat dihubungi. Periksa koneksi Anda lalu coba lagi.');
    } finally {
        setBusy(form, button, false);
    }

    // Back to the select, ready for another unit (no jump: it is in view).
    if (succeeded) {
        form.querySelector('select[name="unit_id"]')?.focus({ preventScroll: true });
    }
}

export default function initHrisMapping() {
    document.addEventListener('submit', (event) => {
        const form = event.target;

        if (!(form instanceof HTMLFormElement) || !form.matches('form[data-hris-map]')) {
            return;
        }

        event.preventDefault();

        // Already being sent: a second click or Enter does nothing.
        if (form.dataset.submitting) {
            return;
        }

        if (!form.reportValidity()) {
            return;
        }

        submit(form, event.submitter ?? form.querySelector('[type="submit"]'));
    }, true);
}
