// Küçük arayüz kütüphanesi. Tüm kullanıcı verisi textContent ile yazılır (XSS'e karşı innerHTML kullanılmaz).
import { api, newKey } from './api.js';

const SVG_NS = 'http://www.w3.org/2000/svg';

export function h(tag, attrs, ...kids) {
  const el = document.createElement(tag);
  fill(el, attrs, kids);
  return el;
}
export function s(tag, attrs, ...kids) {
  const el = document.createElementNS(SVG_NS, tag);
  fill(el, attrs, kids);
  return el;
}
function fill(el, attrs, kids) {
  if (attrs) {
    for (const [k, v] of Object.entries(attrs)) {
      if (v === null || v === undefined || v === false) continue;
      if (k === 'style') continue; // CSP: satır içi stil yok
      if (k === 'class') el.setAttribute('class', v);
      else if (k.startsWith('on') && typeof v === 'function') el.addEventListener(k.slice(2).toLowerCase(), v);
      else if (k === 'value') el.value = v;
      else if (['checked', 'disabled', 'selected', 'required', 'readOnly', 'multiple', 'hidden'].includes(k)) el[k] = !!v;
      else el.setAttribute(k, v === true ? '' : String(v));
    }
  }
  append(el, kids);
}
export function append(el, kids) {
  for (const k of kids.flat(Infinity)) {
    if (k === null || k === undefined || k === false) continue;
    el.appendChild(k instanceof Node ? k : document.createTextNode(String(k)));
  }
  return el;
}
export function clear(el) { while (el.firstChild) el.removeChild(el.firstChild); return el; }
export function mount(el, ...kids) { clear(el); append(el, kids); return el; }

// ---------- biçimlendirme ----------
const SYM = { TRY: '₺', EUR: '€', USD: '$' };
export const CURRENCIES = ['TRY', 'EUR', 'USD'];
export function groupInt(str) { return str.replace(/\B(?=(\d{3})+(?!\d))/g, '.'); }
/** Küçük birim (kuruş) → "1.234,56 ₺". Kayan nokta kullanılmaz. */
export function money(minor, cur = 'TRY', opt = {}) {
  if (minor === null || minor === undefined || Number.isNaN(Number(minor))) return '—';
  const n = Number(minor);
  const neg = n < 0;
  const str = String(Math.abs(Math.trunc(n))).padStart(3, '0');
  const out = groupInt(str.slice(0, -2)) + ',' + str.slice(-2);
  const sym = SYM[cur] || cur;
  return (neg ? '−' : (opt.sign && n > 0 ? '+' : '')) + out + ' ' + sym;
}
export function plain(minor) { // CSV/form girişi için: 1234,56
  const n = Number(minor); const neg = n < 0; const str = String(Math.abs(n)).padStart(3, '0');
  return (neg ? '-' : '') + str.slice(0, -2) + ',' + str.slice(-2);
}
/** Kullanıcı girdisi → küçük birim (tam sayı) ya da null. "1.234,56", "1234,5", "1234.56" kabul. */
export function parseMoney(text) {
  let t = String(text ?? '').trim().replace(/[₺€$\s]/g, '');
  if (t === '') return null;
  let neg = false;
  if (t.startsWith('-') || t.startsWith('−')) { neg = true; t = t.slice(1); }
  if (!/^[\d.,]+$/.test(t)) return null;
  let intPart; let dec = '';
  if (t.includes(',')) {
    const i = t.lastIndexOf(',');
    intPart = t.slice(0, i).replace(/\./g, '');
    dec = t.slice(i + 1);
  } else if (/^\d{1,3}(\.\d{3})+$/.test(t)) {
    intPart = t.replace(/\./g, '');
  } else if (t.includes('.')) {
    const i = t.lastIndexOf('.');
    intPart = t.slice(0, i).replace(/\./g, '');
    dec = t.slice(i + 1);
  } else intPart = t;
  if (!/^\d*$/.test(intPart) || !/^\d{0,2}$/.test(dec)) return null;
  const v = Number(intPart || '0') * 100 + Number((dec + '00').slice(0, 2));
  if (!Number.isSafeInteger(v)) return null;
  return neg ? -v : v;
}
export function qtyMilli(text) { // "2,5" → 2500
  const t = String(text ?? '').trim().replace(',', '.');
  if (!/^\d+(\.\d{1,3})?$/.test(t)) return null;
  const [a, b = ''] = t.split('.');
  return Number(a) * 1000 + Number((b + '000').slice(0, 3));
}
export const fmtQty = (m) => { const a = Math.trunc(Number(m) / 1000); const b = Math.abs(Number(m) % 1000); return b ? `${a},${String(b).padStart(3, '0').replace(/0+$/, '')}` : String(a); };
export function date(d) { // 2026-10-18 → 18.10.2026 (saat dilimi kayması olmaz)
  if (!d) return '—';
  const m = /^(\d{4})-(\d{2})-(\d{2})/.exec(d);
  return m ? `${m[3]}.${m[2]}.${m[1]}` : d;
}
export function dateTime(utc) { // sunucu UTC → Europe/Istanbul
  if (!utc) return '—';
  const d = new Date(utc.replace(' ', 'T') + 'Z');
  return new Intl.DateTimeFormat('tr-TR', { timeZone: 'Europe/Istanbul', dateStyle: 'short', timeStyle: 'short' }).format(d);
}
export const MONTHS = ['Ocak', 'Şubat', 'Mart', 'Nisan', 'Mayıs', 'Haziran', 'Temmuz', 'Ağustos', 'Eylül', 'Ekim', 'Kasım', 'Aralık'];
export const monthLabel = (ym) => { const [y, m] = ym.split('-'); return `${MONTHS[Number(m) - 1]} ${y}`; };
export function todayStr() {
  const p = new Intl.DateTimeFormat('en-CA', { timeZone: 'Europe/Istanbul', year: 'numeric', month: '2-digit', day: '2-digit' }).format(new Date());
  return p; // YYYY-MM-DD
}
export function addDays(ds, n) {
  const [y, m, d] = ds.split('-').map(Number);
  const dt = new Date(Date.UTC(y, m - 1, d + n));
  return dt.toISOString().slice(0, 10);
}
export const pct = (bp) => `%${(Number(bp) / 100).toLocaleString('tr-TR', { maximumFractionDigits: 2 })}`;

