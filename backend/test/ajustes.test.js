'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const a = require('../lib/ajustes');

const java = { edition: 'java', software: 'paper', limits: { memoryMb: 4096, maxPlayers: 10 } };
const envJava = ['EULA=TRUE', 'MOTD=Hola', 'TYPE=PAPER', 'VERSION=1.21.1', 'MAX_PLAYERS=10', 'MAX_MEMORY=3276M', 'PATH=/usr/bin'];
const pedido = { motd: 'Nuevo', maxPlayers: 8, memoryMb: 2048, version: '1.20.4', difficulty: 'hard', gamemode: 'creative', viewDistance: 8, pvp: false, whitelist: true };

test('la RAM y los jugadores nunca superan el plan', () => {
    assert.match(a.validarAjustes({ ...pedido, memoryMb: 8192 }, java, envJava).error, /RAM/);
    assert.match(a.validarAjustes({ ...pedido, memoryMb: 512 }, java, envJava).error, /RAM/);
    assert.match(a.validarAjustes({ ...pedido, maxPlayers: 11 }, java, envJava).error, /Jugadores/);
});

test('rechaza valores fuera de lista y MOTD multilínea', () => {
    assert.ok(a.validarAjustes({ ...pedido, difficulty: 'imposible' }, java, envJava).error);
    assert.ok(a.validarAjustes({ ...pedido, gamemode: 'spectator' }, { ...java, edition: 'bedrock' }, []).error);
    assert.ok(a.validarAjustes({ ...pedido, version: '1.21; rm -rf /' }, java, envJava).error);
    assert.ok(a.validarAjustes({ ...pedido, motd: 'a\nb' }, java, envJava).error);
});

test('traduce el pedido a variables de itzg y conserva el resto del entorno', () => {
    const v = a.validarAjustes(pedido, java, envJava);
    assert.equal(v.memoryMb, 2048);
    assert.deepEqual([v.cambios.MODE, v.cambios.DIFFICULTY, v.cambios.PVP, v.cambios.ENABLE_WHITELIST, v.cambios.MAX_MEMORY], ['creative', 'hard', 'false', 'true', '1638M']);
    const env = a.mezclarEnv(envJava, v.cambios);
    assert.ok(env.includes('PATH=/usr/bin') && env.includes('TYPE=PAPER') && env.includes('VERSION=1.20.4') && env.includes('MOTD=Nuevo'));
    assert.equal(env.filter(l => l.startsWith('MOTD=')).length, 1);
});

test('en un modpack la versión no se puede cambiar', () => {
    const env = ['TYPE=AUTO_CURSEFORGE', 'CF_SLUG=atm9'];
    assert.match(a.validarAjustes({ ...pedido, version: '1.20.1' }, java, env).error, /pack/);
    assert.ok(!a.validarAjustes({ ...pedido, version: 'LATEST' }, java, env).error);
    assert.equal(a.ajustesActuales(java, env, 0).versionEditable, false);
});

test('lee jugadores de rcon-cli y el estado de Playit', () => {
    assert.deepEqual(a.parsearJugadores('There are 2 of a max of 20 players online: Steve, Alex'), { online: 2, max: 20, players: ['Steve', 'Alex'] });
    assert.equal(a.parsearJugadores('Error: connection refused'), null);
    const p = a.parsearPlayit('INFO Visit link to setup https://playit.gg/claim/12a2ac0195\nINFO tunnel ready: green-fox.joinmc.link');
    assert.equal(p.claimUrl, 'https://playit.gg/claim/12a2ac0195');
    assert.deepEqual(p.direcciones, ['green-fox.joinmc.link']);
});
