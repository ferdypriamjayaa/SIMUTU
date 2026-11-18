/*
 * User form (admin): link the account to an HRIS employee and fill the
 * account's Name and NIP from that employee.
 *
 * The page only carries employee names and opaque identifiers. The HRIS NIP is
 * fetched for the one employee the admin picks (admin-only endpoint) and goes
 * straight into the NIP field. The server checks again that Name and NIP
 * match HRIS, so these fields are only a convenience.
 */
export default function userHrisPicker(config) {
    return {
        employees: config.employees,
        detailsUrl: config.detailsUrl,
        id: config.id ?? '',
        linkedName: config.linkedName ?? '',
        accountName: config.name ?? '',
        nip: config.nip ?? '',
        // Name/NIP come from HRIS while linked; NIP stays editable when HRIS has no NIP.
        locked: Boolean(config.id),
        nipFromHris: Boolean(config.id),
        query: '',
        open: false,
        busy: false,
        error: '',
        // Shown after unlinking: Name and NIP are kept unless the admin clears them.
        askClear: false,

        get matches() {
            const q = this.query.trim().toLowerCase();
            const list = q === '' ? this.employees : this.employees.filter((e) => e.name.toLowerCase().includes(q));

            return list.slice(0, 50);
        },

        async choose(employee) {
            this.open = false;
            this.query = '';
            this.error = '';
            this.busy = true;

            try {
                const { data } = await window.axios.get(this.detailsUrl.replace('__ID__', encodeURIComponent(employee.id)));

                this.id = employee.id;
                this.linkedName = data.name;
                this.accountName = data.name;
                // Kept as a string, so leading zeros survive.
                this.nipFromHris = typeof data.nip === 'string' && data.nip !== '';
                if (this.nipFromHris) {
                    this.nip = data.nip;
                }
                this.locked = true;
                this.askClear = false;
            } catch (error) {
                this.error = error.response?.data?.message ?? 'Data karyawan tidak dapat diambil dari HRIS. Coba lagi.';
            } finally {
                this.busy = false;
            }
        },

        unlink() {
            this.id = '';
            this.linkedName = '';
            this.locked = false;
            this.nipFromHris = false;
            this.askClear = this.accountName !== '' || this.nip !== '';
        },

        clearIdentity() {
            this.accountName = '';
            this.nip = '';
            this.askClear = false;
        },
    };
}
