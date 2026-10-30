(() => {
  'use strict';
  const money = value => new Intl.NumberFormat('pt-BR', {style: 'currency', currency: 'BRL'}).format(value);
  const node = (tag, text, className) => {
    const el = document.createElement(tag);
    if (text !== undefined) el.textContent = text;
    if (className) el.className = className;
    return el;
  };
  async function rpc(url, form) {
    const response = await fetch(url, {method: 'POST', body: form, credentials: 'same-origin', cache: 'no-store'});
    let result;
    try { result = await response.json(); }
    catch (_) { throw new Error('Não foi possível ler a resposta. Atualize a página ou consulte a secretaria.'); }
    if (!response.ok || !result.success) {
      const error = new Error(result.data?.message || 'Não foi possível concluir a operação.');
      error.code = result.data?.code || '';
      throw error;
    }
    return result.data;
  }
  function notice(root, message, success = false) {
    const box = root.querySelector('.k8-notice');
    box.textContent = message;
    box.classList.toggle('success', success);
    box.hidden = !message;
  }
  function busy(form, active) {
    form.setAttribute('aria-busy', String(active));
    form.querySelectorAll('button').forEach(button => { button.disabled = active; });
  }
  function reload(result) {
    if (result.redirect) {
      const target = new URL(result.redirect, window.location.href);
      if (target.origin === location.origin && target.pathname === location.pathname && target.search === location.search) {
        history.replaceState(null, '', target.href);
        window.location.reload();
      } else window.location.assign(target.href);
    } else window.location.reload();
  }

  function closePaymentWindow(paymentWindow) {
    try { if (paymentWindow && !paymentWindow.closed) paymentWindow.close(); }
    catch (_) { /* A janela pode ter sido fechada pelo navegador. */ }
  }
  function preparePaymentWindow() {
    let paymentWindow = null;
    try {
      // Abrir durante o clique, antes da requisição, evita o bloqueio de pop-ups.
      paymentWindow = window.open('about:blank', '_blank', 'popup,width=1100,height=800');
      if (!paymentWindow) return null;
      paymentWindow.opener = null;
      paymentWindow.document.title = 'Pagamento K8';
      paymentWindow.document.body.textContent = 'Preparando seu pagamento na K8. Aguarde…';
      return paymentWindow;
    } catch (_) {
      closePaymentWindow(paymentWindow);
      return null;
    }
  }
  function openPaymentWindow(paymentWindow, url) {
    try {
      if (!paymentWindow || paymentWindow.closed) return false;
      paymentWindow.location.replace(url);
    } catch (_) {
      closePaymentWindow(paymentWindow);
      return false;
    }
    try { paymentWindow.focus(); } catch (_) { /* O navegador decide o foco. */ }
    return true;
  }

  document.querySelectorAll('.k8prod[data-nonce]').forEach(root => {
    async function send(form) {
      window.tinymce?.triggerSave();
      const payload = new FormData(form);
      payload.set('action', 'k8prod_manage');
      payload.set('op', form.dataset.k8Manage);
      payload.set('nonce', root.dataset.nonce);
      busy(form, true);
      notice(root, 'Salvando…', true);
      try {
        const result = await rpc(root.dataset.ajax, payload);
        notice(root, result.message || 'Salvo.', true);
        reload(result);
      } catch (error) {
        notice(root, error.message);
        throw error;
      } finally { busy(form, false); }
    }
    root.querySelectorAll('form[data-k8-manage]').forEach(form => {
      form.addEventListener('submit', async event => {
        event.preventDefault();
        if (form.dataset.k8Confirm && !window.confirm(form.dataset.k8Confirm)) return;
        try { await send(form); } catch (_) { /* Mensagem já exibida. */ }
      });
    });

    root.querySelector('[data-k8-product-status]')?.addEventListener('change', event => {
      const price = event.currentTarget.form.querySelector('[name=valor_padrao]');
      if (price) price.required = event.currentTarget.value === '1';
    });

    root.querySelector('[data-k8-image]')?.addEventListener('click', event => {
      if (!window.wp?.media) return;
      const form = event.currentTarget.closest('form');
      const picker = window.wp.media({title: 'Imagem do produto', button: {text: 'Usar imagem'}, library: {type: 'image'}, multiple: false});
      picker.on('select', () => {
        const asset = picker.state().get('selection').first().toJSON();
        form.querySelector('[name=imagem_id]').value = asset.id;
        const img = document.createElement('img');
        img.src = asset.sizes?.thumbnail?.url || asset.url;
        img.alt = asset.alt || 'Imagem selecionada';
        img.style.maxWidth = '160px';
        form.querySelector('[data-k8-image-preview]').replaceChildren(img);
      });
      picker.open();
    });
    root.querySelector('[data-k8-image-remove]')?.addEventListener('click', event => {
      const form = event.currentTarget.closest('form');
      form.querySelector('[name=imagem_id]').value = '0';
      form.querySelector('[data-k8-image-preview]').replaceChildren();
    });

    const dialog = root.querySelector('.k8-sign-dialog');
    if (!dialog) return;
    const canvas = dialog.querySelector('canvas');
    const context = canvas.getContext('2d');
    let targetForm = null, drawing = false, lastPoint = null, distance = 0;
    function clear() {
      context.fillStyle = '#ffffff';
      context.fillRect(0, 0, canvas.width, canvas.height);
      context.strokeStyle = '#15253d';
      context.lineWidth = 2.4;
      context.lineCap = 'round';
      distance = 0;
    }
    function point(event) {
      const rect = canvas.getBoundingClientRect();
      return {x: (event.clientX - rect.left) * canvas.width / rect.width, y: (event.clientY - rect.top) * canvas.height / rect.height};
    }
    canvas.addEventListener('pointerdown', event => {
      event.preventDefault();
      drawing = true;
      lastPoint = point(event);
      canvas.setPointerCapture(event.pointerId);
      context.beginPath();
      context.moveTo(lastPoint.x, lastPoint.y);
    });
    canvas.addEventListener('pointermove', event => {
      if (!drawing) return;
      const current = point(event);
      distance += Math.hypot(current.x - lastPoint.x, current.y - lastPoint.y);
      context.lineTo(current.x, current.y);
      context.stroke();
      lastPoint = current;
    });
    ['pointerup', 'pointercancel', 'lostpointercapture'].forEach(type => canvas.addEventListener(type, () => { drawing = false; }));
    root.querySelectorAll('[data-k8-sign]').forEach(button => button.addEventListener('click', () => {
      targetForm = button.closest('form');
      dialog.querySelector('[data-k8-sign-name]').textContent = button.dataset.student;
      dialog.querySelector('[name=recebido_por]').value = '';
      dialog.querySelector('[data-k8-sign-error]').textContent = '';
      clear();
      dialog.showModal();
    }));
    dialog.querySelector('[data-k8-clear]').addEventListener('click', clear);
    dialog.querySelector('[data-k8-close]').addEventListener('click', () => dialog.close());
    dialog.querySelector('form').addEventListener('submit', async event => {
      event.preventDefault();
      const signForm = event.currentTarget;
      const errorBox = dialog.querySelector('[data-k8-sign-error]');
      if (distance < 12) { errorBox.textContent = 'Assine no quadro antes de confirmar.'; return; }
      targetForm.querySelector('[name=assinatura]').value = canvas.toDataURL('image/png');
      let recipient = targetForm.querySelector('[name=recebido_por]');
      if (!recipient) {
        recipient = document.createElement('input');
        recipient.name = 'recebido_por'; recipient.type = 'hidden';
        targetForm.append(recipient);
      }
      recipient.value = dialog.querySelector('[name=recebido_por]').value;
      busy(signForm, true);
      try { await send(targetForm); }
      catch (error) { errorBox.textContent = error.message; }
      finally { busy(signForm, false); }
    });
  });

  document.querySelectorAll('[data-k8-product]').forEach(root => {
    const product = root.dataset.k8Product;
    const storageKey = `k8prod:${location.pathname}:${product}`;
    const checkout = root.querySelector('[data-k8-checkout]');
    const statusBox = root.querySelector('[data-k8-status]');
    const list = root.querySelector('[data-k8-students]');
    const state = {nonce: '', token: '', coupon: '', student: null, priceKey: '', emailLoaded: false};
    let priceTimer, contactTimer, refreshingOrder = false;
    const emailBox = root.querySelector('[data-k8-email]');
    const emailInput = root.querySelector('[data-k8-email-input]');
    const reminderInput = root.querySelector('[data-k8-reminder]');
    let contactQueue = Promise.resolve();
    function contactFields() {
      return emailBox && !emailBox.hidden ? {email_responsavel: emailInput.value.trim(), lembrete_email: reminderInput.checked ? '1' : '0'} : {};
    }
    function saveContact() {
      if (!emailBox || emailBox.hidden || !state.token || !root.isConnected || (emailInput.value && !emailInput.checkValidity())) return Promise.resolve();
      const token = state.token;
      const fields = {...contactFields(), token, cupom: state.coupon};
      const box = root.querySelector('[data-k8-email-state]');
      contactQueue = contactQueue.catch(() => {}).then(async () => {
        try {
          await request('save_cart', fields);
          if (state.token === token && box) box.textContent = fields.email_responsavel ? 'E-mail salvo para esta compra.' : '';
        } catch (error) {
          if (state.token === token && box) box.textContent = error.message;
        }
      });
      return contactQueue;
    }
    emailInput?.addEventListener('input', () => {
      clearTimeout(contactTimer);
      contactTimer = setTimeout(saveContact, 800);
    });
    emailInput?.addEventListener('change', saveContact);
    reminderInput?.addEventListener('change', () => {
      emailInput.required = emailBox.dataset.required === '1' || reminderInput.checked;
      return saveContact();
    });
    function saveSession() {
      try { sessionStorage.setItem(storageKey, JSON.stringify({token: state.token, student: state.student})); } catch (_) { /* Navegação privada. */ }
    }
    function forgetSession() {
      try { sessionStorage.removeItem(storageKey); } catch (_) { /* Navegação privada. */ }
    }
    function hideProduct() {
      clearTimeout(priceTimer);
      clearTimeout(contactTimer);
      forgetSession();
      root.remove();
    }
    async function request(op, fields = {}) {
      const payload = new FormData();
      Object.entries({action: 'k8prod_public', op, produto_id: product, nonce: state.nonce, token: state.token, ...fields})
        .forEach(([key, value]) => payload.set(key, String(value)));
      return rpc(root.dataset.ajax, payload).catch(error => {
        if (error.code === 'product_unavailable') hideProduct();
        throw error;
      });
    }
    function showPrice(quote) {
      state.priceKey = quote.chave_preco;
      if (emailBox) {
        const config = quote.email_checkout || {};
        emailBox.hidden = !config.ativo;
        emailBox.dataset.required = config.obrigatorio ? '1' : '0';
        root.querySelector('[data-k8-reminder-label]').hidden = !config.lembrete_disponivel;
        root.querySelector('[data-k8-email-info]').textContent = config.obrigatorio ? 'Enviaremos a confirmação para este e-mail quando o pagamento for confirmado.' : 'Informe seu e-mail caso queira receber o lembrete desta compra.';
        if (!state.emailLoaded) {
          emailInput.value = config.email || '';
          reminderInput.checked = Boolean(config.lembrete);
          state.emailLoaded = true;
        }
        if (!config.lembrete_disponivel) reminderInput.checked = false;
        emailInput.required = Boolean(config.obrigatorio || reminderInput.checked);
      }
      const price = root.querySelector('[data-k8-price]');
      price.replaceChildren();
      if (quote.original > quote.valor) price.append(node('del', money(quote.original), 'k8-original-price'));
      price.append(node('strong', money(quote.valor)));
      if (quote.promocao_ate) price.append(node('span', `Preço com desconto até ${quote.promocao_ate}`, 'k8-promo-until'));
      if (quote.cupom) price.append(node('span', `Cupom ${quote.cupom}: ${quote.percentual}%`, 'k8-promo-until'));
      const allowed = quote.formas_pagamento || [];
      root.querySelectorAll('.k8-methods [data-method]').forEach(button => {
        button.hidden = quote.valor <= 0 || !allowed.includes(button.dataset.method);
        if (button.dataset.method === 'Cartao') button.textContent = quote.parcelas_cartao_max > 1 ? `Cartão de crédito — até ${quote.parcelas_cartao_max}x` : 'Cartão de crédito — à vista';
      });
      const terms = root.querySelector('[data-k8-payment-terms]');
      if (terms) {
        terms.hidden = quote.valor <= 0 || !allowed.includes('Cartao');
        terms.textContent = terms.hidden ? '' : `Cartão: ${quote.parcelas_cartao_max > 1 ? `até ${quote.parcelas_cartao_max} parcelas` : 'pagamento à vista'}${quote.parcelamento_ate ? ` até ${quote.parcelamento_ate}` : ''}. Escolha as parcelas no checkout da K8.`;
      }
      root.querySelector('.k8-methods').hidden = quote.valor <= 0;
      root.querySelector('[data-method=Cortesia]').hidden = quote.valor > 0;
      clearTimeout(priceTimer);
      priceTimer = setTimeout(async () => {
        if (checkout.hidden || document.hidden || !state.token || checkout.getAttribute('aria-busy') === 'true') return;
        try {
          const fresh = await request('quote', {cupom: state.coupon});
          if (fresh.chave_preco !== state.priceKey) notice(root, 'O preço ou as condições de pagamento foram atualizados. Confira antes de confirmar.', true);
          state.coupon = fresh.cupom;
          showPrice(fresh);
        } catch (error) { notice(root, error.message); }
      }, Math.max(1, quote.refresh_after || 300) * 1000);
    }
    function renderOrder(order) {
      checkout.hidden = true;
      statusBox.replaceChildren();
      const card = node('div', undefined, 'k8-card');
      card.append(node('h3', order.pagamento === 'Paga' ? 'Pagamento confirmado' : order.status === 'verificacao' || order.status === 'iniciado' ? 'Pedido em conferência' : 'Aguardando pagamento'));
      card.append(node('p', `Pedido #${order.id} · ${money(order.valor)} · ${order.metodo}`));
      if (order.parcelas_cartao_max) card.append(node('p', `Cartão: limite de ${order.parcelas_cartao_max} parcela(s) registrado neste pedido.`, 'k8-muted'));
      if (order.valor_original > order.valor) {
        const original = node('p', 'Valor original: ', 'k8-muted');
        original.append(node('del', money(order.valor_original)));
        card.append(original);
      }
      if (order.promocao_titulo) card.append(node('p', `Desconto aplicado: ${order.promocao_titulo}`, 'k8-muted'));
      if (order.status === 'verificacao' || order.status === 'iniciado') {
        card.append(node('p', 'A secretaria precisa conferir este pedido. Informe o número acima antes de tentar novamente.'));
      } else if (order.pagamento === 'Paga') {
        card.append(node('p', 'Seu pedido está no controle de entregas da secretaria.'));
        for (let stage = 1; stage <= Number(root.dataset.stages); stage++) {
          const delivery = order.entregas.find(item => Number(item.semestre) === stage);
          const label = Number(root.dataset.stages) === 1 ? 'Entrega' : `${stage}º semestre`;
          card.append(node('p', `${label}: ${delivery?.status === 'entregue' ? 'realizada' : 'aguardando retirada'}`));
        }
      }
      if (order.redirect) {
        const link = node('a', 'Continuar pagamento na K8', 'k8-btn');
        link.href = order.redirect;
        link.target = '_blank';
        link.rel = 'noopener noreferrer';
        link.setAttribute('aria-label', 'Continuar pagamento na K8 (abre em nova janela)');
        card.append(link);
        card.append(node('p', 'O pagamento abre em outra janela. Depois, volte aqui para acompanhar a confirmação.', 'k8-muted'));
      }
      const refresh = node('button', 'Atualizar situação', 'k8-btn secondary');
      refresh.type = 'button';
      refresh.addEventListener('click', async () => {
        refresh.disabled = true;
        try { await status(); } catch (error) { notice(root, error.message); }
        finally { refresh.disabled = false; }
      });
      card.append(refresh);
      statusBox.append(card);
    }
    async function status() {
      const result = await request('status');
      if (result.order) {
        renderOrder(result.order);
      } else {
        statusBox.replaceChildren();
        const quote = await request('quote');
        state.coupon = '';
        root.querySelector('[name=cupom]').value = '';
        showPrice(quote);
        checkout.hidden = false;
      }
    }
    async function refreshVisibleOrder() {
      if (refreshingOrder || document.hidden || !root.isConnected || !state.token || !checkout.hidden || !statusBox.childElementCount || checkout.getAttribute('aria-busy') === 'true') return;
      refreshingOrder = true;
      try { await status(); }
      catch (error) { notice(root, error.message); }
      finally { refreshingOrder = false; }
    }
    window.addEventListener('focus', refreshVisibleOrder);
    document.addEventListener('visibilitychange', async () => {
      if (document.hidden || !state.token || checkout.getAttribute('aria-busy') === 'true') return;
      if (checkout.hidden) { await refreshVisibleOrder(); return; }
      try {
        const fresh = await request('quote', {cupom: state.coupon});
        if (fresh.chave_preco !== state.priceKey) notice(root, 'O preço ou as condições de pagamento foram atualizados. Confira antes de confirmar.', true);
        state.coupon = fresh.cupom;
        showPrice(fresh);
      } catch (error) { notice(root, error.message); }
    });
    async function choose(student) {
      state.student = {nome_aluno: student.nome_aluno, turma: student.turma, responsavel: student.responsavel};
      state.token = student.token;
      state.coupon = '';
      state.emailLoaded = false;
      clearTimeout(contactTimer);
      if (emailInput) emailInput.value = '';
      if (reminderInput) reminderInput.checked = false;
      saveSession();
      const summary = root.querySelector('[data-k8-summary]');
      summary.replaceChildren(node('h3', student.nome_aluno), node('p', `Turma: ${student.turma} · Responsável: ${student.responsavel}`));
      await status();
    }
    const ready = request('bootstrap').then(async result => {
      state.nonce = result.nonce;
      const resultAvailability = await request('availability');
      if (!resultAvailability.available) { hideProduct(); return false; }
      root.hidden = false;
      return true;
    });
    async function recheckAvailability() {
      try {
        if (!(await ready) || !root.isConnected) return;
        if (!(await request('availability')).available) hideProduct();
      } catch (_) { /* A próxima operação também confere a disponibilidade. */ }
    }
    window.addEventListener('pageshow', recheckAvailability);
    document.addEventListener('visibilitychange', () => { if (!document.hidden) recheckAvailability(); });
    root.querySelector('[data-k8-lookup]').addEventListener('submit', async event => {
      event.preventDefault();
      const form = event.currentTarget;
      busy(form, true);
      notice(root, 'Consultando…', true);
      checkout.hidden = true;
      statusBox.replaceChildren(); list.replaceChildren();
      state.token = ''; state.coupon = ''; state.student = null; forgetSession();
      try {
        await ready;
        const result = await request('lookup', {cpf: new FormData(form).get('cpf')});
        const heading = node('h3', 'Selecione o aluno');
        list.append(heading);
        result.students.forEach(student => {
          const button = node('button', `${student.nome_aluno} · ${student.turma}`, 'k8-student');
          button.type = 'button';
          button.addEventListener('click', async () => {
            list.querySelectorAll('button').forEach(item => { item.disabled = true; });
            try { notice(root, ''); await choose(student); }
            catch (error) { notice(root, error.message); }
            finally { list.querySelectorAll('button').forEach(item => { item.disabled = false; }); }
          });
          list.append(button);
        });
        notice(root, '');
      } catch (error) { notice(root, error.message); }
      finally { busy(form, false); }
    });
    root.querySelector('[data-k8-coupon]').addEventListener('submit', async event => {
      event.preventDefault();
      const form = event.currentTarget;
      busy(form, true);
      try {
        const quote = await request('quote', {cupom: new FormData(form).get('cupom')});
        state.coupon = quote.cupom;
        showPrice(quote);
        saveContact();
        notice(root, quote.aviso_cupom || (quote.cupom ? 'Cupom aplicado.' : 'Preço atualizado.'), true);
      } catch (error) {
        state.coupon = '';
        try { showPrice(await request('quote')); } catch (_) { checkout.hidden = true; }
        notice(root, error.message);
      } finally { busy(form, false); }
    });
    root.querySelectorAll('[data-method]').forEach(button => button.addEventListener('click', async () => {
      if (checkout.getAttribute('aria-busy') === 'true') return;
      if (emailBox && !emailBox.hidden && (emailInput.required || emailInput.value) && !emailInput.reportValidity()) return;
      clearTimeout(contactTimer);
      const paymentWindow = button.dataset.method === 'Cortesia' ? null : preparePaymentWindow();
      busy(checkout, true);
      notice(root, 'Registrando pedido. Aguarde…', true);
      try {
        const order = await request('checkout', {metodo: button.dataset.method, cupom: state.coupon, chave_preco: state.priceKey, ...contactFields()});
        notice(root, '');
        renderOrder(order);
        if (order.redirect) {
          const opened = openPaymentWindow(paymentWindow, order.redirect);
          notice(root, opened
            ? 'O pagamento foi aberto em outra janela. Volte aqui depois de pagar para acompanhar a confirmação.'
            : 'Para abrir o pagamento em uma nova janela, clique em “Continuar pagamento na K8”.', true);
        } else closePaymentWindow(paymentWindow);
      } catch (error) {
        closePaymentWindow(paymentWindow);
        notice(root, error.message);
        try { await status(); } catch (_) { /* Mantém a mensagem original. */ }
      } finally { busy(checkout, false); }
    }));
    ready.then(async () => {
      if (!root.isConnected) return;
      let saved;
      try { saved = JSON.parse(sessionStorage.getItem(storageKey)); } catch (_) { return; }
      if (!saved?.token || !saved.student) return;
      try { await choose({...saved.student, token: saved.token}); }
      catch (_) { forgetSession(); checkout.hidden = true; }
    }).catch(error => notice(root, error.message));
  });
})();
