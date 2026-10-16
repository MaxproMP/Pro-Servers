'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const { diagnosticar, modelos } = require('../lib/mineai');

const respuesta = (status, body) => ({ ok: status < 400, status, json: async () => body });

test('sin GROQ_API_KEY no llama a Groq y responde 503', async () => {
    const r = await diagnosticar('log', { env: {}, fetchFn: () => assert.fail('no debería llamar') });
    assert.deepEqual([r.ok, r.status], [false, 503]);
});

test('si el modelo fue dado de baja, prueba el siguiente', async () => {
    const usados = [];
    const fetchFn = async (_u, o) => {
        const m = JSON.parse(o.body).model; usados.push(m);
        return m === 'retirado'
            ? respuesta(404, { error: { code: 'model_not_found', message: 'no existe' } })
            : respuesta(200, { choices: [{ message: { content: '{"mensaje":"ok"}' } }] });
    };
    const r = await diagnosticar('log', { env: { GROQ_API_KEY: 'k', GROQ_MODEL: 'retirado' }, fetchFn });
    assert.equal(r.ok, true);
    assert.equal(r.analysis.mensaje, 'ok');
    assert.deepEqual(usados, ['retirado', modelos({})[0]]);
});

test('una clave inválida no se reintenta con otros modelos', async () => {
    let llamadas = 0;
    const r = await diagnosticar('log', { env: { GROQ_API_KEY: 'k' }, fetchFn: async () => (llamadas++, respuesta(401, { error: { message: 'Invalid API Key' } })) });
    assert.deepEqual([r.ok, r.status, llamadas], [false, 502, 1]);
});
