const express = require('express');
const cors = require('cors');
const http = require('http');
const { Server } = require('socket.io');
const Docker = require('dockerode');
const fs = require('fs');
const path = require('path');
const archiver = require('archiver');
const multer = require('multer');
const AdmZip = require('adm-zip');
const { execSync } = require('child_process');
const { pipeline } = require('stream/promises');
const crypto = require('crypto');
require('dotenv').config();

const { initializeApp, cert } = require('firebase-admin/app');
const { getAuth } = require('firebase-admin/auth');
const { connectDatabases, User, IpInfo, getSqlPool } = require('./databases');

const app = express();
const server = http.createServer(app);
const io = new Server(server, { cors: { origin: '*' } });
const docker = new Docker();

app.use(cors());
app.use(express.json({ limit: '10000mb' }));
app.use(express.urlencoded({ extended: true, limit: '10000mb' }));

// ==========================================
// DICCIONARIO DE PLANES Y PRECIOS
// ==========================================
const PLAN_LIMITS = {
    'redstone': { name: 'Plan Redstone', ram: '8G', ramBytes: 8589934592, slots: 10, maxServers: 1, fileManager: false, price: 6.00 },
    'hierro': { name: 'Plan Hierro', ram: '12G', ramBytes: 12884901888, slots: 20, maxServers: 2, fileManager: false, price: 9.00 },
    'cobre': { name: 'Plan Cobre', ram: '16G', ramBytes: 17179869184, slots: 25, maxServers: 2, fileManager: false, price: 12.00 },
    'oro': { name: 'Plan Oro', ram: '16G', ramBytes: 17179869184, slots: 40, maxServers: 3, fileManager: true, price: 15.00 },
    'diamante': { name: 'Plan Diamante', ram: '32G', ramBytes: 34359738368, slots: 100, maxServers: 6, fileManager: true, price: 25.00 },
    'netherite': { name: 'Plan Netherite', ram: '64G', ramBytes: 68719476736, slots: 250, maxServers: 12, fileManager: true, price: 45.00 },
    'ghost-warrior': { name: 'Ghost Warrior', ram: '128G', ramBytes: 137438953472, slots: -1, maxServers: -1, fileManager: true, price: 85.00 },
    'enterprise': { name: 'Professional Enterprise', ram: '256G', ramBytes: 274877906944, slots: -1, maxServers: -1, fileManager: true, price: 0 },
    'ceo': { name: 'Plan ProServers CEO', ram: '∞', ramBytes: 999999999999, slots: -1, maxServers: -1, fileManager: true, price: 0 },
    'banned': { name: 'BANEADO', ram: '0G', ramBytes: 0, slots: 0, maxServers: 0, fileManager: false, price: 0 }
};

const AZURE_NODES = [
    { id: 'nodo-principal', type: 'local', instance: docker }
];

console.log("=========================================");
if (process.env.CURSEFORGE_API_KEY) console.log("\x1b[32m[INIT] CurseForge API Key: DETECTADA OK\x1b[0m");
else console.log("\x1b[31m[INIT] CurseForge API Key: NO ENCONTRADA\x1b[0m");
console.log("=========================================");

let serviceAccount;
try {
    serviceAccount = require('./firebase-adminsdk.json');
    initializeApp({ credential: cert(serviceAccount) });
    console.log("\x1b[32m[SEGURIDAD] Firebase Admin OK.\x1b[0m");
} catch (e) { console.warn("\x1b[31m[ALERTA] Fallo Firebase:\x1b[0m", e.message); }

const verifyToken = async (req, res, next) => {
    let idToken = req.body.token || req.query.token;
    const authHeader = req.headers.authorization;
    if (authHeader && authHeader.startsWith('Bearer ')) idToken = authHeader.split(' ')[1];

    if (!idToken) return res.status(401).json({ error: 'Acceso denegado.' });
    try { req.user = await getAuth().verifyIdToken(idToken); next(); }
    catch (error) { return res.status(403).json({ error: 'Token inválido.' }); }
};

const isAdmin = async (req, res, next) => {
    if (req.user.email === 'rodasmaximo51@gmail.com') return next();
    try {
        const user = await User.findOne({ uid: req.user.uid });
        if (user && user.role === 'soporte') return next();
    } catch(e) {}
    res.status(403).json({ error: "Seguridad: Acceso denegado." });
};

const isStrictCEO = (req, res, next) => {
    if (req.user.email === 'rodasmaximo51@gmail.com') return next();
    res.status(403).json({ error: "Seguridad: Solo Fundador." });
};

// ==========================================
// MIDDLEWARE DE ACCESO Y ZONA DE HIELO (ACTUALIZADO PARA PERMISOS)
// ==========================================
const requireAccess = async (req, res, next) => {
    const uid = req.user.uid; const email = req.user.email;
    const serverId = req.body.serverId || req.query.serverId;
    if (!serverId) return res.status(400).json({ error: "Falta serverId." });

    try {
        const userWithServer = await User.findOne({ "servers.id": serverId });
        if (!userWithServer) return res.status(404).json({ error: "No encontrado." });
        const srv = userWithServer.servers.find(s => s.id === serverId);

        if (email === 'rodasmaximo51@gmail.com') {
            req.serverOwnerUid = userWithServer.uid;
            req.guestPermissions = ['all'];
            return next();
        }

        const callingUser = await User.findOne({ uid: uid });
        if (callingUser && callingUser.role === 'soporte') {
            req.serverOwnerUid = userWithServer.uid;
            req.guestPermissions = ['all'];
            return next();
        }

        if (userWithServer.uid !== uid) {
            // Lógica de compatibilidad para array de strings viejo o array de objetos nuevo
            const isSharedObj = srv.sharedWith && srv.sharedWith.find(e => (typeof e === 'string' ? e === email : e.email === email));
            if (!isSharedObj) {
                return res.status(403).json({ error: "Sin acceso." });
            }
            req.guestPermissions = typeof isSharedObj === 'string' ? ['power', 'console'] : isSharedObj.permissions;
        } else {
            req.guestPermissions = ['all'];
        }

        if (srv.isPaused && req.method === 'POST') {
            return res.status(403).json({ error: "Nodo Congelado. Funciones bloqueadas por administración." });
        }

        req.serverOwnerUid = userWithServer.uid;
        next();
    } catch (e) { res.status(500).json({ error: "Error de validación." }); }
};

const requireFeature = (feature) => {
    return async (req, res, next) => {
        if (req.user.email === 'rodasmaximo51@gmail.com') return next();
        try {
            const callingUser = await User.findOne({ uid: req.user.uid });
            if (callingUser && callingUser.role === 'soporte') return next();

            const targetUid = req.serverOwnerUid || req.user.uid;
            const user = await User.findOne({ uid: targetUid });
            const pDetails = PLAN_LIMITS[user ? user.plan : 'redstone'] || PLAN_LIMITS['redstone'];
            if (!pDetails[feature]) return res.status(403).json({ error: `Bloqueado. Requiere plan superior.` });
            next();
        } catch (e) { res.status(500).json({ error: "Error feature." }); }
    };
};

function getSafePath(serverId, reqPath) {
    const baseDir = path.join(__dirname, 'servers', serverId);
    if (!fs.existsSync(baseDir)) fs.mkdirSync(baseDir, { recursive: true });
    const targetPath = path.join(baseDir, (reqPath || '/').replace(/\.\./g, '').replace(/^\/+/, ''));
    if (!targetPath.startsWith(baseDir)) return null; return targetPath;
}

