'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const { secretoValido, puedeOperar, accesoPremium, crearLimitador } = require('../lib/acceso');

test('el secret de Laravel se compara completo y falla cerrado', () => {
    assert.equal(secretoValido('abc123', 'abc123'), true);
    assert.equal(secretoValido('abc124', 'abc123'), false);
    assert.equal(secretoValido('abc', 'abc123'), false);
    assert.equal(secretoValido(undefined, 'abc123'), false);
    assert.equal(secretoValido('abc123', ''), false);       // sin secret configurado: nadie pasa
    assert.equal(secretoValido('', undefined), false);
});

test('solo el dueño o ceo/admin operan un servidor', () => {
    assert.equal(puedeOperar('u1', 'u1', 'usuario'), true);
    assert.equal(puedeOperar('u1', 'u2', 'usuario'), false);
    assert.equal(puedeOperar('u1', 'u2', 'soporte'), false);
    assert.equal(puedeOperar('u1', 'u2', 'admin'), true);
    assert.equal(puedeOperar('u1', 'u2', 'ceo'), true);
    assert.equal(puedeOperar(undefined, undefined, 'usuario'), false);
});

test('Mine AI y archivos exigen plan Oro o superior, salvo staff', () => {
    for (const p of ['redstone', 'hierro', 'cobre', '', null]) assert.equal(accesoPremium(p, 'usuario'), false, p);
    for (const p of ['oro', 'Diamante', 'netherite', 'ghost-warrior']) assert.equal(accesoPremium(p, 'usuario'), true, p);
    assert.equal(accesoPremium(null, 'soporte'), true);
});

test('el limitador corta al superar el máximo y se reinicia con la ventana', () => {
    const permitir = crearLimitador(3, 1000);
    assert.deepEqual([1, 2, 3, 4].map(() => permitir('u', 0)), [true, true, true, false]);
    assert.equal(permitir('otro', 0), true);
    assert.equal(permitir('u', 1000), true);
});
