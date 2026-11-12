(() => {
  // confirm dialogs
  document.addEventListener('click', e => {
    const b = e.target.closest('[data-confirm]');
    if (b && !confirm(b.dataset.confirm)) e.preventDefault();
    const d = e.target.closest('[data-confirm-delete]');
    if (d) {
      const sel = d.form.querySelector('select[name=action]');
      if (sel && sel.value === 'delete' && !confirm('Delete the selected projects and their files? This can’t be undone.')) e.preventDefault();
    }
  });

  // select all
  const all = document.querySelector('[data-checkall]');
  if (all) all.addEventListener('change', () => {
    document.querySelectorAll('[data-table] li:not([hidden]) input[type=checkbox]').forEach(c => { c.checked = all.checked; });
  });

  // quick filter
  const q = document.querySelector('[data-tablefilter]');
  if (q) q.addEventListener('input', () => {
    const s = q.value.trim().toLowerCase();
    document.querySelectorAll('[data-table] li').forEach(li => { li.hidden = s && !li.dataset.hay.includes(s); });
  });

  // file previews + drag highlight
  document.querySelectorAll('[data-preview]').forEach(inp => {
    const drop = inp.closest('.drop');
    const list = drop && drop.querySelector('[data-preview-list]');
    inp.addEventListener('change', () => {
      if (!list) return;
      const n = inp.files.length;
      const mb = [...inp.files].reduce((a, f) => a + f.size, 0) / 1048576;
      list.textContent = n ? `${n} file${n > 1 ? 's' : ''} · ${mb.toFixed(1)} MB: ${[...inp.files].slice(0, 8).map(f => f.name).join(', ')}${n > 8 ? '…' : ''}` : '';
    });
    if (drop) {
      ['dragenter', 'dragover'].forEach(t => drop.addEventListener(t, () => drop.classList.add('is-over')));
      ['dragleave', 'drop'].forEach(t => drop.addEventListener(t, () => drop.classList.remove('is-over')));
    }
  });

  // warn on unsaved changes
  const form = document.querySelector('form.edit');
  if (form) {
    let dirty = false;
    form.addEventListener('input', () => { dirty = true; });
    form.addEventListener('submit', () => { dirty = false; });
    window.addEventListener('beforeunload', e => { if (dirty) { e.preventDefault(); e.returnValue = ''; } });
  }
})();
