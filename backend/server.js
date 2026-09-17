const express = require('express');
const cors = require('cors');
const http = require('http');
const { Server } = require('socket.io');
const Docker = require('dockerode');
const fs = require('fs');
const path = require('path');
const multer = require('multer');
const AdmZip = require('adm-zip');
const { execSync } = require('child_process');
const { pipeline } = require('stream/promises');
require('dotenv').config();

// Firebase solo se usa para validar que el token que nos manda Laravel es válido
const { initializeApp, cert } = require('firebase-admin/app');
const { getAuth } = require('firebase-admin/auth');

const app = express();
const server = http.createServer(app);
const io = new Server(server, { cors: { origin: '*' } });
const docker = new Docker();

app.use(cors());
app.use(express.json({ limit: '10000mb' }));
app.use(express.urlencoded({ extended: true, limit: '10000mb' }));

console.log("=========================================");
console.log("\x1b[36m[PROSERVERS DAEMON] Inicializando...\x1b[0m");
if (process.env.CURSEFORGE_API_KEY) console.log("\x1b[32m[INIT] CurseForge API Key: DETECTADA OK\x1b[0m");
else console.log("\x1b[31m[INIT] CurseForge API Key: NO ENCONTRADA\x1b[0m");
console.log("=========================================");

let serviceAccount;
try {
    serviceAccount = require('./firebase-adminsdk.json');
    initializeApp({ credential: cert(serviceAccount) });
    console.log("\x1b[32m[SEGURIDAD] Firebase Admin SDK OK.\x1b[0m");
} catch (e) { console.warn("\x1b[31m[ALERTA] Fallo Firebase:\x1b[0m", e.message); }

// ==========================================
// MIDDLEWARES DE SEGURIDAD
// ==========================================
// Laravel o el Frontend nos mandan un JWT. Node lo valida contra Firebase para saber quién está pidiendo la acción.
const verifyToken = async (req, res, next) => {
    let idToken = (req.body && req.body.token) || (req.query && req.query.token);
    const authHeader = req.headers.authorization;
    if (authHeader && authHeader.startsWith('Bearer ')) {
        idToken = authHeader.split(' ')[1];
    }

    if (!idToken) return res.status(401).json({ error: 'Acceso denegado. Token no proporcionado.' });
    try {
        req.user = await getAuth().verifyIdToken(idToken);
        next();
    }
    catch (error) {
        return res.status(403).json({ error: 'Token inválido o expirado.' });
    }
};

// ==========================================
// FUNCIONES DE ARCHIVOS
// ==========================================
function getSafePath(serverId, reqPath) {
    const baseDir = path.join(__dirname, 'servers', serverId);
    if (!fs.existsSync(baseDir)) fs.mkdirSync(baseDir, { recursive: true });
    const targetPath = path.join(baseDir, (reqPath || '/').replace(/\.\./g, '').replace(/^\/+/, ''));
    if (!targetPath.startsWith(baseDir)) return null; return targetPath;
}

const storage = multer.diskStorage({
    destination: (req, file, cb) => cb(null, getSafePath((req.body && req.body.serverId) || (req.query && req.query.serverId), '/')),
    filename: (req, file, cb) => cb(null, file.originalname)
});
const upload = multer({ storage });

function pullImageAsync(imageName) {
    return new Promise((resolve) => {
        docker.pull(imageName, (err, stream) => {
            if (err) return resolve(false);
            docker.modem.followProgress(stream, () => resolve(true));
        });
    });
}

function extractZipSafely(zipFilePath, targetFolder) {
    try { execSync(`unzip -o "${zipFilePath}" -d "${targetFolder}"`, { stdio: 'ignore' }); return true; }
    catch (e) { try { const zip = new AdmZip(zipFilePath); zip.extractAllTo(targetFolder, true); return true; } catch (err) { return false; } }
}

function extraerIdDeDrive(url) {
    const match = url.match(/drive\.google\.com\/(?:file\/d\/|open\?id=|uc\?id=)([a-zA-Z0-9_-]+)/);
    return match ? match[1] : null;
}

