# ProServers — Guía y Contexto del Agente (Antigravity)

## 📌 Visión General del Proyecto
**ProServers** es una plataforma SaaS para la automatización, aprovisionamiento y administración de servidores de Minecraft sobre contenedores Docker independientes.

El proyecto opera bajo una arquitectura desacoplada:
1. **Core / Daemon (Node.js & Express):** Orquestación con Dockerode (`/var/run/docker.sock`), túneles Playit.gg, WebSockets para consola RCON en vivo y telemetría.
2. **Módulo de Facturación y Gestión (Laravel 13 - PHP 8.3):** Capa comercial y académica para administración de planes, suscripciones, pasarelas de pago, tickets de soporte y auditoría en 3FN.

---

## 🛠️ Stack Tecnológico
* **Backend:** PHP 8.3 + Laravel 13 (Billing) | Node.js v20 + Express (Daemon / Infraestructura).
* **Bases de Datos:** MySQL / MariaDB (Esquema relacional 3FN) + MongoDB (Nodos y servidores en juego) + Azure SQL / Cosmos.
* **Autenticación:** Firebase Auth (Web & Backend Token Verification) + Laravel Auth nativo (Módulo académico).
* **Inteligencia Artificial:** Groq API / Llama 3 para "Mine AI" (Auditoría de logs de consola y diagnóstico de fallos).
* **Frontend:** Blade Templates + Tailwind CSS + Feather Icons (Dark UI / Glassmorphism de autoría propia).
* **Integraciones Externas:** CurseForge API (Búsqueda/Descargas directas en Daemon) + Modrinth API (Esquema de datos) + Playit.gg.

---

## 📐 Reglas Estrictas de Base de Datos y Negocio (Esquema v2)
Al generar migraciones, modelos Eloquent, seeders o consultas SQL, se deben respetar las siguientes directivas:

1. **Jerarquía Relacional Suscripción-Servidor:**
   * Relación: `usuarios -> suscripciones -> servidores`.
   * Un servidor **NO** se vincula a `id_usuario`. Pertenece a una `id_suscripcion` (1:1), garantizando que los límites de hardware (RAM/CPU) dependan del plan contratado.
2. **Normalización 3FN en Pagos:**
   * La tabla `pagos` se vincula únicamente con `id_suscripcion`. Prohibido almacenar `id_usuario` directamente en `pagos` para evitar dependencias transitivas.
   * `pagos` distingue `pasarela_pago` (MercadoPago, Stripe, PayPal) de `medio_pago` (tarjeta, transferencia, saldo).
3. **Auditoría y Borrado Lógico:**
   * Tablas financieras (`usuarios`, `suscripciones`) utilizan Soft Deletes (`deleted_at` / estado inactivo) y `ON DELETE RESTRICT` hacia pagos para preservar el historial contable.
   * Tablas técnicas dependientes (`mods_instalados`, `mensajes_ticket`) utilizan `ON DELETE CASCADE`.
4. **Trazabilidad de Mine AI:**
   * Incidencias divididas en `tickets_soporte` (cabecera) y `mensajes_ticket` (hilo).
   * Los diagnósticos generados por la IA deben persistirse en `mensajes_ticket` junto con el extracto del log analizado (`log_analizado`).
5. **Restricciones de Dominio:**
   * Validaciones `CHECK` en memoria (>0), cpu (>0), almacenamiento (>0), precios (>=0), montos (>0) y puertos TCP (1024 a 65535).

---

## 🎨 Convenciones de Frontend y Vistas Blade
* **Estética Visual:** Respetar la identidad Dark Theme / Glassmorphism definida en las plantillas HTML originales.
* **Reutilización:** Al crear vistas Blade en `resources/views/`, convertir directamente la estructura visual de `index.html`, `create.html`, `planes.html`, `checkout.html` y `panel-admin.html`.
* **Seguridad en Formularios:** Incluir siempre `@csrf` en formularios Blade y directivas `@method` para verbos PUT/DELETE.

---

## 🔍 Protocolo de Depuración y Diagnóstico (Debug Mode)
Al asistir en la resolución de bugs, excepciones o fallos de ejecución:

1. **Análisis de Causa Raíz:** Identificar archivo, línea y motivo exacto de la falla (conflicto de FK, tipos en PHP 8.3, promesas colgadas en Node, variables de entorno faltantes).
2. **Parche Mínimo:** Proponer la solución directa y limpia sin alterar componentes o rutas funcionales.
3. **Validación de Integridad:** Verificar que los cambios no rompan la normalización 3FN ni los contratos entre servicios.

---

## 🎯 Modo de Trabajo del Agente
* Explicar brevemente qué archivos se crearán o modificarán antes de aplicar cambios estructurales.
* Priorizar tipado estricto en PHP (`declare(strict_types=1);`), `FormRequest` para validación de entradas y modelos con `$fillable` y `$casts` explícitos.