export const KIND_TR = {
  income: 'Gelir', expense: 'Gider', collection: 'Tahsilat', payment: 'Ödeme', transfer: 'Transfer', fx: 'Döviz', card_payment: 'Kart ödemesi',
  loan_payment: 'Kredi taksiti', loan_proceeds: 'Kredi kullandırım', adjustment: 'Bakiye düzeltme', owner_in: 'İşletmeye para koyma', owner_out: 'İşletmeden para çekme',
};
export const STATE_BADGE = {
  paid: ['ok', 'Ödendi'], partial: ['warn', 'Kısmi'], open: ['info', 'Açık'], overdue: ['bad', 'Gecikmiş'], cancelled: ['', 'İptal'], unpaid: ['info', 'Ödenmedi'],
  planned: ['', 'Planlı'], issued: ['pri', 'Kesildi'], upcoming: ['', 'Yaklaşan'], due: ['warn', 'Ekstre kesildi'],
};
export function stateBadge(st) { const [c, t] = STATE_BADGE[st] || ['', st]; return h('span', { class: 'badge ' + c }, t); }
export const signClass = (n) => (n > 0 ? 'pos' : n < 0 ? 'neg' : '');

// ---------- toast / modal ----------
let toastHost;
export function toast(msg, kind = '') {
  if (!toastHost) { toastHost = h('div', { class: 'toasts', role: 'status', 'aria-live': 'polite' }); document.body.appendChild(toastHost); }
  const t = h('div', { class: 'toast ' + kind }, msg);
  while (toastHost.children.length >= 3) toastHost.firstChild.remove(); // en fazla 3 bildirim
  toastHost.appendChild(t);
  setTimeout(() => t.remove(), kind === 'err' ? 7000 : 3800);
}
export function errToast(e) { toast(e && e.message ? e.message : 'Beklenmeyen bir hata oluştu.', 'err'); }

