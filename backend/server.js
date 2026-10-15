const express = require('express');
const http = require('http');
const { Server } = require('socket.io');
const cors = require('cors');
const Docker = require('dockerode');
const fs = require('fs');
const os = require('os');
const path = require('path');
const dns = require('dns').promises;
const net = require('net');

// === INTEGRACIÓN FIREBASE ADMIN SDK (Seguridad) ===
const admin = require('firebase-admin');
const serviceAccount = require('./firebase-adminsdk.json');

admin.initializeApp({
  credential: admin.credential.cert(serviceAccount)
});
// =================================================

const app = express();
const server = http.createServer(app);
const docker = new Docker({ socketPath: '/var/run/docker.sock' });

app.use(cors());
app.use(express.json());

// Configuramos Socket.io para la consola en vivo del frontend
const io = new Server(server, {
    cors: { origin: '*' }
});

// === MIDDLEWARE DE SEGURIDAD FRONTEND (Firebase) ===
const verificarTokenFirebase = async (req, res, next) => {
    const authHeader = req.headers.authorization;
    if (!authHeader || !authHeader.startsWith('Bearer ')) {
        return res.status(401).json({ error: 'Acceso no autorizado' });
    }
    try {
        const token = authHeader.split(' ')[1];
        req.user = await admin.auth().verifyIdToken(token);
        next();
    } catch (error) {
        return res.status(401).json({ error: 'Token inválido o expirado' });
    }
};

// === ALMACENAMIENTO DE DATOS (JSON en disco) ===
const DATA_DIR = path.join(__dirname, 'data');
const SERVERS_DIR = path.join(__dirname, 'servers');
const DOCKER_SERVERS_DIR = process.env.DOCKER_SERVERS_DIR || SERVERS_DIR;
const DB_FILE = path.join(DATA_DIR, 'projects.json');

// Estado de deploy en progreso (en memoria)
const deployStatus = {};

// Asegurar que existan los directorios
if (!fs.existsSync(DATA_DIR)) fs.mkdirSync(DATA_DIR, { recursive: true });
if (!fs.existsSync(SERVERS_DIR)) fs.mkdirSync(SERVERS_DIR, { recursive: true });

// Deploys que quedaron a medias por un reinicio del demonio: marcarlos como error.
(() => {
    const db = loadDB();
    let cambios = false;
    for (const d of Object.values(db)) {
        for (const s of d.servers || []) {
            if (s.status === 'deploying') { s.status = 'error'; s.error = 'Deploy interrumpido por reinicio del demonio.'; cambios = true; }
        }
    }
    if (cambios) saveDB(db);
})();

function loadDB() {
    if (!fs.existsSync(DB_FILE)) return {};
    try { return JSON.parse(fs.readFileSync(DB_FILE, 'utf8')); }
    catch { return {}; }
}

function saveDB(db) {
    fs.writeFileSync(DB_FILE, JSON.stringify(db, null, 2));
}

// === MIDDLEWARE DE SEGURIDAD (Para rutas internas Laravel→Node) ===
const verificarCerebro = (req, res, next) => {
    const token = req.headers['x-daemon-secret'];
    if (!process.env.NODE_SECRET_KEY || token !== process.env.NODE_SECRET_KEY) {
        console.warn('⚠️ Intento de acceso no autorizado al Demonio.');
        return res.status(403).json({ error: 'Acceso denegado. Solo Laravel puede dar órdenes.' });
    }
    next();
};

async function registrarAuditoria(event, actorId, serverId, payload = {}) {
    if (!process.env.NODE_SECRET_KEY) return;

    try {
        await fetch('http://laravel-api/api/internal/audit', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'x-daemon-secret': process.env.NODE_SECRET_KEY,
            },
            body: JSON.stringify({
                event,
                actor_type: 'daemon',
                actor_id: actorId,
                server_id: serverId,
                payload,
            }),
        });
    } catch (error) {
        console.warn(`[AUDIT] No se pudo persistir ${event}: ${error.message}`);
    }
}

app.use('/api/project', verificarTokenFirebase);
app.use('/api/server', verificarTokenFirebase);
app.use('/api/curseforge', verificarTokenFirebase);
app.use('/api/modrinth', verificarTokenFirebase);

// === HELPER: Generar ID único para servidor ===
function generateId() {
    return 'srv-' + Date.now().toString(36) + '-' + Math.random().toString(36).substring(2, 8);
}

// === HELPER: Puerto TCP disponible (rango 25565-26000) ===
function getAvailablePort(db) {
    const usedPorts = new Set();
    for (const uid of Object.keys(db)) {
        for (const srv of db[uid].servers) {
            if (srv.port) usedPorts.add(srv.port);
        }
    }
    for (let p = 25565; p <= 26000; p++) {
        if (!usedPorts.has(p)) return p;
    }
    return null;
}

// === HELPER: Imagen Docker según edición/software ===
function getDockerImage(edition, software) {
    if (edition === 'bedrock') return 'itzg/minecraft-bedrock-server:latest';
    // Java: vanilla, paper, spigot, forge, fabric, etc.
    return 'itzg/minecraft-server:latest';
}

// === HELPER: Variables de entorno para el contenedor ===
// Límites de hardware del plan (los envía Laravel desde SQL Server). Sin plan: valores históricos.
function limitesDePlan(plan) {
    const memoryMb = Number.isInteger(plan?.memoria_mb) && plan.memoria_mb > 0 ? plan.memoria_mb : 1024;
    const pedidos = Number(plan?.cpu_cores) > 0 ? Number(plan.cpu_cores) : 1;
    return {
        plan: plan?.nombre || null,
        memoryMb,
        cpuCores: Math.min(pedidos, os.cpus().length), // Docker rechaza más CPUs de las que tiene el host
        storageGb: Number(plan?.almacenamiento_gb) > 0 ? Number(plan.almacenamiento_gb) : null, // informativo: no se aplica cuota de disco
        heapMb: Math.max(512, Math.floor(memoryMb * 0.8)), // la JVM deja margen para memoria fuera del heap
    };
}

