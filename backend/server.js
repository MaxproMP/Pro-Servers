const express = require('express');
const http = require('http');
const { Server } = require('socket.io');
const cors = require('cors');
const Docker = require('dockerode');
const fs = require('fs');
const path = require('path');

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

app.use('/api/project', verificarTokenFirebase);
app.use('/api/server', verificarTokenFirebase);
app.use('/api/curseforge', verificarTokenFirebase);

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
function getServerEnv(payload, port) {
    const env = [
        'EULA=TRUE',
        `MOTD=${payload.motd || 'Professional Server'}`,
        `SERVER_PORT=${port}`,
    ];

    if (payload.edition === 'bedrock') {
        env.push(`SERVER_NAME=${payload.projectName || 'ProServer'}`);
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
        };
        env.push(`TYPE=${typeMap[payload.software] || 'VANILLA'}`);

        if (payload.version && payload.version !== 'LATEST') {
            env.push(`VERSION=${payload.version}`);
        }

        if (payload.software === 'Server Pack' && payload.curseforgeFileId) {
            env.push(`CF_PAGE_URL=https://www.curseforge.com/minecraft/modpacks`);
            env.push(`CF_FILE_ID=${payload.curseforgeFileId}`);
        }

        if (payload.modpackUrl) {
            env.push(`GENERIC_PACK=${payload.modpackUrl}`);
        }

        // Memoria por defecto
        env.push('MEMORY=1G');
        env.push('ONLINE_MODE=FALSE');
    }

    return env;
}

// ============================================================
// === RUTAS DEL DEMONIO (API para el Frontend) ===
// ============================================================

// --- Health Check ---
app.get('/ping', (req, res) => {
    res.json({ status: '🟢 Demonio Node.js en línea, blindado y esperando órdenes.' });
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

    const db = loadDB();
    const serverId = generateId();
    const port = getAvailablePort(db);

    if (!port) {
        return res.status(503).json({ success: false, message: 'No hay puertos disponibles. Contactá a soporte.' });
    }

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
        name: projectName || `Server-${serverId.substring(4, 10)}`,
        edition,
        software: software || 'vanilla',
        version: version || 'LATEST',
        port,
        containerName,
        status: 'deploying',
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
        const envVars = getServerEnv(req.body, port);
        const internalPort = edition === 'bedrock' ? '19132/udp' : '25565/tcp';

        // Intentar pull de la imagen de MC
        deployStatus[serverId] = { step: 'Descargando imagen del servidor...', pct: 35, done: false, error: null };
        try {
            await new Promise((resolve, reject) => {
                docker.pull(image, (err, stream) => {
                    if (err) return reject(err);
                    docker.modem.followProgress(stream, (err) => err ? reject(err) : resolve());
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
                Memory: 1024 * 1024 * 1024, // 1GB RAM
                NanoCpus: 1000000000,        // 1 CPU
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

    } catch (deployErr) {
        console.error(`[DEPLOY] ❌ Error desplegando ${serverId}:`, deployErr.message);
        deployStatus[serverId] = { step: 'Error en el deploy', pct: 100, done: true, error: deployErr.message };

        // Marcar como error en DB
        const dbErr = loadDB();
        if (dbErr[uid]) {
            const srv = dbErr[uid].servers.find(s => s.id === serverId);
            if (srv) { srv.status = 'error'; srv.error = deployErr.message; saveDB(dbErr); }
        }
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

    return res.json({ step: 'Deploy en progreso...', pct: 60, done: false, error: null });
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