const openModals = new Set();
export function closeAllModals() { [...openModals].forEach((m) => m.close(true)); document.querySelectorAll('.menu').forEach((n) => n.remove()); }
export function modal({ title, body, foot, wide = false, onClose, dirty }) {
  const prevFocus = document.activeElement;
  const overlay = h('div', { class: 'overlay', role: 'dialog', 'aria-modal': 'true' });
  const close = async (force) => {
    if (!force && dirty && dirty()) {
      if (!(await confirmDialog({ title: 'Kaydedilmemiş değişiklikler', text: 'Değişiklikleriniz kaydedilmedi. Kapatmak istediğinize emin misiniz?', ok: 'Kapat', danger: true }))) return;
    }
    overlay.remove();
    openModals.delete(handle);
    document.removeEventListener('keydown', onKey);
    if (prevFocus && prevFocus.focus) prevFocus.focus();
    if (onClose) onClose();
  };
  const onKey = (e) => { if (e.key === 'Escape' && overlay === [...document.querySelectorAll('.overlay')].pop()) close(); };
  document.addEventListener('keydown', onKey);
  const box = h('div', { class: 'modal' + (wide ? ' wide' : '') },
    h('div', { class: 'modal-head' }, h('h2', null, title), h('button', { class: 'btn ghost sm', type: 'button', 'aria-label': 'Kapat', onclick: () => close() }, '✕')),
    h('div', { class: 'modal-body' }, body),
    foot ? h('div', { class: 'modal-foot' }, foot) : null);
  overlay.appendChild(box);
  overlay.addEventListener('mousedown', (e) => { if (e.target === overlay) close(); });
  document.body.appendChild(overlay);
  const first = box.querySelector('input:not([type=hidden]),select,textarea,button.primary');
  if (first) setTimeout(() => first.focus(), 30);
  const handle = { close, el: box, overlay };
  openModals.add(handle);
  return handle;
}

export function confirmDialog({ title, text, ok = 'Onayla', cancel = 'Vazgeç', danger = false, input }) {
  return new Promise((resolve) => {
    let inp = null;
    if (input) inp = h('input', { class: 'input', type: 'text', placeholder: input, autocomplete: 'off' });
    let done = false;
    const finish = (v) => { if (done) return; done = true; m.close(true); resolve(v); };
    const okBtn = h('button', { class: 'btn ' + (danger ? 'danger' : 'primary'), type: 'button', onclick: () => finish(input ? (inp.value.trim() || false) : true) }, ok);
    const m = modal({
      title, body: h('div', { class: 'stack' }, h('p', { style: null }, text), inp),
      foot: [h('button', { class: 'btn', type: 'button', onclick: () => finish(false) }, cancel), okBtn], onClose: () => finish(false),
    });
  });
}

export function menu(anchor, items) {
  document.querySelectorAll('.menu').forEach((m) => m.remove());
  const r = anchor.getBoundingClientRect();
  const m = h('div', { class: 'menu', role: 'menu' }, items.filter(Boolean).map((it) => h('button', { type: 'button', onclick: () => { m.remove(); it.run(); } }, it.label)));
  document.body.appendChild(m);
  const left = Math.min(window.innerWidth - m.offsetWidth - 8, Math.max(8, r.right - m.offsetWidth + window.scrollX));
  m.style.top = (r.bottom + window.scrollY + 4) + 'px';
  m.style.left = left + 'px';
  setTimeout(() => document.addEventListener('click', () => m.remove(), { once: true }), 0);
}

// ---------- form ----------
function fieldEl(f, ctx) {
  const id = 'f_' + f.name + '_' + Math.random().toString(36).slice(2, 7);
  let input;
  const common = { id, name: f.name, class: 'input', required: !!f.required && f.type !== 'checkbox', placeholder: f.placeholder, autocomplete: f.autocomplete || 'off', disabled: !!f.disabled };
  if (f.type === 'select') {
    const opts = typeof f.options === 'function' ? f.options() : f.options;
    input = h('select', common, opts.map(([v, l]) => h('option', { value: v, selected: String(v) === String(f.value ?? '') }, l)));
  } else if (f.type === 'textarea') {
    input = h('textarea', { ...common, rows: f.rows || 3, maxlength: f.max }, f.value ?? '');
  } else if (f.type === 'checkbox') {
    input = h('input', { id, name: f.name, type: 'checkbox', checked: !!f.value });
  } else if (f.type === 'money') {
    input = h('input', { ...common, type: 'text', inputmode: 'decimal', value: f.value != null && f.value !== '' ? plain(f.value) : '' });
  } else if (f.type === 'qty') {
    input = h('input', { ...common, type: 'text', inputmode: 'decimal', value: f.value != null ? fmtQty(f.value) : '' });
  } else {
    input = h('input', { ...common, type: f.type || 'text', value: f.value ?? '', min: f.min, max: f.max, step: f.step, maxlength: f.maxlength, inputmode: f.inputmode, autocapitalize: f.type === 'email' ? 'none' : undefined });
  }
  const err = h('div', { class: 'err hide' });
  const wrap = h('div', { class: 'field' + (f.full ? ' full' : '') },
    f.type === 'checkbox' ? h('label', { class: 'check', for: id }, input, f.label) : h('label', { for: id }, f.label + (f.required ? ' *' : '')),
    f.type === 'checkbox' ? null : input, f.hint ? h('div', { class: 'hint' }, f.hint) : null, err);
  wrap.setError = (m) => { wrap.classList.toggle('invalid', !!m); err.textContent = m || ''; err.classList.toggle('hide', !m); };
  wrap.input = input;
  wrap.spec = f;
  if (f.onChange) input.addEventListener('change', () => f.onChange(input.value, ctx));
  return wrap;
}
export function readField(wrap) {
  const f = wrap.spec; const el = wrap.input;
  if (f.type === 'checkbox') return el.checked;
  const raw = el.value.trim();
  if (f.type === 'money') { if (raw === '') return null; const v = parseMoney(raw); return v === null ? NaN : v; }
  if (f.type === 'qty') { if (raw === '') return null; const v = qtyMilli(raw); return v === null ? NaN : v; }
  if (f.type === 'number') return raw === '' ? null : Number(raw);
  if (f.type === 'select') return raw === '' ? null : (/^\d+$/.test(raw) && f.numeric !== false && f.asNumber ? Number(raw) : raw);
  return raw === '' ? null : raw;
}