function getServerEnv(payload, port, limites) {
    const env = [
        'EULA=TRUE',
        `MOTD=${payload.motd || 'Professional Server'}`,
        `SERVER_PORT=${port}`,
    ];

    if (payload.edition === 'bedrock') {
        env.push(`SERVER_NAME=${payload.projectName || 'ProServer'}`);
        env.push(`VERSION=${payload.version === 'PREVIEW' ? 'PREVIEW' : 'LATEST'}`);
    } else {
        // Java
        const typeMap = {
            'vanilla': 'VANILLA',
            'paper': 'PAPER',
            'spigot': 'SPIGOT',
            'forge': 'FORGE',
            'fabric': 'FABRIC',
            'purpur': 'PURPUR',
            'Server Pack': 'AUTO_CURSEFORGE',
            'modrinth': 'MODRINTH',
        };
        env.push(`TYPE=${typeMap[payload.software] || 'VANILLA'}`);

        // En modpacks la versión de Minecraft la fija el propio pack.
        if (payload.version && payload.version !== 'LATEST' && !['Server Pack', 'modrinth'].includes(payload.software)) {
            env.push(`VERSION=${payload.version}`);
        }

        if (payload.software === 'Server Pack') {
            env.push(`CF_SLUG=${payload.cfSlug}`);
            if (payload.curseforgeFileId) env.push(`CF_FILE_ID=${payload.curseforgeFileId}`);
            env.push(`CF_API_KEY=${(process.env.CURSEFORGE_API_KEY || '').replace(/^"|"$/g, '').trim()}`);
        }

        if (payload.software === 'modrinth') {
            env.push(`MODRINTH_MODPACK=${payload.modrinthSlug}`);
            if (payload.modrinthVersion) env.push(`MODRINTH_VERSION=${payload.modrinthVersion}`);
        }

        if (payload.modpackUrl) {
            env.push(`GENERIC_PACK=${payload.modpackUrl}`);
        }

        env.push(`MEMORY=${limites.heapMb}M`);
        env.push('ONLINE_MODE=FALSE');
    }

    return env;
}

// === HELPER: Validación del pedido de deploy (listas permitidas) ===
const SOFTWARE_POR_EDICION = {
    java: ['vanilla', 'paper', 'purpur', 'spigot', 'forge', 'fabric', 'Server Pack', 'modrinth'],
    bedrock: ['bedrock'],
};
const SLUG_RE = /^[a-z0-9][a-z0-9_.-]{0,99}$/i;

function validarPedidoDeploy(body) {
    const { edition, software, version } = body;
    if (!SOFTWARE_POR_EDICION[edition]) return 'Edición inválida.';
    if (!SOFTWARE_POR_EDICION[edition].includes(software)) return 'Motor no soportado para esta edición.';
    const versionOk = edition === 'bedrock'
        ? ['LATEST', 'PREVIEW'].includes(version)
        : version === 'LATEST' || /^\d+\.\d+(\.\d+)?$/.test(version || '');
    if (!versionOk) return 'Versión inválida.';
    if (typeof body.projectName !== 'string' || !/^[\p{L}\p{N} _.-]{3,32}$/u.test(body.projectName.trim())) {
        return 'El nombre debe tener entre 3 y 32 caracteres (letras, números, espacios, _ . -).';
    }
    if (body.motd != null && (typeof body.motd !== 'string' || body.motd.length > 59 || /[\r\n]/.test(body.motd))) {
        return 'El MOTD admite hasta 59 caracteres en una sola línea.';
    }
    if (software === 'Server Pack') {
        if (!SLUG_RE.test(body.cfSlug || '')) return 'Modpack de CurseForge inválido.';
        if (body.curseforgeFileId != null && !/^\d{1,12}$/.test(String(body.curseforgeFileId))) return 'Archivo de CurseForge inválido.';
    }
    if (software === 'modrinth') {
        if (!SLUG_RE.test(body.modrinthSlug || '')) return 'Modpack de Modrinth inválido.';
        if (body.modrinthVersion != null && !/^[a-zA-Z0-9]{8}$/.test(body.modrinthVersion)) return 'Versión de Modrinth inválida.';
    }
    return null;
}

// === HELPER: Anti-SSRF para instalación por URL externa ===
// Solo HTTPS, solo .zip, nunca hacia redes privadas/locales (se revalida en cada redirección).
const MAX_PACK_BYTES = 2 * 1024 ** 3;

function ipPrivada(ip) {
    if (net.isIPv4(ip)) {
        const [a, b] = ip.split('.').map(Number);
        return a === 0 || a === 10 || a === 127 || a >= 224 || (a === 100 && b >= 64 && b <= 127)
            || (a === 169 && b === 254) || (a === 172 && b >= 16 && b <= 31) || (a === 192 && b === 168);
    }
    const v6 = ip.toLowerCase();
    if (v6.startsWith('::ffff:')) return ipPrivada(v6.slice(7));
    return v6 === '::' || v6 === '::1' || /^f[cd]/.test(v6) || /^fe[89ab]/.test(v6) || v6.startsWith('ff');
}

async function validarUrlPack(rawUrl) {
    let url = rawUrl;
    for (let salto = 0; salto <= 3; salto++) {
        let u;
        try { u = new URL(url); } catch { throw new Error('URL mal formada.'); }
        if (u.protocol !== 'https:') throw new Error('Solo se aceptan enlaces HTTPS.');
        if (u.username || u.password) throw new Error('La URL no puede incluir credenciales.');
        if (u.port && u.port !== '443') throw new Error('Solo se acepta el puerto 443.');

        const host = u.hostname.replace(/^\[|\]$/g, '');
        const ips = net.isIP(host) ? [host] : (await dns.lookup(host, { all: true }).catch(() => [])).map(r => r.address);
        if (!ips.length) throw new Error('No se pudo resolver el dominio.');
        if (ips.some(ipPrivada)) throw new Error('El destino apunta a una red privada.');

        const res = await fetch(u, { method: 'HEAD', redirect: 'manual', signal: AbortSignal.timeout(10000) })
            .catch(() => { throw new Error('El enlace no respondió.'); });
        if (res.status >= 300 && res.status < 400 && res.headers.get('location')) {
            url = new URL(res.headers.get('location'), u).toString();
            continue;
        }
        if (!res.ok) throw new Error(`El enlace respondió HTTP ${res.status}.`);

        const tipo = (res.headers.get('content-type') || '').split(';')[0].trim();
        const disposicion = res.headers.get('content-disposition') || '';
        const esZip = u.pathname.toLowerCase().endsWith('.zip') || /\.zip"?$/i.test(disposicion)
            || ['application/zip', 'application/x-zip-compressed'].includes(tipo);
        if (!esZip) throw new Error('El archivo debe ser un .zip.');
        const largo = Number(res.headers.get('content-length') || 0);
        if (largo > MAX_PACK_BYTES) throw new Error('El archivo supera el máximo de 2 GB.');
        return u.toString();
    }
    throw new Error('Demasiadas redirecciones.');
}