const storage = multer.diskStorage({
    destination: (req, file, cb) => cb(null, getSafePath(req.body.serverId || req.query.serverId, '/')),
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
const activeSupportChats = [];

app.post('/api/checkout/astropay', verifyToken, async (req, res) => {
    try {
        const planSeleccionado = (req.body.plan || '').toLowerCase();
        const ciclo = parseInt(req.body.ciclo) || 1;

        if (!PLAN_LIMITS[planSeleccionado]) return res.status(400).send('Plan inválido');

        const descuentos = { 1: 0, 3: 10, 6: 15, 12: 20 };
        const descuento = descuentos[ciclo] || 0;
        const precioBase = PLAN_LIMITS[planSeleccionado].price;
        const totalACobrar = Number(((precioBase * (1 - (descuento / 100))) * ciclo).toFixed(2));

        const transactionId = 'PRO-' + crypto.randomBytes(4).toString('hex').toUpperCase();

        const pool = getSqlPool();
        if (pool) {
            await pool.request().query(`
                INSERT INTO Suscripciones (firebase_uid, plan_nombre, ciclo_meses, estado)
                VALUES ('${req.user.uid}', '${planSeleccionado}', ${ciclo}, 'pendiente')
            `);
            await pool.request().query(`
                INSERT INTO Pagos (firebase_uid, monto, metodo, estado, transaccion_id)
                VALUES ('${req.user.uid}', ${totalACobrar}, 'astropay', 'pendiente', '${transactionId}')
            `);
        }

        const clientId = process.env.ASTROPAY_CLIENT_ID;
        const secret = process.env.ASTROPAY_SECRET;
        if (!clientId || !secret) throw new Error("Credenciales de AstroPay no configuradas en el .env");

        const signature = crypto.createHmac('sha256', secret).update(clientId + transactionId).digest('hex');

        const response = await fetch('https://api.astropay.com/merchant/v1/invoices', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Merchant-Id': clientId,
                'Signature': signature
            },
            body: JSON.stringify({
                merchant_invoice_id: transactionId,
                amount: totalACobrar,
                currency: 'USD',
                callback_url: 'http://tu-dominio.com/api/webhooks/astropay'
            })
        });

        const data = await response.json();

        if (!response.ok) throw new Error(data.message || "Error AstroPay");

        res.redirect(data.url);

    } catch (e) {
        console.error("[Pagos] Error generando checkout AstroPay:", e.message);
        res.status(500).send('No se pudo generar el enlace de pago.');
    }
});

app.post('/api/checkout/manual', verifyToken, async (req, res) => {
    try {
        const planSeleccionado = (req.body.plan || '').toLowerCase();
        const ciclo = parseInt(req.body.ciclo) || 1;
        const transactionId = 'PRO-MANUAL-' + crypto.randomBytes(3).toString('hex').toUpperCase();

        const descuentos = { 1: 0, 3: 10, 6: 15, 12: 20 };
        const descuento = descuentos[ciclo] || 0;
        const totalACobrar = Number(((PLAN_LIMITS[planSeleccionado].price * (1 - (descuento / 100))) * ciclo).toFixed(2));

        const pool = getSqlPool();
        if (pool) {
            await pool.request().query(`
                INSERT INTO Suscripciones (firebase_uid, plan_nombre, ciclo_meses, estado)
                VALUES ('${req.user.uid}', '${planSeleccionado}', ${ciclo}, 'pendiente_transferencia')
            `);
            await pool.request().query(`
                INSERT INTO Pagos (firebase_uid, monto, metodo, estado, transaccion_id)
                VALUES ('${req.user.uid}', ${totalACobrar}, 'transferencia_cvu', 'pendiente', '${transactionId}')
            `);
        }
        res.json({ success: true, transactionId, message: "Intención de pago registrada. Esperando comprobante." });
    } catch (e) {
        res.status(500).json({ error: "Error registrando pago manual" });
    }
});

app.post('/api/internal/deploy', async (req, res) => {
    const authHeader = req.headers.authorization;
    if (!authHeader || authHeader !== `Bearer ${process.env.NODE_SECRET_KEY}`) {
        return res.status(403).json({ error: 'Acceso denegado. Token inválido.' });
    }

    const { firebase_uid, plan } = req.body;

    if (!firebase_uid || !plan) {
        return res.status(400).json({ error: 'Faltan datos (firebase_uid o plan).' });
    }

    try {
        console.log(`\x1b[32m[DEPLOY]\x1b[0m Iniciando despliegue de servidor para UID: ${firebase_uid} (Plan: ${plan})`);

        const pool = getSqlPool();
        if (pool) {
            await pool.request().query(`UPDATE users SET plan_activo = '${plan}' WHERE firebase_uid = '${firebase_uid}'`);
        }

        let user = await User.findOne({ uid: firebase_uid });
        if (user) {
            user.plan = plan;
            await user.save();
        }

        console.log(`\x1b[32m[DEPLOY]\x1b[0m Servidor de ${firebase_uid} actualizado con éxito.`);
        return res.status(200).json({ status: 'success', message: 'Servidor desplegado correctamente.' });

    } catch (error) {
        console.error('\x1b[31m[DEPLOY ERROR]\x1b[0m Fallo al levantar el servidor:', error);
        return res.status(500).json({ error: 'Fallo interno al desplegar el contenedor en Node.' });
    }
});

app.post('/api/admin/staff/add', verifyToken, isStrictCEO, async (req, res) => {
    try {
        const { emailToStaff } = req.body;
        if(!emailToStaff) return res.status(400).json({error: "Falta correo"});

        let user = await User.findOne({ email: emailToStaff });
        if (!user) {
            const authRecord = await getAuth().getUserByEmail(emailToStaff).catch(() => null);
            if (!authRecord) return res.status(404).json({error: "El usuario no existe en Firebase"});
            user = new User({ uid: authRecord.uid, email: emailToStaff, role: 'soporte', plan: 'redstone', servers: [] });
        } else {
            user.role = 'soporte';
        }
        await user.save();
        res.json({success: true, message: `Usuario ${emailToStaff} añadido al staff como soporte.`});
    } catch(e) {
        res.status(500).json({error: "Error interno: " + e.message});
    }
});

app.post('/api/admin/staff/remove', verifyToken, isStrictCEO, async (req, res) => {
    try {
        const { emailToRemove } = req.body;
        if(!emailToRemove) return res.status(400).json({error: "Falta correo"});
        
        let user = await User.findOne({ email: emailToRemove });
        if(user) {
            user.role = 'admin'; 
            await user.save();
        }
        res.json({success: true, message: "Rol de soporte revocado"});
    } catch(e) {
        res.status(500).json({error: "Error interno: " + e.message});
    }
});

app.get('/api/admin/staff/list', verifyToken, isAdmin, async (req, res) => {
    try {
        const staffUsers = await User.find({ role: 'soporte' }, 'email role');
        res.json({success: true, staff: staffUsers});
    } catch(e) {
        res.status(500).json({error: "Error listando staff"});
    }
});

app.post('/api/admin/global-broadcast', verifyToken, isStrictCEO, (req, res) => {
    const { message } = req.body;
    if (!message) return res.status(400).json({ error: "Mensaje vacío." });
    io.emit('global_broadcast', { message });
    res.json({ success: true, message: "Alerta global disparada." });
});

app.post('/api/admin/set-plan', verifyToken, isStrictCEO, async (req, res) => {
    const { targetUid, newPlan } = req.body;
    try {
        const user = await User.findOne({ uid: targetUid });
        if (!user) return res.status(404).json({ error: "Usuario no encontrado" });
        user.plan = newPlan;
        await user.save();
        io.to(`user_${targetUid}`).emit('plan_updated');
        res.json({ success: true });
    } catch (e) { res.status(500).json({ error: e.message }); }
});