/** Form + modal. onSubmit(values) Promise döner; hata alan hatasıysa ilgili alanı işaretler. */
export function formModal({ title, fields, submit = 'Kaydet', onSubmit, wide = false, note, extra, cancel = 'Vazgeç' }) {
  const key = newKey();
  const ctx = { fields: {} };
  const wraps = fields.map((f) => { if (f.divider) return h('div', { class: 'full' }, h('h3', null, f.divider)); const w = fieldEl(f, ctx); ctx.fields[f.name] = w; return w; });
  const grid = h('div', { class: 'form-grid' }, wraps);
  const msg = h('div', { class: 'alert err hide', role: 'alert' });
  let dirty = false;
  grid.addEventListener('input', () => { dirty = true; });
  const form = h('form', { novalidate: true, class: 'stack' }, note ? h('div', { class: 'alert info' }, note) : null, msg, grid, extra || null);
  const btn = h('button', { class: 'btn primary', type: 'button', onclick: () => run() }, submit); // düğme modal altbilgisinde (form dışı) olduğundan click ile tetiklenir
  const m = modal({ title, body: form, wide, dirty: () => dirty, foot: [h('button', { class: 'btn', type: 'button', onclick: () => m.close() }, cancel), btn] });
  const values = () => {
    const v = {}; let bad = false;
    for (const w of wraps) {
      if (!w.spec) continue;
      w.setError('');
      const x = readField(w);
      if (typeof x === 'number' && Number.isNaN(x)) { w.setError('Geçerli bir sayı girin.'); bad = true; }
      if (w.spec.required && (x === null || x === '' )) { w.setError('Bu alan zorunludur.'); bad = true; }
      v[w.spec.name] = x;
    }
    return bad ? null : v;
  };
  const run = async (e) => {
    e && e.preventDefault();
    msg.classList.add('hide');
    const v = values();
    if (!v) return;
    btn.disabled = true;
    dirty = false; // gönderim sürerken Escape/dış tıklama "kaydedilmedi" uyarısı çıkarmasın
    try {
      await onSubmit(v, key, ctx);
      m.close(true);
    } catch (err) {
      dirty = true;
      if (err.field && ctx.fields[err.field]) ctx.fields[err.field].setError(err.message);
      else { msg.textContent = err.message || 'İşlem başarısız.'; msg.classList.remove('hide'); }
    } finally { btn.disabled = false; }
  };
  form.addEventListener('submit', run);
  return { ...m, ctx, run };
}