// ============================================================
// === RUTAS DEL DEMONIO (API para el Frontend) ===
// ============================================================

// --- Health Check ---
app.get('/ping', (req, res) => {
    res.json({ status: '🟢 Demonio Node.js en línea, blindado y esperando órdenes.' });
});

// ============================================================
// === ADMIN (solo Laravel, autenticado con x-daemon-secret) ===
// Laravel valida el rol del staff; Node nunca queda expuesto al navegador.
// ============================================================

function ubicarServidor(serverId) {
    const db = loadDB();
    for (const [uid, d] of Object.entries(db)) {
        const srv = (d.servers || []).find(x => x.id === serverId);
        if (srv) return { uid, srv };
    }
    return null;
}

async function estadosDocker() {
    const mapa = new Map();
    try {
        const lista = await docker.listContainers({ all: true });
        for (const c of lista) mapa.set(c.Names[0].replace(/^\//, ''), c);
    } catch (e) {
        console.warn('[ADMIN] No se pudo consultar Docker:', e.message);
    }
    return mapa;
}

app.get('/api/admin/all-servers', verificarCerebro, async (req, res) => {
    const estados = await estadosDocker();
    const db = loadDB();
    const servers = Object.entries(db).flatMap(([uid, d]) => (d.servers || []).map(s => {
        const estado = estados.get(`mc-${s.id}`)?.State || 'missing';
        return { ...s, uid, containerStatus: estado, isRunning: estado === 'running' };
    }));
    res.json({ success: true, servers });
});

async function statsContenedor(info) {
    const st = await Promise.race([
        docker.getContainer(info.Id).stats({ stream: false }),
        new Promise((_, rej) => setTimeout(() => rej(new Error('timeout')), 5000)),
    ]);
    const cpuDelta = st.cpu_stats.cpu_usage.total_usage - st.precpu_stats.cpu_usage.total_usage;
    const sysDelta = st.cpu_stats.system_cpu_usage - st.precpu_stats.system_cpu_usage;
    const ncpu = st.cpu_stats.online_cpus || st.cpu_stats.cpu_usage.percpu_usage?.length || 1;
    const cpuPct = cpuDelta > 0 && sysDelta > 0 ? (cpuDelta / sysDelta) * ncpu * 100 : 0;
    const cache = st.memory_stats.stats?.inactive_file ?? st.memory_stats.stats?.cache ?? 0;
    return {
        cpuPct: Math.round(cpuPct * 10) / 10,
        ramMb: Math.round(((st.memory_stats.usage || 0) - cache) / 1048576),
        ramLimitMb: Math.round((st.memory_stats.limit || 0) / 1048576),
    };
}

app.get('/api/admin/telemetry', verificarCerebro, async (req, res) => {
    const inicio = Date.now();
    let dockerMs = null;
    try { await docker.ping(); dockerMs = Date.now() - inicio; } catch (e) { /* docker caído */ }

    const nucleos = os.cpus().length || 1;
    const total = os.totalmem();
    const libre = os.freemem();
    const disco = fs.statfsSync('/');
    const discoTotal = disco.blocks * disco.bsize;
    const discoLibre = disco.bavail * disco.bsize;

    const host = {
        cores: nucleos,
        load1: Math.round(os.loadavg()[0] * 100) / 100,
        cpuPct: Math.min(100, Math.round((os.loadavg()[0] / nucleos) * 1000) / 10),
        ramUsedMb: Math.round((total - libre) / 1048576),
        ramTotalMb: Math.round(total / 1048576),
        ramPct: Math.round(((total - libre) / total) * 1000) / 10,
        diskUsedGb: Math.round(((discoTotal - discoLibre) / 1073741824) * 10) / 10,
        diskTotalGb: Math.round((discoTotal / 1073741824) * 10) / 10,
        diskPct: Math.round(((discoTotal - discoLibre) / discoTotal) * 1000) / 10,
        uptimeSec: Math.round(os.uptime()),
        dockerPingMs: dockerMs,
    };

    const estados = await estadosDocker();
    const mc = [...estados.entries()].filter(([n]) => n.startsWith('mc-'));
    const containers = await Promise.all(mc.map(async ([nombre, info]) => {
        const base = { serverId: nombre.slice(3), name: nombre, state: info.State, cpuPct: 0, ramMb: 0, ramLimitMb: 0 };
        if (info.State !== 'running') return base;
        try { return { ...base, ...(await statsContenedor(info)) }; } catch (e) { return base; }
    }));

    res.json({ success: true, dockerUp: dockerMs !== null, host, containers });
});

app.post('/api/admin/server-action', verificarCerebro, async (req, res) => {
    const { serverId, action } = req.body;
    if (!serverId || !['start', 'stop', 'restart', 'delete'].includes(action)) {
        return res.status(400).json({ error: 'serverId y action (start|stop|restart|delete) son obligatorios.' });
    }
    const ubicado = ubicarServidor(serverId);
    if (ubicado?.srv.frozen && action !== 'stop' && action !== 'delete') {
        return res.status(423).json({ error: 'account_frozen', message: 'La cuenta está en Zona de Hielo.' });
    }

    try {
        const mc = docker.getContainer(`mc-${serverId}`);
        const playit = docker.getContainer(`playit-${serverId}`);
        const ignorar = async (fn) => { try { await fn(); } catch (e) { if (![304, 404].includes(e.statusCode)) throw e; } };

        if (action === 'start') { await mc.start(); await ignorar(() => playit.start()); }
        if (action === 'stop') { await ignorar(() => mc.stop()); await ignorar(() => playit.stop()); }
        if (action === 'restart') { await mc.restart(); }
        if (action === 'delete') {
            await ignorar(() => mc.remove({ force: true }));
            await ignorar(() => playit.remove({ force: true }));
            fs.rmSync(path.join(SERVERS_DIR, serverId), { recursive: true, force: true });
            if (ubicado) {
                const db = loadDB();
                db[ubicado.uid].servers = db[ubicado.uid].servers.filter(x => x.id !== serverId);
                saveDB(db);
            }
        }
        res.json({ success: true });
    } catch (e) {
        if (e.statusCode === 404) return res.status(404).json({ error: 'container_not_found', message: 'El contenedor no existe.' });
        res.status(500).json({ error: 'Error en la acción: ' + e.message });
    }
});

// Zona de Hielo: detiene los contenedores del cliente y bloquea su arranque.
app.post('/api/admin/freeze', verificarCerebro, async (req, res) => {
    const { uid, freeze } = req.body;
    if (!uid || typeof freeze !== 'boolean') return res.status(400).json({ error: 'uid y freeze (boolean) son obligatorios.' });

    const db = loadDB();
    const servers = db[uid]?.servers || [];
    const errores = [];
    for (const s of servers) {
        s.frozen = freeze;
        for (const nombre of [`mc-${s.id}`, `playit-${s.id}`]) {
            try {
                const c = docker.getContainer(nombre);
                if (freeze) await c.stop(); else await c.start();
            } catch (e) { if (![304, 404].includes(e.statusCode)) errores.push(`${nombre}: ${e.message}`); }
        }
    }
    saveDB(db);
    res.json({ success: errores.length === 0, afectados: servers.length, errores });
});

// Plan cambiado: aplica los nuevos límites de CPU y RAM a los contenedores existentes del cliente.
// El heap de la JVM (MEMORY) solo cambia cuando el servidor se vuelve a crear.
app.post('/api/admin/apply-limits', verificarCerebro, async (req, res) => {
    const { uid, plan } = req.body;
    if (!uid) return res.status(400).json({ error: 'uid es obligatorio.' });
    const limites = limitesDePlan(plan);
    const db = loadDB();
    const errores = [];
    let aplicados = 0;
    for (const s of db[uid]?.servers || []) {
        s.limits = limites;
        try {
            await docker.getContainer(`mc-${s.id}`).update({
                Memory: limites.memoryMb * 1024 * 1024,
                MemorySwap: limites.memoryMb * 1024 * 1024,
                NanoCpus: Math.round(limites.cpuCores * 1e9),
            });
            aplicados++;
        } catch (e) { if (e.statusCode !== 404) errores.push(`${s.id}: ${e.message}`); }
    }
    saveDB(db);
    res.json({ success: errores.length === 0, aplicados, limites, errores });
});

// Purga segura: solo imágenes huérfanas. Nunca borra contenedores ni volúmenes de clientes.
app.post('/api/admin/system-prune', verificarCerebro, async (req, res) => {
    try {
        const r = await docker.pruneImages({ filters: { dangling: ['true'] } });
        res.json({
            success: true,
            imagenesEliminadas: (r.ImagesDeleted || []).length,
            espacioLiberadoMb: Math.round((r.SpaceReclaimed || 0) / 1048576),
        });
    } catch (e) {
        res.status(500).json({ error: 'No se pudo purgar Docker: ' + e.message });
    }
});

app.post('/api/admin/global-broadcast', verificarCerebro, async (req, res) => {
    const { message, level = 'info', author = 'ProServers' } = req.body;
    if (typeof message !== 'string' || !message.trim() || message.length > 500) {
        return res.status(400).json({ error: 'El mensaje es obligatorio (máx. 500 caracteres).' });
    }
    const payload = { message: message.trim(), level, author, at: new Date().toISOString() };
    io.emit('global_broadcast', payload);

    let discord = 'omitido';
    try {
        const r = await fetch('http://discord-bot:3001/api/bot/broadcast', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'x-daemon-secret': process.env.NODE_SECRET_KEY || '' },
            body: JSON.stringify(payload),
            signal: AbortSignal.timeout(5000),
        });
        discord = r.ok ? 'enviado' : `error ${r.status}`;
    } catch (e) { discord = 'bot inaccesible'; }

    res.json({ success: true, sockets: io.engine.clientsCount, discord });
});

