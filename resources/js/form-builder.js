/*
 * Form builder (admin): client-side state of one draft form version.
 *
 * The page keeps the whole field list here and submits it as one JSON
 * document; the server validates everything again (SaveFormDraftRequest) and
 * is the only authority on what gets saved.
 */
const CHOICE_TYPES = ['select', 'radio', 'checkbox'];
// Single-choice types other fields can be shown or hidden by.
const CONDITION_TYPES = ['select', 'radio'];
const TEXT_TYPES = ['text', 'textarea'];

const STRING_PROPS = [
    'key', 'label', 'section', 'placeholder', 'help_text', 'default_value',
    'min_value', 'max_value', 'min_length', 'max_length', 'format', 'max_field_key',
];

function slugify(text, fallback) {
    let slug = String(text ?? '')
        .normalize('NFD')
        .replace(/[̀-ͯ]/g, '')
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '_')
        .replace(/^_+|_+$/g, '');

    if (!/^[a-z]/.test(slug)) {
        slug = slug ? `${fallback}_${slug}` : fallback;
    }

    return slug.slice(0, 56).replace(/_+$/, '');
}

export default function formBuilder(config) {
    let counter = 0;
    const uid = () => `u${++counter}`;

    const hydrateOption = (option) => ({
        uid: uid(),
        label: String(option?.label ?? ''),
        value: String(option?.value ?? ''),
        // The value follows the label until someone edits it by hand.
        autoValue: !option?.value,
    });

    const hydrate = (raw) => {
        const field = {
            uid: uid(),
            id: raw.id ?? null,
            type: raw.type,
            is_required: raw.is_required !== false && raw.is_required !== 0,
            is_active: raw.is_active !== false && raw.is_active !== 0,
            show_in_summary: Boolean(raw.show_in_summary),
            allow_decimal: Boolean(raw.allow_decimal),
            options: (raw.options ?? []).map(hydrateOption),
            autoKey: false,
            // "Show only when": the other field and its options, by uid so renames keep working.
            conditionUid: raw.conditionUid ?? null,
            conditionOptionUids: [...(raw.conditionOptionUids ?? [])],
            savedCondition: raw.visible_when_key ? { key: String(raw.visible_when_key), values: (raw.visible_when_values ?? []).map(String) } : null,
        };

        STRING_PROPS.forEach((prop) => {
            field[prop] = raw[prop] === null || raw[prop] === undefined ? '' : String(raw[prop]);
        });

        // The key as stored in the draft, used to tell whether data depends on it.
        field.savedKey = field.id !== null ? (config.savedKeys?.[field.id] ?? null) : null;

        return field;
    };

    return {
        fields: [],
        selectedUid: null,
        dirty: Boolean(config.restored),
        errors: config.errors ?? {},
        types: config.types,
        formats: config.formats,
        dragUid: null,
        dropIndex: null,

        init() {
            this.fields = config.fields.map(hydrate);
            this.fields.forEach((field) => this.resolveCondition(field));
            this.selectedUid = this.fields[this.firstErrorIndex()]?.uid ?? this.fields[0]?.uid ?? null;

            this.$watch('fields', () => { this.dirty = true; });

            window.addEventListener('beforeunload', (event) => {
                if (this.dirty) {
                    event.preventDefault();
                    event.returnValue = '';
                }
            });
        },

        // ---- lookups -------------------------------------------------------
        get selected() {
            return this.fields.find((field) => field.uid === this.selectedUid) ?? null;
        },

        get selectedIndex() {
            return this.fields.findIndex((field) => field.uid === this.selectedUid);
        },

        get sections() {
            return [...new Set(this.fields.map((field) => field.section.trim()).filter(Boolean))];
        },

        isChoice(field) {
            return CHOICE_TYPES.includes(field.type);
        },

        isText(field) {
            return TEXT_TYPES.includes(field.type);
        },

        /** Recorded observations depend on this field: key and type are fixed, and it cannot be removed. */
        isLocked(field) {
            return field.savedKey !== null && config.lockedKeys.includes(field.savedKey);
        },

        /** Other number fields this one can be compared against. */
        numberFields(field) {
            return this.fields.filter((other) => other.type === 'number' && other.uid !== field.uid && other.key);
        },

        // ---- conditional fields --------------------------------------------
        /** Saved conditions name a key and option values: point them at the fields in the list. */
        resolveCondition(field) {
            if (!field.savedCondition) {
                return;
            }

            const trigger = this.fields.find((other) => other.key === field.savedCondition.key && other.uid !== field.uid);

            if (trigger) {
                field.conditionUid = trigger.uid;
                field.conditionOptionUids = trigger.options
                    .filter((option) => field.savedCondition.values.includes(option.value))
                    .map((option) => option.uid);
            }

            field.savedCondition = null;
        },

        /** Earlier single-choice fields that are always shown: what this field can depend on. */
        conditionTriggers(field) {
            const index = this.fields.indexOf(field);

            return this.fields.filter((other, otherIndex) => otherIndex < index
                && CONDITION_TYPES.includes(other.type) && other.conditionUid === null && other.is_active);
        },

        conditionTrigger(field) {
            return field.conditionUid ? this.fields.find((other) => other.uid === field.conditionUid) ?? null : null;
        },

        isConditional(field) {
            return this.conditionTrigger(field) !== null && field.conditionOptionUids.length > 0;
        },

        setConditionTrigger(field, triggerUid) {
            field.conditionUid = triggerUid || null;
            field.conditionOptionUids = [];
        },

        toggleConditionOption(field, option) {
            const at = field.conditionOptionUids.indexOf(option.uid);

            if (at === -1) {
                field.conditionOptionUids.push(option.uid);
            } else {
                field.conditionOptionUids.splice(at, 1);
            }
        },

        conditionSummary(field) {
            const trigger = this.conditionTrigger(field);

            if (!trigger) {
                return '';
            }

            const labels = trigger.options.filter((option) => field.conditionOptionUids.includes(option.uid)).map((option) => option.label);

            return labels.length ? `Tampil jika ${trigger.label}: ${labels.join(' / ')}` : '';
        },

        startsSection(index) {
            const section = this.fields[index].section.trim();

            return section !== '' && (index === 0 || this.fields[index - 1].section.trim() !== section);
        },

        // ---- validation messages returned by the server --------------------
        firstErrorIndex() {
            const key = Object.keys(this.errors).find((name) => /^fields\.\d+\./.test(name));

            return key ? Number(key.split('.')[1]) : 0;
        },

        hasErrors(index) {
            return Object.keys(this.errors).some((name) => name.startsWith(`fields.${index}.`));
        },

        error(attribute) {
            const index = this.selectedIndex;
            const prefix = `fields.${index}.${attribute}`;
            const key = Object.keys(this.errors).find((name) => name === prefix || name.startsWith(`${prefix}.`));

            return key ? this.errors[key][0] : '';
        },

        /** Server messages are tied to positions, so they are dropped once the list changes shape. */
        structureChanged() {
            this.errors = {};
        },

        // ---- keys ----------------------------------------------------------
        uniqueKey(base, ownUid) {
            const taken = new Set([
                ...config.reservedKeys,
                ...this.fields.filter((field) => field.uid !== ownUid).map((field) => field.key),
            ]);

            let candidate = base;
            for (let suffix = 2; taken.has(candidate); suffix++) {
                candidate = `${base}_${suffix}`;
            }

            return candidate;
        },

        labelChanged(field) {
            if (field.autoKey) {
                field.key = this.uniqueKey(slugify(field.label, 'field'), field.uid);
            }
        },

        keyChanged(field) {
            field.autoKey = false;
            field.key = field.key.toLowerCase().replace(/[^a-z0-9_]/g, '_');
        },

        // ---- fields --------------------------------------------------------
        add(type) {
            const index = this.selectedIndex;
            const position = index === -1 ? this.fields.length : index + 1;
            const label = this.types[type];

            const field = hydrate({
                type,
                label,
                section: this.fields[position - 1]?.section ?? '',
                options: CHOICE_TYPES.includes(type) ? [{ label: 'Opsi 1' }, { label: 'Opsi 2' }] : [],
            });
            field.autoKey = true;
            field.key = this.uniqueKey(slugify(label, 'field'), field.uid);
            field.options.forEach((option) => this.optionLabelChanged(field, option));

            this.fields.splice(position, 0, field);
            this.structureChanged();
            this.select(field.uid, true);
        },

        duplicate(field) {
            const copy = hydrate({
                ...JSON.parse(JSON.stringify(field)),
                id: null,
                label: `${field.label} (salinan)`,
            });
            copy.key = this.uniqueKey(slugify(field.key, 'field'), copy.uid);

            this.fields.splice(this.fields.indexOf(field) + 1, 0, copy);
            this.structureChanged();
            this.select(copy.uid, true);
        },

        remove(field) {
            if (this.isLocked(field)) {
                return;
            }

            if (!window.confirm(`Hapus field "${field.label || field.key}" dari formulir?`)) {
                return;
            }

            const index = this.fields.indexOf(field);
            this.fields.splice(index, 1);
            this.structureChanged();

            if (this.selectedUid === field.uid) {
                this.selectedUid = (this.fields[index] ?? this.fields[index - 1])?.uid ?? null;
            }
        },

        move(index, delta) {
            const target = index + delta;

            if (target < 0 || target >= this.fields.length) {
                return;
            }

            this.fields.splice(target, 0, this.fields.splice(index, 1)[0]);
            this.structureChanged();
        },

        select(fieldUid, focusLabel = false) {
            this.selectedUid = fieldUid;

            this.$nextTick(() => {
                // On phones the properties panel sits below the canvas.
                if (window.matchMedia('(max-width: 1023px)').matches) {
                    this.$refs.properties?.scrollIntoView({ behavior: 'smooth', block: 'start' });
                }

                if (focusLabel) {
                    this.$refs.labelInput?.focus();
                    this.$refs.labelInput?.select();
                }
            });
        },

        typeChanged(field) {
            field.default_value = '';

            if (this.isChoice(field) && field.options.length === 0) {
                field.options = [hydrateOption({ label: 'Opsi 1' }), hydrateOption({ label: 'Opsi 2' })];
                field.options.forEach((option) => this.optionLabelChanged(field, option));
            }
        },

        // ---- drag and drop (mouse); the arrow buttons cover touch and keyboard ----
        dragStart(event, field) {
            this.dragUid = field.uid;
            event.dataTransfer.effectAllowed = 'move';
            event.dataTransfer.setData('text/plain', field.uid);
        },

        dragOver(event, index) {
            if (this.dragUid === null) {
                return;
            }

            event.preventDefault();
            const box = event.currentTarget.getBoundingClientRect();
            this.dropIndex = event.clientY < box.top + box.height / 2 ? index : index + 1;
        },

        drop() {
            const from = this.fields.findIndex((field) => field.uid === this.dragUid);

            if (from !== -1 && this.dropIndex !== null) {
                const to = this.dropIndex > from ? this.dropIndex - 1 : this.dropIndex;

                if (to !== from) {
                    this.fields.splice(to, 0, this.fields.splice(from, 1)[0]);
                    this.structureChanged();
                }
            }

            this.dragEnd();
        },

        dragEnd() {
            this.dragUid = null;
            this.dropIndex = null;
        },

        // ---- options -------------------------------------------------------
        uniqueOptionValue(field, base, ownUid) {
            const taken = new Set(field.options.filter((option) => option.uid !== ownUid).map((option) => option.value));

            let candidate = base;
            for (let suffix = 2; taken.has(candidate); suffix++) {
                candidate = `${base}_${suffix}`;
            }

            return candidate;
        },

        addOption(field) {
            const option = hydrateOption({ label: `Opsi ${field.options.length + 1}` });
            field.options.push(option);
            this.optionLabelChanged(field, option);
        },

        optionLabelChanged(field, option) {
            if (!option.autoValue) {
                return;
            }

            const previous = option.value;
            option.value = this.uniqueOptionValue(field, slugify(option.label, 'opsi'), option.uid);

            if (previous !== '' && field.default_value === previous) {
                field.default_value = option.value;
            }
        },

        optionValueChanged(field, option) {
            option.autoValue = false;
            option.value = option.value.replace(/[^A-Za-z0-9_-]/g, '_');
        },

        removeOption(field, index) {
            if (field.default_value === field.options[index].value) {
                field.default_value = '';
            }

            field.options.splice(index, 1);
        },

        moveOption(field, index, delta) {
            const target = index + delta;

            if (target >= 0 && target < field.options.length) {
                field.options.splice(target, 0, field.options.splice(index, 1)[0]);
            }
        },

        // ---- saving --------------------------------------------------------
        serialize() {
            const blank = (value) => (String(value).trim() === '' ? null : String(value).trim());

            return {
                fields: this.fields.map((field) => ({
                    id: field.id,
                    key: blank(field.key),
                    label: field.label.trim(),
                    type: field.type,
                    section: blank(field.section),
                    placeholder: blank(field.placeholder),
                    help_text: blank(field.help_text),
                    is_required: field.is_required,
                    is_active: field.is_active,
                    show_in_summary: field.show_in_summary,
                    default_value: blank(field.default_value),
                    min_value: blank(field.min_value),
                    max_value: blank(field.max_value),
                    allow_decimal: field.allow_decimal,
                    min_length: blank(field.min_length),
                    max_length: blank(field.max_length),
                    format: blank(field.format),
                    max_field_key: blank(field.max_field_key),
                    ...this.serializeCondition(field),
                    options: this.isChoice(field)
                        ? field.options.map((option) => ({ label: option.label.trim(), value: blank(option.value) }))
                        : [],
                })),
            };
        },

        serializeCondition(field) {
            const trigger = this.conditionTrigger(field);
            const values = trigger
                ? trigger.options.filter((option) => field.conditionOptionUids.includes(option.uid)).map((option) => String(option.value).trim()).filter(Boolean)
                : [];

            return values.length
                ? { visible_when_key: trigger.key, visible_when_values: values }
                : { visible_when_key: null, visible_when_values: null };
        },

        submit(intent) {
            if (intent === 'publish' && !window.confirm(
                'Publikasikan formulir ini?\n\nObservasi baru akan langsung memakai versi ini, dan versi yang terbit tidak dapat diubah lagi (perubahan berikutnya dibuat sebagai versi baru).',
            )) {
                return;
            }

            this.$refs.payload.value = JSON.stringify(this.serialize());
            this.$refs.intent.value = intent;
            this.dirty = false;
            this.$refs.form.requestSubmit();
        },
    };
}
