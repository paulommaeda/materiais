'use strict';

// Executar com Node.js 18+: node k8-produtos/tests/payment-window.js
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

async function runPaymentWindowTests(source, executeScript) {
  let checks = 0;
  function check(value, message) { if (!value) throw new Error(message); checks++; }
  class Element {
    constructor(tag = 'div') {
      this.tagName = tag.toUpperCase(); this.children = []; this.dataset = {}; this.listeners = {};
      this.attributes = {}; this.selectors = {}; this.buttons = []; this.hidden = false;
      this.isConnected = true; this.textContent = ''; this.classList = {toggle() {}};
    }
    get childElementCount() { return this.children.length; }
    append(...items) { this.children.push(...items); }
    replaceChildren(...items) { this.children = items; }
    remove() { this.isConnected = false; }
    setAttribute(name, value) { this.attributes[name] = value; }
    getAttribute(name) { return this.attributes[name] ?? null; }
    addEventListener(type, handler) { (this.listeners[type] ||= []).push(handler); }
    querySelector(selector) { return this.selectors[selector] || null; }
    querySelectorAll(selector) {
      return selector === 'button' ? this.buttons.length ? this.buttons : this.children.filter(item => item.tagName === 'BUTTON') : [];
    }
    async dispatch(type) { await Promise.all((this.listeners[type] || []).map(handler => handler({preventDefault() {}, currentTarget: this}))); }
  }
  class Fields {
    constructor(form) { this.values = new Map(Object.entries(form?.fields || {})); }
    set(key, value) { this.values.set(key, value); }
    get(key) { return this.values.get(key) ?? null; }
  }
  const pendingOrder = (method = 'Pix') => ({
    id: 9, valor: 90, valor_original: 100, metodo: method, status: 'pendente', pagamento: 'Pendente',
    parcelas_cartao_max: method === 'Cartao' ? 6 : null, entregas: [],
    redirect: 'https://pagamentos.meuk8.com.br/checkout/pedido-9'
  });
  const settle = async () => { for (let i = 0; i < 40; i++) await Promise.resolve(); };
  async function fixture(options = {}) {
    const root = new Element('section');
    root.dataset = {k8Product: '1', stages: '1', ajax: '/wp-admin/admin-ajax.php'};
    const elements = {};
    for (const key of ['checkout', 'status', 'students', 'summary', 'price', 'payment-terms', 'lookup', 'coupon']) {
      elements[key] = new Element(key === 'lookup' || key === 'coupon' ? 'form' : 'div');
      root.selectors['[data-k8-' + key + ']'] = elements[key];
    }
    const notice = new Element(), coupon = new Element('input'), methods = new Element();
    root.selectors['.k8-notice'] = notice;
    root.selectors['[name=cupom]'] = coupon;
    root.selectors['.k8-methods'] = methods;
    const buttons = ['Pix', 'Boleto', 'Cartao', 'Cortesia'].map(method => {
      const button = new Element('button'); button.dataset.method = method; return button;
    });
    root.selectors['[data-method=Cortesia]'] = buttons[3];
    root.querySelectorAll = selector => selector === '[data-method]' ? buttons : selector === '.k8-methods [data-method]' ? buttons.slice(0, 3) : [];
    elements.checkout.buttons = buttons;
    elements.checkout.hidden = true;
    const doc = new Element('document');
    doc.createElement = tag => new Element(tag);
    doc.querySelectorAll = selector => selector === '[data-k8-product]' ? [root] : [];
    const win = new Element('window');
    const env = {root, elements, notice, buttons, doc, win, windows: [], trace: [], currentOrder: null, available: true};
    win.location = {href: 'https://loja.example/produto', assign() { throw new Error('A loja não deve navegar para a K8.'); }, reload() {}};
    const location = {pathname: '/produto', origin: 'https://loja.example', search: ''};
    let release;
    const gate = options.gate ? new Promise(resolve => { release = resolve; }) : Promise.resolve();
    env.release = () => release?.();
    win.open = (url, target, features) => {
      env.trace.push('open');
      env.openArgs = {url, target, features};
      if (options.block === 'throw') throw new Error('Popup indisponível');
      if (options.block) return null;
      const popup = {
        closed: false, opener: win, document: {title: '', body: {textContent: ''}},
        location: {replace(next) { if (options.navigateError) throw new Error('Janela indisponível'); env.trace.push('navigate'); popup.url = next; }},
        close() { popup.closed = true; popup.closeCount = (popup.closeCount || 0) + 1; },
        focus() { if (options.focusError) throw new Error('Foco recusado'); popup.focused = true; }
      };
      if (options.openerError) Object.defineProperty(popup, 'opener', {set() { throw new Error('Sem acesso a opener'); }});
      env.windows.push(popup);
      return popup;
    };
    const session = new Map([['k8prod:/produto:1', JSON.stringify({
      token: 'contexto-teste', student: {nome_aluno: 'Aluno', turma: '5 A', responsavel: 'Responsável'}
    })]]);
    const fetch = async (_url, request) => {
      const op = request.body.get('op'); env.trace.push(op);
      let data;
      if (op === 'bootstrap') data = {nonce: 'nonce-teste'};
      else if (op === 'availability') data = {available: env.available};
      else if (op === 'status') data = {order: env.currentOrder};
      else if (op === 'quote') data = {
        chave_preco: 'cotacao-teste', original: 100, valor: options.free ? 0 : 90, cupom: '',
        formas_pagamento: ['Pix', 'Boleto', 'Cartao'], parcelas_cartao_max: 6, refresh_after: 300
      };
      else if (op === 'checkout') {
        env.checkoutMethod = request.body.get('metodo');
        await gate;
        if (options.closedByUser) env.windows[0].closed = true;
        if (options.checkoutError) return {ok: false, json: async () => ({success: false, data: {message: 'Confira as condições atuais.'}})};
        data = options.order || pendingOrder(env.checkoutMethod);
        env.currentOrder = data;
      } else throw new Error('RPC inesperado: ' + op);
      return {ok: true, json: async () => ({success: true, data})};
    };
    executeScript(source, {
      window: win, document: doc, fetch, FormData: Fields, location, history: {replaceState() {}},
      sessionStorage: {getItem: key => session.get(key) ?? null, setItem: (key, value) => session.set(key, value), removeItem: key => session.delete(key)},
      setTimeout: () => 1, clearTimeout() {}
    });
    await settle();
    check(!elements.checkout.hidden, 'A sessão restaurada deve apresentar as opções de compra.');
    env.pay = method => buttons.find(button => button.dataset.method === method).dispatch('click');
    env.link = () => elements.status.children.flatMap(card => card.children).find(item => item.tagName === 'A');
    return env;
  }

  for (const method of ['Pix', 'Boleto', 'Cartao']) {
    const env = await fixture();
    await env.pay(method);
    check(env.checkoutMethod === method, 'O método deve ser preservado: ' + method);
    check(env.trace.includes('open') && env.trace.indexOf('open') < env.trace.indexOf('checkout'), 'A janela deve abrir no clique antes da RPC.');
    check(env.openArgs.url === 'about:blank' && env.openArgs.target === '_blank', 'A preparação deve criar outra janela.');
    check(env.windows[0].url === env.currentOrder.redirect, 'A nova janela deve receber o checkout.');
    check(env.windows[0].opener === null, 'A K8 não deve ter acesso à janela da loja.');
    check(!env.windows[0].closed && env.windows[0].focused, 'O checkout deve continuar aberto.');
    check(env.link()?.target === '_blank' && env.link()?.rel === 'noopener noreferrer', 'Continuar deve abrir separado e sem opener.');
    check(env.link()?.attributes['aria-label'].includes('nova janela'), 'O link deve informar a abertura à tecnologia assistiva.');
    check(env.elements.checkout.hidden && env.elements.status.childElementCount === 1, 'A loja deve manter o pedido e acompanhamento.');
    env.currentOrder = {...env.currentOrder, pagamento: 'Paga', redirect: null};
    await env.win.dispatch('focus');
    check(!env.link() && env.elements.status.children[0].children[0].textContent === 'Pagamento confirmado', 'Voltar à loja deve consultar e exibir confirmação.');
    check(env.windows.length === 1, 'A atualização da situação não deve abrir outra janela.');
  }
  for (const options of [{block: true}, {block: 'throw'}, {closedByUser: true}, {navigateError: true}, {openerError: true}]) {
    const env = await fixture(options); await env.pay('Pix');
    check(env.link()?.href === env.currentOrder.redirect, 'Deve haver link para continuar quando a abertura falhar.');
    check(env.notice.textContent.includes('clique em'), 'Deve orientar a continuação manual.');
    if (options.navigateError || options.openerError) check(env.windows[0].closed, 'A janela vazia deve ser fechada em falha.');
  }
  {
    const env = await fixture({focusError: true}); await env.pay('Pix');
    check(env.windows[0].url === env.currentOrder.redirect && !env.windows[0].closed, 'Uma falha de foco não deve fechar o pagamento aberto.');
  }
  {
    const env = await fixture({checkoutError: true}); await env.pay('Pix');
    check(env.windows[0].closed && !env.windows[0].url, 'A rejeição da cobrança deve fechar a janela vazia.');
    check(env.notice.textContent === 'Confira as condições atuais.', 'O erro original deve permanecer visível.');
    check(!env.elements.checkout.hidden, 'A compra deve continuar disponível para conferir a cotação.');
  }
  for (const status of ['verificacao', 'iniciado', 'pendente']) {
    const env = await fixture({order: {...pendingOrder(), status, redirect: null}});
    await env.pay('Pix');
    check(env.windows[0].closed, 'Pedido sem URL não deve deixar janela vazia: ' + status);
    check(!env.link(), 'Pedido sem URL não deve gerar link vazio.');
  }
  {
    const env = await fixture({order: {...pendingOrder(), pagamento: 'Paga', redirect: null}});
    await env.pay('Cartao');
    check(env.windows[0].closed && !env.link(), 'Recuperar pedido pago deve fechar a preparação, sem checkout.');
  }
  {
    const env = await fixture({free: true, order: {...pendingOrder('Cortesia'), valor: 0, pagamento: 'Paga', redirect: null}});
    await env.pay('Cortesia');
    check(!env.windows.length && !env.trace.includes('open'), 'Cortesia não deve abrir janela da K8.');
    check(env.elements.status.children[0].children[0].textContent === 'Pagamento confirmado', 'Cortesia deve manter a confirmação na loja.');
  }
  {
    const env = await fixture({gate: true});
    const first = env.pay('Pix'); const second = env.pay('Cartao');
    check(env.windows.length === 1 && env.trace.filter(item => item === 'checkout').length === 1, 'Clique repetido durante envio não deve abrir nem criar outro pagamento.');
    env.release(); await Promise.all([first, second]);
    env.currentOrder = {...env.currentOrder, pagamento: 'Paga', redirect: null};
    const count = env.trace.filter(item => item === 'status').length;
    await Promise.all([env.win.dispatch('focus'), env.doc.dispatch('visibilitychange')]);
    check(env.trace.filter(item => item === 'status').length === count + 1, 'Foco e visibilidade simultâneos devem compartilhar a consulta do pedido.');
    env.root.remove();
    const after = env.trace.filter(item => item === 'status').length;
    await env.win.dispatch('focus');
    check(env.trace.filter(item => item === 'status').length === after, 'Produto removido não deve consultar pedido ao receber foco.');
  }
  return checks;
}

runPaymentWindowTests(
  fs.readFileSync(path.join(__dirname, '../assets/app.js'), 'utf8'),
  (source, context) => vm.runInNewContext(source, context, {filename: 'app.js', timeout: 2000})
).then(checks => {
  console.log(`${checks} verificações de abertura do pagamento aprovadas.`);
}).catch(error => {
  console.error(error);
  process.exitCode = 1;
});