// Diagnóstico Mine AI para soporte: devuelve el análisis y el extracto de log analizado.
app.post('/api/admin/diagnose', verificarCerebro, async (req, res) => {
    const { serverId } = req.body;
    if (!serverId) return res.status(400).json({ error: 'Falta serverId' });
    const groqKey = (process.env.GROQ_API_KEY || '').replace(/^"|"$/g, '').trim();
    if (!groqKey) return res.status(503).json({ error: 'Mine AI no está configurada en el servidor.' });

    try {
        const buf = await docker.getContainer(`mc-${serverId}`).logs({ stdout: true, stderr: true, tail: 200 });
        const logText = buf.toString('utf8').slice(-30000);
        const r = await fetch('https://api.groq.com/openai/v1/chat/completions', {
            method: 'POST',
            headers: { 'Authorization': `Bearer ${groqKey}`, 'Content-Type': 'application/json' },
            body: JSON.stringify({
                model: process.env.GROQ_MODEL || 'llama-3.3-70b-versatile',
                messages: [{ role: 'user', content: `Analiza este log de Minecraft como ingeniero de infraestructura. Devuelve únicamente JSON válido con las claves mensaje, hay_que_borrar, archivo_a_borrar, paso_a_paso y mod_alternativo.\n\nLOG:\n${logText}` }],
                temperature: 0.1,
                response_format: { type: 'json_object' },
            }),
        });
        const data = await r.json();
        if (!r.ok) return res.status(502).json({ error: data.error?.message || 'Groq rechazó el diagnóstico.' });
        res.json({ success: true, analysis: JSON.parse(data.choices?.[0]?.message?.content || '{}'), logExtract: logText.slice(-2500) });
    } catch (e) {
        if (e.statusCode === 404) return res.status(404).json({ error: 'container_not_found', message: 'El contenedor no existe.' });
        res.status(500).json({ error: 'Error analizando el log: ' + e.message });
    }
});

// --- Verificar servidores del usuario ---
app.get('/api/project/check', (req, res) => {
    const uid = req.query.uid;
    const db = loadDB();

    if (!uid) {
        // Sin UID: responder genérico
        return res.json({ exists: false, servers: [] });
    }

    const userData = db[uid];
    if (!userData || !userData.servers || userData.servers.length === 0) {
        return res.json({ exists: false, servers: [] });
    }

    res.json({
        exists: true,
        servers: userData.servers
    });
});

