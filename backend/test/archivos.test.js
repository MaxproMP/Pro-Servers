'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('fs');
const os = require('os');
const path = require('path');
const a = require('../lib/archivos');

function jaula() {
    const raiz = fs.mkdtempSync(path.join(os.tmpdir(), 'ps-files-'));
    const base = path.join(raiz, 'srv-1');
    fs.mkdirSync(path.join(base, 'plugins'), { recursive: true });
    fs.writeFileSync(path.join(base, 'server.properties'), 'motd=hola\n');
    fs.writeFileSync(path.join(raiz, 'secreto.txt'), 'no');
    return { raiz, base };
}

test('ninguna ruta sale de la carpeta del servidor', () => {
    const { base } = jaula();
    for (const r of ['../secreto.txt', 'plugins/../../secreto.txt', '..', 'a/\0b']) {
        assert.throws(() => a.leerTexto(base, r), a.ErrorRuta, r);
    }
    // Las rutas "absolutas" se tratan como relativas a la jaula.
    assert.throws(() => a.leerTexto(base, '/etc/passwd'), { status: 404 });
});

test('un symlink hacia afuera no permite leer ni escribir', () => {
    const { raiz, base } = jaula();
    fs.symlinkSync(raiz, path.join(base, 'escape'));
    assert.throws(() => a.leerTexto(base, 'escape/secreto.txt'), /fuera/);
    assert.throws(() => a.guardarTexto(base, 'escape/nuevo.txt', 'x'), /fuera/);
    assert.throws(() => a.listar(base, 'escape'), /fuera/);
});

test('lista, edita, sube, renombra y borra dentro de la jaula', () => {
    const { base } = jaula();
    assert.deepEqual(a.listar(base, '').items.map(i => i.name), ['plugins', 'server.properties']);
    a.guardarTexto(base, 'server.properties', 'motd=chau\n');
    assert.equal(a.leerTexto(base, 'server.properties'), 'motd=chau\n');
    a.subir(base, 'plugins', 'mod.jar', Buffer.from([1, 0, 2]));
    assert.throws(() => a.leerTexto(base, 'plugins/mod.jar'), { status: 415 });
    assert.throws(() => a.subir(base, 'plugins', '../x.jar', Buffer.from('x')), a.ErrorRuta);
    a.renombrar(base, 'plugins/mod.jar', 'plugins/mod2.jar');
    a.borrar(base, 'plugins/mod2.jar');
    assert.equal(a.listar(base, 'plugins').items.length, 0);
});

test('no se puede borrar ni mover la raíz', () => {
    const { base } = jaula();
    assert.throws(() => a.borrar(base, ''), /raíz/);
    assert.throws(() => a.borrar(base, '.'), /raíz/);
    assert.throws(() => a.renombrar(base, '', 'otra'), /raíz/);
});
