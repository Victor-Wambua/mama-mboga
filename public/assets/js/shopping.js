// Market list: tick items off without a page reload, and print button.
// Without JS each row is an ordinary form with a "Mark bought" button.
(() => {
  for (const button of document.querySelectorAll('[data-print]')) button.addEventListener('click', () => window.print());

  const rows = [...document.querySelectorAll('[data-row]')];
  const countEl = document.querySelector('[data-bought-count]');
  const bar = document.querySelector('[data-progress]');
  const recount = () => {
    const done = rows.filter((r) => r.classList.contains('done')).length;
    if (countEl) countEl.textContent = String(done);
    if (bar) bar.className = 'p' + Math.floor((done / Math.max(1, rows.length)) * 10) * 10;
  };

  for (const form of rows) {
    form.addEventListener('submit', async (event) => {
      event.preventDefault();
      const hidden = form.querySelector('[data-bought]');
      const label = form.querySelector('[data-label]');
      const nowBought = hidden.value === '1';
      try {
        const res = await fetch(form.action, { method: 'POST', body: new FormData(form), headers: { 'X-Requested-With': 'fetch' } });
        if (!res.ok) throw new Error(String(res.status));
      } catch {
        label.textContent = 'Try again';
        return;
      }
      form.classList.toggle('done', nowBought);
      hidden.value = nowBought ? '0' : '1';
      label.textContent = nowBought ? 'Bought' : 'Mark bought';
      label.classList.toggle('ghost', !nowBought);
      recount();
    });
  }
})();