app.post('/api/admin/server-action', verifyToken, isAdmin, async (req, res) => {
    const { serverId, action } = req.body;
    try {
        const container = docker.getContainer(`mc-${serverId}`);
        const user = await User.findOne({ "servers.id": serverId });
        if (!user) return res.status(404).json({ error: "Usuario no existe." });
        const srv = user.servers.find(s => s.id === serverId);

        if (action === 'pause') {
            srv.isPaused = true;
            user.markModified('servers');
            await user.save();
            try { await container.pause(); } catch (e) { }
            return res.json({ success: true, message: "Servidor Congelado." });
        }
        if (action === 'unpause') {
            srv.isPaused = false;
            user.markModified('servers');
            await user.save();
            try { await container.unpause(); } catch (e) { }
            return res.json({ success: true, message: "Servidor Descongelado." });
        }
        if (action === 'wipe') {
            if(req.user.email !== 'rodasmaximo51@gmail.com') return res.status(403).json({error: "Solo Fundador puede borrar mundos."});
            const sPath = path.join(__dirname, 'servers', serverId);
            ['world', 'world_nether', 'world_the_end'].forEach(f => {
                const p = path.join(sPath, f);
                if (fs.existsSync(p)) fs.rmSync(p, { recursive: true, force: true });
            });
            await container.restart().catch(() => { });
            return res.json({ success: true, message: "Mundo reseteado a cero." });
        }
        res.status(400).json({ error: "Acción no reconocida." });
    } catch (e) { res.status(500).json({ error: e.message }); }
});

app.post('/api/admin/ban-user', verifyToken, isStrictCEO, async (req, res) => {
    const { targetUid } = req.body;
    try {
        const user = await User.findOne({ uid: targetUid });
        if (user) {
            user.plan = 'banned';
            await user.save();
            for (let s of user.servers) {
                try {
                    await docker.getContainer(`mc-${s.id}`).stop().catch(() => { });
                    await docker.getContainer(`playit-${s.id}`).stop().catch(() => { });
                } catch (e) { }
            }
        }
        res.json({ success: true, message: "Usuario erradicado de la plataforma." });
    } catch (e) { res.status(500).json({ error: e.message }); }
});

app.post('/api/admin/system-prune', verifyToken, isStrictCEO, async (req, res) => {
    try {
        await docker.pruneContainers();
        await docker.pruneImages({ filters: { dangling: ['true'] } });
        res.json({ success: true, message: "VPS limpiado correctamente." });
    } catch (e) { res.status(500).json({ error: e.message }); }
});

app.post('/api/admin/global-rcon', verifyToken, isAdmin, async (req, res) => {
    const { command } = req.body;
    try {
        const containers = await docker.listContainers({ filters: { name: ['mc-'] } });
        for (let cInfo of containers) {
            try {
                const exec = await docker.getContainer(cInfo.Id).exec({ Cmd: ['rcon-cli', command], AttachStdout: true });
                exec.start(() => { });
            } catch (e) { }
        }
        res.json({ success: true, message: `Comando enviado a ${containers.length} nodos.` });
    } catch (e) { res.status(500).json({ error: e.message }); }
});

app.get('/api/admin/all-servers', verifyToken, isAdmin, async (req, res) => {
    try {
        const users = await User.find({});
        let serverPromises = [];
        users.forEach(u => {
            u.servers.forEach(s => {
                serverPromises.push((async () => {
                    let isRunning = false; let isPaused = s.isPaused || false; let cpuUsage = '0%'; let ramUsage = '0 MB';
                    try {
                        const container = docker.getContainer(`mc-${s.id}`);
                        const inspectData = await container.inspect();
                        isRunning = inspectData.State.Running;
                        if (inspectData.State.Paused) isPaused = true;

                        if (isRunning && !isPaused) {
                            const stats = await container.stats({ stream: false });
                            const cpuDelta = stats.cpu_stats.cpu_usage.total_usage - stats.precpu_stats.cpu_usage.total_usage;
                            const systemDelta = stats.cpu_stats.system_cpu_usage - stats.precpu_stats.system_cpu_usage;
                            let cpu = (systemDelta > 0 && cpuDelta > 0) ? ((cpuDelta / systemDelta) * stats.cpu_stats.online_cpus * 100).toFixed(1) : 0;
                            cpuUsage = `${cpu}%`; ramUsage = `${(stats.memory_stats.usage / (1024 * 1024)).toFixed(0)} MB`;
                        }
                    } catch (err) { }
                    return { ...s.toObject(), ownerUid: u.uid, ownerPlan: u.plan, ownerEmail: u.email, isRunning, isPaused, cpuUsage, ramUsage };
                })());
            });
        });
        res.json({ success: true, servers: await Promise.all(serverPromises) });
    } catch (e) { res.status(500).json({ error: "Error global." }); }
});

app.get('/api/admin/dashboard-stats', verifyToken, isStrictCEO, async (req, res) => {
    try {
        const pool = getSqlPool();
        const pagos = pool ? await pool.request().query('SELECT ISNULL(SUM(monto), 0) as total FROM Pagos') : { recordset: [{ total: 0 }] };
        const suscriptores = pool ? await pool.request().query('SELECT COUNT(*) as count FROM Suscripciones') : { recordset: [{ count: 0 }] };
        const totalUsers = await User.countDocuments();
        const allUsers = await User.find({});
        let totalServers = 0; allUsers.forEach(u => totalServers += u.servers.length);
        res.json({ dineroTotal: pagos.recordset[0].total, totalClientes: suscriptores.recordset[0].count, totalUsuariosMongo: totalUsers, totalServidores: totalServers });
    } catch (e) { res.status(500).json({ error: "Error en el dashboard." }); }
});

app.get('/api/admin/support-tickets', verifyToken, isAdmin, (req, res) => {
    res.json({ success: true, tickets: activeSupportChats });
});

app.post('/api/admin/support-reply', verifyToken, isAdmin, (req, res) => {
    const { uid, reply } = req.body;
    const adminName = req.user.email === 'rodasmaximo51@gmail.com' ? 'Administrador' : 'Soporte Técnico';
    const entry = { id: Date.now(), uid, email: adminName, message: reply, timestamp: new Date(), sender: 'admin' };
    activeSupportChats.push(entry);
    io.to(`user_${uid}`).emit('support_reply', { reply, sender: adminName });
    res.json({ success: true });
});

app.post('/api/admin/close-ticket', verifyToken, isAdmin, (req, res) => {
    const { uid } = req.body;
    for (let i = activeSupportChats.length - 1; i >= 0; i--) {
        if (activeSupportChats[i].uid === uid) {
            activeSupportChats.splice(i, 1);
        }
    }
    io.to(`user_${uid}`).emit('support_reply', { reply: "🔒 Un miembro del staff ha cerrado este ticket marcándolo como resuelto. Si necesitas más ayuda, envía un nuevo mensaje." });
    res.json({ success: true });
});

app.post('/api/support/chat', verifyToken, async (req, res) => {
    const { message, serverId } = req.body;
    const uid = req.user.uid;
    const email = req.user.email;

    const userEntry = { id: Date.now(), uid, email, message, serverId, timestamp: new Date(), sender: 'user' };
    activeSupportChats.push(userEntry);
    io.to('admin_room').emit('new_support_msg', userEntry);

    const GROQ_API_KEY = process.env.GROQ_API_KEY;
    if (!GROQ_API_KEY) {
        return res.json({ success: true, reply: "Mensaje recibido. Un miembro del staff te responderá pronto." });
    }

    try {
        const prompt = `Eres Mine, agente virtual de soporte de Professional Servers. Responde brevemente de forma amable y concisa a la siguiente consulta del cliente. Si requiere acción manual o cuenta, dile que el Staff revisará su caso: "${message}"`;
        const response = await fetch('https://api.groq.com/openai/v1/chat/completions', {
            method: 'POST',
            headers: { 'Authorization': `Bearer ${GROQ_API_KEY}`, 'Content-Type': 'application/json' },
            body: JSON.stringify({
                model: "openai/gpt-oss-120b",
                messages: [{ "role": "user", "content": prompt }]
            })
        });

        if (!response.ok) {
            const errBody = await response.text();
            console.error('[Support AI] Groq error:', response.status, errBody);
            throw new Error(`Fallo de API Groq (${response.status})`);
        }

        const data = await response.json();
        const botReply = data.choices[0].message.content;

        const aiEntry = { id: Date.now() + 1, uid, email: 'Mine AI', message: botReply, serverId, timestamp: new Date(), sender: 'ai' };
        activeSupportChats.push(aiEntry);
        io.to('admin_room').emit('new_support_msg', aiEntry);

        res.json({ success: true, reply: botReply });
    } catch (e) {
        res.json({ success: true, reply: "Hemos recibido tu mensaje. El equipo de soporte se contactará contigo a la brevedad." });
    }
});

