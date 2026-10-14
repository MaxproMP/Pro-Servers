# CLAUDE.md — Guía Oficial, Ahorro y Reglas: ProServers

## ⚡ Reglas de Ahorro de Uso, Modelos ("El Portero") y Estilo Ponytail
- **Modelo por defecto:** `opusplan` (Opus planifica en Modo Plan, Sonnet ejecuta el código).
- **Sub-agentes ("El Portero"):**
  - Para buscar archivos, explorar carpetas, leer logs o cambios chicos de texto/CSS: usar siempre el modelo más barato (**Haiku** / sub-agente de exploración).
  - Para programar un plan aprobado: **Sonnet**.
  - Para diseñar arquitectura 3FN o bugs graves: **Opus**.
- **Estilo Ponytail (Concisión Extrema):**
  - Cero introducciones ni explicaciones de relleno. Escribir el parche mínimo indispensable (no escribir 200 líneas si se resuelve en 20).
  - Antes de crear código nuevo, verificar si ya existe en el proyecto o si lo resuelve Laravel 13 / Node.js de forma nativa.
  - Nunca recortar validación, manejo de errores ni seguridad. Cerrar indicando qué NO se verificó.
- **Higiene de Contexto:**
  - Prohibido leer `core/vendor/`, `node_modules/`, `.git/`, `*.log` o archivos `.env`.
  - Avisar proactivamente cuándo conviene tirar `/compact` o `/clear`.
- **Herramientas Activas:** Usar `context7` (MCP) para documentación actualizada, `Playwright CLI` para testear en navegador y `Taste Skill` para diseño frontend.

---

## 📌 Visión General del Proyecto
**ProServers** es una plataforma SaaS para la automatización, aprovisionamiento y administración de servidores de Minecraft sobre contenedores Docker independientes, integrada con **Pro Server Bot** (Discord).

Opera bajo una arquitectura desacoplada en `~/Proservers`:
1. **Core / Daemon (`backend/` - Node.js v20 & Express):** Orquestación con Dockerode (`/var/run/docker.sock`), túneles Playit.gg, WebSockets para consola RCON en vivo y telemetría.
2. **Módulo de Facturación y Gestión (`core/` - Laravel 13 & PHP 8.3):** Capa comercial y académica para administración de planes, suscripciones, pasarelas de pago, tickets de soporte y auditoría en 3FN.

---

## 🛠️ Stack Tecnológico Oficial
- **Backend:** PHP 8.3 + Laravel 13 (`core/` - Billing & Auth) | Node.js v20 + Express (`backend/` - Daemon / Infraestructura).
- **Base de Datos Relacional (3FN):** **Microsoft SQL Server** (Esquema relacional estricto en 3FN para usuarios, planes, clientes, suscripciones y pagos).
- **Base de Datos NoSQL:** **MongoDB Atlas** (Nodos, telemetría y servidores en juego).
- **Autenticación:** Firebase Auth (Web & Backend Token Verification) + Laravel Auth nativo + WebAuthn.
- **Inteligencia Artificial ("Mine AI"):** Groq API / Llama 3 (Auditoría de logs de consola y diagnóstico de fallos).
- **Frontend:** Plantillas HTML (`frontend/`, `index.html`, `admin.html`, `Entrads.html`) y Blade Templates (`core/resources/views/`) + Tailwind CSS + Feather Icons (Dark UI / Glassmorphism de autoría propia).
- **Integraciones Externas:** CurseForge API + Modrinth API + Playit.gg.

---

## 📐 Reglas Estrictas de Base de Datos y Negocio (Esquema v2 - NO ROMPER)
1. **Jerarquía Relacional Suscripción-Servidor:**
   - Relación: `usuarios -> suscripciones -> servidores`.
   - Un servidor **NO** se vincula a `id_usuario`. Pertenece a una `id_suscripcion` (1:1), garantizando que los límites de hardware (RAM/CPU) dependan del plan contratado.
2. **Normalización 3FN en Pagos:**
   - La tabla `pagos` se vincula únicamente con `id_suscripcion`. Prohibido almacenar `id_usuario` directamente en `pagos` para evitar dependencias transitivas.
   - `pagos` distingue `pasarela_pago` (MercadoPago, Stripe, PayPal) de `medio_pago` (tarjeta, transferencia, saldo).
3. **Auditoría y Borrado Lógico:**
   - Tablas financieras (`usuarios`, `suscripciones`) utilizan Soft Deletes (`deleted_at` / estado inactivo) y `ON DELETE RESTRICT` hacia pagos para preservar el historial contable.
   - Tablas técnicas dependientes (`mods_instalados`, `mensajes_ticket`) utilizan `ON DELETE CASCADE`.
4. **Trazabilidad de Mine AI:**
   - Incidencias divididas en `tickets_soporte` (cabecera) y `mensajes_ticket` (hilo).
   - Los diagnósticos generados por la IA deben persistirse en `mensajes_ticket` junto con el extracto del log analizado (`log_analizado`).
5. **Restricciones de Dominio:**
   - Validaciones `CHECK` en memoria (>0), cpu (>0), almacenamiento (>0), precios (>=0), montos (>0) y puertos TCP (1024 a 65535).

---

## 🎨 Convenciones de Frontend y Modo Plan
- Respetar la identidad Dark Theme / Glassmorphism definida en las plantillas HTML originales.
- En vistas Blade (`core/resources/views/`), incluir siempre `@csrf` en formularios y `@method` para PUT/DELETE.
- En PHP 8.3: priorizar `declare(strict_types=1);`, `FormRequest` para validación y modelos Eloquent con `$fillable` y `$casts` explícitos.
- **Modo Plan Obligatorio:** Antes de modificar cualquier archivo, entregar diagnóstico de causa raíz (`archivo:línea`) y plan de máximo 3 pasos numerados. Esperar aprobación antes de editar.