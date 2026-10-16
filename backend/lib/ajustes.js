'use strict';

// Configuración editable de un servidor. La imagen itzg reescribe server.properties con estas
// variables en cada arranque, así que el contenedor (su Env) es la fuente de verdad.

const RAM_MINIMA_MB = 1024;
const VERSION_JAVA = /^\d+\.\d+(\.\d+)?$/;
const DIFICULTADES = ['peaceful', 'easy', 'normal', 'hard'];
const MODOS = { java: ['survival', 'creative', 'adventure', 'spectator'], bedrock: ['survival', 'creative', 'adventure'] };

const leerEnv = (env = []) => Object.fromEntries(env.map(l => { const i = l.indexOf('='); return [l.slice(0, i), l.slice(i + 1)]; }));
const bool = (v) => String(v).toLowerCase() === 'true';
const numero = (v, def) => (Number.isFinite(Number(v)) && v !== '' && v != null ? Number(v) : def);

/** Modpacks: la versión la fija el pack, no el usuario. */
function versionDelPack(e) {
    return ['AUTO_CURSEFORGE', 'MODRINTH'].includes(e.TYPE) || Boolean(e.GENERIC_PACK);
}

/** Límites efectivos: los del plan con el que se desplegó (o el último aplicado por Laravel). */
function topes(srv) {
    return {
        memoryMb: Math.max(RAM_MINIMA_MB, Number(srv?.limits?.memoryMb) || 2048),
        maxPlayers: Math.max(1, Number(srv?.limits?.maxPlayers) || 20),
    };
}

function ajustesActuales(srv, env, memoriaBytes) {
    const e = leerEnv(env);
    const bedrock = srv.edition === 'bedrock';
    return {
        edition: srv.edition,
        software: srv.software,
        motd: bedrock ? (e.SERVER_NAME || srv.name || '') : (e.MOTD || ''),
        version: e.VERSION || 'LATEST',
        versionEditable: !bedrock ? !versionDelPack(e) : true,
        maxPlayers: numero(e.MAX_PLAYERS, 20),
        memoryMb: memoriaBytes ? Math.round(memoriaBytes / 1048576) : topes(srv).memoryMb,
        difficulty: (e.DIFFICULTY || 'easy').toLowerCase(),
        gamemode: ((bedrock ? e.GAMEMODE : e.MODE) || 'survival').toLowerCase(),
        pvp: e.PVP === undefined ? true : bool(e.PVP),
        viewDistance: numero(e.VIEW_DISTANCE, 10),
        whitelist: bool(e.ENABLE_WHITELIST),
        allowCheats: bool(e.ALLOW_CHEATS),
        topes: { ...topes(srv), memoryMinMb: RAM_MINIMA_MB },
    };
}

/**
 * Valida el pedido contra el plan y devuelve las variables a cambiar.
 * @returns {{ error: string } | { cambios: Record<string,string>, memoryMb: number }}
 */