// ==========================================
// NUEVO SISTEMA DE CLONACIÓN DE NODOS (V0.9)
// ==========================================
app.post('/api/project/clone', verifyToken, requireAccess, async (req, res) => {
    req.setTimeout(300000);
    // Solo dueño o CEO puede clonar
    if(req.user.uid !== req.serverOwnerUid && req.user.email !== 'rodasmaximo51@gmail.com') {
        return res.status(403).json({error: "Solo el propietario puede clonar este servidor."});
    }
    
    try {
        const user = await User.findOne({ uid: req.serverOwnerUid });
        const pDetails = PLAN_LIMITS[user.plan] || PLAN_LIMITS['redstone'];
        
        if (pDetails.maxServers !== -1 && user.servers.length >= pDetails.maxServers) {
            return res.status(403).json({ success: false, error: "Has alcanzado el límite de servidores de tu plan actual. Borra uno o mejora tu plan." });
        }

        const originalSrv = user.servers.find(s => s.id === req.body.serverId);
        const newId = Date.now().toString();
        const newServer = {
            id: newId,
            edition: originalSrv.edition,
            projectName: originalSrv.projectName + ' (Clon)',
            motd: originalSrv.motd,
            software: originalSrv.software,
            version: originalSrv.version,
            publicIp: null,
            sharedWith: [],
            isPaused: false
        };

        user.servers.push(newServer);
        await user.save();

        const oldPath = path.join(__dirname, 'servers', originalSrv.id);
        const newPath = path.join(__dirname, 'servers', newId);
        
        // Copiar todos los archivos recursivamente
        if(fs.existsSync(oldPath)) {
            fs.cpSync(oldPath, newPath, { recursive: true });
        }

        // Crear el contenedor de Docker para el clon
        let mcImage = originalSrv.edition === 'bedrock' 
            ? (originalSrv.software === 'pocketmine' ? 'pmmp/pocketmine-mp:latest' : 'itzg/minecraft-bedrock-server')
            : 'itzg/minecraft-server';
        
        let envVars = ['EULA=TRUE', `MOTD=${newServer.motd}`, `MEMORY=${pDetails.ram === '∞' ? '16G' : pDetails.ram}`, 'ENABLE_RCON=TRUE', 'JAVA_TOOL_OPTIONS=-Dnetty.transport=epoll'];
        if (pDetails.slots !== -1) envVars.push(`MAX_PLAYERS=${pDetails.slots}`);
        
        if (originalSrv.edition === 'bedrock' && originalSrv.software !== 'pocketmine') {
            envVars.push(originalSrv.software === 'preview' ? 'VERSION=PREVIEW' : 'VERSION=LATEST');
        } else if (originalSrv.edition === 'java') {
            envVars.push(`VERSION=${originalSrv.version}`);
            envVars.push(`TYPE=${originalSrv.software === 'snapshot' ? 'VANILLA' : originalSrv.software.toUpperCase()}`);
        }

        const memLimit = pDetails.ramBytes === 999999999999 ? 34359738368 : pDetails.ramBytes;
        
        const mcContainer = await docker.createContainer({
            Image: mcImage, name: `mc-${newId}`, Env: envVars,
            HostConfig: { Memory: memLimit, MemorySwap: memLimit, Binds: [`/home/maxpro/Proservers/servers/${newId}:/data`], Dns: ['8.8.8.8', '8.8.4.4'] }
        });
        
        const playitContainer = await docker.createContainer({ 
            Image: 'pepaondrugs/playitgg-docker:latest', 
            name: `playit-${newId}`, 
            HostConfig: { NetworkMode: `container:mc-${newId}` } 
        });

        res.json({ success: true, message: "Servidor clonado con éxito.", server: newServer });

    } catch (e) { res.status(500).json({ error: "Error al clonar: " + e.message }); }
});

// ==========================================
// ACCESO COMPARTIDO GRANULAR (V0.9)
// ==========================================
app.post('/api/project/share', verifyToken, requireAccess, async (req, res) => {
    const { serverId, emailToShare, permissions } = req.body;
    if(req.user.uid !== req.serverOwnerUid && req.user.email !== 'rodasmaximo51@gmail.com') {
        return res.status(403).json({error: "Solo el dueño original puede gestionar invitados."});
    }
    
    try {
        const user = await User.findOne({ uid: req.serverOwnerUid });
        const srv = user.servers.find(s => s.id === serverId);
        if (!srv.sharedWith) srv.sharedWith = [];
        
        const existing = srv.sharedWith.find(s => (typeof s === 'string' ? s === emailToShare : s.email === emailToShare));
        
        if (!existing) {
            srv.sharedWith.push({ email: emailToShare, permissions: permissions || ['power', 'console'] });
        } else {
            // Actualizamos permisos si ya existía (o si era string viejo lo pasamos a objeto)
            if (typeof existing === 'string') {
                srv.sharedWith = srv.sharedWith.filter(s => s !== emailToShare);
                srv.sharedWith.push({ email: emailToShare, permissions: permissions });
            } else {
                existing.permissions = permissions;
            }
        }
        
        user.markModified('servers');
        await user.save();
        res.json({ success: true, sharedWith: srv.sharedWith });
    } catch (e) { res.status(500).json({ error: e.message }); }
});

app.post('/api/project/unshare', verifyToken, requireAccess, async (req, res) => {
    const { serverId, emailToUnshare } = req.body;
    if(req.user.uid !== req.serverOwnerUid && req.user.email !== 'rodasmaximo51@gmail.com') {
        return res.status(403).json({error: "Solo el dueño original puede gestionar invitados."});
    }
    try {
        const user = await User.findOne({ uid: req.serverOwnerUid });
        const srv = user.servers.find(s => s.id === serverId);
        if (srv.sharedWith) {
            srv.sharedWith = srv.sharedWith.filter(e => (typeof e === 'string' ? e !== emailToUnshare : e.email !== emailToUnshare));
            user.markModified('servers');
            await user.save();
        }
        res.json({ success: true, sharedWith: srv.sharedWith || [] });
    } catch (e) { res.status(500).json({ error: e.message }); }
});

app.post('/api/project/ip', verifyToken, requireAccess, async (req, res) => {
    const { serverId, ip } = req.body;
    try {
        const user = await User.findOne({ uid: req.serverOwnerUid });
        const srv = user.servers.find(s => s.id === serverId);
        srv.publicIp = ip;
        user.markModified('servers');
        await user.save();
        res.json({ success: true });
    } catch (e) { res.status(500).json({ error: e.message }); }
});

app.post('/api/server/reset-network', verifyToken, requireAccess, async (req, res) => {
    const { serverId } = req.body;
    if(!req.guestPermissions.includes('all') && !req.guestPermissions.includes('settings')) return res.status(403).json({error: 'Sin permisos de configuración.'});
    try {
        const playitContainer = docker.getContainer(`playit-${serverId}`);
        await playitContainer.stop().catch(() => { });
        await playitContainer.remove({ force: true }).catch(() => { });

        const user = await User.findOne({ uid: req.serverOwnerUid });
        const srv = user.servers.find(s => s.id === serverId);
        srv.publicIp = null;
        user.markModified('servers');
        await user.save();

        const newPlayit = await docker.createContainer({
            Image: 'pepaondrugs/playitgg-docker:latest',
            name: `playit-${serverId}`,
            HostConfig: { NetworkMode: `container:mc-${serverId}` }
        });
        await newPlayit.start();
        res.json({ success: true });
    } catch (e) { res.status(500).json({ error: e.message }); }
});

