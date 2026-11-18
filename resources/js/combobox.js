/*
 * Searchable dropdown (combobox) used by the observation form.
 *
 * Two sources:
 * - local: the options are on the page (Form Builder choices); typing filters them.
 * - remote: the options are searched on the server, page by page (HRIS
 *   employees, Doctor API doctors), so the page never carries the whole list.
 *   Typing is debounced; a stale answer never replaces a newer one.
 *
 * The chosen value goes into a hidden input; the server rendered the current
 * choice (also after a failed save), so it survives a validation error.
 *
 * The list panel is teleported to <body> and positioned against the trigger,
 * so it is never cut off by a scrolling modal and always sits above it.
 *
 * Config: {
 *   id, options?: [{value, label, description?, group?}], url?, params?,
 *   selected?: {value, label}, manual?: {value, label}, placeholder,
 *   emptyText, required, invalid
 * }
 */
const DEBOUNCE = 250;

// When a list was last closed by a click outside it; that click must not also close the modal underneath.
let dismissedAt = 0;

export function justDismissed() {
    return performance.now() - dismissedAt < 400;
}
const PANEL_MAX = 320;
const GAP = 6;

function normalize(value) {
    return String(value ?? '')
        .normalize('NFD')
        .replace(/[̀-ͯ]/g, '')
        .toLowerCase()
        .replace(/\s+/g, ' ')
        .trim();
}

