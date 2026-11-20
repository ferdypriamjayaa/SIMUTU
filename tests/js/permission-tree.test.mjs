// Logic of the permission tree (resources/js/permission-tree.js).
// Run: node --test tests/js/permission-tree.test.mjs
import { test } from 'node:test';
import assert from 'node:assert/strict';
import permissionTree from '../../resources/js/permission-tree.js';

const leaves = {
    inm: ['inm.view', 'inm.create', 'inm.edit', 'inm.export'],
    master: ['units.view', 'units.create', 'roles.view'],
    'master.units': ['units.view', 'units.create'],
};

test('parent checkbox selects and clears every child', () => {
    const t = permissionTree({ mode: 'role', leaves });
    assert.equal(t.state('inm'), 'none');
    t.toggleNode('inm');
    assert.equal(t.state('inm'), 'all');
    assert.deepEqual([...t.checked].sort(), [...leaves.inm].sort());
    t.toggleNode('inm');
    assert.equal(t.state('inm'), 'none');
    assert.deepEqual(t.checked, []);
});

test('partial selection is indeterminate, and a parent click then selects all', () => {
    const t = permissionTree({ mode: 'role', leaves, checked: ['inm.view'] });
    assert.equal(t.state('inm'), 'some');
    assert.equal(t.count('inm'), '1/4');
    t.toggleNode('inm');
    assert.equal(t.state('inm'), 'all');
});

test('nested folders: a child folder change is reflected in its parent', () => {
    const t = permissionTree({ mode: 'role', leaves });
    t.toggleNode('master.units');
    assert.equal(t.state('master.units'), 'all');
    assert.equal(t.state('master'), 'some');
});

test('expand and collapse', () => {
    const t = permissionTree({ mode: 'role', leaves, open: { inm: true } });
    t.toggleOpen('inm');
    assert.equal(t.open.inm, false);
    t.toggleOpen('master');
    assert.equal(t.open.master, true);
    t.expandAll(true);
    assert.ok(Object.keys(leaves).every((k) => t.open[k]));
    t.expandAll(false);
    assert.ok(Object.keys(leaves).every((k) => ! t.open[k]));
});

test('permissions the editor may not grant cannot be added, but can be removed', () => {
    const t = permissionTree({ mode: 'role', leaves, checked: ['inm.export'], grantable: ['inm.view'] });
    t.toggleNode('inm');
    assert.deepEqual([...t.checked].sort(), ['inm.export', 'inm.view']);
    t.toggle('inm.export');
    assert.deepEqual(t.checked, ['inm.view']);
    t.toggle('inm.export'); // was held before: may come back
    assert.ok(t.checked.includes('inm.export'));
    t.toggle('inm.create');
    assert.ok(! t.checked.includes('inm.create'));
});

test('user mode: inherited, added and restricted', () => {
    const roles = { pic: { slug: 'pic', active: true, system: false, permissions: ['inm.view', 'inm.create'] } };
    const ov = Object.fromEntries(leaves.inm.map((p) => [p, 'inherit']));
    const t = permissionTree({ mode: 'user', role: 'pic', roles, overrides: ov, leaves });

    assert.equal(t.status('inm.view'), 'inherited');
    assert.equal(t.state('inm'), 'some');

    t.toggle('inm.export'); // role lacks it => added
    assert.equal(t.ov['inm.export'], 'allow');
    assert.equal(t.status('inm.export'), 'added');

    t.toggle('inm.view'); // role has it => restricted
    assert.equal(t.ov['inm.view'], 'deny');
    assert.equal(t.isOn('inm.view'), false);

    t.toggle('inm.view'); // back to the role's value => inherit
    assert.equal(t.ov['inm.view'], 'inherit');

    t.reset('inm.export');
    assert.equal(t.ov['inm.export'], 'inherit');

    t.toggleNode('inm'); // some => all: missing ones become grants
    assert.deepEqual(t.ov, { 'inm.view': 'inherit', 'inm.create': 'inherit', 'inm.edit': 'allow', 'inm.export': 'allow' });
    t.toggleNode('inm'); // all => none: inherited ones become restrictions
    assert.deepEqual(t.ov, { 'inm.view': 'deny', 'inm.create': 'deny', 'inm.edit': 'inherit', 'inm.export': 'inherit' });
    assert.deepEqual(t.summary, { effective: 0, added: 0, restricted: 2 });
});

test('user mode: system role always has everything and cannot be overridden; read-only cannot change', () => {
    const roles = { admin: { slug: 'admin', active: true, system: true, permissions: leaves.inm } };
    const t = permissionTree({ mode: 'user', role: 'admin', roles, overrides: { 'inm.view': 'inherit' }, leaves });
    assert.equal(t.isOn('inm.edit'), true);
    t.toggle('inm.edit');
    assert.equal(t.isOn('inm.edit'), true);

    const ro = permissionTree({ mode: 'role', leaves, checked: ['inm.view'], editable: false });
    ro.toggleNode('inm');
    assert.deepEqual(ro.checked, ['inm.view']);
});