async function fetchDescarga(url) {
    if (url.includes('mediafire.com')) {
        const c1 = new AbortController(); const t1 = setTimeout(() => c1.abort(), 30000);
        const response = await fetch(url, { signal: c1.signal }); clearTimeout(t1);
        const html = await response.text();
        const match = html.match(/href="([^"]+)"\s+id="downloadButton"/i);
        if (!match || !match[1]) throw new Error('No directo');
        const c2 = new AbortController(); const t2 = setTimeout(() => c2.abort(), 180000);
        const finalResponse = await fetch(match[1], { signal: c2.signal }); clearTimeout(t2);
        return finalResponse;
    }
    const driveId = extraerIdDeDrive(url);
    if (driveId) {
        const baseUrl = `https://drive.google.com/uc?export=download&id=${driveId}`;
        const c1 = new AbortController(); const t1 = setTimeout(() => c1.abort(), 60000);
        let response = await fetch(baseUrl, { headers: { 'User-Agent': 'Mozilla/5.0' }, signal: c1.signal });
        clearTimeout(t1);
        if ((response.headers.get('content-type') || '').includes('text/html')) {
            const html = await response.text(); const cookies = response.headers.get('set-cookie') || '';
            let confirmToken = 't'; const confirmMatch = html.match(/confirm=([0-9A-Za-z_-]+)/);
            if (confirmMatch) confirmToken = confirmMatch[1];
            const c2 = new AbortController(); const t2 = setTimeout(() => c2.abort(), 180000);
            response = await fetch(`${baseUrl}&confirm=${confirmToken}`, { headers: { 'User-Agent': 'Mozilla/5.0', 'Cookie': cookies }, signal: c2.signal });
            clearTimeout(t2);
        }
        return response;
    }
    const c = new AbortController(); const t = setTimeout(() => c.abort(), 60000);
    const response = await fetch(url, { signal: c.signal }); clearTimeout(t);
    return response;
}

const deployProgress = {};

// ==========================================
// GESTIÓN DE DOCKER (CONTENEDORES Y NODOS)
// ==========================================

