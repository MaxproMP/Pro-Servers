'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const { esMiembro, agregarMiembro, vistaCompartida, MAX_MIEMBROS } = require('../lib/acceso');

test('agrega invitados sin duplicar, sin incluir al dueño y con tope', () => {
    const srv = { id: 's1' };
    assert.ok(agregarMiembro(srv, 'dueno', { uid: 'a', username: 'ana' }).miembro);
    assert.ok(esMiembro(srv, 'a') && !esMiembro(srv, 'b') && !esMiembro(srv, ''));
    assert.equal(agregarMiembro(srv, 'dueno', { uid: 'a', username: 'ana' }).status, 409);
    assert.equal(agregarMiembro(srv, 'dueno', { uid: 'dueno', username: 'yo' }).status, 422);
    assert.equal(agregarMiembro(srv, 'dueno', { uid: '', username: 'x' }).status, 422);
    for (let i = 1; i < MAX_MIEMBROS; i++) agregarMiembro(srv, 'dueno', { uid: 'u' + i, username: 'u' + i });
    assert.equal(agregarMiembro(srv, 'dueno', { uid: 'extra', username: 'extra' }).status, 422);
});

test('la vista compartida no expone el correo del dueño ni la lista de invitados', () => {
    const v = vistaCompartida({ id: 's1', name: 'Survival', email: 'dueno@x.com', containerId: 'abc', members: [{ uid: 'a' }] }, 'maxpro');
    assert.deepEqual(v, { id: 's1', name: 'Survival', shared: true, owner: 'maxpro' });
});
