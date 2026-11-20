/**
 * Folder-style permission tree (App\Support\Permissions::TREE).
 *
 * mode "role": the checkboxes are the role's permissions (permissions[]).
 * mode "user": the checkboxes are the account's effective permissions; each
 *   change is stored as an override against the selected role:
 *   checked while the role lacks it => allow, unchecked while the role has it
 *   => deny, otherwise inherit (overrides[...] hidden inputs). Same rules as
 *   Permissions::resolve() on the server, which checks everything again.
 *
 * A parent checkbox selects or clears every permission below it and shows an
 * indeterminate state when only some are on. Permissions the editor may not
 * grant (`grantable`) can be removed but not added.
 */
export default function permissionTree({
    mode = 'role',
    checked = [],
    role = null,
    roles = {},
    overrides = {},
    grantable = null,
    editable = true,
    leaves = {},
    open = {},
} = {}) {
    return {
        mode,
        role,
        roles,
        editable,
        grantable,
        leaves,
        open,
        checked: [...checked],
        initialChecked: [...checked],
        ov: { ...overrides },
        initialOv: { ...overrides },

        get current() {
            return this.roles[this.role] ?? null;
        },

        /** The selected role grants it (an inactive role grants nothing). */
        inherited(p) {
            const r = this.current;
            return !! r && r.active && r.permissions.includes(p);
        },

        isOn(p) {
            if (this.mode === 'role') return this.checked.includes(p);
            const r = this.current;
            if (! r) return false;
            if (r.system) return true;
            if (this.ov[p] === 'deny') return false;
            if (this.ov[p] === 'allow') return true;
            return this.inherited(p);
        },

        canSet(p, on) {
            if (! this.editable) return false;
            if (this.mode === 'user' && this.current?.system) return false;
            if (! on || this.grantable === null || this.grantable.includes(p)) return true;
            // Not grantable by this editor: only what was already held may stay or come back.
            return this.mode === 'role'
                ? this.initialChecked.includes(p)
                : this.inherited(p) || this.initialOv[p] === 'allow';
        },

        set(p, on) {
            if (this.isOn(p) === on || ! this.canSet(p, on)) return;
            if (this.mode === 'role') {
                this.checked = on ? [...this.checked, p] : this.checked.filter((x) => x !== p);
                return;
            }
            this.ov[p] = on === this.inherited(p) ? 'inherit' : on ? 'allow' : 'deny';
        },

        toggle(p) {
            this.set(p, ! this.isOn(p));
        },

        /** none | some | all, for a folder. */
        state(node) {
            const list = this.leaves[node] ?? [];
            const on = list.filter((p) => this.isOn(p)).length;
            return on === 0 ? 'none' : on === list.length ? 'all' : 'some';
        },

        toggleNode(node) {
            const on = this.state(node) !== 'all';
            (this.leaves[node] ?? []).forEach((p) => this.set(p, on));
        },

        nodeLocked(node) {
            return ! (this.leaves[node] ?? []).some((p) => this.canSet(p, ! this.isOn(p)));
        },

        count(node) {
            const list = this.leaves[node] ?? [];
            return `${list.filter((p) => this.isOn(p)).length}/${list.length}`;
        },

        toggleOpen(node) {
            this.open[node] = ! this.open[node];
        },

        expandAll(on) {
            Object.keys(this.leaves).forEach((k) => { this.open[k] = on; });
        },

        /** User mode: where the effective value comes from. */
        status(p) {
            const r = this.current;
            if (! r) return null;
            if (r.system) return 'system';
            if (this.ov[p] === 'allow') return 'added';
            if (this.ov[p] === 'deny') return 'restricted';
            return this.inherited(p) ? 'inherited' : null;
        },

        reset(p) {
            if (this.editable) this.ov[p] = 'inherit';
        },

        get summary() {
            const all = Object.keys(this.ov);
            return {
                effective: all.filter((p) => this.isOn(p)).length,
                added: all.filter((p) => this.status(p) === 'added').length,
                restricted: all.filter((p) => this.status(p) === 'restricted').length,
            };
        },
    };
}
