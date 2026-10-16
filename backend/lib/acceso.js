'use strict';
// Reglas de acceso del demonio. Funciones puras: se testean con `node --test backend/test`.
const crypto = require('crypto');

const ROLES_DUENO = ['ceo', 'admin'];             // pueden operar servidores ajenos
const ROLES_STAFF = ['ceo', 'admin', 'soporte'];  // acceso a funciones premium sin plan
const PLANES_PREMIUM = ['oro', 'diamante', 'netherite', 'ghost-warrior']; // Oro o superior

/** Comparación en tiempo constante del secret compartido con Laravel. */
function secretoValido(recibido, esperado) {
    if (!esperado || typeof recibido !== 'string') return false;
    const a = Buffer.from(recibido);
    const b = Buffer.from(String(esperado));
    return a.length === b.length && crypto.timingSafeEqual(a, b);
}

/** El dueño del servidor, o un ceo/admin, puede operarlo. */
function puedeOperar(duenoUid, uid, rol) {
    return Boolean(duenoUid) && (duenoUid === uid || ROLES_DUENO.includes(rol));
}

/** Gestor de archivos y Mine AI: plan Oro o superior, o staff. */
function accesoPremium(plan, rol) {
    return ROLES_STAFF.includes(rol) || PLANES_PREMIUM.includes(String(plan || '').toLowerCase());
}

/** Ventana fija en memoria por clave (uid o IP). Suficiente para un solo proceso. */
function crearLimitador(max, ventanaMs) {
    const cubetas = new Map();
    return function permitir(clave, ahora = Date.now()) {
        const c = cubetas.get(clave);
        if (!c || ahora - c.inicio >= ventanaMs) {
            cubetas.set(clave, { inicio: ahora, n: 1 });
            if (cubetas.size > 50000) {
                for (const [k, v] of cubetas) if (ahora - v.inicio >= ventanaMs) cubetas.delete(k);
            }
            return true;
        }
        c.n += 1;
        return c.n <= max;
    };
}

module.exports = { secretoValido, puedeOperar, accesoPremium, crearLimitador, ROLES_STAFF, PLANES_PREMIUM };