app.post('/api/project/create', verifyToken, async (req, res) => {
    req.setTimeout(300000);
    // Toda la lógica de "si tiene plan o no" ahora la debe hacer Laravel. 
    // Node.js confía ciegamente en lo que le pide Laravel y simplemente ejecuta Docker.
    const { serverId, edition, motd, software, version, modpackUrl, curseforgeModpackId, curseforgeFileId, ramBytes, maxPlayers } = req.body;
    
    try {
        const serverPath = path.join(__dirname, 'servers', serverId);
        if (!fs.existsSync(serverPath)) fs.mkdirSync(serverPath, { recursive: true });

        let mcImage = 'itzg/minecraft-server';
        let envVars = ['EULA=TRUE', `MOTD=${motd}`, `MEMORY=${ramBytes / (1024 * 1024)}M`, 'ENABLE_RCON=TRUE', 'JAVA_TOOL_OPTIONS=-Dnetty.transport=epoll'];
        if (maxPlayers !== -1) envVars.push(`MAX_PLAYERS=${maxPlayers}`);

        const isModpack = !!(modpackUrl || curseforgeModpackId);

        if (edition === 'bedrock') {
            if (software === 'pocketmine') mcImage = 'pmmp/pocketmine-mp:latest';
            else { mcImage = 'itzg/minecraft-bedrock-server'; envVars.push(software === 'preview' ? 'VERSION=PREVIEW' : 'VERSION=LATEST'); }
        } else {
            if (!isModpack) { envVars.push(`VERSION=${version}`); envVars.push(`TYPE=${software === 'snapshot' ? 'VANILLA' : software.toUpperCase()}`); }
        }

        deployProgress[serverId] = { step: "Conectando al demonio de Docker...", pct: 5, done: false, error: null };
        res.json({ success: true, message: "Iniciando contenedor..." });

        setImmediate(async () => {
            try {
                let finalModpackUrl = modpackUrl;
                if (curseforgeModpackId && curseforgeFileId) {
                    deployProgress[serverId] = { step: "Contactando CurseForge...", pct: 15, done: false, error: null };
                    const cfRes = await fetch(`https://api.curseforge.com/v1/mods/${curseforgeModpackId}/files/${curseforgeFileId}`, { headers: { 'Accept': 'application/json', 'x-api-key': process.env.CURSEFORGE_API_KEY } });
                    const cfData = await cfRes.json();
                    if (cfData.data && cfData.data.downloadUrl) finalModpackUrl = cfData.data.downloadUrl;
                    else throw new Error("Descarga bloqueada por el autor.");
                }

                if (finalModpackUrl) {
                    deployProgress[serverId] = { step: "Descargando Server Pack...", pct: 30, done: false, error: null };
                    const response = await fetchDescarga(finalModpackUrl);
                    deployProgress[serverId] = { step: "Guardando ZIP...", pct: 50, done: false, error: null };
                    const tempZipPath = path.join(serverPath, 'temp_pack.zip');
                    const fileStream = fs.createWriteStream(tempZipPath);
                    await pipeline(response.body, fileStream);
                    deployProgress[serverId] = { step: "Descomprimiendo archivos...", pct: 65, done: false, error: null };
                    if (!extractZipSafely(tempZipPath, serverPath)) throw new Error("Archivo ZIP corrupto.");
                    if (fs.existsSync(tempZipPath)) fs.unlinkSync(tempZipPath);
                    const visibleFiles = fs.readdirSync(serverPath).filter(f => !f.startsWith('.'));
                    if (visibleFiles.length === 1 && fs.statSync(path.join(serverPath, visibleFiles[0])).isDirectory()) {
                        const singleItem = path.join(serverPath, visibleFiles[0]);
                        fs.readdirSync(singleItem).forEach(f => fs.renameSync(path.join(singleItem, f), path.join(serverPath, f)));
                        fs.rmdirSync(singleItem);
                    }
                    deployProgress[serverId] = { step: "Instalando motor...", pct: 75, done: false, error: null };
                    let engineToUse = software;
                    if (software === 'Server Pack' || software === 'Server Pack Oficial') {
                        const allFilesString = fs.readdirSync(serverPath).join(' ').toLowerCase();
                        engineToUse = 'forge';
                        if (allFilesString.includes('fabric')) engineToUse = 'fabric';
                        else if (allFilesString.includes('neoforge')) engineToUse = 'neoforge';
                        else if (allFilesString.includes('quilt')) engineToUse = 'quilt';
                    }
                    ['start.bat', 'start.sh', 'run.bat', 'run.sh', 'user_jvm_args.txt'].forEach(scr => {
                        if (fs.existsSync(path.join(serverPath, scr))) fs.unlinkSync(path.join(serverPath, scr));
                    });
                    envVars = envVars.filter(e => !e.startsWith('TYPE=') && !e.startsWith('VERSION='));
                    envVars.push(`TYPE=${engineToUse.toUpperCase()}`);
                    if (version && version !== 'Auto' && version !== 'LATEST') envVars.push(`VERSION=${version}`);
                }

                deployProgress[serverId] = { step: "Verificando imágenes Docker...", pct: 85, done: false, error: null };
                await pullImageAsync(mcImage); await pullImageAsync('pepaondrugs/playitgg-docker:latest');
                deployProgress[serverId] = { step: "Levantando contenedor en la infraestructura...", pct: 95, done: false, error: null };

                const mcContainer = await docker.createContainer({
                    Image: mcImage, name: `mc-${serverId}`, Env: envVars,
                    HostConfig: { Memory: ramBytes, MemorySwap: ramBytes, Binds: [`/home/ubuntu/Proservers/servers/${serverId}:/data`], Dns: ['8.8.8.8', '8.8.4.4'] }
                });
                await mcContainer.start();
                const playitContainer = await docker.createContainer({ Image: 'pepaondrugs/playitgg-docker:latest', name: `playit-${serverId}`, HostConfig: { NetworkMode: `container:mc-${serverId}` } });
                await playitContainer.start();
                deployProgress[serverId] = { step: "¡Nodo Inicializado con Éxito!", pct: 100, done: true, error: null };
            } catch (err) {
                deployProgress[serverId] = { step: "Despliegue Abortado", pct: 0, done: true, error: err.message };
            }
        });
    } catch (e) { res.status(500).json({ error: "Fallo interno de comunicación con Docker Daemon." }); }
});

app.get('/api/project/deploy-status', verifyToken, (req, res) => {
    const { serverId } = req.query;
    if (!serverId || !deployProgress[serverId]) return res.json({ step: "Aguardando conexión...", pct: 0, done: false, error: null });
    res.json(deployProgress[serverId]);
    if (deployProgress[serverId].done) setTimeout(() => delete deployProgress[serverId], 10000);
});