app.get('/api/user/status', verifyToken, async (req, res) => {
    try {
        let user = await User.findOne({ uid: req.user.uid });
        if (!user) user = await User.create({ uid: req.user.uid, email: req.user.email });
        if (req.user.email === 'rodasmaximo51@gmail.com' && user.plan !== 'ceo') { user.plan = 'ceo'; await user.save(); }

        const pool = getSqlPool();
        if (pool) {
            const checkUser = await pool.request().query(`SELECT id FROM users WHERE firebase_uid = '${req.user.uid}'`);
            if (checkUser.recordset.length === 0) {
                await pool.request().query(`
                    INSERT INTO users (firebase_uid, email, plan_activo, role)
                    VALUES ('${req.user.uid}', '${req.user.email}', '${user.plan}', '${user.role || 'admin'}')
                `);
            } else {
                await pool.request().query(`
                    UPDATE users SET plan_activo = '${user.plan}' WHERE firebase_uid = '${req.user.uid}'
                `);
            }
        }

        const pDetails = PLAN_LIMITS[user.plan] || PLAN_LIMITS['redstone'];
        res.json({
            status: user.plan === 'banned' ? 'banned' : 'active',
            role: user.role || 'admin',
            plan: { id: user.plan, name: pDetails.name, ram: pDetails.ram, ramNum: pDetails.ram === '∞' ? 9999 : parseInt(pDetails.ram.replace('G', '')), maxServers: pDetails.maxServers === -1 ? 'ilimitado' : pDetails.maxServers, slots: pDetails.slots === -1 ? 'ilimitado' : pDetails.slots, fileManager: pDetails.fileManager }
        });
    } catch (e) {
        console.error("Error en estado de usuario:", e);
        res.status(500).json({ error: "Error DB" });
    }
});

app.get('/api/project/check', verifyToken, async (req, res) => {
    try {
        const uid = req.user.uid; const email = req.user.email;
        const user = await User.findOne({ uid });
        const ownServers = user ? user.servers : [];
        const usersWithShared = await User.find({ "servers.sharedWith.email": email });
        const usersWithSharedOld = await User.find({ "servers.sharedWith": email }); // Legacy strings
        
        let sharedServers = [];
        const processOwner = (owner) => {
            owner.servers.forEach(s => {
                if(s.sharedWith) {
                    const sharedObj = s.sharedWith.find(e => (typeof e === 'string' ? e === email : e.email === email));
                    if (sharedObj) {
                        const guestPerms = typeof sharedObj === 'string' ? ['power', 'console'] : sharedObj.permissions;
                        sharedServers.push({ ...s.toObject(), isShared: true, guestPermissions: guestPerms, ownerId: owner.uid });
                    }
                }
            });
        };
        usersWithShared.forEach(processOwner);
        usersWithSharedOld.forEach(processOwner);

        // Remove duplicates if any
        sharedServers = sharedServers.filter((v,i,a)=>a.findIndex(v2=>(v2.id===v.id))===i);

        res.json({ exists: (ownServers.length + sharedServers.length) > 0, servers: [...ownServers, ...sharedServers] });
    } catch (e) { res.status(500).json({ error: "Error DB" }); }
});

app.post('/api/project/create', verifyToken, async (req, res) => {
    req.setTimeout(300000);
    const { email, edition, projectName, motd, software, version, modpackUrl, curseforgeModpackId, curseforgeFileId } = req.body;
    const uid = req.user.uid; const clientIp = req.headers['x-forwarded-for'] || req.socket.remoteAddress || 'unknown';
    try {
        let user = await User.findOne({ uid });
        if (!user) user = new User({ uid, email: req.user.email, role: 'admin', plan: 'redstone', servers: [] });
        const pDetails = PLAN_LIMITS[user.plan] || PLAN_LIMITS['redstone'];

        if (user.plan === 'redstone' && clientIp !== 'unknown' && req.user.email !== 'rodasmaximo51@gmail.com') {
            let ipRecord = await IpInfo.findOne({ ip: clientIp });
            if (!ipRecord) ipRecord = new IpInfo({ ip: clientIp, uids: [] });

            if (ipRecord.uids.length > 0 && !ipRecord.uids.includes(uid)) {
                user.plan = 'banned';
                await user.save();
                for (let s of user.servers) {
                    try {
                        await docker.getContainer(`mc-${s.id}`).stop().catch(() => { });
                        await docker.getContainer(`mc-${s.id}`).remove({ force: true }).catch(() => { });
                        await docker.getContainer(`playit-${s.id}`).stop().catch(() => { });
                        await docker.getContainer(`playit-${s.id}`).remove({ force: true }).catch(() => { });
                    } catch (e) { }
                }
                return res.status(403).json({ success: false, message: "Bloqueo de Seguridad: Has intentado evadir los límites creando multicuentas gratuitas. Tu usuario ha sido expulsado permanentemente." });
            }
            if (!ipRecord.uids.includes(uid)) { ipRecord.uids.push(uid); await ipRecord.save(); }
        }

        const isModpack = !!(modpackUrl || curseforgeModpackId);
        const esMotorDeMods = ['forge', 'fabric', 'quilt', 'neoforge', 'arclight'].includes(software);
        const safeVersion = version || '';

        if (!isModpack && (safeVersion.startsWith('26.') || safeVersion === 'LATEST') && esMotorDeMods) return res.status(403).json({ success: false, message: "Versión no soporta mods." });
        if (pDetails.maxServers !== -1 && user.servers.length >= pDetails.maxServers) return res.status(403).json({ success: false, message: `Has alcanzado el límite máximo de servidores para tu plan.` });

        const serverId = Date.now().toString();
        const newServer = { id: serverId, edition, projectName, motd, software, version, publicIp: null, sharedWith: [], isPaused: false };
        user.servers.push(newServer); await user.save();

        const serverPath = path.join(__dirname, 'servers', serverId);
        if (!fs.existsSync(serverPath)) fs.mkdirSync(serverPath, { recursive: true });

        let mcImage = 'itzg/minecraft-server';
        let envVars = ['EULA=TRUE', `MOTD=${motd}`, `MEMORY=${pDetails.ram === '∞' ? '16G' : pDetails.ram}`, 'ENABLE_RCON=TRUE', 'JAVA_TOOL_OPTIONS=-Dnetty.transport=epoll'];
        if (pDetails.slots !== -1) envVars.push(`MAX_PLAYERS=${pDetails.slots}`);

        if (edition === 'bedrock') {
            if (software === 'pocketmine') mcImage = 'pmmp/pocketmine-mp:latest';
            else { mcImage = 'itzg/minecraft-bedrock-server'; envVars.push(software === 'preview' ? 'VERSION=PREVIEW' : 'VERSION=LATEST'); }
        } else {
            if (!isModpack) { envVars.push(`VERSION=${version}`); envVars.push(`TYPE=${software === 'snapshot' ? 'VANILLA' : software.toUpperCase()}`); }
        }

        deployProgress[serverId] = { step: "Conectando al nodo...", pct: 5, done: false, error: null };
        res.json({ success: true, message: "Iniciando...", server: newServer });

        setImmediate(async () => {
            try {
                let finalModpackUrl = modpackUrl;
                if (curseforgeModpackId && curseforgeFileId) {
                    deployProgress[serverId] = { step: "Contactando CurseForge...", pct: 15, done: false, error: null };
                    const cfRes = await fetch(`https://api.curseforge.com/v1/mods/${curseforgeModpackId}/files/${curseforgeFileId}`, { headers: { 'Accept': 'application/json', 'x-api-key': process.env.CURSEFORGE_API_KEY } });
                    const cfData = await cfRes.json();
                    if (cfData.data && cfData.data.downloadUrl) finalModpackUrl = cfData.data.downloadUrl;
                    else throw new Error("Descarga automática bloqueada por autor.");
                }

                if (finalModpackUrl) {
                    deployProgress[serverId] = { step: "Descargando Server Pack...", pct: 30, done: false, error: null };
                    const response = await fetchDescarga(finalModpackUrl);
                    deployProgress[serverId] = { step: "Guardando ZIP...", pct: 50, done: false, error: null };
                    const tempZipPath = path.join(serverPath, 'temp_pack.zip');
                    const fileStream = fs.createWriteStream(tempZipPath);
                    await pipeline(response.body, fileStream);
                    deployProgress[serverId] = { step: "Descomprimiendo...", pct: 65, done: false, error: null };
                    if (!extractZipSafely(tempZipPath, serverPath)) throw new Error("ZIP corrupto.");
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
                        const userUpdate = await User.findOne({ uid });
                        const srvIndex = userUpdate.servers.findIndex(s => s.id === serverId);
                        if (srvIndex !== -1) { userUpdate.servers[srvIndex].software = engineToUse; await userUpdate.save(); }
                    }
                    ['start.bat', 'start.sh', 'run.bat', 'run.sh', 'user_jvm_args.txt'].forEach(scr => {
                        if (fs.existsSync(path.join(serverPath, scr))) fs.unlinkSync(path.join(serverPath, scr));
                    });
                    envVars = envVars.filter(e => !e.startsWith('TYPE=') && !e.startsWith('VERSION='));
                    envVars.push(`TYPE=${engineToUse.toUpperCase()}`);
                    if (version && version !== 'Auto' && version !== 'LATEST') envVars.push(`VERSION=${version}`);
                }

                deployProgress[serverId] = { step: "Verificando imágenes...", pct: 85, done: false, error: null };
                await pullImageAsync(mcImage); await pullImageAsync('pepaondrugs/playitgg-docker:latest');
                deployProgress[serverId] = { step: "Levantando contenedor...", pct: 95, done: false, error: null };

                const memLimit = pDetails.ramBytes === 999999999999 ? 34359738368 : pDetails.ramBytes;
                const mcContainer = await docker.createContainer({
                    Image: mcImage, name: `mc-${serverId}`, Env: envVars,
                    HostConfig: { Memory: memLimit, MemorySwap: memLimit, Binds: [`/home/maxpro/Proservers/servers/${serverId}:/data`], Dns: ['8.8.8.8', '8.8.4.4'] }
                });
                await mcContainer.start();
                const playitContainer = await docker.createContainer({ Image: 'pepaondrugs/playitgg-docker:latest', name: `playit-${serverId}`, HostConfig: { NetworkMode: `container:mc-${serverId}` } });
                await playitContainer.start();
                deployProgress[serverId] = { step: "¡Todo Listo!", pct: 100, done: true, error: null };
            } catch (err) {
                deployProgress[serverId] = { step: "Despliegue Abortado", pct: 0, done: true, error: err.message };
                const userError = await User.findOne({ uid });
                if (userError) { userError.servers = userError.servers.filter(s => s.id !== serverId); await userError.save(); }
            }
        });
    } catch (e) { res.status(500).json({ error: "Error interno DB" }); }
});

