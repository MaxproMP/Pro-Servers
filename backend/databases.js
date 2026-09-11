const mongoose = require('mongoose');
const mssql = require('mssql');
require('dotenv').config();

// ==========================================
// ESQUEMAS DE MONGODB (Servidores, Usuarios y Red)
// ==========================================
const serverSchema = new mongoose.Schema({
    id: String,
    edition: String,
    projectName: String,
    motd: String,
    software: String,
    version: String,
    publicIp: String,
    sharedWith: [{
        email: String,
        permissions: [String] // 'power', 'console', 'files', 'settings'
    }],
    isPaused: { type: Boolean, default: false }
});

const userSchema = new mongoose.Schema({
    uid: { type: String, required: true, unique: true },
    email: { type: String },
    role: { type: String, default: 'admin' },
    plan: { type: String, default: 'redstone' },
    servers: [serverSchema]
});

const ipSchema = new mongoose.Schema({
    ip: { type: String, required: true, unique: true },
    uids: [String]
});

const User = mongoose.model('User', userSchema);
const IpInfo = mongoose.model('IpInfo', ipSchema);

// ==========================================
// GESTOR DE CONEXIONES HÍBRIDAS
// ==========================================
let sqlPool = null;

const connectDatabases = async () => {
    try {
        await mongoose.connect(process.env.MONGO_URI);
        console.log('\x1b[34m[MongoDB] Conectado a Azure Cosmos DB (Infraestructura).\x1b[0m');

        sqlPool = await mssql.connect(process.env.SQL_URI);
        console.log('\x1b[36m[SQL Server] Conectado a Azure SQL (Usuarios y Facturación).\x1b[0m');

        await sqlPool.request().query(`
            IF NOT EXISTS (SELECT * FROM sysobjects WHERE name='users' AND xtype='U')
            CREATE TABLE users (
                id INT IDENTITY(1,1) PRIMARY KEY,
                firebase_uid VARCHAR(100) UNIQUE NULL,
                name VARCHAR(255) NULL,
                email VARCHAR(255) UNIQUE NOT NULL,
                password VARCHAR(255) NULL,
                discord_id VARCHAR(50), 
                plan_activo VARCHAR(50) DEFAULT 'redstone',
                role VARCHAR(50) DEFAULT 'admin',
                remember_token VARCHAR(100) NULL,
                created_at DATETIME DEFAULT GETDATE(),
                updated_at DATETIME DEFAULT GETDATE()
            );

            IF NOT EXISTS (SELECT * FROM sysobjects WHERE name='Suscripciones' AND xtype='U')
            CREATE TABLE Suscripciones (
                id INT IDENTITY(1,1) PRIMARY KEY,
                firebase_uid VARCHAR(100) NOT NULL,
                plan_nombre VARCHAR(50) NOT NULL,
                ciclo_meses INT DEFAULT 1, 
                estado VARCHAR(20) NOT NULL, 
                fecha_inicio DATETIME DEFAULT GETDATE(),
                fecha_vencimiento DATETIME,
                created_at DATETIME DEFAULT GETDATE(),
                updated_at DATETIME DEFAULT GETDATE()
            );

            IF NOT EXISTS (SELECT * FROM sysobjects WHERE name='Pagos' AND xtype='U')
            CREATE TABLE Pagos (
                id INT IDENTITY(1,1) PRIMARY KEY,
                firebase_uid VARCHAR(100) NOT NULL,
                monto DECIMAL(10,2) NOT NULL,
                metodo VARCHAR(50), 
                estado VARCHAR(20) DEFAULT 'pendiente', 
                fecha DATETIME DEFAULT GETDATE(),
                transaccion_id VARCHAR(100) UNIQUE,
                created_at DATETIME DEFAULT GETDATE(),
                updated_at DATETIME DEFAULT GETDATE()
            );
        `);
        console.log('\x1b[36m[SQL Server] Tablas sincronizadas (Adaptadas para Laravel y Node.js).\x1b[0m');

    } catch (error) {
        console.error('\x1b[31m[ERROR CRÍTICO DB]\x1b[0m Fallo al conectar con las bases de datos:', error);
        process.exit(1);
    }
};

module.exports = {
    connectDatabases,
    User,
    IpInfo,
    getSqlPool: () => sqlPool
};