function validarAjustes(body, srv, env) {
    const e = leerEnv(env);
    const bedrock = srv.edition === 'bedrock';
    const t = topes(srv);
    const b = body || {};
    const cambios = {};

    const motd = String(b.motd ?? '').trim();
    if (motd.length < 1 || motd.length > 59 || /[\r\n]/.test(motd)) return { error: 'El MOTD admite de 1 a 59 caracteres en una sola línea.' };
    cambios[bedrock ? 'SERVER_NAME' : 'MOTD'] = motd;

    const jugadores = Number(b.maxPlayers);
    if (!Number.isInteger(jugadores) || jugadores < 1 || jugadores > t.maxPlayers) return { error: `Jugadores: de 1 a ${t.maxPlayers} con tu plan.` };
    cambios.MAX_PLAYERS = String(jugadores);

    const memoryMb = Number(b.memoryMb);
    if (!Number.isInteger(memoryMb) || memoryMb < RAM_MINIMA_MB || memoryMb > t.memoryMb) return { error: `RAM: de ${RAM_MINIMA_MB} a ${t.memoryMb} MB con tu plan.` };

    const version = String(b.version ?? 'LATEST');
    if (bedrock) {
        if (!['LATEST', 'PREVIEW'].includes(version)) return { error: 'Versión inválida.' };
        cambios.VERSION = version;
    } else if (!versionDelPack(e)) {
        if (version !== 'LATEST' && !VERSION_JAVA.test(version)) return { error: 'Versión inválida.' };
        cambios.VERSION = version;
    } else if (version !== (e.VERSION || 'LATEST')) {
        return { error: 'En un modpack la versión la define el propio pack.' };
    }

    const dif = String(b.difficulty || '').toLowerCase();
    if (!DIFICULTADES.includes(dif)) return { error: 'Dificultad inválida.' };
    cambios.DIFFICULTY = dif;

    const modo = String(b.gamemode || '').toLowerCase();
    if (!MODOS[bedrock ? 'bedrock' : 'java'].includes(modo)) return { error: 'Modo de juego inválido.' };
    cambios[bedrock ? 'GAMEMODE' : 'MODE'] = modo;

    const vista = Number(b.viewDistance);
    if (!Number.isInteger(vista) || vista < 4 || vista > 16) return { error: 'La distancia de renderizado va de 4 a 16 chunks.' };
    cambios.VIEW_DISTANCE = String(vista);

    if (bedrock) {
        cambios.ALLOW_CHEATS = String(Boolean(b.allowCheats));
    } else {
        cambios.PVP = String(Boolean(b.pvp));
        cambios.ENABLE_WHITELIST = String(Boolean(b.whitelist));
        const heap = Math.max(512, Math.floor(memoryMb * 0.8));
        cambios.MAX_MEMORY = `${heap}M`;
        cambios.INIT_MEMORY = `${Math.min(1024, heap)}M`;
    }
    return { cambios, memoryMb };
}

/** Reemplaza o agrega variables manteniendo el resto (CF_SLUG, GENERIC_PACK, etc.). */
function mezclarEnv(env, cambios) {
    const pendientes = { ...cambios };
    const salida = env.map(l => {
        const k = l.slice(0, l.indexOf('='));
        if (k in pendientes) { const v = `${k}=${pendientes[k]}`; delete pendientes[k]; return v; }
        return l;
    });
    return salida.concat(Object.entries(pendientes).map(([k, v]) => `${k}=${v}`));
}

/** Respuesta de `rcon-cli list`: "There are 1 of a max of 20 players online: Steve". */
function parsearJugadores(texto) {
    const limpio = String(texto).replace(/\x1b\[[0-9;]*[A-Za-z]/g, '');
    const m = limpio.match(/There are (\d+) of a max(?: of)? (\d+) players online:?\s*(.*)/i);
    if (!m) return null;
    const nombres = m[3].split(',').map(s => s.trim()).filter(Boolean);
    return { online: Number(m[1]), max: Number(m[2]), players: nombres };
}

/** Registros del agente Playit: enlace de reclamo y direcciones públicas asignadas. */
function parsearPlayit(logs) {
    const limpio = String(logs || '').replace(/\x1b\[[0-9;]*[A-Za-z]/g, '');
    const claims = limpio.match(/https:\/\/playit\.gg\/claim\/[a-zA-Z0-9]+/g) || [];
    const direcciones = [...new Set((limpio.match(/\b[a-z0-9-]+(?:\.[a-z0-9-]+)*\.(?:ply\.gg|joinmc\.link)(?::\d{2,5})?\b/gi) || []).map(s => s.toLowerCase()))];
    return { claimUrl: claims.length ? claims[claims.length - 1] : null, direcciones };
}

module.exports = { ajustesActuales, validarAjustes, mezclarEnv, parsearJugadores, parsearPlayit, leerEnv, RAM_MINIMA_MB };