// --- Crear servidor (Deploy de contenedor Docker) ---
app.post('/api/project/create', async (req, res) => {
    const { uid, email, projectName, motd, edition, software, version, curseforgeFileId, modpackUrl } = req.body;

    if (!uid || !edition) {
        return res.status(400).json({ success: false, message: 'Faltan datos obligatorios (uid, edition).' });
    }

    const errorPedido = validarPedidoDeploy(req.body);
    if (errorPedido) return res.status(422).json({ success: false, message: errorPedido });

    if (modpackUrl) {
        if (edition !== 'java') return res.status(422).json({ success: false, message: 'La instalación por URL es solo para Java.' });
        try {
            req.body.modpackUrl = await validarUrlPack(String(modpackUrl));
        } catch (e) {
            return res.status(422).json({ success: false, message: `URL rechazada: ${e.message}` });
        }
    }
    req.body.projectName = projectName.trim();

    const db = loadDB();
    const serverId = generateId();
    const port = getAvailablePort(db);

    if (!port) {
        return res.status(503).json({ success: false, message: 'No hay puertos disponibles. Contactá a soporte.' });
    }

    const limites = limitesDePlan(req.body.plan);
    const containerName = `mc-${serverId}`;
    const playitName = `playit-${serverId}`;
    const serverDataPath = path.join(SERVERS_DIR, serverId);
    const dockerServerDataPath = path.join(DOCKER_SERVERS_DIR, serverId);
    fs.mkdirSync(serverDataPath, { recursive: true });
    fs.mkdirSync(dockerServerDataPath, { recursive: true });

    // Iniciar estado de deploy
    deployStatus[serverId] = { step: 'Preparando contenedor...', pct: 10, done: false, error: null };

    console.log(`[DEPLOY] Creando servidor ${serverId} para ${email || uid} | ${edition}/${software}/${version} | Puerto: ${port}`);

    const serverRecord = {
        id: serverId,
        name: req.body.projectName,
        edition,
        software: software || 'vanilla',
        version: version || 'LATEST',
        port,
        containerName,
        status: 'deploying',
        limits: limites,
        motd: motd || 'Professional Server',
        createdAt: new Date().toISOString(),
        email: email || null,
    };

    // Guardar en DB inmediatamente
    if (!db[uid]) db[uid] = { servers: [] };
    db[uid].servers.push(serverRecord);
    saveDB(db);

    // Responder al frontend inmediatamente (el deploy sigue en background)
    res.json({ success: true, server: serverRecord });

    // === DEPLOY ASÍNCRONO ===
    try {
        deployStatus[serverId] = { step: 'Descargando imagen Docker...', pct: 25, done: false, error: null };

        const image = getDockerImage(edition, software);
        const envVars = getServerEnv(req.body, port, limites);
        const internalPort = edition === 'bedrock' ? '19132/udp' : '25565/tcp';

        // Intentar pull de la imagen de MC
        deployStatus[serverId] = { step: 'Descargando imagen del servidor...', pct: 35, done: false, error: null };
        try {
            await new Promise((resolve, reject) => {
                docker.pull(image, (err, stream) => {
                    if (err) return reject(err);
                    let ultimo = 0;
                    docker.modem.followProgress(stream, (err) => err ? reject(err) : resolve(), (ev) => {
                        if (Date.now() - ultimo < 1000 || !ev.status) return;
                        ultimo = Date.now();
                        deployStatus[serverId] = { step: `Descargando imagen del servidor (${ev.status})...`, pct: 35, done: false, error: null };
                    });
                });
            });
        } catch (pullErr) { console.warn(`[DEPLOY] Pull warning: ${pullErr.message} — Intentando con imagen local`); }

        // Intentar pull de la imagen de Playit (CORREGIDO A PEPAONDRUGS)
        deployStatus[serverId] = { step: 'Descargando agente de red...', pct: 45, done: false, error: null };
        try {
            await new Promise((resolve, reject) => {
                docker.pull('pepaondrugs/playitgg-docker:latest', (err, stream) => {
                    if (err) return reject(err);
                    docker.modem.followProgress(stream, (err) => err ? reject(err) : resolve());
                });
            });
        } catch (pullErr) { console.warn(`[DEPLOY] Pull Playit warning — Intentando local`); }

        deployStatus[serverId] = { step: 'Creando contenedores...', pct: 60, done: false, error: null };

        // Crear el contenedor MC
        const portBindings = {};
        portBindings[internalPort] = [{ HostPort: String(port) }];

        const mcContainer = await docker.createContainer({
            Image: image,
            name: containerName,
            Env: envVars,
            HostConfig: {
                PortBindings: portBindings,
                Binds: [`${dockerServerDataPath}:/data`],
                Memory: limites.memoryMb * 1024 * 1024,
                MemorySwap: limites.memoryMb * 1024 * 1024, // sin swap
                NanoCpus: Math.round(limites.cpuCores * 1e9),
                RestartPolicy: { Name: 'unless-stopped' },
                NetworkMode: 'minecraft-panel_minecraft-net',
            },
            ExposedPorts: { [internalPort]: {} }
        });

        // Crear contenedor Playit (CORREGIDO A PEPAONDRUGS)
        const playitContainer = await docker.createContainer({
            Image: 'pepaondrugs/playitgg-docker:latest',
            name: playitName,
            HostConfig: {
                NetworkMode: 'minecraft-panel_minecraft-net',
                RestartPolicy: { Name: 'unless-stopped' },
                Memory: 128 * 1024 * 1024 // 128MB RAM es suficiente para Playit
            }
        });

        deployStatus[serverId] = { step: 'Iniciando servidor y red...', pct: 80, done: false, error: null };

        await mcContainer.start();
        await playitContainer.start();

        deployStatus[serverId] = { step: '¡Servidor desplegado con éxito!', pct: 100, done: true, error: null };

        // Actualizar estado en DB
        const dbUpdated = loadDB();
        if (dbUpdated[uid]) {
            const srv = dbUpdated[uid].servers.find(s => s.id === serverId);
            if (srv) {
                srv.status = 'running';
                srv.containerId = mcContainer.id;
                saveDB(dbUpdated);
            }
        }

        console.log(`[DEPLOY] ✅ Servidor ${serverId} y Túnel levantados.`);
        void registrarAuditoria('server.deploy.completed', uid, serverId, { port, edition, software });

    } catch (deployErr) {
        console.error(`[DEPLOY] ❌ Error desplegando ${serverId}:`, deployErr.message);
        deployStatus[serverId] = { step: 'Error en el deploy', pct: 100, done: true, error: deployErr.message };

        // Marcar como error en DB
        const dbErr = loadDB();
        if (dbErr[uid]) {
            const srv = dbErr[uid].servers.find(s => s.id === serverId);
            if (srv) { srv.status = 'error'; srv.error = deployErr.message; saveDB(dbErr); }
        }
        void registrarAuditoria('server.deploy.failed', uid, serverId, { error: deployErr.message });
    }
});