export default function combobox(config) {
    return {
        id: config.id,
        remote: Boolean(config.url),
        selected: config.selected ?? null,
        manual: config.manual ?? null,
        placeholder: config.placeholder ?? 'Pilih…',
        open: false,
        query: '',
        items: [],
        active: -1,
        loading: false,
        error: '',
        nextPage: null,
        loaded: false,
        invalid: Boolean(config.invalid),
        panelStyle: '',
        placement: 'bottom',
        request: 0,
        timer: null,
        controller: null,

        init() {
            this.$watch('query', () => {
                this.active = -1;

                if (this.remote) {
                    clearTimeout(this.timer);
                    this.loading = true;
                    this.timer = setTimeout(() => this.fetch(1), DEBOUNCE);
                } else {
                    this.items = this.filterLocal();
                }
            });

            this.onOutside = (event) => {
                if (this.open && !this.$root.contains(event.target) && !this.panel()?.contains(event.target)) {
                    dismissedAt = performance.now();
                    this.hide(false);
                }
            };
            this.onReflow = (event) => {
                // Scrolling inside the list itself does not move the trigger.
                if (this.open && !(event?.target instanceof Node && this.panel()?.contains(event.target))) {
                    this.position();
                }
            };

            document.addEventListener('pointerdown', this.onOutside, true);
            window.addEventListener('resize', this.onReflow);
            window.addEventListener('scroll', this.onReflow, true);
            window.visualViewport?.addEventListener('resize', this.onReflow);
        },

        destroy() {
            document.removeEventListener('pointerdown', this.onOutside, true);
            window.removeEventListener('resize', this.onReflow);
            window.removeEventListener('scroll', this.onReflow, true);
            window.visualViewport?.removeEventListener('resize', this.onReflow);
            this.controller?.abort();
        },

        get value() {
            return this.selected?.value ?? '';
        },

        get isManual() {
            return this.manual !== null && this.value === this.manual.value;
        },

        get emptyText() {
            if (this.query.trim() !== '') {
                return `Tidak ada hasil untuk “${this.query.trim()}”.`;
            }

            return config.emptyText ?? 'Tidak ada pilihan.';
        },

        panel() {
            return document.getElementById(`${this.id}-panel`);
        },

        // --- opening and closing ---------------------------------------------------

        toggle() {
            this.open ? this.hide() : this.show();
        },

        show() {
            if (this.open) {
                return;
            }

            this.open = true;
            this.query = '';
            this.items = this.remote ? this.items : this.filterLocal();

            if (this.remote && !this.loaded) {
                this.loading = true;
                this.fetch(1);
            }

            this.$nextTick(() => {
                this.position();
                // The first search shows the current choice highlighted.
                this.active = this.items.findIndex((item) => item.value === this.value);
                this.$nextTick(() => {
                    document.getElementById(`${this.id}-search`)?.focus({ preventScroll: true });
                    this.scrollToActive();
                });
            });
        },

        hide(refocus = true) {
            if (!this.open) {
                return;
            }

            this.open = false;
            clearTimeout(this.timer);

            if (refocus) {
                this.$refs.trigger.focus({ preventScroll: true });
            }
        },

        // --- choosing ------------------------------------------------------------

        choose(item) {
            const changed = item.value !== this.value;

            this.selected = { value: item.value, label: item.label };
            this.invalid = false;
            this.hide();

            if (changed) {
                // Lets the form know it now holds unsaved input.
                this.$root.dispatchEvent(new Event('change', { bubbles: true }));

                if (this.isManual) {
                    this.$nextTick(() => this.$root.querySelector('[data-combobox-manual]')?.focus());
                }
            }
        },

        chooseActive() {
            const options = this.navigable();

            if (this.active >= 0 && options[this.active]) {
                this.choose(options[this.active]);
            }
        },

        clear() {
            this.selected = null;
            this.$root.dispatchEvent(new Event('change', { bubbles: true }));
        },

        // The hidden "required" mirror failed validation: show it on the trigger.
        onInvalid(event) {
            event.preventDefault();
            this.invalid = true;

            const form = this.$root.closest('form');
            if (form?.querySelector(':invalid') === event.target) {
                this.$refs.trigger.focus();
                this.$refs.trigger.scrollIntoView({ block: 'center', behavior: 'smooth' });
            }
        },

        // --- keyboard --------------------------------------------------------------

        navigable() {
            return this.manual ? [...this.items, this.manual] : this.items;
        },

        move(step) {
            const count = this.navigable().length;

            if (count === 0) {
                return;
            }

            this.active = this.active < 0 ? (step > 0 ? 0 : count - 1) : (this.active + step + count) % count;
            this.$nextTick(() => this.scrollToActive());

            // Reaching the end of a remote list loads the next page.
            if (this.remote && this.nextPage && this.active >= this.items.length - 2) {
                this.loadMore();
            }
        },

        scrollToActive() {
            document.getElementById(`${this.id}-option-${this.active}`)?.scrollIntoView({ block: 'nearest' });
        },

        // --- data ------------------------------------------------------------------

        filterLocal() {
            const words = normalize(this.query).split(' ').filter(Boolean);
            const options = config.options ?? [];

            if (words.length === 0) {
                return options;
            }

            return options.filter((option) => {
                const text = normalize(`${option.label} ${option.description ?? ''}`);

                return words.every((word) => text.includes(word));
            });
        },

        async fetch(page) {
            const request = ++this.request;

            this.controller?.abort();
            this.controller = new AbortController();
            this.loading = true;
            this.error = '';

            try {
                const { data } = await window.axios.get(config.url, {
                    params: { ...(config.params ?? {}), q: this.query.trim(), page },
                    signal: this.controller.signal,
                });

                // A newer search was started meanwhile.
                if (request !== this.request) {
                    return;
                }

                this.items = page === 1 ? data.data : [...this.items, ...data.data];
                this.nextPage = data.next_page;
                this.loaded = true;
            } catch (error) {
                if (request !== this.request || error.code === 'ERR_CANCELED') {
                    return;
                }

                this.error = error.response?.data?.message ?? 'Data tidak dapat dimuat. Periksa koneksi lalu coba lagi.';
            } finally {
                if (request === this.request) {
                    this.loading = false;
                    this.$nextTick(() => this.position());
                }
            }
        },

        loadMore() {
            if (this.remote && this.nextPage && !this.loading) {
                this.fetch(this.nextPage);
            }
        },

        retry() {
            this.fetch(1);
        },

        onListScroll(event) {
            const list = event.target;

            if (list.scrollTop + list.clientHeight >= list.scrollHeight - 48) {
                this.loadMore();
            }
        },

        // Group heading before the first option of each group (doctors by hospital).
        groupStart(index) {
            const group = this.items[index]?.group;

            return Boolean(group) && (index === 0 || this.items[index - 1]?.group !== group);
        },

        // --- placement -------------------------------------------------------------

        position() {
            const trigger = this.$refs.trigger;

            if (!trigger) {
                return;
            }

            const rect = trigger.getBoundingClientRect();
            const viewport = window.visualViewport;
            const height = viewport ? viewport.height : window.innerHeight;
            const width = document.documentElement.clientWidth;

            // The trigger scrolled out of view (e.g. inside the modal): close the list.
            if (rect.bottom < 0 || rect.top > height) {
                this.hide(false);
                return;
            }

            const below = height - rect.bottom - GAP - 8;
            const above = rect.top - GAP - 8;
            this.placement = below >= Math.min(PANEL_MAX, 220) || below >= above ? 'bottom' : 'top';

            const room = Math.max(160, Math.min(PANEL_MAX, this.placement === 'bottom' ? below : above));
            const left = Math.max(8, Math.min(rect.left, width - rect.width - 8));
            const panelWidth = Math.min(Math.max(rect.width, 260), width - 16);
            const vertical = this.placement === 'bottom'
                ? `top:${rect.bottom + GAP}px;`
                : `bottom:${window.innerHeight - rect.top + GAP}px;`;

            this.panelStyle = `${vertical}left:${Math.min(left, width - panelWidth - 8)}px;width:${panelWidth}px;max-height:${room}px;`;
        },
    };
}