app.post('/api/project/delete', verifyToken, async (req, res) => {
    try {
        const mc = docker.getContainer(`mc-${req.body.serverId}`);
        await mc.stop().catch(() => { }); await mc.remove({ force: true, v: true }).catch(() => { });
        const playit = docker.getContainer(`playit-${req.body.serverId}`);
        await playit.stop().catch(() => { }); await playit.remove({ force: true, v: true }).catch(() => { });
        const sPath = path.join(__dirname, 'servers', req.body.serverId);
        if (fs.existsSync(sPath)) fs.rmSync(sPath, { recursive: true, force: true });
        
        res.json({ success: true, message: "Contenedor y volúmenes destruidos." });
    } catch (e) { res.status(500).json({ error: "Fallo al comunicar con Docker Daemon" }); }
});

app.get('/api/server/status', verifyToken, async (req, res) => {
    try {
        const data = await docker.getContainer(`mc-${req.query.serverId}`).inspect();
        res.json({ status: data.State.Running ? 'on' : 'off', isPaused: data.State.Paused });
    } catch (e) { res.json({ status: 'off', isPaused: false }); }
});

app.post('/api/server/start', verifyToken, async (req, res) => {
    try {
        const mc = docker.getContainer(`mc-${req.body.serverId}`);
        if (!(await mc.inspect()).State.Running) await mc.start();
        const playit = docker.getContainer(`playit-${req.body.serverId}`);
        if (!(await playit.inspect()).State.Running) await playit.start();
        res.json({ success: true });
    } catch (e) { res.status(500).json({ error: e.message }); }
});

app.post('/api/server/stop', verifyToken, async (req, res) => {
    try {
        const mc = docker.getContainer(`mc-${req.body.serverId}`);
        if ((await mc.inspect()).State.Running) await mc.stop();
        const playit = docker.getContainer(`playit-${req.body.serverId}`);
        if ((await playit.inspect()).State.Running) await playit.stop();
        res.json({ success: true });
    } catch (e) { res.status(500).json({ error: e.message }); }
});

app.post('/api/server/restart', verifyToken, async (req, res) => {
    try {
        await docker.getContainer(`mc-${req.body.serverId}`).restart();
        await docker.getContainer(`playit-${req.body.serverId}`).restart().catch(() => { });
        res.json({ success: true });
    } catch (e) { res.status(500).json({ error: e.message }); }
});

app.post('/api/server/command', verifyToken, async (req, res) => {
    try {
        const exec = await docker.getContainer(`mc-${req.body.serverId}`).exec({ Cmd: ['rcon-cli', req.body.command], AttachStdout: true });
        exec.start(() => res.json({ success: true }));
    } catch (e) { res.status(500).json({ error: "Fallo en RCON." }); }
});

app.get('/api/server/stats', verifyToken, async (req, res) => {
    try {
        const stats = await docker.getContainer(`mc-${req.query.serverId}`).stats({ stream: false });
        const cpuDelta = stats.cpu_stats.cpu_usage.total_usage - stats.precpu_stats.cpu_usage.total_usage;
        const systemDelta = stats.cpu_stats.system_cpu_usage - stats.precpu_stats.system_cpu_usage;
        let cpu = (systemDelta > 0 && cpuDelta > 0) ? ((cpuDelta / systemDelta) * stats.cpu_stats.online_cpus * 100).toFixed(1) : 0;
        res.json({ cpu: `${cpu}%`, ram: (stats.memory_stats.usage / (1024 * 1024)).toFixed(2) });
    } catch (e) { res.json({ cpu: '0%', ram: '0' }); }
});