// --- Estado del deploy en progreso ---
app.get('/api/project/deploy-status', (req, res) => {
    const { serverId } = req.query;
    if (!serverId) {
        return res.status(400).json({ step: 'Falta serverId', pct: 0, done: true, error: 'Falta serverId' });
    }

    if (deployStatus[serverId]) return res.json(deployStatus[serverId]);

    const server = Object.values(loadDB())
        .flatMap(userData => userData.servers || [])
        .find(serverRecord => serverRecord.id === serverId);

    if (!server) {
        return res.status(404).json({ step: 'Servidor no encontrado', pct: 100, done: true, error: 'Servidor no encontrado' });
    }

    if (server.status === 'running') {
        return res.json({ step: '¡Servidor desplegado con éxito!', pct: 100, done: true, error: null });
    }

    if (server.status === 'error') {
        return res.json({ step: 'Error en el deploy', pct: 100, done: true, error: server.error || 'El deploy falló.' });
    }

    // Sin estado en memoria y aún 'deploying': el demonio se reinició a mitad del deploy.
    return res.json({ step: 'Deploy interrumpido', pct: 100, done: true, error: 'El despliegue se interrumpió. Elimina este servidor y créalo de nuevo.' });
});

// --- Eliminar servidor ---
app.post('/api/project/delete', async (req, res) => {
    const { uid, serverId } = req.body;

    if (!uid || !serverId) {
        return res.status(400).json({ success: false, message: 'Faltan uid o serverId.' });
    }

    const db = loadDB();
    if (!db[uid]) return res.status(404).json({ success: false, message: 'Usuario no encontrado.' });

    const serverIndex = db[uid].servers.findIndex(s => s.id === serverId);
    if (serverIndex === -1) return res.status(404).json({ success: false, message: 'Servidor no encontrado.' });

    const srv = db[uid].servers[serverIndex];

    // Detener y eliminar contenedor Docker MC
    try {
        const mcContainer = docker.getContainer(srv.containerName);
        try { await mcContainer.stop(); } catch {}
        try { await mcContainer.remove({ force: true }); } catch {}
    } catch (e) { console.warn("Error borrando MC container:", e.message); }

    // Detener y eliminar contenedor Playit
    try {
        const playitContainer = docker.getContainer(`playit-${serverId}`);
        try { await playitContainer.stop(); } catch {}
        try { await playitContainer.remove({ force: true }); } catch {}
    } catch (e) { console.warn("Error borrando Playit container:", e.message); }

    // Eliminar datos del servidor
    const serverDataPath = path.join(SERVERS_DIR, serverId);
    try {
        fs.rmSync(serverDataPath, { recursive: true, force: true });
    } catch {}

    // Eliminar de la DB
    db[uid].servers.splice(serverIndex, 1);
    saveDB(db);

    console.log(`[DELETE] ✅ Servidor ${serverId} eliminado para ${uid}`);
    res.json({ success: true, message: 'Servidor eliminado.' });
});

// --- Clonar servidor ---
app.post('/api/project/clone', async (req, res) => {
    const { uid, serverId } = req.body;
    const db = loadDB();
    if (!db[uid]) return res.status(404).json({ success: false, message: 'Usuario no encontrado.' });

    const original = db[uid].servers.find(s => s.id === serverId);
    if (!original) return res.status(404).json({ success: false, message: 'Servidor original no encontrado.' });

    // Re-usar la lógica de create con los datos del original
    const fakeReq = {
        body: {
            uid,
            email: original.email,
            projectName: original.name + ' (Clon)',
            motd: original.motd,
            edition: original.edition,
            software: original.software,
            version: original.version,
        }
    };

    // Simplificado: delegar al handler de create
    req.body = fakeReq.body;
    // Reutilizamos la misma lógica llamando internamente
    return app._router.handle(Object.assign(req, { url: '/api/project/create', method: 'POST', body: fakeReq.body }), res, () => {});
});

// --- Compartir / Dejar de compartir (stub) ---
app.post('/api/project/share', (req, res) => {
    res.json({ success: true, message: 'Servidor compartido (funcionalidad en desarrollo).' });
});

app.post('/api/project/unshare', (req, res) => {
    res.json({ success: true, message: 'Se dejó de compartir el servidor.' });
});

// --- Obtener IP del servidor ---
app.post('/api/project/ip', (req, res) => {
    const { uid, serverId } = req.body;
    const db = loadDB();
    if (!db[uid]) return res.status(404).json({ success: false });

    const srv = db[uid].servers.find(s => s.id === serverId);
    if (!srv) return res.status(404).json({ success: false });

    // La IP pública del host + el puerto asignado
    res.json({
        success: true,
        ip: process.env.PUBLIC_IP || '18.189.90.203',
        port: srv.port,
        address: `${process.env.PUBLIC_IP || '18.189.90.203'}:${srv.port}`
    });
});

// ============================================================
// === RUTAS DE ACCIÓN DEL SERVIDOR (/api/server/*) ===
// ============================================================

// --- Estado del servidor (Online/Offline) ---
app.get('/api/server/status', async (req, res) => {
    const { serverId } = req.query;
    if (!serverId) return res.status(400).json({ error: 'Falta serverId' });

    try {
        const container = docker.getContainer(`mc-${serverId}`);
        const info = await container.inspect();
        const isRunning = info.State.Running;
        res.json({ online: isRunning, status: info.State.Status });
    } catch (e) {
        res.json({ online: false, status: 'offline' });
    }
});

// --- Iniciar servidor ---
app.post('/api/server/start', async (req, res) => {
    const { serverId } = req.body;
    if (!serverId) return res.status(400).json({ error: 'Falta serverId' });
    if (ubicarServidor(serverId)?.srv.frozen) {
        return res.status(423).json({ error: 'Tu cuenta está en Zona de Hielo. Contacta a soporte.' });
    }

    try {
        const minecraftContainer = docker.getContainer(`mc-${serverId}`);
        try { await minecraftContainer.start(); } catch (error) {
            if (error.statusCode !== 304) throw error;
        }

        try {
            await docker.getContainer(`playit-${serverId}`).start();
        } catch (error) {
            console.warn(`[SERVER] Playit no pudo iniciarse para ${serverId}: ${error.message}`);
        }

        res.json({ success: true, message: 'Servidor iniciado.' });
    } catch (e) {
        if (e.statusCode === 404) {
            return res.status(404).json({ error: 'container_not_found', message: 'El contenedor del servidor no existe.' });
        }
        res.status(500).json({ error: 'Error al iniciar: ' + e.message });
    }
});

