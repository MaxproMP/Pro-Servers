<div align="center">

# 🚀 ProServers | Cloud & Game Hosting Automation

![Status](https://img.shields.io/badge/Status-En%20Desarrollo-orange?style=for-the-badge)
![Version](https://img.shields.io/badge/Versión-v0.9_beta-blue?style=for-the-badge)
![Target](https://img.shields.io/badge/Meta-v1.0_Stable-success?style=for-the-badge)

*Plataforma automatizada de despliegue, gestión y auditoría de servidores de alto rendimiento.*

</div>

---

## 📌 Estado Actual del Proyecto
El proyecto ha evolucionado a la fase **v0.9 beta (Fase "El Imperio")**. Dejamos de ser solo un script de despliegue para convertirnos en una infraestructura en la nube completa. Hemos implementado el **Panel CEO (God Mode)**, auditoría en tiempo real, asistencia IA y control estricto de recursos. Estamos en la recta final hacia la **v1.0 (Versión Oficial)** 🎯.

---

## 🛠️ Arquitectura Tecnológica
El sistema está diseñado bajo una arquitectura de microservicios robusta y moderna:
*   ⚡ **Backend / Cerebro:** Node.js & Express (Daemon, APIs, WebSockets). Próxima capa de facturación en Laravel 13.
*   🔒 **Seguridad y DB:** Firebase Auth (Identidad) + MongoDB (Nodos y Usuarios) + SQL Server (Facturación).
*   🐳 **Infraestructura:** Dockerode (control absoluto de contenedores y límites de hardware).
*   🌐 **Redes:** Playit.gg (Túneles TCP/UDP dinámicos) acoplados a cada nodo.
*   🤖 **Inteligencia Artificial:** Groq API integrada para "Mine AI" (Análisis de logs en tiempo real).
*   ☁️ **Nube:** Integración nativa con Google Drive y OneDrive para backups.

---

## 📜 Historia y Evolución (El Origen y el 🚫 de Copilot)
El nacimiento de ProServers no fue corporativo, sino académico y lleno de anécdotas de trinchera que forjaron su arquitectura actual. Una verdadera historia de resiliencia de código:

*   **v0.0 Pre-Alpha (2024) - "El Proyecto de Facultad":** Todo nació a las apuradas en el primer año del terciario. La idea original iba a ser física junto a un compañero, pero fracasó. Para salvar la nota, hubo una fusión obligada con otro grupo. Las primeras pruebas de despliegue fueron un caos de infraestructura: se intentó orquestar en AWS con Jenkins y falló estrepitosamente.
*   **v0.1 a v0.4 Beta (2025/2026) - "El Camino Solo y la Presentación":** Ya en segundo año de la carrera de Técnico Superior Analista de Sistemas, me di de baja del grupo original y empecé a desarrollarlo por mi cuenta. Llegó el día de presentar el proyecto ante **todos los profesores de la carrera**. Para testear y levantar el primer servidor a las apuradas, se usó **Kubernetes**. El backend fue un éxito total (¡el server de Minecraft prendió!), pero la interfaz... era un desastre hermoso. De tantos cambios locos intentando integrar sugerencias de GitHub Copilot a último minuto, lo único que funcionaba era la consola y el gestor de archivos. No podías ni prender ni apagar el servidor desde el panel, porque el botón había quedado literalmente trabado con este ícono: 🚫. 
*   **v0.5 a v0.8 Beta - "La Plataforma":** Dejando atrás K8s, se migró a una integración limpia y oficial con la API de Docker local. Creación del primer panel web interactivo real. Se sumaron el Gestor de Archivos estable, la consola RCON y las métricas, aunque la UX seguía dependiendo de alertas nativas del navegador.
*   **v0.9 Beta (Actual) - "El Imperio y la Independencia":** Reestructuración total y desarrollo 100% *in-house*. Se abandonó cualquier código de terceros. Desde el login hasta el dashboard modular (*Dark Theme / Glassmorphism* impulsado por Tailwind CSS), todo el frontend es de autoría propia. Además, se introdujo el Modo CEO, la *Zona de Hielo* y la Inteligencia Artificial (Mine AI), convirtiendo un salvavidas universitario que tenía botones rotos (🚫) en un software corporativo de élite.

---

## 🗺️ Roadmap de Desarrollo

### FASE 1: Core de Infraestructura (v0.1 - v0.8) ✅
- [x] Arquitectura base de nodos y contenedores Docker.
- [x] Daemon de Node.js para despliegue automático e inyección de variables.
- [x] Gestor de archivos integrado y descarga de Modpacks masivos.
- [x] Panel de control completo para usuarios finales (Consola RCON, Métricas, Staff).

### FASE 2: Control Avanzado y Experiencia de Usuario (v0.9) 🚧
- [x] Integración definitiva con base de datos híbrida (MongoDB / SQL).
- [x] **Panel Superadmin (CEO):** Visión global de hardware, ingresos y acceso espía a contenedores.
- [x] **Sistema de Soporte en Vivo:** WebSockets para chat en tiempo real cliente-admin.
- [x] **Seguridad Extrema (Zona de Hielo):** Suspensión total e instantánea de nodos y paneles de clientes morosos o baneados.
- [x] **Mine AI Blindado:** Analista técnico virtual para revisar logs y diagnosticar crasheos.
- [x] **Ecosistema de Mods Automático:** Búsqueda e instalación in-panel de CurseForge (Mods, Plugins, Datapacks y Server Packs).
- [x] **Soporte Multimotor:** Compatibilidad nativa con ecosistemas Java y Bedrock.
- [ ] **Acceso Compartido 2.0:** Mejorar el panel de invitados para dar permisos más granulares.
- [ ] **Sistema de Clonación de Nodos:** Terminar la función para duplicar servidores de prueba con un solo clic.

### FASE 3: Monetización, UX y Legal (v1.0) 🎯
- [ ] **Perfil de Usuario Avanzado:** Nuevos campos para agregar información extra.
- [ ] **Hub de Facturación (Billing):** Integración de un sistema tipo "Checkout" para gestión de planes y pagos.
- [ ] **Estructura Legal y Derechos:** Agregar términos y condiciones, contratos y políticas de privacidad.
- [ ] **Frontend Footer:** Desarrollar pie de página con datos corporativos y accesos legales.
- [ ] Lanzamiento oficial **ProServers v1.0** 🚀.

---
<p align="center">Desarrollado con 💻, noches sin dormir y mucho café por Maximo Rodas en Misiones, Argentina.</p>
