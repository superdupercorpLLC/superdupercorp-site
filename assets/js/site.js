// SUPER DUPER CORP — small enhancements. Site works without JS.
(() => {
  // mobile menu
  const t = document.querySelector('.bar__toggle'), nav = document.getElementById('nav');
  if (t && nav) t.addEventListener('click', () => {
    const open = nav.classList.toggle('is-open');
    t.setAttribute('aria-expanded', String(open));
    t.textContent = open ? 'Close' : 'Menu';
  });

  // work filters
  const wrap = document.querySelector('[data-works]');
  const filters = document.querySelector('[data-filters]');
  if (wrap && filters) {
    const cards = [...wrap.querySelectorAll('.card')];
    const years = [...wrap.querySelectorAll('.works__year')];
    const empty = wrap.querySelector('[data-empty]');
    const tagSel = filters.querySelector('[data-tag]');
    const q = filters.querySelector('[data-q]');
    const store = (k, v) => { try { v === undefined ? (v = localStorage.getItem(k)) : localStorage.setItem(k, v); } catch (e) { v = null; } return v; };
    let cat = window.__initialCat || 'all';

    const apply = () => {
      const tag = tagSel.value, s = q.value.trim().toLowerCase();
      let shown = 0;
      const visibleYears = new Set();
      cards.forEach(c => {
        const ok = (cat === 'all' || c.dataset.cat === cat)
          && (!tag || c.dataset.tags.split('|').includes(tag))
          && (!s || c.dataset.hay.includes(s));
        c.hidden = !ok;
        if (ok) { shown++; visibleYears.add(c.dataset.y); }
      });
      years.forEach(y => { y.hidden = !visibleYears.has(y.dataset.year); });
      empty.hidden = shown > 0;
      filters.querySelectorAll('[data-cat]').forEach(b => b.setAttribute('aria-pressed', String(b.dataset.cat === cat)));
      const u = new URL(location.href);
      cat === 'all' ? u.searchParams.delete('c') : u.searchParams.set('c', cat);
      history.replaceState(null, '', u);
    };
    filters.querySelectorAll('[data-cat]').forEach(b => b.addEventListener('click', () => { cat = b.dataset.cat; apply(); }));
    tagSel.addEventListener('change', apply);
    q.addEventListener('input', apply);

    const setView = v => {
      wrap.dataset.view = v;
      filters.querySelectorAll('[data-view]').forEach(b => b.setAttribute('aria-pressed', String(b.dataset.view === v)));
      store('sdc-view', v);
    };
    filters.querySelectorAll('[data-view]').forEach(b => b.addEventListener('click', () => setView(b.dataset.view)));
    const saved = store('sdc-view');
    if (saved === 'list' || saved === 'grid') setView(saved);
    apply();
  }

  // lightbox
  document.addEventListener('click', ev => {
    const a = ev.target.closest('[data-lightbox]');
    if (!a) return;
    ev.preventDefault();
    const lb = document.createElement('div');
    lb.className = 'lb';
    lb.setAttribute('role', 'dialog');
    lb.setAttribute('aria-label', 'Image viewer');
    lb.innerHTML = '<button class="btn" type="button">Close ✕</button>';
    const img = new Image();
    img.src = a.href; img.alt = a.querySelector('img')?.alt || '';
    lb.appendChild(img);
    const close = () => { lb.remove(); document.removeEventListener('keydown', key); a.focus(); };
    const key = e => { if (e.key === 'Escape') close(); };
    lb.addEventListener('click', close);
    document.addEventListener('keydown', key);
    document.body.appendChild(lb);
    lb.querySelector('button').focus();
  });
})();