// --- Detener servidor ---
app.post('/api/server/stop', async (req, res) => {
    const { serverId } = req.body;
    if (!serverId) return res.status(400).json({ error: 'Falta serverId' });

    try {
        try { await docker.getContainer(`mc-${serverId}`).stop(); } catch (error) {
            if (error.statusCode !== 304 && error.statusCode !== 404) throw error;
        }
        try { await docker.getContainer(`playit-${serverId}`).stop(); } catch (error) {
            if (error.statusCode !== 304 && error.statusCode !== 404) {
                console.warn(`[SERVER] Playit no pudo detenerse para ${serverId}: ${error.message}`);
            }
        }
        res.json({ success: true, message: 'Servidor detenido.' });
    } catch (e) {
        res.status(500).json({ error: 'Error al detener: ' + e.message });
    }
});

// --- Reiniciar servidor ---
app.post('/api/server/restart', async (req, res) => {
    const { serverId } = req.body;
    if (!serverId) return res.status(400).json({ error: 'Falta serverId' });

    try {
        await docker.getContainer(`mc-${serverId}`).restart();
        try {
            await docker.getContainer(`playit-${serverId}`).restart();
        } catch (error) {
            console.warn(`[SERVER] Playit no pudo reiniciarse para ${serverId}: ${error.message}`);
        }
        res.json({ success: true, message: 'Servidor reiniciado.' });
    } catch (e) {
        res.status(500).json({ error: 'Error al reiniciar: ' + e.message });
    }
});

// --- Enviar comandos a la consola ---
app.post('/api/server/command', async (req, res) => {
    const { serverId, command } = req.body;
    if (!serverId || !command) return res.status(400).json({error: "Datos faltantes"});
    try {
        const container = docker.getContainer(`mc-${serverId}`);
        const exec = await container.exec({ AttachStdin: true, AttachStdout: true, AttachStderr: true, Tty: true, Cmd: ['rcon-cli', command] });
        await exec.start({ hijack: true, stdin: true });
        res.json({success: true});
    } catch(e) { res.status(500).json({error: e.message}); }
});

// --- Mine AI: diagnóstico técnico del log del servidor ---
app.post('/api/server/mine-ai', async (req, res) => {
    const { serverId } = req.body;
    if (!serverId) return res.status(400).json({ success: false, error: 'Falta serverId' });

    const userServers = loadDB()[req.user.uid]?.servers || [];
    if (!userServers.some(serverRecord => serverRecord.id === serverId)) {
        return res.status(403).json({ success: false, error: 'No tenés permisos para analizar este servidor.' });
    }

    const groqKey = (process.env.GROQ_API_KEY || '').replace(/^"|"$/g, '').trim();
    if (!groqKey) {
        return res.status(503).json({ success: false, error: 'Mine AI no está configurada en el servidor.' });
    }

    try {
        const container = docker.getContainer(`mc-${serverId}`);
        const logBuffer = await container.logs({ stdout: true, stderr: true, tail: 200 });
        const logText = logBuffer.toString('utf8').slice(-30000);
        const prompt = `Analiza este log de Minecraft como ingeniero de infraestructura. Devuelve únicamente JSON válido con las claves mensaje, hay_que_borrar, archivo_a_borrar, paso_a_paso y mod_alternativo. No ordenes borrar archivos si no hay evidencia clara.\n\nLOG:\n${logText}`;

        const groqResponse = await fetch('https://api.groq.com/openai/v1/chat/completions', {
            method: 'POST',
            headers: {
                'Authorization': `Bearer ${groqKey}`,
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                model: process.env.GROQ_MODEL || 'llama-3.3-70b-versatile',
                messages: [{ role: 'user', content: prompt }],
                temperature: 0.1,
                response_format: { type: 'json_object' }
            })
        });

        const responseData = await groqResponse.json();
        if (!groqResponse.ok) {
            return res.status(502).json({ success: false, error: responseData.error?.message || 'Groq rechazó el diagnóstico.' });
        }

        const analysis = JSON.parse(responseData.choices?.[0]?.message?.content || '{}');
        void registrarAuditoria('mine-ai.diagnosis.completed', req.user.uid, serverId, {
            hay_que_borrar: Boolean(analysis.hay_que_borrar),
            archivo_a_borrar: analysis.archivo_a_borrar || null,
        });
        return res.json({ success: true, analysis });
    } catch (error) {
        return res.status(500).json({ success: false, error: 'Error analizando el log: ' + error.message });
    }
});

// --- CurseForge API Proxy (Corregido y blindado contra errores de formato) ---
app.get('/api/curseforge/search', async (req, res) => {
    try {
        const params = new URLSearchParams(req.query);
        const cfUrl = `https://api.curseforge.com/v1/mods/search?gameId=432&${params.toString()}`;

        let apiKey = process.env.CURSEFORGE_API_KEY || '';
        apiKey = apiKey.replace(/^"|"$/g, '').trim();

        const response = await fetch(cfUrl, {
            headers: {
                'x-api-key': apiKey,
                'Accept': 'application/json'
            }
        });

        const textData = await response.text();

        try {
            const jsonData = JSON.parse(textData);
            return res.json(jsonData);
        } catch (parseErr) {
            console.error("Error parseando CurseForge:", textData);
            return res.status(500).json({ error: 'La API de CurseForge devolvió un formato no válido (Revisá tu API Key).' });
        }

    } catch (err) {
        res.status(500).json({ error: 'Error consultando CurseForge: ' + err.message });
    }
});

app.get('/api/curseforge/files', async (req, res) => {
    try {
        const { modId } = req.query;
        const cfUrl = `https://api.curseforge.com/v1/mods/${modId}/files`;

        let apiKey = process.env.CURSEFORGE_API_KEY || '';
        apiKey = apiKey.replace(/^"|"$/g, '').trim();

        const response = await fetch(cfUrl, {
            headers: {
                'x-api-key': apiKey,
                'Accept': 'application/json'
            }
        });

        const textData = await response.text();

        try {
            const jsonData = JSON.parse(textData);
            return res.json(jsonData);
        } catch (parseErr) {
            console.error("Error parseando ficheros CurseForge:", textData);
            return res.status(500).json({ error: 'Error al interpretar la respuesta de CurseForge.' });
        }

    } catch (err) {
        res.status(500).json({ error: 'Error consultando CurseForge: ' + err.message });
    }
});

