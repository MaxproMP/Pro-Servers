require('dotenv').config(); // Agregado para leer el archivo universal .env
const { Client, GatewayIntentBits, REST, Routes } = require('discord.js');
const express = require('express');
const sql = require('mssql');

// 1. CONFIGURACIÓN DE DISCORD
const client = new Client({
    intents: [
        GatewayIntentBits.Guilds,
        GatewayIntentBits.GuildMembers,
    ],
});

const app = express();
app.use(express.json());

const SERVIDOR_ID = '1543466056901726308'; // Tu servidor de Discord

const rolesPorPlan = {
    'ghost-warrior': 'ID_DEL_ROL_GHOST_WARRIOR',
    'netherite': 'ID_DEL_ROL_NETHERITE',
    'diamante': 'ID_DEL_ROL_DIAMANTE',
    'oro': 'ID_DEL_ROL_ORO',
    'cobre': 'ID_DEL_ROL_COBRE',
    'hierro': 'ID_DEL_ROL_HIERRO',
    'redstone': 'ID_DEL_ROL_REDSTONE'
};

// ⚠️ ACTUALIZADO: Ahora usa las variables del .env unificado de Laravel
const sqlConfig = {
    user: process.env.DB_USERNAME,
    password: process.env.DB_PASSWORD,
    database: process.env.DB_DATABASE,
    server: process.env.DB_HOST,
    pool: { max: 10, min: 0, idleTimeoutMillis: 30000 },
    options: { encrypt: true, trustServerCertificate: false }
};

// 2. REGISTRO DE SLASH COMMANDS AL INICIAR
client.once('clientReady', async () => {
    console.log(`¡Sistemas en línea! Operando bajo la designación: ${client.user.tag}`);

    const rest = new REST({ version: '10' }).setToken(process.env.DISCORD_TOKEN);
    const comandos = [
        {
            name: 'ping',
            description: 'Verifica el estado del bot y del servidor.'
        },
        {
            name: 'vincular',
            description: 'Vincula tu cuenta del panel web con tu perfil de Discord.',
            options: [
                {
                    name: 'email',
                    type: 3, // Tipo STRING
                    description: 'El correo electrónico que usaste para registrarte en el panel.',
                    required: true
                }
            ]
        }
    ];

    try {
        console.log('Registrando Slash Commands (/)....');
        await rest.put(
            Routes.applicationGuildCommands(client.user.id, SERVIDOR_ID),
            { body: comandos }
        );
        console.log('✅ Slash Commands instalados con éxito en el servidor.');
    } catch (error) {
        console.error('Error al registrar comandos:', error);
    }
});

// 3. RESPUESTA A LOS SLASH COMMANDS
client.on('interactionCreate', async interaction => {
    if (!interaction.isChatInputCommand()) return;

    if (interaction.commandName === 'ping') {
        await interaction.reply({ content: '¡Pong! Servidor operando al 100%.', ephemeral: true });
    }

    if (interaction.commandName === 'vincular') {
        const email = interaction.options.getString('email');
        const discordId = interaction.user.id;

        await interaction.reply({ content: `Buscando **${email}** en los servidores de Azure SQL...`, ephemeral: true });

        try {
            await sql.connect(sqlConfig);

            const result = await sql.query`SELECT * FROM users WHERE email = ${email}`;

            if (result.recordset.length === 0) {
                return await interaction.editReply('❌ No se encontró ninguna cuenta con ese correo. Verificá que esté bien escrito.');
            }

            await sql.query`UPDATE users SET discord_id = ${discordId} WHERE email = ${email}`;

            await interaction.editReply('✅ **¡Cuenta vinculada con éxito!** Ya estás integrado al sistema de ProServers.');

        } catch (err) {
            console.error('Error SQL:', err);
            await interaction.editReply('⚠️ Hubo un error de conexión con la base de datos central.');
        }
    }
});

// 4. MÓDULO DE AUTO-ASIGNACIÓN DE ROLES (Receptor API)
app.post('/api/bot/asignar-rol', async (req, res) => {
    const { discordId, planNombre } = req.body;
    if (!discordId || !planNombre) return res.status(400).json({ error: 'Faltan datos' });

    try {
        const guild = await client.guilds.fetch(SERVIDOR_ID);
        const member = await guild.members.fetch(discordId);
        const rolId = rolesPorPlan[planNombre.toLowerCase()];

        if (rolId && member) {
            await member.roles.add(rolId);
            await member.send(`¡Tu pago fue procesado con éxito! Se te ha asignado el rango **${planNombre.toUpperCase()}** en Discord.`);
            return res.json({ status: 'Rol asignado' });
        }
        res.status(404).json({ error: 'Rol/Usuario no encontrado' });
    } catch (error) {
        res.status(500).json({ error: 'Fallo interno en Discord' });
    }
});

app.listen(3001, () => {
    console.log('Receptor de Webhooks del bot escuchando en el puerto 3001');
});

client.login(process.env.DISCORD_TOKEN);
