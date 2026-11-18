/*
 * Conditional fields of the observation form.
 *
 * A field marked data-visible-when='{"key": "...", "values": [...]}' is shown
 * only while the named single-choice field holds one of those values (e.g.
 * the Rawat Inap questions while "Jenis Pelayanan" is Rawat Inap). While
 * hidden, its inputs are disabled: they are not required by the browser and
 * not submitted. The server applies the same rule again
 * (IndicatorField::isVisibleFor), so a hidden answer is never validated or
 * stored either way.
 *
 * The server renders the starting state, so nothing flickers on load.
 */
const FIELD = '[data-visible-when]';

function currentValue(form, key) {
    const element = form.elements.namedItem(key);

    if (!element) {
        return '';
    }

    // Radios share one name: RadioNodeList.value is the checked one.
    return element instanceof RadioNodeList ? element.value : (element.disabled ? '' : element.value);
}

function setDisabled(container, disabled) {
    container.querySelectorAll('input, select, textarea, button').forEach((input) => {
        if (disabled && !input.disabled) {
            input.disabled = true;
            input.dataset.conditionDisabled = '1';
        } else if (!disabled && input.dataset.conditionDisabled) {
            input.disabled = false;
            delete input.dataset.conditionDisabled;
        }
    });
}

export function applyConditions(form) {
    form.querySelectorAll(FIELD).forEach((field) => {
        let rule;

        try {
            rule = JSON.parse(field.dataset.visibleWhen);
        } catch {
            return;
        }

        const show = (rule.values ?? []).map(String).includes(currentValue(form, rule.key));
        const wasHidden = field.hidden;

        field.hidden = !show;
        setDisabled(field, !show);

        // A light fade-in when a field appears; nothing moves when it goes.
        if (show && wasHidden) {
            field.classList.remove('field-reveal');
            void field.offsetWidth;
            field.classList.add('field-reveal');
        }
    });

    // A section whose questions are all hidden disappears with its heading.
    form.querySelectorAll('[data-section]').forEach((section) => {
        const fields = [...section.querySelectorAll('[data-field]')];
        section.hidden = fields.length > 0 && fields.every((field) => field.hidden);
    });
}

export default function initConditionalFields() {
    const forms = () => new Set([...document.querySelectorAll(FIELD)].map((field) => field.closest('form')).filter(Boolean));

    forms().forEach(applyConditions);

    // Radios and selects fire change; the searchable dropdown dispatches one too.
    document.addEventListener('change', (event) => {
        const form = event.target.closest?.('form');

        if (form && form.querySelector(FIELD)) {
            applyConditions(form);
        }
    });

    // The observation modal is moved into <body> by Alpine after load.
    document.addEventListener('dialog-opened', () => forms().forEach(applyConditions));
}
