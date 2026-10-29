(() => {
  'use strict';
  document.querySelectorAll('[data-bmt-catalog]').forEach(root => {
    let nonce = '', timer, busy = false;
    async function request(op, ids = []) {
      const form = new FormData();
      form.set('action', 'k8prod_public'); form.set('op', op); form.set('nonce', nonce);
      ids.forEach(id => form.append('ids[]', id));
      const response = await fetch(root.dataset.ajax, {method: 'POST', body: form, credentials: 'same-origin', cache: 'no-store'});
      const result = await response.json();
      if (!response.ok || !result.success) throw new Error('Consulta de preços indisponível.');
      return result.data;
    }
    async function refresh() {
      clearTimeout(timer);
      if (busy || document.hidden) return;
      const cards = Array.from(root.querySelectorAll('[data-bmt-product]'));
      if (!cards.length) return;
      busy = true;
      try {
        if (!nonce) nonce = (await request('bootstrap')).nonce;
        // Mantém cada consulta limitada a cem produtos sem deixar os demais desatualizados.
        let next = 300;
        for (let offset = 0; offset < cards.length; offset += 100) {
          const batch = cards.slice(offset, offset + 100);
          const result = await request('catalog_prices', batch.map(card => card.dataset.bmtProduct));
          batch.forEach(card => {
            const html = result.prices[card.dataset.bmtProduct];
            if (typeof html === 'string') card.querySelector('[data-bmt-price]').innerHTML = html;
            else card.remove();
          });
          next = Math.min(next, result.refresh_after || 300);
        }
        timer = setTimeout(refresh, Math.max(1, next) * 1000);
      } catch (_) {
        nonce = '';
        timer = setTimeout(refresh, 60000);
      } finally { busy = false; }
    }
    document.addEventListener('visibilitychange', () => { if (!document.hidden) refresh(); });
    window.addEventListener('pageshow', refresh);
    refresh();
  });
})();
