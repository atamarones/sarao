// Panel de la carta: productos, categorías, promos y ajustes.
(() => {
  'use strict';

  const $ = (s, el = document) => el.querySelector(s);
  const $$ = (s, el = document) => [...el.querySelectorAll(s)];
  const CSRF = $('meta[name="csrf-token"]').content;
  const view = $('#view');
  const DAYS = [[1, 'Lun'], [2, 'Mar'], [3, 'Mié'], [4, 'Jue'], [5, 'Vie'], [6, 'Sáb'], [7, 'Dom']];
  const DAY_NAMES = { 1: 'Lunes', 2: 'Martes', 3: 'Miércoles', 4: 'Jueves', 5: 'Viernes', 6: 'Sábado', 7: 'Domingo' };

  let state = null;
  const ui = { q: '', cat: 'all' };

  // ---------- utilidades ----------
  const esc = (v) => String(v ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const money = (n) => (n === 0 ? 'Gratis' : '$' + Number(n).toLocaleString('es-CO'));
  const norm = (s) => String(s || '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();
  const icon = (name) => `<svg viewBox="0 0 24 24" aria-hidden="true">${ICONS[name]}</svg>`;
  const ICONS = {
    up: '<path d="m6 15 6-6 6 6"/>',
    down: '<path d="m6 9 6 6 6-6"/>',
    star: '<path d="m12 3.5 2.6 5.3 5.9.9-4.3 4.1 1 5.8L12 16.9l-5.2 2.7 1-5.8-4.3-4.1 5.9-.9L12 3.5Z"/>',
    edit: '<path d="M4 20h4L19 9l-4-4L4 16v4Z"/><path d="m13.5 6.5 4 4"/>',
    copy: '<rect x="8" y="8" width="12" height="12" rx="2"/><path d="M16 8V6a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v8a2 2 0 0 0 2 2h2"/>',
    trash: '<path d="M4 7h16M10 11v6M14 11v6M6 7l1 12a2 2 0 0 0 2 2h6a2 2 0 0 0 2-2l1-12M9 7V4h6v3"/>',
    plus: '<path d="M12 5v14M5 12h14"/>',
    x: '<path d="M6 6l12 12M18 6 6 18"/>',
    image: '<rect x="3" y="4" width="18" height="16" rx="2"/><circle cx="9" cy="10" r="2"/><path d="m21 16-5-5-9 9"/>',
  };

  function toast(msg, kind = 'ok') {
    const box = $('.toasts');
    const t = document.createElement('div');
    t.className = 'toast toast-' + kind;
    t.textContent = msg;
    box.append(t);
    setTimeout(() => t.classList.add('out'), 3200);
    setTimeout(() => t.remove(), 3700);
  }

  async function api(action, body, { form = false } = {}) {
    const opts = { method: body ? 'POST' : 'GET', credentials: 'same-origin', headers: { 'X-CSRF-Token': CSRF, Accept: 'application/json' } };
    if (body) {
      if (form) opts.body = body;
      else { opts.body = JSON.stringify(body); opts.headers['Content-Type'] = 'application/json'; }
    }
    let res;
    try {
      res = await fetch('api.php?action=' + encodeURIComponent(action), opts);
    } catch {
      throw Object.assign(new Error('Sin conexión. Revisa internet e inténtalo de nuevo.'), { fields: {} });
    }
    if (res.status === 401) {
      location.reload();
      throw new Error('Sesión expirada');
    }
    const data = await res.json().catch(() => ({}));
    if (!res.ok) throw Object.assign(new Error(data.detail || 'No se pudo guardar.'), { fields: data.fields || {} });
    if (data.state) state = data.state;
    return data;
  }

  function priceSummary(p) {
    const active = p.variants.filter((v) => v.is_active);
    if (active.length) {
      const prices = active.map((v) => v.price).filter((n) => n > 0);
      const min = prices.length ? Math.min(...prices) : 0;
      const max = prices.length ? Math.max(...prices) : 0;
      return `${active.length} presentaciones · ${min === max ? money(min) : money(min) + ' a ' + money(max)}`;
    }
    return p.price == null ? 'Sin precio' : money(p.price);
  }

  function confirmDialog(title, text, okLabel) {
    return new Promise((resolve) => {
      const d = document.createElement('dialog');
      d.className = 'modal modal-sm';
      d.innerHTML = `<form method="dialog" class="modal-body stack">
        <h2 class="modal-title">${esc(title)}</h2><p class="muted">${esc(text)}</p>
        <div class="modal-actions"><button class="btn btn-ghost" value="no">Cancelar</button><button class="btn btn-danger" value="yes">${esc(okLabel)}</button></div></form>`;
      document.body.append(d);
      d.addEventListener('close', () => { resolve(d.returnValue === 'yes'); d.remove(); });
      d.showModal();
      $('[value="no"]', d).focus();
    });
  }

  function openModal(html, { wide = false } = {}) {
    const d = document.createElement('dialog');
    d.className = 'modal' + (wide ? ' modal-wide' : '');
    d.innerHTML = html;
    document.body.append(d);
    d.addEventListener('close', () => d.remove());
    d.addEventListener('cancel', (e) => {
      if (d.dataset.dirty === '1' && !window.confirm('Tienes cambios sin guardar. ¿Cerrar de todos modos?')) e.preventDefault();
    });
    d.addEventListener('input', () => { d.dataset.dirty = '1'; });
    $$('[data-close]', d).forEach((b) => b.addEventListener('click', () => d.dispatchEvent(new Event('cancel', { cancelable: true })) && d.close()));
    d.showModal();
    return d;
  }

  function showErrors(form, err) {
    $$('.field-error', form).forEach((n) => n.remove());
    $$('[aria-invalid]', form).forEach((n) => n.removeAttribute('aria-invalid'));
    Object.entries(err.fields || {}).forEach(([name, msg]) => {
      const input = form.elements[name];
      if (!input || !input.closest) return;
      input.setAttribute('aria-invalid', 'true');
      const p = document.createElement('p');
      p.className = 'field-error';
      p.id = 'err-' + name;
      p.textContent = msg;
      input.setAttribute('aria-describedby', p.id);
      input.closest('label')?.append(p);
    });
    const box = $('.form-error', form);
    if (box) { box.textContent = err.message; box.hidden = false; }
    ($('[aria-invalid]', form) || box)?.focus?.();
  }

  async function withBusy(btn, fn) {
    const label = btn.textContent;
    btn.disabled = true;
    btn.textContent = 'Guardando…';
    try { return await fn(); } finally { btn.disabled = false; btn.textContent = label; }
  }

  function move(list, id, dir) {
    const ids = list.map((x) => x.id);
    const i = ids.indexOf(id);
    const j = i + dir;
    if (i < 0 || j < 0 || j >= ids.length) return null;
    [ids[i], ids[j]] = [ids[j], ids[i]];
    return ids;
  }

  // ---------- router ----------
  const views = { productos: renderProducts, categorias: renderCategories, promos: renderPromos, ajustes: renderSettings };
  function route() {
    const name = (location.hash || '#productos').slice(1);
    const fn = views[name] || renderProducts;
    $$('.tabs a').forEach((a) => a.setAttribute('aria-current', a.dataset.view === name ? 'page' : 'false'));
    fn();
  }
  window.addEventListener('hashchange', () => { route(); view.focus({ preventScroll: true }); window.scrollTo(0, 0); });

  // ---------- productos ----------
  function renderProducts() {
    const cats = state.categories;
    const q = norm(ui.q);
    const groups = cats
      .filter((c) => ui.cat === 'all' || String(c.id) === ui.cat)
      .map((c) => ({ c, items: state.products.filter((p) => p.category_id === c.id && (!q || norm(p.name + ' ' + (p.description || '')).includes(q))) }))
      .filter((g) => g.items.length || (!q && ui.cat !== 'all'));
    const total = state.products.length;
    const hidden = state.products.filter((p) => !p.is_active).length;

    view.innerHTML = `
      <div class="view-head">
        <div><h1>Productos</h1><p class="muted">${total} en la carta${hidden ? ` · ${hidden} ${hidden === 1 ? 'oculto' : 'ocultos'}` : ''}</p></div>
        <button class="btn btn-primary" data-act="new">${icon('plus')} Nuevo producto</button>
      </div>
      <div class="toolbar">
        <label class="grow"><span class="sr-only">Buscar producto</span><input type="search" id="pq" placeholder="Buscar producto…" value="${esc(ui.q)}"></label>
        <label><span class="sr-only">Filtrar por categoría</span><select id="pc">
          <option value="all">Todas las categorías</option>
          ${cats.map((c) => `<option value="${c.id}" ${String(c.id) === ui.cat ? 'selected' : ''}>${esc(c.name)}</option>`).join('')}
        </select></label>
      </div>
      ${groups.length ? groups.map(({ c, items }) => `
        <section class="group">
          <h2 class="group-title">${esc(c.name)} <span class="count">${items.length}</span>${c.is_active ? '' : ' <span class="tag">Categoría oculta</span>'}</h2>
          ${items.length ? `<ul class="rows">${items.map((p, i) => productRow(p, i, items.length, !!q)).join('')}</ul>`
            : `<p class="empty-row">Sin productos. <button class="link" data-act="new" data-cat="${c.id}">Añade el primero</button></p>`}
        </section>`).join('')
      : `<div class="empty-state"><p class="empty-title">Nada coincide con «${esc(ui.q)}»</p><p class="muted">Revisa la búsqueda o crea el producto.</p></div>`}
    `;

    const pq = $('#pq');
    pq.addEventListener('input', () => {
      ui.q = pq.value;
      const pos = pq.selectionStart;
      renderProducts();
      const n = $('#pq');
      n.focus();
      n.setSelectionRange(pos, pos);
    });
    $('#pc').addEventListener('change', (e) => { ui.cat = e.target.value; renderProducts(); });
  }

  function productRow(p, i, n, searching) {
    return `<li class="row ${p.is_active ? '' : 'is-off'}" data-id="${p.id}">
      <span class="thumb">${p.image_url ? `<img src="../${esc(p.image_url)}" alt="" loading="lazy">` : icon('image')}</span>
      <div class="row-main">
        <button class="row-title" data-act="edit">${esc(p.name)}</button>
        <p class="row-sub">${esc(priceSummary(p))}${p.badge ? ` · <span class="tag tag-gold">${esc(p.badge)}</span>` : ''}</p>
      </div>
      <div class="row-actions">
        <label class="switch" title="Mostrar en la carta"><input type="checkbox" data-act="active" ${p.is_active ? 'checked' : ''}><span class="switch-ui" aria-hidden="true"></span><span class="switch-label">${p.is_active ? 'Visible' : 'Oculto'}</span></label>
        <button class="icon-btn ${p.is_featured ? 'is-on' : ''}" data-act="featured" aria-pressed="${p.is_featured}" title="Destacar en «Los más pedidos»">${icon('star')}<span class="sr-only">Destacar</span></button>
        ${searching ? '' : `<button class="icon-btn" data-act="up" ${i === 0 ? 'disabled' : ''} title="Subir">${icon('up')}<span class="sr-only">Subir</span></button>
        <button class="icon-btn" data-act="down" ${i === n - 1 ? 'disabled' : ''} title="Bajar">${icon('down')}<span class="sr-only">Bajar</span></button>`}
        <button class="icon-btn" data-act="duplicate" title="Duplicar">${icon('copy')}<span class="sr-only">Duplicar</span></button>
        <button class="icon-btn icon-danger" data-act="delete" title="Eliminar">${icon('trash')}<span class="sr-only">Eliminar</span></button>
      </div>
    </li>`;
  }

  view.addEventListener('click', async (e) => {
    const btn = e.target.closest('[data-act]');
    if (!btn || !view.contains(btn) || btn.type === 'checkbox') return;
    const page = (location.hash || '#productos').slice(1);
    const id = Number(btn.closest('[data-id]')?.dataset.id);
    try {
      if (page === 'productos') await productAction(btn.dataset.act, id, btn);
      else if (page === 'categorias') await categoryAction(btn.dataset.act, id);
      else if (page === 'promos') await promoAction(btn.dataset.act, id);
    } catch (err) {
      toast(err.message, 'error');
    }
  });

  view.addEventListener('change', async (e) => {
    const cb = e.target.closest('input[type="checkbox"][data-act]');
    if (!cb) return;
    const id = Number(cb.closest('[data-id]').dataset.id);
    const page = (location.hash || '#productos').slice(1);
    try {
      if (page === 'productos') await api('product.toggle', { id, field: 'is_active', value: cb.checked });
      else if (page === 'categorias') {
        const c = state.categories.find((x) => x.id === id);
        await api('category.save', { ...c, is_active: cb.checked });
      } else if (page === 'promos') await api('promo.toggle', { id, value: cb.checked });
      toast(cb.checked ? 'Visible en la carta' : 'Oculto de la carta');
      route();
    } catch (err) {
      cb.checked = !cb.checked;
      toast(err.message, 'error');
    }
  });

  async function productAction(act, id, btn) {
    const p = state.products.find((x) => x.id === id);
    if (act === 'new') return productEditor(null, btn.dataset.cat ? Number(btn.dataset.cat) : (ui.cat !== 'all' ? Number(ui.cat) : undefined));
    if (act === 'edit') return productEditor(p);
    if (act === 'featured') {
      await api('product.toggle', { id, field: 'is_featured', value: !p.is_featured });
      toast(p.is_featured ? 'Quitado de destacados' : 'Añadido a «Los más pedidos»');
    }
    if (act === 'up' || act === 'down') {
      const siblings = state.products.filter((x) => x.category_id === p.category_id);
      const ids = move(siblings, id, act === 'up' ? -1 : 1);
      if (!ids) return;
      await api('product.reorder', { category_id: p.category_id, ids });
    }
    if (act === 'duplicate') {
      await api('product.duplicate', { id });
      toast('Copia creada. Está oculta hasta que la actives.');
    }
    if (act === 'delete') {
      if (!(await confirmDialog('¿Eliminar producto?', `«${p.name}» y sus presentaciones se borrarán de la carta. No se puede deshacer.`, 'Eliminar'))) return;
      await api('product.delete', { id });
      toast('Producto eliminado');
    }
    renderProducts();
    if (act === 'up' || act === 'down') $(`[data-id="${id}"] [data-act="${act}"]`)?.focus();
  }

  function variantRow(v = {}) {
    return `<li class="var-row">
      <label><span class="sr-only">Presentación</span><input name="v_label" placeholder="Ej.: Botella" value="${esc(v.label)}" maxlength="60"></label>
      <label><span class="sr-only">Detalle</span><input name="v_detail" placeholder="750 ml" value="${esc(v.detail)}" maxlength="80"></label>
      <label><span class="sr-only">Nota</span><input name="v_note" placeholder="Solo miércoles" value="${esc(v.note)}" maxlength="60"></label>
      <label><span class="sr-only">Precio</span><input name="v_price" inputmode="numeric" placeholder="$ precio" value="${v.price ?? ''}"></label>
      <label class="check" title="Visible"><input type="checkbox" name="v_active" ${v.is_active === false ? '' : 'checked'}><span class="sr-only">Visible</span></label>
      <div class="var-tools">
        <button type="button" class="icon-btn" data-v="up" title="Subir">${icon('up')}<span class="sr-only">Subir</span></button>
        <button type="button" class="icon-btn" data-v="down" title="Bajar">${icon('down')}<span class="sr-only">Bajar</span></button>
        <button type="button" class="icon-btn icon-danger" data-v="remove" title="Quitar">${icon('x')}<span class="sr-only">Quitar presentación</span></button>
      </div>
    </li>`;
  }

  function productEditor(p, presetCat) {
    const isNew = !p;
    p = p || { name: '', description: '', price: null, badge: '', category_id: presetCat ?? state.categories[0]?.id, is_active: true, is_featured: false, variants: [], image_url: null };
    if (!state.categories.length) { toast('Crea primero una categoría.', 'error'); location.hash = '#categorias'; return; }
    const d = openModal(`
      <form class="modal-body" novalidate>
        <header class="modal-head">
          <h2 class="modal-title">${isNew ? 'Nuevo producto' : 'Editar producto'}</h2>
          <button type="button" class="icon-btn" data-close title="Cerrar">${icon('x')}<span class="sr-only">Cerrar</span></button>
        </header>
        <p class="alert alert-error form-error" tabindex="-1" hidden></p>
        <div class="editor-grid">
          <div class="stack">
            <label>Nombre<input name="name" required maxlength="120" value="${esc(p.name)}"></label>
            <label>Categoría<select name="category_id">${state.categories.map((c) => `<option value="${c.id}" ${c.id === p.category_id ? 'selected' : ''}>${esc(c.name)}</option>`).join('')}</select></label>
            <label>Descripción <span class="opt">opcional</span><textarea name="description" rows="3" maxlength="1000">${esc(p.description)}</textarea></label>
            <div class="two">
              <label>Precio único<input name="price" inputmode="numeric" placeholder="Ej.: 30000" value="${p.price ?? ''}"></label>
              <label>Etiqueta <span class="opt">opcional</span><input name="badge" maxlength="24" placeholder="Nuevo, Promo…" value="${esc(p.badge)}"></label>
            </div>
            <p class="hint price-hint">Si agregas presentaciones, el precio se toma de cada una y el precio único se ignora.</p>
            <div class="checks">
              <label class="check"><input type="checkbox" name="is_active" ${p.is_active ? 'checked' : ''}> Visible en la carta</label>
              <label class="check"><input type="checkbox" name="is_featured" ${p.is_featured ? 'checked' : ''}> Mostrar en «Los más pedidos»</label>
            </div>
          </div>
          <div class="stack">
            <span class="label">Foto</span>
            <div class="drop ${p.image_url ? 'has-img' : ''}">
              <img class="drop-preview" alt="" ${p.image_url ? `src="../${esc(p.image_url)}"` : 'hidden'}>
              <div class="drop-empty">${icon('image')}<span>Arrastra una imagen o elígela</span><small>JPG, PNG o WebP · máx. 8 MB</small></div>
              <input type="file" name="image" accept="image/jpeg,image/png,image/webp,image/gif" aria-label="Elegir foto">
            </div>
            <button type="button" class="link remove-img" ${p.image_url ? '' : 'hidden'}>Quitar foto</button>
            <input type="hidden" name="remove_image" value="0">
          </div>
        </div>
        <section class="variants">
          <div class="variants-head">
            <h3>Presentaciones <span class="opt">trago, media, botella, michelada…</span></h3>
            <button type="button" class="btn btn-ghost btn-sm" data-v="add">${icon('plus')} Añadir</button>
          </div>
          <div class="var-cols" aria-hidden="true"><span>Presentación</span><span>Detalle</span><span>Nota</span><span>Precio</span><span>Ver</span><span></span></div>
          <ul class="var-list">${p.variants.map(variantRow).join('')}</ul>
        </section>
        <footer class="modal-actions">
          <button type="button" class="btn btn-ghost" data-close>Cancelar</button>
          <button class="btn btn-primary" type="submit">${isNew ? 'Crear producto' : 'Guardar cambios'}</button>
        </footer>
      </form>`, { wide: true });

    const form = $('form', d);
    const list = $('.var-list', form);
    const syncHint = () => {
      const has = $$('.var-row', list).length > 0;
      form.elements.price.disabled = has;
      $('.price-hint', form).hidden = !has;
      $('.var-cols', form).hidden = !has;
    };
    syncHint();

    form.addEventListener('click', (e) => {
      const b = e.target.closest('[data-v]');
      if (!b) return;
      const row = b.closest('.var-row');
      if (b.dataset.v === 'add') {
        list.insertAdjacentHTML('beforeend', variantRow());
        $('.var-row:last-child input', list).focus();
      }
      if (b.dataset.v === 'remove') row.remove();
      if (b.dataset.v === 'up' && row.previousElementSibling) row.previousElementSibling.before(row);
      if (b.dataset.v === 'down' && row.nextElementSibling) row.nextElementSibling.after(row);
      d.dataset.dirty = '1';
      syncHint();
    });

    const file = form.elements.image;
    const preview = $('.drop-preview', form);
    const drop = $('.drop', form);
    file.addEventListener('change', () => {
      const f = file.files[0];
      if (!f) return;
      if (f.size > 8 * 1024 * 1024) { toast('La imagen pesa más de 8 MB.', 'error'); file.value = ''; return; }
      preview.src = URL.createObjectURL(f);
      preview.hidden = false;
      drop.classList.add('has-img');
      $('.remove-img', form).hidden = false;
      form.elements.remove_image.value = '0';
    });
    ['dragenter', 'dragover'].forEach((ev) => drop.addEventListener(ev, () => drop.classList.add('is-over')));
    ['dragleave', 'drop'].forEach((ev) => drop.addEventListener(ev, () => drop.classList.remove('is-over')));
    $('.remove-img', form).addEventListener('click', (e) => {
      file.value = '';
      preview.hidden = true;
      preview.removeAttribute('src');
      drop.classList.remove('has-img');
      e.target.hidden = true;
      form.elements.remove_image.value = '1';
      d.dataset.dirty = '1';
    });

    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      const fd = new FormData();
      if (!isNew) fd.append('id', p.id);
      ['name', 'category_id', 'description', 'badge', 'remove_image'].forEach((k) => fd.append(k, form.elements[k].value));
      fd.append('price', form.elements.price.disabled ? '' : form.elements.price.value);
      fd.append('is_active', form.elements.is_active.checked ? '1' : '0');
      fd.append('is_featured', form.elements.is_featured.checked ? '1' : '0');
      fd.append('variants', JSON.stringify($$('.var-row', list).map((r) => ({
        label: $('[name="v_label"]', r).value,
        detail: $('[name="v_detail"]', r).value,
        note: $('[name="v_note"]', r).value,
        price: $('[name="v_price"]', r).value,
        is_active: $('[name="v_active"]', r).checked,
      }))));
      if (file.files[0]) fd.append('image', file.files[0]);
      try {
        await withBusy($('[type="submit"]', form), () => api('product.save', fd, { form: true }));
        d.dataset.dirty = '0';
        d.close();
        toast(isNew ? 'Producto creado' : 'Cambios guardados');
        renderProducts();
      } catch (err) {
        showErrors(form, err);
      }
    });
    form.elements.name.focus();
  }

  // ---------- categorías ----------
  function renderCategories() {
    const cats = state.categories;
    view.innerHTML = `
      <div class="view-head">
        <div><h1>Categorías</h1><p class="muted">El orden de esta lista es el orden de la carta.</p></div>
        <button class="btn btn-primary" data-act="new">${icon('plus')} Nueva categoría</button>
      </div>
      <ul class="rows">${cats.map((c, i) => `
        <li class="row ${c.is_active ? '' : 'is-off'}" data-id="${c.id}">
          <div class="row-main">
            <button class="row-title" data-act="edit">${esc(c.name)}</button>
            <p class="row-sub">${c.product_count} ${c.product_count === 1 ? 'producto' : 'productos'}${c.description ? ' · ' + esc(c.description) : ''}</p>
          </div>
          <div class="row-actions">
            <label class="switch" title="Mostrar en la carta"><input type="checkbox" data-act="active" ${c.is_active ? 'checked' : ''}><span class="switch-ui" aria-hidden="true"></span><span class="switch-label">${c.is_active ? 'Visible' : 'Oculta'}</span></label>
            <button class="icon-btn" data-act="up" ${i === 0 ? 'disabled' : ''} title="Subir">${icon('up')}<span class="sr-only">Subir</span></button>
            <button class="icon-btn" data-act="down" ${i === cats.length - 1 ? 'disabled' : ''} title="Bajar">${icon('down')}<span class="sr-only">Bajar</span></button>
            <button class="icon-btn" data-act="products" title="Ver productos">${icon('edit')}<span class="sr-only">Ver productos</span></button>
            <button class="icon-btn icon-danger" data-act="delete" title="Eliminar">${icon('trash')}<span class="sr-only">Eliminar</span></button>
          </div>
        </li>`).join('')}
      </ul>`;
  }

  async function categoryAction(act, id) {
    const c = state.categories.find((x) => x.id === id);
    if (act === 'new' || act === 'edit') return categoryEditor(c);
    if (act === 'products') { ui.cat = String(id); ui.q = ''; location.hash = '#productos'; return; }
    if (act === 'up' || act === 'down') {
      const ids = move(state.categories, id, act === 'up' ? -1 : 1);
      if (!ids) return;
      await api('category.reorder', { ids });
    }
    if (act === 'delete') {
      if (c.product_count > 0) { toast(`«${c.name}» tiene ${c.product_count} productos. Muévelos o elimínalos primero.`, 'error'); return; }
      if (!(await confirmDialog('¿Eliminar categoría?', `«${c.name}» se borrará de la carta.`, 'Eliminar'))) return;
      await api('category.delete', { id });
      toast('Categoría eliminada');
    }
    renderCategories();
    if (act === 'up' || act === 'down') $(`[data-id="${id}"] [data-act="${act}"]`)?.focus();
  }

  function categoryEditor(c) {
    const isNew = !c;
    c = c || { name: '', description: '', is_active: true };
    const d = openModal(`
      <form class="modal-body stack" novalidate>
        <header class="modal-head"><h2 class="modal-title">${isNew ? 'Nueva categoría' : 'Editar categoría'}</h2>
          <button type="button" class="icon-btn" data-close title="Cerrar">${icon('x')}<span class="sr-only">Cerrar</span></button></header>
        <p class="alert alert-error form-error" tabindex="-1" hidden></p>
        <label>Nombre<input name="name" required maxlength="80" value="${esc(c.name)}"></label>
        <label>Descripción <span class="opt">opcional</span><input name="description" maxlength="255" value="${esc(c.description)}"></label>
        <label class="check"><input type="checkbox" name="is_active" ${c.is_active ? 'checked' : ''}> Visible en la carta</label>
        <footer class="modal-actions"><button type="button" class="btn btn-ghost" data-close>Cancelar</button>
          <button class="btn btn-primary" type="submit">${isNew ? 'Crear categoría' : 'Guardar cambios'}</button></footer>
      </form>`);
    const form = $('form', d);
    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      try {
        await withBusy($('[type="submit"]', form), () => api('category.save', {
          id: c.id, name: form.elements.name.value, description: form.elements.description.value, is_active: form.elements.is_active.checked,
        }));
        d.dataset.dirty = '0';
        d.close();
        toast(isNew ? 'Categoría creada' : 'Cambios guardados');
        renderCategories();
      } catch (err) { showErrors(form, err); }
    });
    form.elements.name.focus();
  }

  // ---------- promos ----------
  const fmtDays = (csv) => {
    const ds = csv.split(',').map(Number).filter(Boolean);
    if (ds.length === 7) return 'Todos los días';
    return ds.map((n) => DAYS[n - 1][1]).join(', ');
  };

  function renderPromos() {
    const list = state.promotions;
    view.innerHTML = `
      <div class="view-head">
        <div><h1>Promos</h1><p class="muted">Se muestran arriba de la carta; la del día aparece marcada como «Hoy».</p></div>
        <button class="btn btn-primary" data-act="new">${icon('plus')} Nueva promo</button>
      </div>
      ${list.length ? `<ul class="rows">${list.map((pr, i) => `
        <li class="row ${pr.is_active ? '' : 'is-off'}" data-id="${pr.id}">
          <div class="row-main">
            <button class="row-title" data-act="edit">${esc(pr.title)}</button>
            <p class="row-sub">${esc(fmtDays(pr.days))}${pr.time_from ? ` · ${esc(pr.time_from)} a ${esc(pr.time_to)}` : ''}${pr.detail ? ' · ' + esc(pr.detail) : ''}</p>
          </div>
          <div class="row-actions">
            <label class="switch"><input type="checkbox" data-act="active" ${pr.is_active ? 'checked' : ''}><span class="switch-ui" aria-hidden="true"></span><span class="switch-label">${pr.is_active ? 'Visible' : 'Oculta'}</span></label>
            <button class="icon-btn" data-act="up" ${i === 0 ? 'disabled' : ''} title="Subir">${icon('up')}<span class="sr-only">Subir</span></button>
            <button class="icon-btn" data-act="down" ${i === list.length - 1 ? 'disabled' : ''} title="Bajar">${icon('down')}<span class="sr-only">Bajar</span></button>
            <button class="icon-btn icon-danger" data-act="delete" title="Eliminar">${icon('trash')}<span class="sr-only">Eliminar</span></button>
          </div>
        </li>`).join('')}</ul>`
      : `<div class="empty-state"><p class="empty-title">Sin promos</p><p class="muted">Crea una para destacarla arriba de la carta.</p></div>`}`;
  }

  async function promoAction(act, id) {
    const pr = state.promotions.find((x) => x.id === id);
    if (act === 'new' || act === 'edit') return promoEditor(pr);
    if (act === 'up' || act === 'down') {
      const ids = move(state.promotions, id, act === 'up' ? -1 : 1);
      if (!ids) return;
      await api('promo.reorder', { ids });
    }
    if (act === 'delete') {
      if (!(await confirmDialog('¿Eliminar promo?', `«${pr.title}» dejará de mostrarse.`, 'Eliminar'))) return;
      await api('promo.delete', { id });
      toast('Promo eliminada');
    }
    renderPromos();
  }

  function promoEditor(pr) {
    const isNew = !pr;
    pr = pr || { title: '', detail: '', days: '', time_from: '', time_to: '', is_active: true };
    const sel = pr.days.split(',').map(Number);
    const d = openModal(`
      <form class="modal-body stack" novalidate>
        <header class="modal-head"><h2 class="modal-title">${isNew ? 'Nueva promo' : 'Editar promo'}</h2>
          <button type="button" class="icon-btn" data-close title="Cerrar">${icon('x')}<span class="sr-only">Cerrar</span></button></header>
        <p class="alert alert-error form-error" tabindex="-1" hidden></p>
        <label>Título<input name="title" required maxlength="80" placeholder="2 cócteles por $40.000" value="${esc(pr.title)}"></label>
        <label>Detalle <span class="opt">opcional</span><textarea name="detail" rows="2" maxlength="255">${esc(pr.detail)}</textarea></label>
        <fieldset class="days"><legend>Días</legend>
          ${DAYS.map(([n, l]) => `<label class="day"><input type="checkbox" name="days" value="${n}" ${sel.includes(n) ? 'checked' : ''}><span>${l}</span></label>`).join('')}
        </fieldset>
        <div class="two">
          <label>Desde <span class="opt">opcional</span><input type="time" name="time_from" value="${esc(pr.time_from)}"></label>
          <label>Hasta<input type="time" name="time_to" value="${esc(pr.time_to)}"></label>
        </div>
        <label class="check"><input type="checkbox" name="is_active" ${pr.is_active ? 'checked' : ''}> Visible en la carta</label>
        <footer class="modal-actions"><button type="button" class="btn btn-ghost" data-close>Cancelar</button>
          <button class="btn btn-primary" type="submit">${isNew ? 'Crear promo' : 'Guardar cambios'}</button></footer>
      </form>`);
    const form = $('form', d);
    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      try {
        await withBusy($('[type="submit"]', form), () => api('promo.save', {
          id: pr.id,
          title: form.elements.title.value,
          detail: form.elements.detail.value,
          days: $$('[name="days"]:checked', form).map((x) => Number(x.value)),
          time_from: form.elements.time_from.value,
          time_to: form.elements.time_to.value,
          is_active: form.elements.is_active.checked,
        }));
        d.dataset.dirty = '0';
        d.close();
        toast(isNew ? 'Promo creada' : 'Cambios guardados');
        renderPromos();
      } catch (err) { showErrors(form, err); }
    });
    form.elements.title.focus();
  }

  // ---------- ajustes ----------
  function renderSettings() {
    const s = state.settings;
    const field = (k, label, opts = {}) => `<label>${label}${opts.opt ? ' <span class="opt">opcional</span>' : ''}
      <input name="${k}" value="${esc(s[k])}" ${opts.attrs || ''}>${opts.hint ? `<span class="hint">${opts.hint}</span>` : ''}</label>`;
    view.innerHTML = `
      <div class="view-head"><div><h1>Ajustes</h1><p class="muted">Datos del bar, horario y acceso al panel.</p></div></div>
      <form class="settings" id="sf" novalidate>
        <p class="alert alert-error form-error" tabindex="-1" hidden></p>
        <section class="card stack">
          <h2 class="card-title">El bar</h2>
          <div class="two">${field('business_name', 'Nombre', { attrs: 'maxlength="80" required' })}${field('tagline', 'Frase bajo el logo', { attrs: 'maxlength="120"' })}</div>
          <div class="two">${field('address', 'Dirección', { attrs: 'maxlength="120"' })}${field('phone', 'Teléfono', { attrs: 'maxlength="30" inputmode="tel"' })}</div>
          <div class="two">${field('whatsapp', 'WhatsApp', { attrs: 'maxlength="20" inputmode="tel"', hint: 'Con indicativo, sin espacios: 573163936616', opt: true })}${field('instagram', 'Instagram', { attrs: 'maxlength="40"', hint: 'Usuario sin @', opt: true })}</div>
          <div class="two">${field('website', 'Sitio web', { attrs: 'maxlength="200" inputmode="url"', opt: true })}${field('maps_url', 'Enlace de Google Maps', { attrs: 'maxlength="300" inputmode="url"', opt: true })}</div>
        </section>
        <section class="card stack">
          <h2 class="card-title">Mensajes</h2>
          <label>Aviso destacado <span class="opt">opcional</span><textarea name="notice" rows="2" maxlength="240" placeholder="Ej.: Este sábado karaoke en vivo desde las 9 p. m.">${esc(s.notice)}</textarea><span class="hint">Aparece debajo del logo. Déjalo vacío para ocultarlo.</span></label>
          ${field('currency_note', 'Nota de precios', { attrs: 'maxlength="160"' })}
        </section>
        <section class="card stack">
          <h2 class="card-title">Horario</h2>
          <p class="hint">Si cierras después de medianoche, pon la hora del día siguiente (ej.: 03:00).</p>
          <div class="hours-edit">${DAYS.map(([n]) => {
            const h = s.hours[n] || null;
            return `<div class="hour-row" data-day="${n}">
              <span class="hour-day">${DAY_NAMES[n]}</span>
              <label class="check"><input type="checkbox" class="h-open" ${h ? 'checked' : ''}> Abre</label>
              <label><span class="sr-only">Abre a las</span><input type="time" class="h-from" value="${h ? h[0] : '17:00'}" ${h ? '' : 'disabled'}></label>
              <span class="muted">a</span>
              <label><span class="sr-only">Cierra a las</span><input type="time" class="h-to" value="${h ? h[1] : '23:00'}" ${h ? '' : 'disabled'}></label>
            </div>`;
          }).join('')}</div>
        </section>
        <div class="sticky-save"><button class="btn btn-primary" type="submit">Guardar ajustes</button></div>
      </form>
      <form class="card stack narrow" id="pf" novalidate>
        <h2 class="card-title">Cambiar contraseña</h2>
        <p class="alert alert-error form-error" tabindex="-1" hidden></p>
        <label>Contraseña actual<input type="password" name="current" autocomplete="current-password" required></label>
        <label>Nueva contraseña<input type="password" name="new" autocomplete="new-password" minlength="10" required><span class="hint">Mínimo 10 caracteres.</span></label>
        <div><button class="btn btn-ghost" type="submit">Cambiar contraseña</button></div>
      </form>`;

    const sf = $('#sf');
    $$('.h-open', sf).forEach((cb) => cb.addEventListener('change', () => {
      const row = cb.closest('.hour-row');
      $$('input[type="time"]', row).forEach((i) => { i.disabled = !cb.checked; });
    }));
    sf.addEventListener('submit', async (e) => {
      e.preventDefault();
      const body = {};
      ['business_name', 'tagline', 'address', 'phone', 'whatsapp', 'instagram', 'website', 'maps_url', 'notice', 'currency_note'].forEach((k) => { body[k] = sf.elements[k].value; });
      body.hours = {};
      $$('.hour-row', sf).forEach((r) => {
        body.hours[r.dataset.day] = $('.h-open', r).checked ? [$('.h-from', r).value, $('.h-to', r).value] : null;
      });
      try {
        await withBusy($('[type="submit"]', sf), () => api('settings.save', body));
        $('.form-error', sf).hidden = true;
        toast('Ajustes guardados');
      } catch (err) { showErrors(sf, err); }
    });

    const pf = $('#pf');
    pf.addEventListener('submit', async (e) => {
      e.preventDefault();
      try {
        await withBusy($('[type="submit"]', pf), () => api('account.password', { current: pf.elements.current.value, new: pf.elements.new.value }));
        pf.reset();
        $('.form-error', pf).hidden = true;
        toast('Contraseña actualizada');
      } catch (err) { showErrors(pf, err); }
    });
  }

  // ---------- inicio ----------
  api('state').then((data) => { state = data; route(); }).catch((err) => {
    view.innerHTML = `<div class="empty-state"><p class="empty-title">No se pudo cargar la carta</p><p class="muted">${esc(err.message)}</p><button class="btn btn-primary" id="retry">Reintentar</button></div>`;
    $('#retry').addEventListener('click', () => location.reload());
  });
})();
