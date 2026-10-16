/* ProServers · pie institucional compartido.
   Uso: <footer data-ps-footer></footer> (+ data-compact para la versión de una línea)
   Estado de nodos en vivo: /ping (daemon Node) y /api/ping (Laravel), cada 60 s. */
(() => {
  'use strict';
  const SOC = [
    ['Instagram', 'instagram'], ['TikTok', 'tiktok'], ['YouTube', 'youtube'], ['Telegram', 'telegram'], ['Discord', 'discord'],
  ];
  const ic = (n) => `https://cdn.jsdelivr.net/npm/simple-icons@13.21.0/icons/${n}.svg`;

  const html = () => `
  <div class="psf-wrap">
    <div class="psf-grid">
      <div class="psf-about">
        <a href="/" class="psf-brand"><img src="/img/favicon.ico" alt="" width="26" height="26">ProServers</a>
        <p>Servidores de Minecraft en contenedores Docker aislados, con consola en vivo, túneles Playit.gg y diagnóstico con Mine AI.</p>
        <div class="psf-socials">${SOC.map(([n, s]) => `<a class="psf-soc" href="#" data-pending="true" aria-label="${n} (próximamente)" title="${n}"><i style="--ic:url(${ic(s)})"></i></a>`).join('')}</div>
      </div>
      <nav aria-label="Plataforma"><h4>Plataforma</h4><ul>
        <li><a href="/index.html">Panel de control</a></li>
        <li><a href="/crear.html">Desplegar servidor</a></li>
        <li><a href="/planes.html">Planes y precios</a></li>
        <li><a href="/index.html#perfil">Mi cuenta</a></li>
      </ul></nav>
      <nav aria-label="Soporte"><h4>Soporte</h4><ul>
        <li><a href="/index.html#soporte">Abrir un ticket</a></li>
        <li><a href="/index.html#ai">Mine AI</a></li>
        <li><a href="/legal.html#terminos">Uso aceptable</a></li>
      </ul></nav>
      <nav aria-label="Legal"><h4>Legal</h4><ul>
        <li><a href="/legal.html#terminos">Términos de servicio</a></li>
        <li><a href="/legal.html#privacidad">Privacidad</a></li>
        <li><a href="/legal.html#eula">Licencia (EULA)</a></li>
        <li><a class="psf-regret" href="/legal.html#arrepentimiento">Botón de Arrepentimiento</a></li>
      </ul></nav>
      <div><h4>Estado de nodos</h4>
        <div class="psf-status" role="status" aria-live="polite">
          <div class="psf-node" data-s="" data-ps-node="/ping"><span class="psf-led"></span><b>Daemon</b><span>…</span></div>
          <div class="psf-node" data-s="" data-ps-node="/api/ping"><span class="psf-led"></span><b>API</b><span>…</span></div>
        </div>
      </div>
    </div>
    <div class="psf-bottom">
      <span>© ${new Date().getFullYear()} ProServers · Professional Servers. Todos los derechos reservados. Minecraft es marca de Mojang AB; no afiliado.</span>
      <span class="psf-sign">PROFESSIONAL SERVERS V1.0 | BY @MAXPRO</span>
    </div>
    <div class="psf-mega" aria-hidden="true">PROSERVERS</div>
  </div>`;

  async function ping(el) {
    const t0 = performance.now();
    let ok = false;
    try { ok = (await fetch(el.dataset.psNode, { cache: 'no-store', headers: { Accept: 'application/json' } })).ok; } catch { ok = false; }
    el.dataset.s = ok ? 'ok' : 'down';
    el.lastElementChild.textContent = ok ? `${Math.max(1, Math.round(performance.now() - t0))} ms` : 'Sin respuesta';
  }

  function montar() {
    const pies = document.querySelectorAll('footer[data-ps-footer]');
    if (!pies.length) return;
    pies.forEach(f => {
      f.classList.add('psf');
      if (f.hasAttribute('data-compact')) f.classList.add('psf-compact');
      f.innerHTML = html();
      f.addEventListener('click', e => { if (e.target.closest('[data-pending]')) e.preventDefault(); });
    });
    const revisar = () => document.querySelectorAll('[data-ps-node]').forEach(ping);
    revisar();
    setInterval(() => { if (!document.hidden) revisar(); }, 60000);
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', montar); else montar();
})();
