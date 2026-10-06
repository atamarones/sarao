// Karaoke por mesa: código de la noche, búsqueda, pedidos y «mis pedidos».
// Todo lo que viene del servidor se pinta con textContent (nunca innerHTML con datos).
(() => {
  'use strict';

  const $ = (s, el = document) => el.querySelector(s);
  const $$ = (s, el = document) => [...el.querySelectorAll(s)];
  const root = $('#kk');
  if (!root) return;

  const TOKEN = root.dataset.token;
  const KEY_CODE = 'kk-code-' + TOKEN;
  const KEY_SINGER = 'kk-singer';
  const POLL_MS = 8000;
  const STATUS = {
    descargando: ['Descargando el video', 'wait'],
    descargado: ['Video listo', 'wait'],
    en_espera: ['En espera', 'wait'],
    enviado: ['Pasando a KaraFun', 'next'],
    en_cola: ['En la cola de KaraFun', 'next'],
    cantando: ['¡Suena ahora!', 'live'],
    cantada: ['Cantada', 'done'],
    fallido: ['No se pudo', 'bad'],
    retirado: ['Quitada de la cola', 'bad'],
    cancelado: ['Cancelada', 'done'],
  };

  const store = {
    get: (k) => { try { return localStorage.getItem(k) || ''; } catch { return ''; } },
    set: (k, v) => { try { localStorage.setItem(k, v); } catch { /* modo privado */ } },
    del: (k) => { try { localStorage.removeItem(k); } catch { /* modo privado */ } },
  };
  let code = store.get(KEY_CODE);
  let pollTimer = null;
  let current = null; // canción elegida en la hoja

  const uuid = () => {
    if (window.crypto && crypto.randomUUID) return crypto.randomUUID();
    const b = crypto.getRandomValues(new Uint8Array(16));
    b[6] = (b[6] & 0x0f) | 0x40;
    b[8] = (b[8] & 0x3f) | 0x80;
    const h = [...b].map((x) => x.toString(16).padStart(2, '0')).join('');
    return `${h.slice(0, 8)}-${h.slice(8, 12)}-${h.slice(12, 16)}-${h.slice(16, 20)}-${h.slice(20)}`;
  };
  const el = (tag, cls, text) => {
    const n = document.createElement(tag);
    if (cls) n.className = cls;
    if (text != null) n.textContent = text;
    return n;
  };
  const mmss = (s) => (s > 0 ? `${Math.floor(s / 60)}:${String(s % 60).padStart(2, '0')}` : '');

  function toast(msg) {
    const t = $('.kk-toast');
    t.textContent = msg;
    t.classList.add('is-on');
    clearTimeout(toast.timer);
    toast.timer = setTimeout(() => t.classList.remove('is-on'), 3200);
  }

  async function api(action, body = {}) {
    let res;
    try {
      res = await fetch('api.php?action=' + encodeURIComponent(action), {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        body: JSON.stringify({ ...body, t: TOKEN, code }),
        cache: 'no-store',
      });
    } catch {
      throw Object.assign(new Error('Sin conexión. Revisa los datos o el wifi e inténtalo de nuevo.'), { code: 'offline' });
    }
    const data = await res.json().catch(() => ({}));
    if (!res.ok) throw Object.assign(new Error(data.detail || 'No se pudo completar. Inténtalo de nuevo.'), { code: data.code || 'error', status: res.status });
    return data;
  }

  // Errores que sacan de la sesión: código viejo, noche cerrada, mesa desactivada.
  function handleSessionError(err) {
    if (err.code === 'bad_code') {
      // Si este celular ya había entrado con ese código, es que el encargado lo cambió.
      const hadCode = store.get(KEY_CODE) === code && code !== '';
      store.del(KEY_CODE);
      code = '';
      showGate(hadCode ? 'El código cambió. Pide el nuevo al personal del bar.' : err.message);
      return true;
    }
    if (['no_night', 'table_inactive', 'bad_table'].includes(err.code)) {
      stopPolling();
      $('#kk-app').hidden = true;
      $('#kk-gate').hidden = true;
      const c = $('#kk-closed');
      c.textContent = err.message;
      c.hidden = false;
      return true;
    }
    return false;
  }

  function setStatus(online) {
    const s = $('#kk-status');
    s.hidden = false;
    s.classList.toggle('is-open', online);
    $('span:last-child', s).textContent = online ? 'Karaoke conectado' : 'Karaoke del bar sin conexión';
    $('#agent-warning').hidden = online;
  }

  // ---------- código de la noche ----------
  function showGate(msg) {
    stopPolling();
    $('#kk-app').hidden = true;
    $('#kk-closed').hidden = true;
    $('#kk-gate').hidden = false;
    const e = $('#gate-error');
    e.textContent = msg || '';
    e.hidden = !msg;
    $('#gate-code').value = '';
    $('#gate-code').focus();
  }

  async function enter() {
    try {
      const d = await api('session');
      store.set(KEY_CODE, code);
      $('#kk-gate').hidden = true;
      $('#kk-closed').hidden = true;
      $('#kk-app').hidden = false;
      setStatus(d.agent_online);
      route();
      refreshOrders();
      startPolling();
    } catch (err) {
      if (!handleSessionError(err)) showGate(err.message);
    }
  }

  $('#gate-form').addEventListener('submit', (e) => {
    e.preventDefault();
    const v = $('#gate-code').value.replace(/\D/g, '');
    if (v.length !== 4) {
      const er = $('#gate-error');
      er.textContent = 'El código tiene 4 números.';
      er.hidden = false;
      return;
    }
    code = v;
    enter();
  });
  $('#gate-code').addEventListener('input', (e) => {
    e.target.value = e.target.value.replace(/\D/g, '').slice(0, 4);
  });

  // ---------- pestañas ----------
  function route() {
    const name = ['buscar', 'enlace', 'pedidos'].includes(location.hash.slice(1)) ? location.hash.slice(1) : 'buscar';
    $$('.kk-tabs a').forEach((a) => a.setAttribute('aria-current', String(a.dataset.tab === name)));
    $$('.kk-panel').forEach((p) => { p.hidden = p.id !== 'tab-' + name; });
    if (name === 'pedidos') refreshOrders();
    if (name === 'enlace' && !linkForm.elements.singer.value) linkForm.elements.singer.value = store.get(KEY_SINGER);
  }
  window.addEventListener('hashchange', route);

  // ---------- búsqueda ----------
  let searchSeq = 0;
  let searchTimer = null;
  $('#q').addEventListener('input', () => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(runSearch, 280);
  });
  $('#q').addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); clearTimeout(searchTimer); runSearch(); } });

  async function runSearch() {
    const q = $('#q').value.trim();
    const list = $('#results');
    const hint = $('#search-hint');
    const seq = ++searchSeq;
    if (q.length < 2) {
      list.replaceChildren();
      hint.textContent = 'Busca en las canciones del karaoke. Si no está, pega el enlace de YouTube.';
      return;
    }
    try {
      const d = await api('search', { q });
      if (seq !== searchSeq) return;
      list.replaceChildren(...d.songs.map(songItem));
      hint.textContent = d.songs.length
        ? (d.songs.length >= 30 ? 'Mostrando 30. Escribe algo más para afinar.' : '')
        : `No encontramos «${q}». Prueba con el artista, o pega el enlace de YouTube.`;
      if (!d.songs.length) {
        const a = el('a', 'kk-link', 'Pegar enlace de YouTube');
        a.href = '#enlace';
        hint.append(' ', a);
      }
    } catch (err) {
      if (!handleSessionError(err)) hint.textContent = err.message;
    }
  }

  function songItem(s) {
    const li = el('li', 'kk-song');
    const main = el('div', 'kk-song-main');
    main.append(el('p', 'kk-song-title', s.title));
    const meta = el('p', 'kk-song-meta', [s.artist, mmss(s.duration_s)].filter(Boolean).join(' · '));
    // Origen: «Local» = archivo en el PC del bar; «KaraFun» = catálogo en línea de KaraFun.
    meta.prepend(el('span', `kk-tag kk-src-${s.source === 'local' ? 'local' : 'karafun'}`, s.source === 'local' ? 'Local' : 'KaraFun'));
    if (s.from_youtube) meta.append(el('span', 'kk-tag kk-src-youtube', 'YouTube'));
    main.append(meta);
    const b = el('button', 'kk-btn kk-btn-small', 'Pedir');
    b.type = 'button';
    b.setAttribute('aria-label', `Pedir ${s.title}`);
    b.addEventListener('click', () => openAsk(s));
    li.append(main, b);
    return li;
  }

  // ---------- pedir ----------
  const ask = $('#ask');
  function openAsk(song) {
    current = song;
    $('#ask-title').textContent = song.title;
    $('#ask-artist').textContent = song.artist || '';
    const f = $('#ask-form');
    f.elements.singer.value = store.get(KEY_SINGER);
    $('.kk-error', f).hidden = true;
    ask.showModal();
    f.elements.singer.focus();
  }
  $('[data-close]', ask).addEventListener('click', () => ask.close());
  ask.addEventListener('click', (e) => { if (e.target === ask) ask.close(); });

  async function submitRequest(form, payload, onDone) {
    const err = $('.kk-error', form);
    const btn = $('button[type="submit"]', form);
    const singer = form.elements.singer.value.trim();
    if (!singer) {
      err.textContent = 'Escribe el nombre de quien va a cantar.';
      err.hidden = false;
      form.elements.singer.focus();
      return;
    }
    const label = btn.textContent;
    btn.disabled = true;
    btn.textContent = 'Pidiendo…';
    err.hidden = true;
    try {
      let d;
      for (let i = 0; i < 3; i++) {
        try {
          d = await api('request', { ...payload, id: uuid(), singer });
          break;
        } catch (e) {
          if (e.code !== 'marker_collision' || i === 2) throw e;
        }
      }
      store.set(KEY_SINGER, singer);
      renderOrders(d.requests);
      onDone();
      toast('¡Pedida! Mira tu turno en «Mis pedidos».');
      location.hash = '#pedidos';
    } catch (e) {
      if (!handleSessionError(e)) {
        err.textContent = e.message;
        err.hidden = false;
      }
    } finally {
      btn.disabled = false;
      btn.textContent = label;
    }
  }

  $('#ask-form').addEventListener('submit', (e) => {
    e.preventDefault();
    submitRequest(e.target, { song_id: current.id }, () => ask.close());
  });

  const linkForm = $('#link-form');
  linkForm.elements.singer.value = store.get(KEY_SINGER);
  linkForm.addEventListener('submit', (e) => {
    e.preventDefault();
    const url = linkForm.elements.youtube_url.value.trim();
    if (!url) {
      const er = $('.kk-error', linkForm);
      er.textContent = 'Pega el enlace del video de YouTube.';
      er.hidden = false;
      linkForm.elements.youtube_url.focus();
      return;
    }
    submitRequest(linkForm, { youtube_url: url }, () => { linkForm.elements.youtube_url.value = ''; });
  });

  // ---------- mis pedidos ----------
  function turnText(r) {
    if (r.status === 'en_cola' && r.queue_pos != null) return r.queue_pos <= 1 ? 'Es la siguiente' : `Faltan ${r.queue_pos} canciones`;
    if (!r.turn) return '';
    const n = r.turn.ahead;
    if (n === 0) return 'Es la siguiente';
    return `${n === 1 ? 'Falta 1 canción' : `Faltan ${n} canciones`} · unos ${Math.max(1, r.turn.eta_min)} min`;
  }

  function renderOrders(list) {
    const ul = $('#orders');
    const active = list.filter((r) => !['cantada', 'cancelado', 'fallido', 'retirado'].includes(r.status));
    const count = $('#kk-count');
    count.textContent = active.length;
    count.hidden = !active.length;
    if (!list.length) {
      const li = el('li', 'kk-empty');
      li.append(el('p', 'kk-empty-title', 'Aún no hay pedidos'));
      const a = el('a', 'kk-link', 'Buscar una canción');
      a.href = '#buscar';
      li.append(a);
      ul.replaceChildren(li);
      return;
    }
    // Lo que sigue vivo primero; lo terminado (cantada, cancelada, fallida) al final.
    const sorted = [...active, ...list.filter((r) => !active.includes(r))];
    ul.replaceChildren(...sorted.map((r) => {
      const [label, tone] = STATUS[r.status] || [r.status, 'wait'];
      const li = el('li', `kk-order is-${tone}`);
      const head = el('div', 'kk-order-head');
      head.append(el('span', `kk-pill is-${tone}`, label));
      const turn = turnText(r);
      if (turn && ['wait', 'next'].includes(tone)) head.append(el('span', 'kk-turn', turn));
      li.append(head);
      const t = el('p', 'kk-order-title' + (r.status === 'cantando' ? ' kara is-singing' : ''), r.title);
      li.append(t);
      li.append(el('p', 'kk-order-meta', [r.artist, `Canta: ${r.singer}`, r.from_youtube && r.artist !== 'YouTube' ? 'YouTube' : ''].filter(Boolean).join(' · ')));
      if (r.error && ['fallido', 'retirado'].includes(r.status)) li.append(el('p', 'kk-order-error', r.error));
      if (r.cancellable) {
        const b = el('button', 'kk-btn kk-btn-ghost kk-btn-small', 'Cancelar');
        b.type = 'button';
        b.setAttribute('aria-label', `Cancelar ${r.title}`);
        b.addEventListener('click', () => cancel(r, b));
        li.append(b);
      }
      return li;
    }));
  }

  async function cancel(r, btn) {
    btn.disabled = true;
    try {
      const d = await api('cancel', { id: r.id });
      renderOrders(d.requests);
      toast('Pedido cancelado.');
    } catch (err) {
      if (!handleSessionError(err)) toast(err.message);
      btn.disabled = false;
    }
  }

  async function refreshOrders() {
    try {
      const d = await api('mine');
      setStatus(d.agent_online);
      renderOrders(d.requests);
    } catch (err) {
      handleSessionError(err);
    }
  }

  function startPolling() {
    stopPolling();
    pollTimer = setInterval(() => { if (!document.hidden) refreshOrders(); }, POLL_MS);
  }
  function stopPolling() {
    if (pollTimer) clearInterval(pollTimer);
    pollTimer = null;
  }
  document.addEventListener('visibilitychange', () => { if (!document.hidden && pollTimer) refreshOrders(); });

  // ---------- inicio ----------
  if (code) enter();
  else showGate();
})();