app.get('/api/project/deploy-status', verifyToken, (req, res) => {
    const { serverId } = req.query;
    if (!serverId || !deployProgress[serverId]) return res.json({ step: "Iniciando despliegue...", pct: 0, done: false, error: null });
    res.json(deployProgress[serverId]);
    if (deployProgress[serverId].done) setTimeout(() => delete deployProgress[serverId], 10000);
});

app.post('/api/project/delete', verifyToken, isStrictCEO, async (req, res) => {
    try {
        const user = await User.findOne({ "servers.id": req.body.serverId });
        if (user) { user.servers = user.servers.filter(s => s.id !== req.body.serverId); await user.save(); }
        try {
            const mc = docker.getContainer(`mc-${req.body.serverId}`);
            await mc.stop().catch(() => { }); await mc.remove({ force: true, v: true }).catch(() => { });
            const playit = docker.getContainer(`playit-${req.body.serverId}`);
            await playit.stop().catch(() => { }); await playit.remove({ force: true, v: true }).catch(() => { });
            const sPath = path.join(__dirname, 'servers', req.body.serverId);
            if (fs.existsSync(sPath)) fs.rmSync(sPath, { recursive: true, force: true });
        } catch (e) { }
        res.json({ success: true });
    } catch (e) { res.status(500).json({ error: "Error DB" }); }
});

app.get('/api/server/status', verifyToken, requireAccess, async (req, res) => {
    try {
        const user = await User.findOne({ uid: req.serverOwnerUid });
        const srv = user.servers.find(s => s.id === req.query.serverId);
        const data = await docker.getContainer(`mc-${req.query.serverId}`).inspect();
        res.json({ status: data.State.Running ? 'on' : 'off', isPaused: srv.isPaused || false });
    } catch (e) { res.json({ status: 'off', isPaused: false }); }
});

app.post('/api/server/start', verifyToken, requireAccess, async (req, res) => {
    if(!req.guestPermissions.includes('all') && !req.guestPermissions.includes('power')) return res.status(403).json({error: 'Sin permisos de energía.'});
    try {
        const mc = docker.getContainer(`mc-${req.body.serverId}`);
        if (!(await mc.inspect()).State.Running) await mc.start();
        const playit = docker.getContainer(`playit-${req.body.serverId}`);
        if (!(await playit.inspect()).State.Running) await playit.start();
        res.json({ success: true });
    } catch (e) { res.json({ error: e.message }); }
});

app.post('/api/server/stop', verifyToken, requireAccess, async (req, res) => {
    if(!req.guestPermissions.includes('all') && !req.guestPermissions.includes('power')) return res.status(403).json({error: 'Sin permisos de energía.'});
    try {
        const mc = docker.getContainer(`mc-${req.body.serverId}`);
        if ((await mc.inspect()).State.Running) await mc.stop();
        const playit = docker.getContainer(`playit-${req.body.serverId}`);
        if ((await playit.inspect()).State.Running) await playit.stop();
        res.json({ success: true });
    } catch (e) { res.json({ error: e.message }); }
});

app.post('/api/server/restart', verifyToken, requireAccess, async (req, res) => {
    if(!req.guestPermissions.includes('all') && !req.guestPermissions.includes('power')) return res.status(403).json({error: 'Sin permisos de energía.'});
    try {
        await docker.getContainer(`mc-${req.body.serverId}`).restart();
        await docker.getContainer(`playit-${req.body.serverId}`).restart().catch(() => { });
        res.json({ success: true });
    } catch (e) { res.json({ error: e.message }); }
});

app.post('/api/server/command', verifyToken, requireAccess, async (req, res) => {
    if(!req.guestPermissions.includes('all') && !req.guestPermissions.includes('console')) return res.status(403).json({error: 'Sin permisos de consola.'});
    try {
        const exec = await docker.getContainer(`mc-${req.body.serverId}`).exec({ Cmd: ['rcon-cli', req.body.command], AttachStdout: true });
        exec.start(() => res.json({ success: true }));
    } catch (e) { res.status(500).json({ error: "Error" }); }
});

app.post('/api/server/mine-ai', verifyToken, requireAccess, async (req, res) => {
    const GROQ_API_KEY = process.env.GROQ_API_KEY;
    if (!GROQ_API_KEY) return res.status(500).json({ success: false, error: "Falta API Key." });
    try {
        const container = docker.getContainer(`mc-${req.body.serverId}`);
        const logsBuffer = await container.logs({ stdout: true, stderr: true, tail: 150 });

        const logsText = logsBuffer.toString('utf8').replace(/[^\x20-\x7E\n\r]/g, '').trim();

        const prompt = `Eres Mine, el analista técnico de servidores Minecraft de Professional Servers. Analiza esto y responde SOLO en JSON: {"mensaje": "diagnostico", "hay_que_borrar": false, "archivo_a_borrar": "null", "paso_a_paso": "que hacer", "mod_alternativo": "null"}\nLog:\n${logsText}`;

        const response = await fetch('https://api.groq.com/openai/v1/chat/completions', {
            method: 'POST',
            headers: { 'Authorization': `Bearer ${GROQ_API_KEY}`, 'Content-Type': 'application/json' },
            body: JSON.stringify({
                model: "openai/gpt-oss-120b",
                messages: [{ "role": "user", "content": prompt }]
            })
        });

        if (!response.ok) {
            const errBody = await response.text();
            console.error('[Mine AI] Groq error:', response.status, errBody);
            throw new Error(`Fallo de API Groq (${response.status})`);
        }

        const data = await response.json();

        let rawContent = data.choices[0].message.content.trim();

        const jsonMatch = rawContent.match(/\{[\s\S]*\}/);
        if (!jsonMatch) {
            throw new Error("El modelo de IA no devolvió un formato JSON válido.");
        }

        const parsedData = JSON.parse(jsonMatch[0]);
        res.json({ success: true, analysis: parsedData });
    } catch (e) { res.status(500).json({ success: false, error: "Error Mine AI: " + e.message }); }
});

