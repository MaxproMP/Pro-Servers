'use strict';

const MB = 1048576;

/**
 * Recrea un contenedor con otro entorno y otra memoria, conservando imagen, puertos, volúmenes,
 * red, CPU y política de reinicio. Si el nuevo no se puede crear o arrancar, vuelve al anterior.
 * @returns {Promise<{ reiniciado: boolean }>}
 */
async function recrear(docker, nombre, envNuevo, memoryMb) {
    const c = docker.getContainer(nombre);
    const info = await c.inspect();
    const corriendo = info.State.Running;
    const h = info.HostConfig;
    const spec = (env, memBytes) => ({
        Image: info.Config.Image,
        name: nombre,
        Env: env,
        ExposedPorts: info.Config.ExposedPorts,
        HostConfig: {
            PortBindings: h.PortBindings, Binds: h.Binds, RestartPolicy: h.RestartPolicy,
            NetworkMode: h.NetworkMode, NanoCpus: h.NanoCpus, Memory: memBytes, MemorySwap: memBytes,
        },
    });
    if (corriendo) {
        try { await c.stop({ t: 30 }); } catch (e) { if (e.statusCode !== 304) throw e; }
    }
    await c.remove({ force: true });
    let nuevo = null;
    try {
        nuevo = await docker.createContainer(spec(envNuevo, memoryMb * MB));
        if (corriendo) await nuevo.start();
        return { reiniciado: corriendo };
    } catch (e) {
        if (nuevo) await nuevo.remove({ force: true }).catch(() => {});
        const viejo = await docker.createContainer(spec(info.Config.Env, h.Memory));
        if (corriendo) await viejo.start().catch(() => {});
        throw e;
    }
}

module.exports = { recrear };