// --- Modrinth API Proxy (API pública, sin key) ---
const MODRINTH_API = 'https://api.modrinth.com/v2';
const modrinthGet = async (res, url) => {
    try {
        const r = await fetch(url, { headers: { 'User-Agent': 'ProServers-Daemon/1.0' }, signal: AbortSignal.timeout(10000) });
        if (!r.ok) return res.status(502).json({ error: `Modrinth respondió HTTP ${r.status}.` });
        return res.json(await r.json());
    } catch (err) {
        return res.status(502).json({ error: 'Error consultando Modrinth: ' + err.message });
    }
};

app.get('/api/modrinth/search', (req, res) => {
    const params = new URLSearchParams({
        query: String(req.query.query || '').slice(0, 80),
        facets: JSON.stringify([['project_type:modpack'], ['server_side:required', 'server_side:optional']]),
        index: 'downloads',
        limit: '24',
    });
    return modrinthGet(res, `${MODRINTH_API}/search?${params}`);
});

app.get('/api/modrinth/versions', (req, res) => {
    const slug = String(req.query.slug || '');
    if (!SLUG_RE.test(slug)) return res.status(422).json({ error: 'Slug inválido.' });
    return modrinthGet(res, `${MODRINTH_API}/project/${encodeURIComponent(slug)}/version`);
});

// === Rutas internas Laravel -> Node ===
const recibirOrdenLaravel = (req, res) => {
    const clienteId = req.body.cliente_id || req.body.firebase_uid || req.body.uid;
    const plan = req.body.plan_activo || req.body.plan || req.body.newPlan;

    if (!clienteId || !plan) {
        return res.status(400).json({ error: 'cliente_id/firebase_uid y plan son obligatorios.' });
    }

    console.log(`[ORDEN RECIBIDA] Laravel solicita operar servidor para Cliente: ${clienteId} | Plan: ${plan}`);

    return res.status(202).json({
        status: 'accepted',
        message: 'Orden recibida por el demonio. La operación queda encolada.'
    });
};

app.post('/daemon/server/start', verificarCerebro, recibirOrdenLaravel);
app.post('/api/internal/deploy', verificarCerebro, recibirOrdenLaravel);
app.post('/api/internal/upgrade-plan', verificarCerebro, recibirOrdenLaravel);

app.post('/daemon/server/destroy-forced', verificarCerebro, async (req, res) => {
    const { firebase_uid: uid } = req.body;
    if (!uid) return res.status(400).json({ success: false, error: 'Falta firebase_uid.' });

    const db = loadDB();
    const servers = db[uid]?.servers || [];
    const destroyed = [];
    const failures = [];

    for (const serverRecord of servers) {
        const serverId = serverRecord.id;
        for (const containerName of [serverRecord.containerName || `mc-${serverId}`, `playit-${serverId}`]) {
            try {
                const container = docker.getContainer(containerName);
                try { await container.stop({ t: 15 }); } catch (error) {
                    if (error.statusCode !== 304 && error.statusCode !== 404) throw error;
                }
                try { await container.remove({ force: true }); } catch (error) {
                    if (error.statusCode !== 404) throw error;
                }
            } catch (error) {
                failures.push({ serverId, containerName, error: error.message });
            }
        }

        try {
            fs.rmSync(path.join(DOCKER_SERVERS_DIR, serverId), { recursive: true, force: true });
            destroyed.push(serverId);
        } catch (error) {
            failures.push({ serverId, error: error.message });
        }
    }

    delete db[uid];
    saveDB(db);
    await registrarAuditoria('server.refund.purge.completed', uid, null, { destroyed, failures });

    return res.status(failures.length ? 500 : 200).json({
        success: failures.length === 0,
        destroyed,
        failures,
    });
});

// --- Ver Logs de Playit (Para el túnel de red) ---
app.get('/api/server/playitlogs', async (req, res) => {
    try {
        const pContainer = docker.getContainer(`playit-${req.query.serverId}`);
        const logs = await pContainer.logs({ stdout: true, stderr: true, tail: 50 });
        res.json({ logs: logs.toString('utf8') });
    } catch (e) {
        res.json({ logs: 'Aguardando inicialización del túnel...' });
    }
});

// --- Ver Stats (Para la gráfica de RAM/CPU) ---
app.get('/api/server/stats', async (req, res) => {
    res.json({ cpu: '1%', ram: '1024' });
});

// === WEBSOCKETS (Consola en Vivo) ===
io.use(async (socket, next) => {
    try {
        const token = socket.handshake.auth?.token || socket.handshake.query?.token;
        if (!token) return next(new Error('Token requerido'));
        socket.user = await admin.auth().verifyIdToken(token);
        next();
    } catch (error) {
        next(new Error('Token inválido o expirado'));
    }
});

io.on('connection', async (socket) => {
    console.log('🌐 Cliente conectado al WebSocket (Consola)');

    const serverId = socket.handshake.query.serverId;
    if (serverId) {
        const db = loadDB();
        const userServers = db[socket.user.uid]?.servers || [];
        if (!userServers.some(serverRecord => serverRecord.id === serverId)) {
            socket.emit('error', 'No tenés permisos para ver este servidor.');
            return socket.disconnect(true);
        }

        try {
            const container = docker.getContainer(`mc-${serverId}`);
            const logStream = await container.logs({ follow: true, stdout: true, stderr: true, tail: 100 });
            logStream.on('data', chunk => socket.emit('log', chunk.toString('utf8')));
        } catch (e) {}
    }

    socket.on('disconnect', () => {
        console.log('🔌 Cliente desconectado');
    });
});

// === INICIO DEL SERVIDOR ===
const PORT = process.env.PORT || 3000;
server.listen(PORT, () => {
    console.log(`\n=================================================`);
    console.log(`🚀 DEMONIO PROSERVERS (Node.js) INICIADO`);
    console.log(`📡 Escuchando en el puerto: ${PORT}`);
    console.log(`🔒 Modo de seguridad híbrida: ACTIVADO`);
    console.log(`🐳 Docker socket: /var/run/docker.sock`);
    console.log(`=================================================\n`);
});