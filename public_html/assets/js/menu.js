// Carta pública: categoría activa, búsqueda, ficha del producto y títulos "cantados".
(() => {
  'use strict';

  const $ = (s, el = document) => el.querySelector(s);
  const $$ = (s, el = document) => [...el.querySelectorAll(s)];
  const data = JSON.parse($('#menu-data')?.textContent || '{}');
  const money = (n) => (n === 0 ? 'Gratis' : '$' + n.toLocaleString('es-CO'));
  const reduceMotion = matchMedia('(prefers-reduced-motion: reduce)').matches;

  // ---- Títulos karaoke: solo se ocultan si JS corre y el usuario acepta movimiento.
  const titles = $$('.kara');
  if (!reduceMotion && 'IntersectionObserver' in window) {
    document.documentElement.classList.add('kara-armed');
    const io = new IntersectionObserver((entries) => {
      entries.forEach((en) => {
        if (en.isIntersecting) {
          en.target.classList.add('is-sung');
          io.unobserve(en.target);
        }
      });
    }, { rootMargin: '0px 0px -15% 0px' });
    titles.forEach((t) => io.observe(t));
  }

  // ---- Categoría activa en la barra.
  const chips = $$('.setlist-chips a');
  const chipBar = $('.setlist-chips');
  const sections = $$('.cat');
  let current = null;
  const setActive = (slug) => {
    if (slug === current) return;
    current = slug;
    chips.forEach((a) => {
      const on = a.dataset.cat === slug;
      a.setAttribute('aria-current', on ? 'true' : 'false');
      if (on) {
        const left = a.offsetLeft - chipBar.clientWidth / 2 + a.clientWidth / 2;
        chipBar.scrollTo({ left, behavior: reduceMotion ? 'auto' : 'smooth' });
      }
    });
  };
  if ('IntersectionObserver' in window && sections.length) {
    const visible = new Map();
    const spy = new IntersectionObserver((entries) => {
      entries.forEach((en) => visible.set(en.target.id, en.isIntersecting ? en.boundingClientRect.top : null));
      const first = sections.find((s) => visible.get(s.id) != null && !s.hidden);
      if (first) setActive(first.id);
    }, { rootMargin: '-80px 0px -55% 0px' });
    sections.forEach((s) => spy.observe(s));
  }
  chips.forEach((a) => a.addEventListener('click', (ev) => {
    const target = document.getElementById(a.dataset.cat);
    if (!target) return;
    ev.preventDefault();
    clearSearch();
    target.scrollIntoView({ behavior: reduceMotion ? 'auto' : 'smooth', block: 'start' });
    target.querySelector('.kara')?.classList.add('is-sung');
    history.replaceState(null, '', '#' + a.dataset.cat);
    setActive(a.dataset.cat);
  }));

  // ---- Búsqueda en vivo.
  const toggle = $('.search-toggle');
  const box = $('#search');
  const input = $('#q');
  const empty = $('.empty');
  const featured = $('.featured');
  const norm = (s) => s.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase().trim();
  const items = $$('.item').map((el) => ({ el, text: norm(el.dataset.search || '') }));

  const openSearch = () => {
    box.hidden = false;
    toggle.setAttribute('aria-expanded', 'true');
    input.focus();
  };
  function clearSearch() {
    if (box.hidden) return;
    input.value = '';
    filter('');
    box.hidden = true;
    toggle.setAttribute('aria-expanded', 'false');
  }
  function filter(raw) {
    const q = norm(raw);
    const terms = q.split(/\s+/).filter(Boolean);
    let shown = 0;
    items.forEach(({ el, text }) => {
      const ok = terms.every((t) => text.includes(t));
      el.hidden = !ok;
      if (ok) shown++;
    });
    sections.forEach((s) => { s.hidden = !$$('.item', s).some((i) => !i.hidden); });
    if (featured) featured.hidden = terms.length > 0;
    empty.hidden = shown > 0;
    if (!shown) $('span', empty).textContent = raw.trim();
    if (terms.length) $$('.kara').forEach((t) => t.classList.add('is-sung'));
  }
  toggle.addEventListener('click', () => (box.hidden ? openSearch() : clearSearch()));
  $('.search-close').addEventListener('click', () => { clearSearch(); toggle.focus(); });
  input.addEventListener('input', () => filter(input.value));
  input.addEventListener('keydown', (e) => { if (e.key === 'Escape') { clearSearch(); toggle.focus(); } });

  // ---- Ficha del producto.
  const sheet = $('.sheet');
  const sImg = $('.sheet-img img');
  let opener = null;
  const el = (tag, cls, text) => {
    const n = document.createElement(tag);
    if (cls) n.className = cls;
    if (text != null) n.textContent = text;
    return n;
  };
  const openSheet = (id, from) => {
    const p = data[id];
    if (!p || typeof sheet.showModal !== 'function') return;
    opener = from;
    $('.sheet-cat').textContent = p.category;
    $('.sheet-title').textContent = p.name;
    $('.sheet-desc').textContent = p.description || '';
    if (p.image) {
      sImg.src = p.image;
      sImg.alt = p.name;
      sImg.hidden = false;
    } else {
      sImg.removeAttribute('src');
      sImg.hidden = true;
    }
    const list = $('.sheet-prices');
    list.replaceChildren();
    const rows = p.variants.length ? p.variants : (p.price != null ? [{ label: 'Precio', price: p.price }] : []);
    rows.forEach((v) => {
      const li = el('li');
      const label = el('span', 'v-label', v.label);
      if (v.detail) label.append(' ', el('small', null, v.detail));
      if (v.note) label.append(' ', el('em', null, v.note));
      li.append(label, el('span', 'leader'), el('span', 'price' + (v.price === 0 ? ' price-free' : ''), money(v.price)));
      list.append(li);
    });
    if (!rows.length) list.append(el('li', 'ask', 'Pregunta el precio a tu mesero.'));
    sheet.showModal();
    $('.sheet-inner').scrollTop = 0;
  };
  document.addEventListener('click', (e) => {
    const btn = e.target.closest('[data-open]');
    if (btn) openSheet(btn.dataset.open, btn);
  });
  $('.sheet-close').addEventListener('click', () => sheet.close());
  sheet.addEventListener('click', (e) => { if (e.target === sheet) sheet.close(); });
  sheet.addEventListener('close', () => opener?.focus());
})();