// ---------- tablo ----------
export function table({ cols, rows, empty, foot, rowClass, id }) {
  if (!rows.length && empty) return emptyState(empty);
  let sortKey = null; let sortDir = 1;
  const wrap = h('div', { class: 'table-wrap' });
  const draw = () => {
    let data = rows.slice();
    if (sortKey !== null) {
      const c = cols.find((x) => x.key === sortKey);
      const val = (r) => (c.sort ? c.sort(r) : r[c.key]);
      data.sort((a, b) => { const x = val(a); const y = val(b); return (x > y ? 1 : x < y ? -1 : 0) * sortDir; });
    }
    clear(wrap);
    const thead = h('thead', null, h('tr', null, cols.map((c) => h('th', {
      class: (c.num ? 'num ' : '') + (c.sortable !== false && c.key ? 'sortable' : ''),
      onclick: c.sortable !== false && c.key ? () => { sortDir = sortKey === c.key ? -sortDir : 1; sortKey = c.key; draw(); } : null,
    }, c.label + (sortKey === c.key ? (sortDir > 0 ? ' ▲' : ' ▼') : '')))));
    const tbody = h('tbody', null, data.map((r) => h('tr', { class: rowClass ? rowClass(r) : '' }, cols.map((c) => h('td', { class: (c.num ? 'num ' : '') + (c.act ? 'act ' : '') + (c.cls || ''), 'data-label': c.act ? '' : c.label }, c.render ? c.render(r) : (r[c.key] ?? '—'))))));
    const tfoot = foot ? h('tfoot', null, foot()) : null;
    wrap.appendChild(h('table', { id }, thead, tbody, tfoot));
  };
  draw();
  return wrap;
}
export function emptyState({ icon = '📭', title, text, action }) {
  return h('div', { class: 'empty card flat' }, h('div', { class: 'big' }, icon), h('h3', null, title), text ? h('p', null, text) : null, action || null);
}
export function loading() { return h('div', { class: 'loading' }, h('span', { class: 'spinner', role: 'status', 'aria-label': 'Yükleniyor' })); }
export function seg(options, value, onChange) {
  const el = h('div', { class: 'seg', role: 'tablist' });
  const draw = (v) => { clear(el); options.forEach(([val, label]) => el.appendChild(h('button', { type: 'button', class: String(val) === String(v) ? 'on' : '', onclick: () => { draw(val); onChange(val); } }, label))); };
  draw(value);
  return el;
}
export function tabs(items, active, onSelect) {
  return h('div', { class: 'tabs', role: 'tablist' }, items.map(([k, label]) => h('button', { type: 'button', class: k === active ? 'on' : '', onclick: () => onSelect(k) }, label)));
}

// ---------- grafikler ----------
export function barChart({ labels, series, height = 220, fmt = (v) => groupInt(String(Math.round(v / 100))) }) {
  const W = 640; const H = height; const padL = 44; const padB = 28; const padT = 10;
  const max = Math.max(1, ...series.flatMap((x) => x.values.map((v) => Math.abs(v))));
  const svg = s('svg', { viewBox: `0 0 ${W} ${H}`, class: 'chart', role: 'img', 'aria-label': 'Grafik' });
  const plotH = H - padB - padT;
  const hasNeg = series.some((x) => x.values.some((v) => v < 0));
  const zeroY = hasNeg ? padT + plotH / 2 : padT + plotH;
  const scale = (hasNeg ? plotH / 2 : plotH) / max;
  for (let i = 0; i <= 4; i++) {
    const y = padT + (plotH / 4) * i;
    svg.appendChild(s('line', { x1: padL, x2: W - 6, y1: y, y2: y, class: 'grid-line' }));
    const val = hasNeg ? max - (max * 2 / 4) * i : max - (max / 4) * i;
    svg.appendChild(s('text', { x: padL - 6, y: y + 4, 'text-anchor': 'end' }, fmt(val)));
  }
  const n = labels.length; const gw = (W - padL - 8) / Math.max(n, 1); const bw = Math.min(26, (gw - 8) / series.length);
  labels.forEach((lb, i) => {
    const gx = padL + gw * i + gw / 2 - (bw * series.length) / 2;
    series.forEach((se, j) => {
      const v = se.values[i] || 0; const hh = Math.abs(v) * scale;
      const y = v >= 0 ? zeroY - hh : zeroY;
      const r = s('rect', { x: gx + bw * j, y, width: Math.max(bw - 2, 2), height: Math.max(hh, 0), rx: 3, class: se.cls });
      r.appendChild(s('title', null, `${se.name}: ${money(v)}`));
      svg.appendChild(r);
    });
    svg.appendChild(s('text', { x: padL + gw * i + gw / 2, y: H - 8, 'text-anchor': 'middle' }, lb));
  });
  return svg;
}
export function legend(items) { return h('div', { class: 'legend' }, items.map(([cls, name]) => h('span', null, h('i', { class: cls }), name))); }

export function printBtn() { return h('button', { class: 'btn no-print', type: 'button', onclick: () => window.print() }, '🖨 Yazdır / PDF'); }

export function money2(map, cur) { // {TRY:{...}} yardımcı
  return money(map, cur);
}
/** {TRY: n, EUR: m} → "1.000,00 ₺ · 500,00 €" */
export function multi(obj, key) {
  const parts = Object.entries(obj || {}).map(([c, v]) => money(key ? v[key] : v, c));
  return parts.length ? parts.join(' · ') : money(0);
}
export function debounce(fn, ms = 300) { let t; return (...a) => { clearTimeout(t); t = setTimeout(() => fn(...a), ms); }; }
