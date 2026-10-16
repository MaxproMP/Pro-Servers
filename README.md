<div align="center">

# 🚀 ProServers | Cloud & Game Hosting Automation

![Status](https://img.shields.io/badge/Status-v1.0%20Release%20Active-success?style=for-the-badge)
![Version](https://img.shields.io/badge/Versión-v1.0_Official-blue?style=for-the-badge)
![Security](https://img.shields.io/badge/Auth-2FA%20%2B%20WebAuthn-8A2BE2?style=for-the-badge)
![Database](https://img.shields.io/badge/DB-SQL%20Server%203FN%20%2B%20MongoDB-0078D4?style=for-the-badge)
![Target](https://img.shields.io/badge/Etapa-Fase%204%20UI%20%26%20Brand%20Overhaul-ff69b4?style=for-the-badge)

*Plataforma automatizada de despliegue, gestión y auditoría de servidores de alto rendimiento.*

</div>

---

## 📌 Estado Actual del Proyecto
El proyecto ha culminado exitosamente la **v0.9.5 Beta (El Punto de Inflexión Híbrido)** y entra oficialmente en la **v1.0**. Esta versión ya cuenta con la arquitectura monorepo consolidada y desplegada en el dominio oficial, con el centro de comando administrativo **Panel Admin**, métricas 100% reales desde SQL Server y Docker, generadores contables de PDF, blindaje de memoria en contenedores y las nuevas interfaces de despliegue (`crear.html`) y catálogo interactivo (`planes.html`). El objetivo actual en esta **v1.0** es completar el rediseño total de la experiencia de cliente, la página principal inmersiva, el checkout y la nueva identidad visual corporativa.

### Estado de la versión v1.0
- **Fase:** lanzamiento v1.0 y remodelación visual de experiencia de usuario y marca.
- **Entorno:** monorepo híbrido operativo con Nginx (DNS dinámico anti-502), Laravel, Node.js, Docker, Firebase y Playit.gg.
- **Dominio:** `https://www.professional-servers.com.ar` operativo con HTTPS.
- **Despliegue:** creación de servidores Java y Bedrock en 3 pasos, contenedores Minecraft/Playit con topes seguros de RAM/CPU según el plan, buscador de modpacks (CurseForge y Modrinth), instalación por URL validada contra SSRF, volúmenes persistentes y acciones de inicio, detención y reinicio implementadas.
- **Seguridad:** APIs del daemon protegidas con Firebase y reenvío Bearer desde Laravel; comunicación interna Laravel → Node protegida con `x-daemon-secret`; WebSocket con autenticación y control de propiedad.
- **Autenticación Avanzada (2FA & WebAuthn):** verificación en dos pasos con TOTP y código QR (`google2fa_secret`), soporte de llaves biométricas/Passkeys (`WebAuthn`), login social federado (Google, GitHub, Microsoft), flujo de Recovery de cuentas por Soporte y sincronización de sesión (`authStateReady`) anti-bucles.
- **Integridad Contable 3FN, Reembolsos y PDFs:** esquema relacional estricto en Tercera Forma Normal (`clientes -> suscripciones -> servidores`), gestión de reembolsos/reparaciones, borrado lógico (`Soft Deletes`), generación de facturas y reportes de tickets en PDF, y base de datos 100% libre de registros falsos o seeders de prueba.
- **Ecosistema Discord:** integración con **Pro Server Bot** para notificaciones transaccionales, alertas operativas y gestión remota.
- **Calidad:** suite Pest con 34 pruebas y 114 aserciones ejecutadas en verde (con candado de memoria SQLite para proteger Azure SQL), validaciones de sintaxis para PHP/Node/Python y configuración Docker verificada.

---

## 🛠️ Arquitectura Tecnológica (Monorepo v1.0)
El sistema está diseñado bajo una arquitectura de microservicios robusta y separada por dominios de responsabilidad:
-   🧠 **El Cerebro (API Core):** Laravel (PHP 8.4) + Nginx. Se encarga del enrutamiento de la API, la autenticación, modelos de negocio, integraciones de APIs externas (Groq, CurseForge, Modrinth), pasarelas de pago, emisión de PDFs y soporte para pruebas automatizadas con Pest v5.
-   ⚙️ **El Demonio (Infraestructura):** Node.js & Express + Socket.io. Un microservicio aislado y protegido que solo obedece a Laravel. Tiene el control absoluto de Dockerode, aplicación de límites físicos de RAM/CPU por contenedor, gestión de archivos masivos (I/O) y WebSockets para las consolas en vivo.
-   🔒 **Seguridad y Bases de Datos:** 
    *   **Microsoft SQL Server (AWS RDS / Azure SQL):** Base de datos relacional estricta para Facturación, Membresías, Planes y Perfiles de Usuario.
    *   **MongoDB Atlas:** Almacenamiento rápido y flexible para logs, auditorías y estados de los nodos.
    *   **Firebase Auth:** Emisión de tokens JWT de identidad verificados en ambas capas.
    *   **Verificación en Dos Pasos (2FA) y WebAuthn:** Capa adicional de blindaje de identidad con segundo factor TOTP, llaves de seguridad biométricas (Passkeys), proveedores OAuth (Google, GitHub, Microsoft) y sistema de recuperación asistida por Soporte.
    *   **Normalización 3FN y Auditoría Financiera:** Separación estricta de `pagos` y `reembolsos` vinculados por `id_suscripcion`, `ON DELETE RESTRICT` para historial contable y validaciones de dominio `CHECK`.
-   🌐 **Redes:** Playit.gg (Túneles TCP/UDP dinámicos) acoplados a cada nodo de Docker con reseteo de red automático.
-   🤖 **Inteligencia Artificial:** API de Groq integrada nativamente para "Mine AI" (Análisis de logs y autodiagnóstico técnico en tiempo real).
-   💬 **Pro Server Bot (Discord Integration):** Servicio integrado al monorepo para envío de alertas de estado, avisos de facturación y control administrativo desde Discord.
-   🎨 **Frontend (Obsidian Cyber-Ops / Dark UI / Glassmorphism):** Vistas desacopladas (`index.html`, `crear.html`, `planes.html`, `checkout.html`, `perfil.html`, `admin.html`) con Tailwind CSS, Three.js 3D, tipografía `Plus Jakarta Sans` + `JetBrains Mono`, control de carrera de sesión y conmutación segura de Modo CEO/Usuario.

---

## 📜 Historia y Evolución (El Origen y el 🚫 de Copilot)
El nacimiento de ProServers no fue corporativo, sino académico y lleno de anécdotas de trinchera que forjaron su arquitectura actual. Una verdadera historia de resiliencia de código:

-   **v0.0 Pre-Alpha (2024) - "El Proyecto de Facultad":** Todo nació a las apuradas en el primer año del terciario. La idea original iba a ser física junto a un compañero, pero fracasó. Para salvar la nota, hubo una fusión obligada con otro grupo. Las primeras pruebas de despliegue fueron un caos de infraestructura: se intentó orquestar en AWS con Jenkins y falló estrepitosamente.
-   **v0.1 a v0.4 Beta (2025/2026) - "El Camino Solo y la Presentación":** Ya en segundo año de la carrera de Técnico Superior Analista de Sistemas, me di de baja del grupo original y empecé a desarrollarlo por mi cuenta. Llegó el día de presentar el proyecto ante **todos los profesores de la carrera**. Para testear y levantar el primer servidor a las apuradas, se usó **Kubernetes**. El backend fue un éxito total (¡el server de Minecraft prendió!), pero la interfaz... era un desastre hermoso. De tantos cambios locos intentando integrar sugerencias de GitHub Copilot a último minuto, lo único que funcionaba era la consola y el gestor de archivos. No podías ni prender ni apagar el servidor desde el panel, porque el botón había quedado literalmente trabado con este ícono: 🚫. 
-   **v0.5 a v0.8 Beta - "La Plataforma":** Dejando atrás K8s, se migró a una integración limpia y oficial con la API de Docker local. Creación del primer panel web interactivo real. Se sumaron el Gestor de Archivos estable, la consola RCON y las métricas.
-   **v0.9 Beta - "El Imperio y la Independencia":** Reestructuración total y desarrollo 100% *in-house*. Se abandonó cualquier código de terceros. Se introdujo el Modo CEO, la *Zona de Hielo*, Mine AI, los permisos granulares de invitados y la clonación de nodos.
-   **v0.9.5 Beta - "El Punto de Inflexión Híbrido":** A un paso de la v1.0, el sistema exigía una profesionalización mayor. Se decidió que Node.js no podía manejar de forma segura la facturación de los clientes y el control de Docker al mismo tiempo. Se migró la plataforma a un Monorepo, introduciendo Laravel y Microsoft SQL Server como el "Cerebro" corporativo, delegando a Node.js la tarea exclusiva de "Demonio" de infraestructura. Sobre esta base se sumó la autenticación con verificación en dos pasos (2FA), soporte WebAuthn (Passkeys), gestión contable de reembolsos en 3FN, integración con Pro Server Bot y la estabilización del flujo de sesiones entre Firebase y Laravel.
-   **v1.0 Oficial (Actual) - "La Era del Panel Admin y Experiencia Inmersiva":** Con el backend híbrido blindado y operando exclusivamente con datos de producción reales, nace el centro de comando **Panel Admin** en 3D con gestión integral de cuentas (IAM Recovery), facturación PDF y el rediseño completo de las interfaces de despliegue y planes escalonados.

---

## 🗺️ Roadmap de Desarrollo

### FASE 1: Core de Infraestructura (v0.1 - v0.8) ✅
- [x] Arquitectura base de nodos y contenedores Docker.
- [x] Daemon de Node.js para despliegue automático e inyección de variables.
- [x] Gestor de archivos integrado y descarga de Modpacks masivos.
- [x] Panel de control completo para usuarios finales (Consola RCON, Métricas).

### FASE 2: Control Avanzado y Experiencia de Usuario (v0.9) ✅
- [x] **Panel Superadmin (CEO):** Visión global de hardware, ingresos y acceso espía a contenedores.
- [x] **Sistema de Soporte en Vivo:** WebSockets para chat en tiempo real cliente-admin y roles de staff dedicados.
- [x] **Seguridad Extrema (Zona de Hielo):** Suspensión total e instantánea de nodos y paneles de clientes morosos o baneados.
- [x] **Mine AI Blindado:** Analista técnico virtual para revisar logs y diagnosticar crasheos.
- [x] **Ecosistema de Mods Automático:** Búsqueda e instalación in-panel de CurseForge.
- [x] **Acceso Compartido 2.0 & Clonación:** Panel de invitados avanzado y duplicación automática de servidores.

### FASE 3: El Punto de Inflexión Híbrido (v0.9.5) ✅
*Motivo del cambio: Separación de responsabilidades. Aislamiento de la lógica de negocio y facturación (Laravel/SQL) del acceso de bajo nivel a los contenedores y el disco duro (Node/Docker).*
- [x] **Arquitectura Monorepo:** Consolidación de Nginx, Dockerfile, Laravel y Node.js en un entorno unificado.
- [x] **Dominio y proxy HTTPS:** Publicación del frontend y APIs bajo `professional-servers.com.ar`, incluyendo health checks, resolver DNS dinámico anti-502 y WebSockets.
- [x] **Refactorización de Node.js:** El daemon quedó enfocado en Docker, I/O, consola, acciones de servidor, persistencia de volúmenes y aplicación de topes seguros de RAM/CPU.
- [x] **Persistencia Docker:** Corrección de rutas host para que los archivos de Minecraft se conserven en `./servers/srv-*`.
- [x] **Seguridad de infraestructura:** Firebase en las APIs del daemon, autorización de propiedad, Socket.io autenticado, protección anti-SSRF en URLs y secreto interno Laravel → Node.
- [x] **CurseForge, Modrinth y Mine AI:** Proxies seguros de CurseForge y Modrinth, búsqueda de modpacks y endpoint autenticado de diagnóstico de logs con Groq.
- [x] **Panel Admin (CEO / Admin / Soporte):** Rediseño total de `admin.html` con núcleo 3D, telemetría real del host, directorio de cuentas SQL Server, Recovery de 2FA/contraseña por correo y exportación de facturas y tickets en PDF.
- [x] **Modelado SQL base y Producción Limpia:** Modelos Eloquent, migraciones, relaciones `clientes -> suscripciones -> servidores`, pagos normalizados, purga total de datos falsos de prueba y restricciones de dominio en Laravel.
- [x] **Validación técnica:** Suite Pest (34 tests en verde), Pint, validaciones de sintaxis y builds Docker ejecutados correctamente.
- [x] **Migración controlada de RDS:** Esquema aplicado y verificado sobre Microsoft SQL Server; planes comerciales con límites de jugadores y escala de hardware cargados.
- [x] **Identidad Firebase en Laravel:** Middleware Bearer, login, perfil, estado y checkout validan directamente tokens Firebase.
- [x] **Auditoría persistente:** MongoDB Atlas conectado; Laravel persiste eventos y Node exporta deploys y diagnósticos Mine AI mediante endpoint firmado.
- [x] **Verificación en Dos Pasos (2FA) y Campos Auth:** Implementación completa de TOTP con código QR en `perfil.html` y validación en login.
- [x] **Autenticación Biométrica y Passkeys (WebAuthn):** Migraciones y soporte para inicio de sesión sin contraseña mediante llaves de seguridad y biometría.
- [x] **Login Social Federado (OAuth):** Integración de acceso con Google, GitHub y Microsoft conectado al flujo de tokens de Firebase y Laravel.
- [x] **Sistema de Reembolsos y Reparaciones:** Migraciones y tablas dedicadas para trazabilidad contable de devoluciones, ajustes y compensaciones en 3FN.
- [x] **Sincronización de Sesión Anti-Bucles y Colisión de Usernames:** Implementación de espera `authStateReady()`, generación de `username` único en SQL Server y nuevo centro de despliegue en `crear.html`.
- [x] **Integración de Pro Server Bot:** Incorporación del bot de Discord al monorepo para notificaciones transaccionales y monitoreo.
- [x] **Catálogo en Vivo y Planes (`planes.html`):** Sincronización en tiempo real con `/api/plans`, descuentos por ciclo (Mensual, Trimestral, Semestral, Anual), límites progresivos de jugadores y período de prueba de 15 días.

### FASE 4: Rediseño Total de Interfaz, Experiencia y Marca (v1.0 - En Progreso 🚀)
- [ ] **Creación de Nuevo Logo Corporativo:** Diseño de una nueva identidad visual moderna, disruptiva y de élite para ProServers.
- [ ] **Rediseño del Panel de Usuario / Cliente:** Remodelación total de la experiencia de gestión de servidores, estadísticas y control de cuenta.
- [ ] **Evolución de Mine AI:** Rediseño, blindaje y potenciación de la inteligencia artificial de autodiagnóstico y auditoría de logs.
- [ ] **Inmersión de la Página Principal (`index.html`):** Implementación de la experiencia de scroll vertical interactivo y visuales de alta gama.
- [ ] **Reestructuración del Checkout (`checkout.html`):** Sincronización completa con los nuevos planes escalonados (8GB-256GB+), ciclos de facturación y pasarelas de pago.
- [ ] **Retiro del legado `users` y Rotación de Secretos:** Eliminar migraciones heredadas, rotar credenciales productivas y cerrar textos legales del footer.
- [ ] **Auditoría Pre-Lanzamiento Web (Checklist 20 Puntos) & Testing E2E (Playwright):** Validar metadatos OpenGraph, `sitemap.xml`, analítica y pruebas automatizadas de extremo a extremo.
- [ ] **Estrategia de Google Ads, SEO y Redes Sociales:** Campañas de captación y posicionamiento orgánico en comunidades de gaming.
- [ ] Lanzamiento comercial definitivo **ProServers v1.0** 🚀.

---
<p align="center">Desarrollado con 💻, noches sin dormir y mucho café por Maximo Rodas en Misiones, Argentina.</p>