app.get('/api/server/settings', verifyToken, requireAccess, (req, res) => {
    try {
        const propPath = getSafePath(req.query.serverId, '/server.properties');
        if (!fs.existsSync(propPath)) return res.json({ gamemode: 'survival', difficulty: 'easy', pvp: 'true' });

        const content = fs.readFileSync(propPath, 'utf8');
        const getVal = (key) => { const m = content.match(new RegExp(`^${key}=(.*)$`, 'm')); return m ? m[1].trim() : ''; };

        res.json({
            gamemode: getVal('gamemode') || 'survival',
            difficulty: getVal('difficulty') || 'easy',
            pvp: getVal('pvp') || 'true'
        });
    } catch (e) { res.status(500).json({ error: e.message }); }
});

app.post('/api/server/settings', verifyToken, requireAccess, async (req, res) => {
    if(!req.guestPermissions.includes('all') && !req.guestPermissions.includes('settings')) return res.status(403).json({error: 'Sin permisos de ajustes.'});
    try {
        const { gamemode, difficulty, pvp, serverId } = req.body;
        const propPath = getSafePath(serverId, '/server.properties');
        if (fs.existsSync(propPath)) {
            let content = fs.readFileSync(propPath, 'utf8');
            content = content.replace(/^gamemode=.*$/gm, `gamemode=${gamemode}`);
            content = content.replace(/^difficulty=.*$/gm, `difficulty=${difficulty}`);
            content = content.replace(/^pvp=.*$/gm, `pvp=${pvp}`);
            if (!content.includes('gamemode=')) content += `\ngamemode=${gamemode}`;
            if (!content.includes('difficulty=')) content += `\ndifficulty=${difficulty}`;
            if (!content.includes('pvp=')) content += `\npvp=${pvp}`;
            fs.writeFileSync(propPath, content, 'utf8');
        } else {
            fs.writeFileSync(propPath, `gamemode=${gamemode}\ndifficulty=${difficulty}\npvp=${pvp}\n`, 'utf8');
        }
        await docker.getContainer(`mc-${serverId}`).restart().catch(() => { });
        res.json({ success: true });
    } catch (e) { res.status(500).json({ error: e.message }); }
});

app.post('/api/server/regenerate-world', verifyToken, requireAccess, async (req, res) => {
    if(!req.guestPermissions.includes('all') && !req.guestPermissions.includes('settings')) return res.status(403).json({error: 'Sin permisos de ajustes.'});
    const { seed, serverId } = req.body;
    try {
        const sPath = path.join(__dirname, 'servers', serverId);
        const mc = docker.getContainer(`mc-${serverId}`);
        await mc.stop().catch(() => { });
        ['world', 'world_nether', 'world_the_end'].forEach(f => {
            const p = path.join(sPath, f);
            if (fs.existsSync(p)) fs.rmSync(p, { recursive: true, force: true });
        });
        const propPath = path.join(sPath, 'server.properties');
        if (fs.existsSync(propPath)) {
            let content = fs.readFileSync(propPath, 'utf8');
            if (content.match(/^level-seed=.*$/m)) {
                content = content.replace(/^level-seed=.*$/gm, `level-seed=${seed || ''}`);
            } else {
                content += `\nlevel-seed=${seed || ''}\n`;
            }
            fs.writeFileSync(propPath, content, 'utf8');
        }
        await mc.start().catch(() => { });
        res.json({ success: true });
    } catch (e) { res.status(500).json({ error: e.message }); }
});

app.get('/api/curseforge/search', verifyToken, async (req, res) => {
    try {
        let fetchUrl = 'https://api.curseforge.com/v1/mods/search?gameId=432&sortField=2&sortOrder=desc';

        if (req.query.query && req.query.query.trim() !== '' && req.query.query !== 'undefined') {
            fetchUrl += `&searchFilter=${encodeURIComponent(req.query.query.trim())}`;
        }

        if (req.query.categoryId) fetchUrl += `&classId=${req.query.categoryId}`;
        else if (req.query.classId) fetchUrl += `&classId=${req.query.classId}`;

        const response = await fetch(fetchUrl, { headers: { 'Accept': 'application/json', 'x-api-key': process.env.CURSEFORGE_API_KEY } });

        if (!response.ok) {
            const errTxt = await response.text();
            throw new Error(`CF Search Error: ${response.status} - ${errTxt}`);
        }

        res.json(await response.json());
    } catch (e) { res.status(500).json({ error: "Error CurseForge: " + e.message }); }
});

app.get('/api/curseforge/files', verifyToken, async (req, res) => {
    try {
        let fetchUrl = `https://api.curseforge.com/v1/mods/${req.query.modId}/files`;

        if (req.query.version && req.query.version !== 'undefined' && req.query.version !== 'Auto') {
            fetchUrl += `?gameVersion=${encodeURIComponent(req.query.version)}`;
        }

        const response = await fetch(fetchUrl, { headers: { 'Accept': 'application/json', 'x-api-key': process.env.CURSEFORGE_API_KEY } });

        if (!response.ok) {
            const errTxt = await response.text();
            throw new Error(`CF Files Error: ${response.status} - ${errTxt}`);
        }

        res.json(await response.json());
    } catch (e) { res.status(500).json({ error: "Error obteniendo archivos: " + e.message }); }
});

app.post('/api/curseforge/install-file', verifyToken, requireAccess, requireFeature('fileManager'), async (req, res) => {
    if(!req.guestPermissions.includes('all') && !req.guestPermissions.includes('files')) return res.status(403).json({error: 'Sin permisos de archivos.'});
    try {
        const { serverId, downloadUrl, fileName, type } = req.body;
        if (!downloadUrl) return res.status(400).json({ error: "Falta la URL de descarga del archivo." });

        const serverPath = getSafePath(serverId, '/');

        let targetFolder = 'mods';
        if (type === 'plugin') targetFolder = 'plugins';
        if (type === 'datapack') targetFolder = 'world/datapacks';

        const targetDir = path.join(serverPath, targetFolder);
        if (!fs.existsSync(targetDir)) fs.mkdirSync(targetDir, { recursive: true });

        const finalName = fileName || 'downloaded_mod.jar';
        const tempFilePath = path.join(targetDir, finalName);

        const response = await fetchDescarga(downloadUrl);
        const fileStream = fs.createWriteStream(tempFilePath);
        await pipeline(response.body, fileStream);

        res.json({ success: true, message: `Archivo guardado correctamente en la carpeta /${targetFolder}` });
    } catch (e) {
        res.status(500).json({ error: "Error descargando el archivo: " + e.message });
    }
});

app.get('/api/server/stats', verifyToken, requireAccess, async (req, res) => {
    try {
        const stats = await docker.getContainer(`mc-${req.query.serverId}`).stats({ stream: false });
        const cpuDelta = stats.cpu_stats.cpu_usage.total_usage - stats.precpu_stats.cpu_usage.total_usage;
        const systemDelta = stats.cpu_stats.system_cpu_usage - stats.precpu_stats.system_cpu_usage;
        let cpu = (systemDelta > 0 && cpuDelta > 0) ? ((cpuDelta / systemDelta) * stats.cpu_stats.online_cpus * 100).toFixed(1) : 0;
        res.json({ cpu: `${cpu}%`, ram: (stats.memory_stats.usage / (1024 * 1024)).toFixed(2) });
    } catch (e) { res.json({ cpu: '0%', ram: '0' }); }
});

