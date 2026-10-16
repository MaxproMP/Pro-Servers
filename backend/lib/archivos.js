'use strict';

// Gestor de archivos con jaula de rutas: toda operación queda dentro de servers/<serverId>/.
// Se valida dos veces: la ruta pedida (sin "..", sin absolutas) y la ruta REAL (sin escapar por symlinks).
const fs = require('fs');
const path = require('path');

const MAX_TEXTO = 1024 * 1024;          // edición en el navegador: hasta 1 MB
const MAX_SUBIDA = 20 * 1024 * 1024;    // subida: hasta 20 MB por archivo
const MAX_DESCARGA = 25 * 1024 * 1024;
const NOMBRE_OK = /^[^\0/\\]{1,255}$/;
const UID_MC = 1000;                    // las imágenes itzg corren como 1000:1000

class ErrorRuta extends Error { constructor(m, status = 400) { super(m); this.status = status; } }

function normalizar(rel) {
    const r = String(rel ?? '').replace(/\\/g, '/').trim();
    if (r.includes('\0')) throw new ErrorRuta('Ruta inválida.');
    const partes = r.split('/').filter(p => p && p !== '.');
    if (partes.some(p => p === '..')) throw new ErrorRuta('Ruta fuera del servidor.');
    if (partes.some(p => !NOMBRE_OK.test(p))) throw new ErrorRuta('Nombre de archivo inválido.');
    return partes.join('/');
}

function dentro(base, destino) {
    return destino === base || destino.startsWith(base + path.sep);
}

/** Resuelve una ruta relativa dentro de la base. `crear`: el destino puede no existir (se valida el padre). */
function resolver(base, rel, { crear = false } = {}) {
    const baseReal = fs.realpathSync(base);
    const limpio = normalizar(rel);
    const abs = path.join(baseReal, limpio);
    if (!dentro(baseReal, abs)) throw new ErrorRuta('Ruta fuera del servidor.');
    let real;
    try {
        real = fs.realpathSync(abs);
    } catch (e) {
        if (e.code !== 'ENOENT' || !crear) throw new ErrorRuta('No existe.', 404);
        real = path.join(fs.realpathSync(path.dirname(abs)), path.basename(abs)); // el padre sí tiene que existir
    }
    if (!dentro(baseReal, real)) throw new ErrorRuta('Ruta fuera del servidor.');
    return { abs: real, rel: limpio, base: baseReal };
}

function listar(base, rel) {
    const { abs, rel: limpio } = resolver(base, rel);
    if (!fs.statSync(abs).isDirectory()) throw new ErrorRuta('No es una carpeta.');
    const items = fs.readdirSync(abs, { withFileTypes: true }).slice(0, 2000).map(d => {
        let st = null;
        try { st = fs.lstatSync(path.join(abs, d.name)); } catch { /* entrada que desapareció */ }
        return { name: d.name, dir: d.isDirectory(), link: d.isSymbolicLink(), size: st && !d.isDirectory() ? st.size : null, mtime: st ? st.mtime.toISOString() : null };
    });
    items.sort((a, b) => (b.dir - a.dir) || a.name.localeCompare(b.name));
    return { path: limpio, items };
}

function esBinario(buf) {
    return buf.subarray(0, 8000).includes(0);
}

function leerTexto(base, rel) {
    const { abs } = resolver(base, rel);
    const st = fs.statSync(abs);
    if (!st.isFile()) throw new ErrorRuta('No es un archivo.');
    if (st.size > MAX_TEXTO) throw new ErrorRuta('El archivo supera 1 MB: descargalo para editarlo.', 413);
    const buf = fs.readFileSync(abs);
    if (esBinario(buf)) throw new ErrorRuta('Es un archivo binario: no se puede editar en el navegador.', 415);
    return buf.toString('utf8');
}

function asegurarDueño(abs) {
    try { fs.chownSync(abs, UID_MC, UID_MC); } catch { /* sin permisos para chown: se deja como está */ }
}

function guardarTexto(base, rel, contenido) {
    if (typeof contenido !== 'string') throw new ErrorRuta('Contenido inválido.');
    if (Buffer.byteLength(contenido) > MAX_TEXTO) throw new ErrorRuta('El contenido supera 1 MB.', 413);
    const { abs } = resolver(base, rel, { crear: true });
    if (fs.existsSync(abs) && !fs.statSync(abs).isFile()) throw new ErrorRuta('No es un archivo.');
    fs.writeFileSync(abs, contenido);
    asegurarDueño(abs);
}

function subir(base, dirRel, nombre, buf) {
    if (!Buffer.isBuffer(buf) || !buf.length) throw new ErrorRuta('El archivo está vacío.');
    if (buf.length > MAX_SUBIDA) throw new ErrorRuta('El archivo supera 20 MB.', 413);
    if (!NOMBRE_OK.test(nombre || '') || nombre === '.' || nombre === '..') throw new ErrorRuta('Nombre de archivo inválido.');
    const carpeta = resolver(base, dirRel);
    if (!fs.statSync(carpeta.abs).isDirectory()) throw new ErrorRuta('El destino no es una carpeta.');
    const { abs } = resolver(base, path.posix.join(carpeta.rel, nombre), { crear: true });
    fs.writeFileSync(abs, buf);
    asegurarDueño(abs);
}

function crearCarpeta(base, rel) {
    const { abs, rel: limpio } = resolver(base, rel, { crear: true });
    if (!limpio) throw new ErrorRuta('Nombre inválido.');
    if (fs.existsSync(abs)) throw new ErrorRuta('Ya existe.', 409);
    fs.mkdirSync(abs);
    asegurarDueño(abs);
}

function renombrar(base, desde, hacia) {
    const a = resolver(base, desde), b = resolver(base, hacia, { crear: true });
    if (!a.rel || !b.rel) throw new ErrorRuta('No se puede mover la raíz.');
    if (fs.existsSync(b.abs)) throw new ErrorRuta('Ya existe un archivo con ese nombre.', 409);
    fs.renameSync(a.abs, b.abs);
}

function borrar(base, rel) {
    const { abs, rel: limpio } = resolver(base, rel);
    if (!limpio) throw new ErrorRuta('No se puede borrar la raíz del servidor.');
    fs.rmSync(abs, { recursive: true, force: false });
}

function paraDescargar(base, rel) {
    const { abs } = resolver(base, rel);
    const st = fs.statSync(abs);
    if (!st.isFile()) throw new ErrorRuta('Solo se descargan archivos.');
    if (st.size > MAX_DESCARGA) throw new ErrorRuta('El archivo supera 25 MB.', 413);
    return { abs, nombre: path.basename(abs), size: st.size };
}

module.exports = { resolver, listar, leerTexto, guardarTexto, subir, crearCarpeta, renombrar, borrar, paraDescargar, ErrorRuta, MAX_SUBIDA };
