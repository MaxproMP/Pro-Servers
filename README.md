<div align="center">

# 🚀 ProServers | Cloud & Game Hosting Automation

![Status](https://img.shields.io/badge/Status-Migración%20Híbrida-warning?style=for-the-badge)
![Version](https://img.shields.io/badge/Versión-v0.9.5_Beta-blue?style=for-the-badge)
![Target](https://img.shields.io/badge/Meta-v1.0_Production-success?style=for-the-badge)

*Plataforma automatizada de despliegue, gestión y auditoría de servidores de alto rendimiento.*

</div>

---

## 📌 Estado Actual del Proyecto
El proyecto ha superado la fase v0.9 y actualmente se encuentra en la **v0.9.5 (El Punto de Inflexión Híbrido)**. Debido a la necesidad de escalar comercialmente y mantener una seguridad empresarial estricta, se tomó la decisión crítica de frenar el lanzamiento de la v1.0 para cambiar radicalmente la arquitectura. Dejamos atrás el monolito para convertirnos en un **Monorepo Híbrido**, separando el cerebro financiero y de negocio (Laravel) del músculo de infraestructura y bajo nivel (Node.js). Todo orquestado bajo un proxy Nginx, preparando el terreno definitivo para el despegue comercial 🎯.

---

## 🛠️ Arquitectura Tecnológica (Monorepo v0.9.5)
El sistema está diseñado bajo una arquitectura de microservicios robusta y separada por dominios de responsabilidad:
*   🧠 **El Cerebro (API Core):** Laravel (PHP 8.4) + Nginx. Se encarga del enrutamiento de la API, la autenticación, modelos de negocio, integraciones de APIs externas (Groq, CurseForge), pasarelas de pago y soporte para pruebas automatizadas con Pest v5.
*   ⚙️ **El Demonio (Infraestructura):** Node.js & Express + Socket.io. Un microservicio aislado y protegido que solo obedece a Laravel. Tiene el control absoluto de Dockerode, gestión de archivos masivos (I/O) y WebSockets para las consolas en vivo.
*   🔒 **Seguridad y Bases de Datos:** 
    *   **Microsoft SQL Server (AWS RDS):** Base de datos relacional estricta para Facturación, Membresías, Planes y Perfiles de Usuario.
    *   **MongoDB Atlas:** Almacenamiento rápido y flexible para logs, auditorías y estados de los nodos.
    *   **Firebase Auth:** Emisión de tokens JWT de identidad verificados en ambas capas.
*   🌐 **Redes:** Playit.gg (Túneles TCP/UDP dinámicos) acoplados a cada nodo de Docker con reseteo de red automático.
*   🤖 **Inteligencia Artificial:** API de Groq integrada nativamente para "Mine AI" (Análisis de logs y autodiagnóstico técnico en tiempo real).

---

## 📜 Historia y Evolución (El Origen y el 🚫 de Copilot)
El nacimiento de ProServers no fue corporativo, sino académico y lleno de anécdotas de trinchera que forjaron su arquitectura actual. Una verdadera historia de resiliencia de código:

*   **v0.0 Pre-Alpha (2024) - "El Proyecto de Facultad":** Todo nació a las apuradas en el primer año del terciario. La idea original iba a ser física junto a un compañero, pero fracasó. Para salvar la nota, hubo una fusión obligada con otro grupo. Las primeras pruebas de despliegue fueron un caos de infraestructura: se intentó orquestar en AWS con Jenkins y falló estrepitosamente.
*   **v0.1 a v0.4 Beta (2025/2026) - "El Camino Solo y la Presentación":** Ya en segundo año de la carrera de Técnico Superior Analista de Sistemas, me di de baja del grupo original y empecé a desarrollarlo por mi cuenta. Llegó el día de presentar el proyecto ante **todos los profesores de la carrera**. Para testear y levantar el primer servidor a las apuradas, se usó **Kubernetes**. El backend fue un éxito total (¡el server de Minecraft prendió!), pero la interfaz... era un desastre hermoso. De tantos cambios locos intentando integrar sugerencias de GitHub Copilot a último minuto, lo único que funcionaba era la consola y el gestor de archivos. No podías ni prender ni apagar el servidor desde el panel, porque el botón había quedado literalmente trabado con este ícono: 🚫. 
*   **v0.5 a v0.8 Beta - "La Plataforma":** Dejando atrás K8s, se migró a una integración limpia y oficial con la API de Docker local. Creación del primer panel web interactivo real. Se sumaron el Gestor de Archivos estable, la consola RCON y las métricas.
*   **v0.9 Beta - "El Imperio y la Independencia":** Reestructuración total y desarrollo 100% *in-house*. Se abandonó cualquier código de terceros. Se introdujo el Modo CEO, la *Zona de Hielo*, Mine AI, los permisos granulares de invitados y la clonación de nodos.
*   **v0.9.5 Beta (Actual) - "El Punto de Inflexión Híbrido":** A un paso de la v1.0, el sistema exigía una profesionalización mayor. Se decidió que Node.js no podía manejar de forma segura la facturación de los clientes y el control de Docker al mismo tiempo. Se migró la plataforma a un Monorepo, introduciendo Laravel y Microsoft SQL Server como el "Cerebro" corporativo, delegando a Node.js la tarea exclusiva de "Demonio" de infraestructura.

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

### FASE 3: El Punto de Inflexión Híbrido (v0.9.5 - En Progreso ⏳)
*Motivo del cambio: Separación de responsabilidades. Aislamiento de la lógica de negocio y facturación (Laravel/SQL) del acceso de bajo nivel a los contenedores y el disco duro (Node/Docker).*
- [x] **Arquitectura Monorepo:** Consolidación de Nginx, Dockerfile, Laravel y Node.js en un entorno unificado.
- [x] **Refactorización de Node.js:** Limpieza total de lógica comercial, transformado en Daemon puro de Docker e I/O.
- [x] **Modelado SQL (Laravel):** Configuración de modelos Eloquent y migraciones apuntando a Microsoft SQL Server (Azure).
- [ ] **Migración de Controladores:** Trasladar la lógica de registro, creación de nodos y validaciones de Firebase al backend de Laravel.
- [ ] **Frontend Footer:** Desarrollar pie de página con datos corporativos y accesos legales.
- [ ] **Hub de Facturación (Billing):** Integración definitiva en producción de pasarelas de pago.

### FASE 4: Monetización, Publicidad y Lanzamiento (v1.0) 🎯
- [ ] **Estructura Legal y Derechos:** Agregar términos y condiciones, contratos y políticas de privacidad.
- [ ] **Estrategia de Google Ads y Paid Media:** Campañas orientadas a palabras clave de alto tráfico para captación de clientes de hosting de juegos y servidores dedicados.
- [ ] **Posicionamiento CEO y Redes Sociales:** Optimización técnica del sitio público para buscadores y campañas orgánicas en comunidades de gaming.
- [ ] Lanzamiento oficial **ProServers v1.0** 🚀.

---
<p align="center">Desarrollado con 💻, noches sin dormir y mucho café por Maximo Rodas en Misiones, Argentina.</p>