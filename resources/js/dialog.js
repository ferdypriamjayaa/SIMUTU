import { lockScroll, unlockScroll } from './scroll-lock';
import { justDismissed } from './combobox';

/*
 * Modal dialog rendered open by the server (e.g. the observation form).
 *
 * - Fades and scales in after the first paint, so it never flashes or jumps.
 * - Locks the page scroll without shifting it; the page keeps its position.
 * - Keeps Tab inside the dialog; Escape, the backdrop and Cancel close it.
 * - Asks before closing when the form holds unsaved input.
 *
 * Config: { closeUrl, dirty, confirm } — closeUrl replaces the address on
 * close (the page underneath is the list the dialog was opened from).
 */
const DURATION = 200;

export default function dialog(config = {}) {
    return {
        visible: true,
        open: false,
        dirty: Boolean(config.dirty),

        init() {
            lockScroll();
            this.lastFocus = document.activeElement;
            // Lets page scripts set up the form it holds (conditional fields).
            this.$dispatch('dialog-opened');

            // Two frames: the closed state is painted first, so the transition runs.
            requestAnimationFrame(() => requestAnimationFrame(() => {
                this.open = true;
                // After x-show has displayed the form: hidden fields cannot take focus.
                this.$nextTick(() => this.focusFirst());
            }));
        },

        destroy() {
            if (this.visible) {
                unlockScroll();
            }
        },

        // Backdrop click: ignored when it only closed an open dropdown list.
        closeFromBackdrop() {
            if (!justDismissed()) {
                this.close();
            }
        },

        close() {
            if (!this.open) {
                return;
            }

            if (this.dirty && !window.confirm(config.confirm ?? 'Isian yang belum disimpan akan hilang. Tutup formulir?')) {
                return;
            }

            this.open = false;

            if (config.closeUrl) {
                history.replaceState(history.state, '', config.closeUrl);
            }

            setTimeout(() => {
                this.visible = false;
                unlockScroll();
                this.$dispatch('dialog-closed');
            }, DURATION);
        },

        focusables() {
            const selector = 'a[href], button:not([disabled]), input:not([type=hidden]):not([disabled]):not([tabindex="-1"]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';

            return [...this.$el.querySelectorAll(selector)].filter((el) => el.offsetParent !== null);
        },

        focusFirst() {
            const field = this.$el.querySelector('[data-dialog-body] :is(input:not([type=hidden]):not([tabindex="-1"]), select, textarea, button)');

            (field ?? this.focusables()[0])?.focus({ preventScroll: true });
        },

        trap(event) {
            const items = this.focusables();

            if (items.length === 0) {
                return;
            }

            const first = items[0];
            const last = items[items.length - 1];

            if (event.shiftKey && document.activeElement === first) {
                event.preventDefault();
                last.focus();
            } else if (!event.shiftKey && document.activeElement === last) {
                event.preventDefault();
                first.focus();
            }
        },
    };
}