app.get('/api/server/players', verifyToken, requireAccess, async (req, res) => {
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

app.get('/api/server/playitlogs', verifyToken, requireAccess, async (req, res) => {
    if(!req.guestPermissions.includes('all') && !req.guestPermissions.includes('settings')) return res.status(403).json({error: 'Sin permisos.'});
    try {
        const logs = await docker.getContainer(`playit-${req.query.serverId}`).logs({ stdout: true, stderr: true, tail: 50 });
        res.json({ logs: logs.toString('utf8').replace(/[\u0000-\u0009\u000b-\u001f\u007f-\u009f]/g, '') });
    } catch (e) { res.json({ logs: "Conectando..." }); }
});

app.post('/api/files/upload', verifyToken, upload.single('file'), requireAccess, requireFeature('fileManager'), (req, res) => {
    if(!req.guestPermissions.includes('all') && !req.guestPermissions.includes('files')) return res.status(403).json({error: 'Sin permisos de archivos.'});
    try {
        const serverPath = getSafePath(req.body.serverId, '/');
        if (req.file.originalname.endsWith('.zip')) {
            extractZipSafely(req.file.path, serverPath); fs.unlinkSync(req.file.path); res.json({ success: true, message: "Modpack descomprimido." });
        } else if (req.file.originalname.endsWith('.jar')) {
            const mPath = getSafePath(req.body.serverId, '/mods');
            if (!fs.existsSync(mPath)) fs.mkdirSync(mPath, { recursive: true });
            fs.renameSync(req.file.path, path.join(mPath, req.file.originalname));
            res.json({ success: true, message: "Mod guardado." });
        } else res.json({ success: true });
    } catch (e) { res.status(500).json({ error: "Error." }); }
});

app.post('/api/files/upload-url', verifyToken, requireAccess, requireFeature('fileManager'), async (req, res) => {
    if(!req.guestPermissions.includes('all') && !req.guestPermissions.includes('files')) return res.status(403).json({error: 'Sin permisos de archivos.'});
    try {
        const serverPath = getSafePath(req.body.serverId, '/');
        const fileName = req.body.fileName || 'cloud_temp.zip';
        const isJar = fileName.endsWith('.jar');

        const targetDir = isJar ? path.join(serverPath, 'mods') : serverPath;
        if (isJar && !fs.existsSync(targetDir)) fs.mkdirSync(targetDir, { recursive: true });

        const tempFilePath = path.join(targetDir, fileName);
        const response = await fetchDescarga(req.body.downloadUrl);
        const fileStream = fs.createWriteStream(tempFilePath);
        await pipeline(response.body, fileStream);

        if (isJar) {
            res.json({ success: true, message: "Mod instalado correctamente." });
        } else {
            if (extractZipSafely(tempFilePath, serverPath)) {
                fs.unlinkSync(tempFilePath);
                res.json({ success: true, message: "Nube extraida." });
            }
            else res.status(500).json({ error: "Fallo extracción." });
        }
    } catch (e) { res.status(500).json({ error: "Error nube." }); }
});

app.get('/api/files/list', verifyToken, requireAccess, requireFeature('fileManager'), (req, res) => {
    if(!req.guestPermissions.includes('all') && !req.guestPermissions.includes('files')) return res.status(403).json({error: 'Sin permisos de archivos.'});
    try {
        const sPath = req.query.path === '/' ? '' : req.query.path;
        const tPath = getSafePath(req.query.serverId, sPath);
        if (!tPath || !fs.existsSync(tPath)) return res.json([]);
        res.json(fs.readdirSync(tPath, { withFileTypes: true }).map(f => ({ name: f.name, isDir: f.isDirectory(), path: path.posix.join(sPath || '/', f.name) })));
    } catch (e) { res.status(500).json({ error: "Error" }); }
});

app.get('/api/files/content', verifyToken, requireAccess, requireFeature('fileManager'), (req, res) => {
    if(!req.guestPermissions.includes('all') && !req.guestPermissions.includes('files')) return res.status(403).json({error: 'Sin permisos de archivos.'});
    try {
        const tPath = getSafePath(req.query.serverId, req.query.path);
        if (!tPath || !fs.existsSync(tPath)) return res.status(404).json({ error: "No encontrado" });
        res.json({ content: fs.readFileSync(tPath, 'utf8') });
    } catch (e) { res.status(500).json({ error: "Error" }); }
});

app.post('/api/files/save', verifyToken, requireAccess, requireFeature('fileManager'), (req, res) => {
    if(!req.guestPermissions.includes('all') && !req.guestPermissions.includes('files')) return res.status(403).json({error: 'Sin permisos de archivos.'});
    try {
        const tPath = getSafePath(req.body.serverId, req.body.path);
        if (fs.existsSync(tPath)) fs.copyFileSync(tPath, `${tPath}.bak`);
        fs.writeFileSync(tPath, req.body.content, 'utf8');
        res.json({ success: true });
    } catch (e) { res.status(500).json({ error: "Error" }); }
});

app.post('/api/files/delete', verifyToken, requireAccess, requireFeature('fileManager'), (req, res) => {
    if(!req.guestPermissions.includes('all') && !req.guestPermissions.includes('files')) return res.status(403).json({error: 'Sin permisos de archivos.'});
    try {
        const tPath = getSafePath(req.body.serverId, req.body.path);
        if (fs.statSync(tPath).isDirectory()) fs.rmSync(tPath, { recursive: true, force: true });
        else fs.unlinkSync(tPath);
        res.json({ success: true });
    } catch (e) { res.status(500).json({ error: "Error" }); }
});

io.use(async (socket, next) => {
    if (!socket.handshake.query.token) return next(new Error('Sin token'));
    try { socket.user = await getAuth().verifyIdToken(socket.handshake.query.token); next(); }
    catch (err) { next(new Error('Invalido')); }
});

io.on('connection', async (socket) => {
    if (socket.user) {
        socket.join(`user_${socket.user.uid}`);
        
        try {
            const userDb = await User.findOne({ uid: socket.user.uid });
            if (socket.user.email === 'rodasmaximo51@gmail.com' || (userDb && userDb.role === 'soporte')) {
                socket.join('admin_room');
            }
        } catch(e) {}
    }

    const serverId = socket.handshake.query.serverId;
    if (!serverId || serverId === 'undefined' || serverId === 'null') {
        if (socket.user && socket.user.email === 'rodasmaximo51@gmail.com') return;
        try {
            const userDb = await User.findOne({ uid: socket.user.uid });
            if (userDb && userDb.role === 'soporte') return;
        } catch(e) {}
        return socket.disconnect();
    }

    try {
        const userWithServer = await User.findOne({ "servers.id": serverId });
        if (!userWithServer) return socket.disconnect();
        const srv = userWithServer.servers.find(s => s.id === serverId);
        
        const isOwner = userWithServer.uid === socket.user.uid;
        const isSharedObj = srv.sharedWith && srv.sharedWith.find(e => (typeof e === 'string' ? e === socket.user.email : e.email === socket.user.email));
        const isCEO = socket.user.email === 'rodasmaximo51@gmail.com';
        
        let isSoporte = false;
        try {
            const callingUser = await User.findOne({ uid: socket.user.uid });
            if (callingUser && callingUser.role === 'soporte') isSoporte = true;
        } catch(e) {}

        // Validamos que tenga acceso al contenedor
        if (!isOwner && !isSharedObj && !isCEO && !isSoporte) return socket.disconnect();
        
        // Si es compartido y no tiene permisos de consola, que no lea los logs
        if (isSharedObj && !isCEO && !isOwner) {
            const perms = typeof isSharedObj === 'string' ? ['power', 'console'] : isSharedObj.permissions;
            if (!perms.includes('console')) return socket.disconnect();
        }

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

connectDatabases().then(() => {
    server.listen(3000, () => {
        console.log('\x1b[32m[Professional Servers] Backend v1.0 (Con Sistema de Staff V0.9 y Clonación) Listo.\x1b[0m');
    });
});