/* ProServers Motion v1.0 — API global: window.PSMotion
   crossfade(container, nextPane)       → fundido cruzado entre .ps-pane
   shared(pairs, update)                → elemento compartido (View Transitions o FLIP)
   dock(el, { axis, max, sigma })       → dock con lupa gaussiana
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

  window.PSMotion = { crossfade, shared, dock };
})();
