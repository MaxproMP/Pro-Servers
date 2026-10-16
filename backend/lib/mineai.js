'use strict';

// Groq retira modelos sin aviso (llama-3.3-70b-versatile dejó de existir y Mine AI devolvía 502).
// Se prueba GROQ_MODEL primero y, si el modelo no existe, se cae al siguiente de la lista.
const MODELOS_RESPALDO = ['openai/gpt-oss-120b', 'qwen/qwen3.8-27b', 'openai/gpt-oss-20b'];

function modelos(env = process.env) {
    const propio = (env.GROQ_MODEL || '').trim();
    return [...new Set([propio, ...MODELOS_RESPALDO].filter(Boolean))];
}

const PROMPT = 'Analiza este log de Minecraft como ingeniero de infraestructura. Responde en español rioplatense. '
    + 'Devuelve únicamente JSON válido con las claves mensaje (string), hay_que_borrar (boolean), archivo_a_borrar (string o null), '
    + 'paso_a_paso (array de strings) y mod_alternativo (string o null). No ordenes borrar archivos si no hay evidencia clara.\n\nLOG:\n';

/** @returns {Promise<{ok: true, analysis: object, modelo: string} | {ok: false, status: number, error: string}>} */
async function diagnosticar(logText, { env = process.env, fetchFn = fetch } = {}) {
    const key = (env.GROQ_API_KEY || '').replace(/^"|"$/g, '').trim();
    if (!key) return { ok: false, status: 503, error: 'Mine AI no está configurada en el servidor.' };

    let ultimo = 'Groq rechazó el diagnóstico.';
    for (const modelo of modelos(env)) {
        let r;
        try {
            r = await fetchFn('https://api.groq.com/openai/v1/chat/completions', {
                method: 'POST',
                headers: { Authorization: `Bearer ${key}`, 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    model: modelo,
                    messages: [{ role: 'user', content: PROMPT + logText }],
                    temperature: 0.1,
                    response_format: { type: 'json_object' },
                }),
                signal: AbortSignal.timeout(45000),
            });
        } catch (e) {
            return { ok: false, status: 504, error: 'Mine AI tardó demasiado en responder. Probá de nuevo.' };
        }
        const data = await r.json().catch(() => ({}));
        if (r.ok) {
            try {
                return { ok: true, analysis: JSON.parse(data.choices?.[0]?.message?.content || '{}'), modelo };
            } catch {
                ultimo = 'Mine AI devolvió una respuesta ilegible.';
                continue;
            }
        }
        ultimo = data.error?.message || ultimo;
        // Solo se reintenta con otro modelo si este no existe o fue dado de baja; el resto de errores (clave, cuota) es definitivo.
        if (!(r.status === 404 || data.error?.code === 'model_not_found' || data.error?.code === 'model_decommissioned')) {
            return { ok: false, status: r.status === 429 ? 429 : 502, error: ultimo };
        }
    }
    return { ok: false, status: 502, error: ultimo };
}

module.exports = { diagnosticar, modelos };
