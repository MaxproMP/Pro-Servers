/* ProServers Motion v1.0 — API global: window.PSMotion
   crossfade(container, nextPane)       → fundido cruzado entre .ps-pane
   shared(pairs, update)                → elemento compartido (View Transitions o FLIP)
   dock(el, { axis, max, sigma })       → dock con lupa gaussiana
   ring(el, { size, stroke })           → anillo de progreso animado: .set(pct) .done() .fail()
   count(el, value, { decimals, suffix }) → número que se desliza al nuevo valor
   Focus Blur es solo CSS: .ps-focus-group > .ps-focus-item */
(() => {
  'use strict';
  const calm = () => matchMedia('(prefers-reduced-motion: reduce)').matches;
  const dur = () => (calm() ? 10 : 350);

  /* 1. CROSSFADE */
  function crossfade(container, next) {
    if (!container || !next) return;
    const prev = container.querySelector(':scope > .ps-pane.is-active');
    if (prev === next) return;
    container.querySelectorAll(':scope > .ps-pane.is-leaving').forEach(p => p.classList.remove('is-leaving'));
    next.classList.add('is-entering', 'is-active');
    void next.offsetWidth; // fuerza el estado inicial antes de transicionar
    next.classList.remove('is-entering');
    if (prev) {
      prev.classList.replace('is-active', 'is-leaving');
      setTimeout(() => prev.classList.remove('is-leaving'), dur() + 40);
    }
  }

  /* 2. ELEMENTO COMPARTIDO
     pairs: [{ from: Element, to: selector | () => Element, name: 'ps-title' }]
     update: función (async o no) que arma la vista destino. */
  async function shared(pairs, update) {
    const resolve = (t) => (typeof t === 'function' ? t() : document.querySelector(t));
    if (calm()) { await update(); return; }

    if (document.startViewTransition) {
      pairs.forEach(p => p.from && (p.from.style.viewTransitionName = p.name));
      const vt = document.startViewTransition(async () => {
        pairs.forEach(p => p.from && (p.from.style.viewTransitionName = ''));
        await update();
        pairs.forEach(p => { const el = resolve(p.to); if (el) el.style.viewTransitionName = p.name; });
      });
      try { await vt.finished; } finally {
        pairs.forEach(p => { const el = resolve(p.to); if (el) el.style.viewTransitionName = ''; });
      }
      return;
    }

    // Fallback FLIP: medir origen, actualizar, invertir y animar al lugar final.
    const first = pairs.map(p => p.from?.getBoundingClientRect());
    await update();
    pairs.forEach((p, i) => {
      const el = resolve(p.to); const a = first[i];
      if (!el || !a || !el.animate) return;
      const b = el.getBoundingClientRect();
      if (!b.width || !b.height) return;
      el.animate([
        { transformOrigin: 'top left', transform: `translate(${a.left - b.left}px, ${a.top - b.top}px) scale(${a.width / b.width}, ${a.height / b.height})` },
        { transformOrigin: 'top left', transform: 'none' },
      ], { duration: 450, easing: 'cubic-bezier(0.22, 1, 0.36, 1)' });
    });
  }

  /* 3. MAGNIFICATION — escala gaussiana según la distancia del cursor al centro de cada ítem. */
  function dock(el, { axis = el.dataset.axis || 'x', max = 1.55, sigma = 70 } = {}) {
    if (!el || el.dataset.psDock) return;
    el.dataset.psDock = '1';
    el.dataset.axis = axis;
    const items = () => [...el.querySelectorAll('.ps-dock-item')];
    const fino = matchMedia('(hover: hover) and (pointer: fine)');
    let raf = 0, last = null;

    const paint = () => {
      raf = 0;
      if (!last) return;
      let cercano = null, mejor = Infinity;
      for (const it of items()) {
        const r = it.getBoundingClientRect();
        const c = axis === 'y' ? r.top + r.height / 2 : r.left + r.width / 2;
        const d = (axis === 'y' ? last.clientY : last.clientX) - c;
        const s = calm() ? 1 : 1 + (max - 1) * Math.exp(-(d * d) / (2 * sigma * sigma));
        it.style.setProperty('--ps-scale', s.toFixed(3));
        if (Math.abs(d) < mejor) { mejor = Math.abs(d); cercano = it; }
      }
      items().forEach(it => it.classList.toggle('is-near', it === cercano));
    };
    el.addEventListener('pointermove', e => {
      if (!fino.matches) return;
      last = e;
      if (!raf) raf = requestAnimationFrame(paint);
    });
    el.addEventListener('pointerleave', () => {
      last = null;
      items().forEach(it => { it.style.setProperty('--ps-scale', '1'); it.classList.remove('is-near'); });
    });
  }

  /* 5. ANILLO DE PROGRESO — interpola hacia el avance real y, mientras espera el próximo dato,
     avanza muy despacio (sin pasar del 99 % ni alejarse más de 8 puntos del último valor real). */
  function ring(el, { size = 64, stroke = 5 } = {}) {
    const r = (size - stroke) / 2, C = 2 * Math.PI * r;
    el.classList.add('ps-ring');
    el.style.width = el.style.height = size + 'px';
    el.innerHTML = `<svg viewBox="0 0 ${size} ${size}" aria-hidden="true">
      <circle class="ps-ring-track" cx="${size / 2}" cy="${size / 2}" r="${r}" stroke-width="${stroke}"/>
      <circle class="ps-ring-bar" cx="${size / 2}" cy="${size / 2}" r="${r}" stroke-width="${stroke}" stroke-dasharray="${C}" stroke-dashoffset="${C}"/>
      <circle class="ps-ring-sheen" cx="${size / 2}" cy="${size / 2}" r="${r}" stroke-width="${stroke}" stroke-dasharray="${C * 0.08} ${C}"/>
    </svg><span class="ps-ring-val">0%</span>`;
    el.setAttribute('role', 'progressbar'); el.setAttribute('aria-valuemin', '0'); el.setAttribute('aria-valuemax', '100');
    const bar = el.querySelector('.ps-ring-bar'), val = el.querySelector('.ps-ring-val');
    let shown = 0, real = 0, raf = 0, fin = false;
    const paint = () => {
      bar.style.strokeDashoffset = String(C * (1 - shown / 100));
      val.textContent = Math.round(shown) + '%';
      el.setAttribute('aria-valuenow', String(Math.round(shown)));
    };
    const tick = () => {
      const techo = fin ? real : Math.min(99, real + 8);
      const objetivo = shown < real ? real : techo;
      const paso = shown < real ? (real - shown) * (calm() ? 1 : 0.12) : (calm() ? 0 : (techo - shown) * 0.008);
      shown = Math.min(objetivo, shown + Math.max(paso, 0));
      paint();
      if (!fin || shown < real - 0.1) raf = requestAnimationFrame(tick); else { shown = real; paint(); raf = 0; }
    };
    const start = () => { if (!raf) raf = requestAnimationFrame(tick); };
    return {
      set(p) { real = Math.max(real, Math.min(100, Number(p) || 0)); start(); },
      done() { fin = true; real = 100; el.classList.add('is-done'); start(); },
      fail() { fin = true; el.classList.add('is-error'); start(); },
      reset() { fin = false; real = shown = 0; el.classList.remove('is-done', 'is-error'); paint(); start(); },
    };
  }

  /* 6. CONTADOR — desliza el número mostrado hasta el nuevo valor (métricas en vivo). */
  function count(el, value, { decimals = 0, suffix = '', duration = 600 } = {}) {
    if (!el) return;
    const to = Number(value) || 0, from = Number(el.dataset.psVal ?? to);
    el.dataset.psVal = String(to);
    const fmt = (n) => n.toFixed(decimals) + suffix;
    if (calm() || from === to || !el.isConnected) { el.innerHTML = fmt(to); return; }
    const t0 = performance.now();
    cancelAnimationFrame(Number(el.dataset.psRaf || 0));
    const step = (t) => {
      const k = Math.min(1, (t - t0) / duration), e = 1 - Math.pow(1 - k, 3);
      el.innerHTML = fmt(from + (to - from) * e);
      if (k < 1) el.dataset.psRaf = String(requestAnimationFrame(step));
    };
    el.dataset.psRaf = String(requestAnimationFrame(step));
  }

  window.PSMotion = { crossfade, shared, dock, ring, count };
})();