app.get('/api/server/players', verifyToken, async (req, res) => {
    try {
        const exec = await docker.getContainer(`mc-${req.query.serverId}`).exec({ Cmd: ['rcon-cli', 'list'], AttachStdout: true });
        exec.start((err, stream) => {
            if (err) return res.json({ players: [] });
            let output = '';
            stream.on('data', chunk => output += chunk.toString());
            stream.on('end', () => {
                const parts = output.replace(/\u001b\[[0-9;]*m/g, '').split(':');
                const players = (parts.length > 1 && parts[1].trim() !== '') ? parts[1].split(',').map(n => ({ name: n.trim(), avatar: `https://minotar.net/helm/${n.trim()}/100.png` })) : [];
                res.json({ players });
            });
        });
    } catch (e) { res.json({ players: [] }); }
});

app.get('/api/server/playitlogs', verifyToken, async (req, res) => {
    try {
        const logs = await docker.getContainer(`playit-${req.query.serverId}`).logs({ stdout: true, stderr: true, tail: 50 });
        res.json({ logs: logs.toString('utf8').replace(/[\u0000-\u0009\u000b-\u001f\u007f-\u009f]/g, '') });
    } catch (e) { res.json({ logs: "Conectando al túnel..." }); }
});

// ==========================================
// GESTOR DE ARCHIVOS Y MUNDOS
// ==========================================

app.post('/api/files/upload', verifyToken, upload.single('file'), (req, res) => {
    try {
        const serverPath = getSafePath(req.body.serverId, '/');
        if (req.file.originalname.endsWith('.zip')) {
            extractZipSafely(req.file.path, serverPath); fs.unlinkSync(req.file.path); res.json({ success: true, message: "Modpack descomprimido en el Nodo." });
        } else if (req.file.originalname.endsWith('.jar')) {
            const mPath = getSafePath(req.body.serverId, '/mods');
            if (!fs.existsSync(mPath)) fs.mkdirSync(mPath, { recursive: true });
            fs.renameSync(req.file.path, path.join(mPath, req.file.originalname));
            res.json({ success: true, message: "Mod inyectado en el Contenedor." });
        } else res.json({ success: true });
    } catch (e) { res.status(500).json({ error: "Fallo al escribir en el disco del Nodo." }); }
});

app.get('/api/files/list', verifyToken, (req, res) => {
    try {
        const sPath = req.query.path === '/' ? '' : req.query.path;
        const tPath = getSafePath(req.query.serverId, sPath);
        if (!tPath || !fs.existsSync(tPath)) return res.json([]);
        res.json(fs.readdirSync(tPath, { withFileTypes: true }).map(f => ({ name: f.name, isDir: f.isDirectory(), path: path.posix.join(sPath || '/', f.name) })));
    } catch (e) { res.status(500).json({ error: "Fallo I/O." }); }
});

app.get('/api/files/content', verifyToken, (req, res) => {
    try {
        const tPath = getSafePath(req.query.serverId, req.query.path);
        if (!tPath || !fs.existsSync(tPath)) return res.status(404).json({ error: "Archivo inexistente en el volumen" });
        res.json({ content: fs.readFileSync(tPath, 'utf8') });
    } catch (e) { res.status(500).json({ error: "No se puede leer este archivo binario." }); }
});

app.post('/api/files/save', verifyToken, (req, res) => {
    try {
        const tPath = getSafePath(req.body.serverId, req.body.path);
        if (fs.existsSync(tPath)) fs.copyFileSync(tPath, `${tPath}.bak`);
        fs.writeFileSync(tPath, req.body.content, 'utf8');
        res.json({ success: true });
    } catch (e) { res.status(500).json({ error: "Fallo de escritura en el volumen de Docker." }); }
});

app.post('/api/files/delete', verifyToken, (req, res) => {
    try {
        const tPath = getSafePath(req.body.serverId, req.body.path);
        if (fs.statSync(tPath).isDirectory()) fs.rmSync(tPath, { recursive: true, force: true });
        else fs.unlinkSync(tPath);
        res.json({ success: true });
    } catch (e) { res.status(500).json({ error: "El archivo está bloqueado por el servidor." }); }
});

// ==========================================
// CONEXIÓN EN VIVO (WEBSOCKETS)
// ==========================================

io.use(async (socket, next) => {
    if (!socket.handshake.query.token) return next(new Error('Sin token de autorización'));
    try { 
        socket.user = await getAuth().verifyIdToken(socket.handshake.query.token); 
        next(); 
    }
    catch (err) { next(new Error('Token rechazado o expirado.')); }
});

io.on('connection', async (socket) => {
    if (socket.user) socket.join(`user_${socket.user.uid}`);

    const serverId = socket.handshake.query.serverId;
    if (!serverId || serverId === 'undefined' || serverId === 'null') return socket.disconnect();

    try {
        // Enlazar la terminal del contenedor al socket del cliente
        const container = docker.getContainer(`mc-${serverId}`);
        let logStream = null;
        container.inspect(async (err, data) => {
            if (err || !data.State.Running) return;
            try {
                logStream = await container.logs({ follow: true, stdout: true, stderr: true, tail: 100 });
                logStream.on('data', chunk => socket.emit('log', chunk.toString('utf8')));
            } catch (e) { }
        });
        socket.on('disconnect', () => { if (logStream) logStream.destroy(); });
    } catch (e) { socket.disconnect(); }
});

server.listen(3000, () => {
    console.log('\x1b[32m[Professional Servers] Demonio de Infraestructura v0.9 (Docker/Archivos) Listo en Puerto 3000.\x1b[0m');
});