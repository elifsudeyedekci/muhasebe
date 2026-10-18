import { api } from './api.js';
import { state, loadScope, setScope, loadMeta, LS } from './state.js';
import { h, mount, clear, loading, toast, errToast, menu, confirmDialog, closeAllModals } from './ui.js';
import { renderLogin, renderForgot, renderReset, renderForcePassword, renderEnroll2fa } from './auth.js';

const root = document.getElementById('app');

const PAGES = {
  dashboard: ['Ana Ekran', '🏠'], notifications: ['Bildirimler', '🔔'], accounts: ['Hesaplar & Kasalar', '🏦'], transactions: ['Gelir & Gider', '💸'],
  transfers: ['Transfer & Döviz', '🔁'], reconcile: ['Banka Mutabakatı', '✅'], contacts: ['Cariler', '👥'], plan: ['Alacak, Borç & Takvim', '📅'],
  invoices: ['Faturalar', '🧾'], bills: ['Şahsi Faturalar', '🏡'], cards: ['Kartlar & Krediler', '💳'], projects: ['Projeler', '🏗️'],
  stock: ['Stok & Depo', '📦'], reports: ['Raporlar', '📊'], settings: ['Ayarlar', '⚙️'],
};
const NAV = [
  ['Genel', ['dashboard', 'notifications']], ['Para', ['accounts', 'transactions', 'transfers', 'reconcile']],
  ['Cari & Fatura', ['contacts', 'plan', 'invoices', 'bills']], ['Finansman', ['cards']], ['İş', ['projects', 'stock']], ['Analiz', ['reports']],
];
const ADMIN_NAV = [['admin', 'Yönetim Özeti', '🛡️'], ['admin/customers', 'Müşteriler', '👥'], ['admin/audit', 'Denetim Kayıtları', '📜'], ['admin/system', 'Sistem & Güvenlik', '🧰']];

let shell = null; let current = 0;

function parseHash() {
  const raw = location.hash.replace(/^#/, '') || '';
  const [pathPart, qs = ''] = raw.split('?');
  const path = '/' + pathPart.replace(/^\/+/, '').replace(/\/+$/, '');
  return { path, query: Object.fromEntries(new URLSearchParams(qs)) };
}
export const go = (p) => { location.hash = '#' + p; };

// ---------- önyükleme ----------
async function boot(notice) {
  document.querySelectorAll('.overlay,.menu').forEach((n) => n.remove());
  mount(root, loading());
  const { path, query } = parseHash();
  if (path === '/reset' && query.token) return renderReset(root, query.token, go);
  if (path === '/forgot') return renderForgot(root, go);
  let sess;
  try { sess = await api.get('/auth/session'); } catch (e) { return renderLogin(root, { notice: e.message, onSuccess: afterLogin, go }); }
  if (!sess.authenticated) {
    state.user = null;
    return renderLogin(root, { notice, onSuccess: afterLogin, go });
  }
  api.setCsrf(sess.csrf);
  await enter(sess.user);
}

async function afterLogin(user) { await enter(user); }

async function enter(user) {
  state.user = user;
  document.querySelectorAll('.overlay,.menu').forEach((n) => n.remove());
  if (user.must_change_password) return renderForcePassword(root, user, async (u) => enter(u));
  if (user.must_enroll_2fa) return renderEnroll2fa(root, user, async () => { const s = await api.get('/auth/session'); api.setCsrf(s.csrf); await enter(s.user); });
  loadScope();
  if (user.role === 'customer') {
    try { await loadMeta(); } catch (e) { return renderLogin(root, { notice: e.message, onSuccess: afterLogin, go }); }
    applyTheme(state.meta.settings && state.meta.settings.theme);
  }
  buildShell();
  if (!location.hash || location.hash === '#/' || ['#/login', '#/forgot'].includes(location.hash)) go(user.role === 'admin' ? '/admin' : '/dashboard');
  else route();
}

export function applyTheme(t) {
  const v = t || LS.get('fb_theme') || 'auto';
  if (v === 'auto') document.documentElement.removeAttribute('data-theme'); else document.documentElement.setAttribute('data-theme', v);
  LS.set('fb_theme', v);
}

api.on(async (err) => {
  if (!state.user) return;
  if (err.status === 401) { state.user = null; api.setCsrf(null); api.setTenant(null); state.readonly = false; renderLogin(root, { notice: 'Oturumunuz sona erdi veya başka yerden kapatıldı. Lütfen tekrar giriş yapın.', onSuccess: afterLogin, go }); }
  else if (err.code === 'password_change_required' || err.code === 'mfa_enrollment_required') boot();
  else if (err.code === 'forbidden' && state.user.role === 'customer' && /askı|arşiv/i.test(err.message)) boot('Hesabınız askıya alınmış.');
});

// ---------- kabuk ----------
function buildShell() {
  const u = state.user;
  const isAdmin = u.role === 'admin';
  const nav = h('nav', { class: 'nav', 'aria-label': 'Ana menü' });
  const content = h('main', { class: 'content', id: 'content', tabindex: '-1' });
  const banner = h('div', { class: 'banner admin hide', id: 'banner' });
  const announce = h('div', { class: 'banner hide', id: 'announce', role: 'status' });
  const scopeSel = h('select', { class: 'input', 'aria-label': 'Görünüm', id: 'scopeSel', onchange: (e) => { const v = e.target.value; if (v.startsWith('ws:')) setScope('ws', Number(v.slice(3))); else setScope(v); route(); } });
  const bell = h('a', { href: '#/notifications', class: 'btn ghost sm', 'aria-label': 'Bildirimler', id: 'bell' }, '🔔');
  const userBtn = h('button', { class: 'btn ghost sm', type: 'button', onclick: (e) => menu(e.currentTarget, [
    !isAdmin ? { label: '⚙️ Ayarlar', run: () => go('/settings') } : { label: '🔐 Güvenlik', run: () => go('/admin/system') },
    { label: '🌓 Tema: ' + ({ auto: 'otomatik', light: 'açık', dark: 'koyu' })[LS.get('fb_theme') || 'auto'], run: () => { const c = LS.get('fb_theme') || 'auto'; const n = c === 'auto' ? 'light' : c === 'light' ? 'dark' : 'auto'; applyTheme(n); toast('Tema: ' + ({ auto: 'otomatik', light: 'açık', dark: 'koyu' })[n]); } },
    { label: '🚪 Çıkış yap', run: logout },
  ]) }, (u.display_name || u.email).split('@')[0] + ' ▾');
  const side = h('aside', { class: 'sidebar' }, h('div', { class: 'brand' }, h('div', { class: 'logo' }, 'F'), h('div', null, 'FunBreak', h('small', null, 'Ön Muhasebe'))), nav);
  const tabbar = h('nav', { class: 'tabbar', id: 'tabbar', 'aria-label': 'Hızlı menü' });
  const top = h('header', { class: 'topbar' }, h('div', { class: 'grow' }), isAdmin ? null : h('div', { id: 'scopeWrap' }, scopeSel), bell, userBtn);
  shell = { nav, content, banner, scopeSel, bell, tabbar, top };
  const fab = h('button', { class: 'fab no-print hide', id: 'fab', type: 'button', 'aria-label': 'Hızlı ekle', onclick: quickAdd }, '+');
  mount(root, h('div', { class: 'shell' }, side, h('div', { class: 'main' }, top, announce, banner, content)), tabbar, fab);
  window.removeEventListener('hashchange', route);
  window.addEventListener('hashchange', route);
  api.get('/system/status').then((r) => { if (r.announcement) { announce.textContent = '📢 ' + r.announcement; announce.classList.remove('hide'); } }).catch(() => {});
}

async function logout() {
  try { await api.post('/auth/logout'); } catch { /* oturum zaten kapalı olabilir */ }
  state.user = null; state.meta = null; api.setCsrf(null); api.setTenant(null);
  location.hash = '#/login';
  location.reload();
}

function renderNav(active, tenantPrefix) {
  const { nav, tabbar } = shell;
  clear(nav);
  const a = (href, label, ic, on, extra) => h('a', { href, class: (on ? 'active ' : '') + (extra || ''), 'aria-current': on ? 'page' : null }, h('span', { class: 'ic' }, ic), label);
  if (state.user.role === 'admin' && !tenantPrefix) {
    nav.appendChild(h('div', { class: 'nav-group' }, 'Yönetim'));
    ADMIN_NAV.forEach(([p, l, ic]) => nav.appendChild(a('#/' + p, l, ic, active === p)));
  } else {
    if (tenantPrefix) {
      nav.appendChild(a('#/admin/customers/' + state.tenantId, '← Müşteri detayı', '🛡️', false, 'admin-link'));
    }
    NAV.forEach(([g, items]) => {
      nav.appendChild(h('div', { class: 'nav-group' }, g));
      items.forEach((k) => nav.appendChild(a((tenantPrefix || '#/') + (tenantPrefix ? '/' : '') + k, PAGES[k][0], PAGES[k][1], active === k)));
    });
    nav.appendChild(h('div', { class: 'nav-group' }, 'Hesap'));
    if (!tenantPrefix) nav.appendChild(a('#/settings', PAGES.settings[0], PAGES.settings[1], active === 'settings'));
  }
  clear(tabbar);
  const base = tenantPrefix ? tenantPrefix + '/' : '#/';
  const tabs = state.user.role === 'admin' && !tenantPrefix
    ? [['#/admin', '🛡️', 'Özet', 'admin'], ['#/admin/customers', '👥', 'Müşteriler', 'admin/customers'], ['#/admin/audit', '📜', 'Denetim', 'admin/audit'], ['#/admin/system', '🧰', 'Sistem', 'admin/system']]
    : [[base + 'dashboard', '🏠', 'Ana Ekran', 'dashboard'], [base + 'transactions', '💸', 'İşlemler', 'transactions'], [base + 'plan', '📅', 'Plan', 'plan'], [base + 'invoices', '🧾', 'Fatura', 'invoices']];
  tabs.forEach(([href, ic, lb, k]) => tabbar.appendChild(h('a', { href, class: active === k ? 'on' : '' }, h('span', { class: 'ic' }, ic), lb)));
  tabbar.appendChild(h('button', { type: 'button', onclick: (e) => menu(e.currentTarget, allMenu(tenantPrefix)) }, h('span', { class: 'ic' }, '☰'), 'Menü'));
}
function allMenu(tenantPrefix) {
  if (state.user.role === 'admin' && !tenantPrefix) return ADMIN_NAV.map(([p, l, ic]) => ({ label: ic + ' ' + l, run: () => go('/' + p) }));
  const items = Object.entries(PAGES).filter(([k]) => !(tenantPrefix && k === 'settings')).map(([k, [l, ic]]) => ({ label: ic + ' ' + l, run: () => go(tenantPrefix ? tenantPrefix.slice(1) + '/' + k : '/' + k) }));
  return items;
}

function scopeSelector() {
  const sel = shell.scopeSel; clear(sel);
  const { scope } = state; const ws = state.meta.workspaces.filter((w) => !w.archived);
  const add = (v, l) => sel.appendChild(h('option', { value: v, selected: (v === scope.kind) || (v === 'ws:' + scope.id) }, l));
  add('all', 'Tüm alanlar');
  if (ws.some((w) => w.kind === 'business')) add('business', 'Yalnızca şirketler');
  if (ws.some((w) => w.kind === 'personal')) add('personal', 'Yalnızca şahsi');
  ws.forEach((w) => add('ws:' + w.id, '› ' + w.name));
}

const quickAdd = async (e) => {
  const anchor = e.currentTarget; // await sonrası currentTarget null olur
  const f = await import('./forms_core.js');
  menu(anchor, [
    { label: '➕ Gelir ekle', run: () => f.txnForm({ kind: 'income' }, refresh) }, { label: '➖ Gider ekle', run: () => f.txnForm({ kind: 'expense' }, refresh) },
    { label: '📥 Tahsilat gir', run: () => f.quickSettle('receivable', refresh) }, { label: '📤 Ödeme yap', run: () => f.quickSettle('payable', refresh) },
    { label: '🔁 Transfer', run: () => f.transferForm(refresh) }, { label: '🧾 Fatura', run: () => go('/invoices?new=1') },
  ]);
};
export const refresh = () => route();

// ---------- yönlendirici ----------
async function route() {
  if (!state.user || !shell) return;
  closeAllModals(); // sayfa değişince açık pencereler kapanır
  const my = ++current;
  const { path, query } = parseHash();
  const seg = path.split('/').filter(Boolean);
  let page = seg[0] || 'dashboard'; let params = {}; let readonly = false; let tenantPrefix = null;
  let loader;
  if (seg[0] === 'admin') {
    if (state.user.role !== 'admin') { go('/dashboard'); return; }
    if (seg[1] === 't' && seg[2]) { // yönetici → müşteri finans görünümü (salt okunur)
      readonly = true; state.tenantId = Number(seg[2]); tenantPrefix = '#/admin/t/' + state.tenantId; page = seg[3] || 'dashboard';
      if (!PAGES[page] || page === 'settings') page = 'dashboard';
      loader = () => import('./pages/' + page + '.js');
    } else if (!seg[1]) { page = 'admin'; loader = () => import('./pages/admin_overview.js'); }
    else if (seg[1] === 'customers') { page = seg[2] ? 'admin/customers/x' : 'admin/customers'; params.id = seg[2]; loader = () => import(seg[2] ? './pages/admin_customer.js' : './pages/admin_customers.js'); }
    else if (seg[1] === 'audit') { page = 'admin/audit'; loader = () => import('./pages/admin_audit.js'); }
    else if (seg[1] === 'system') { page = 'admin/system'; loader = () => import('./pages/admin_system.js'); }
    else { go('/admin'); return; }
  } else {
    if (state.user.role === 'admin') { go('/admin'); return; }
    if (!PAGES[page]) { go('/dashboard'); return; }
    loader = () => import('./pages/' + page + '.js');
  }
  // tenant bağlamı
  if (readonly) {
    if (api.getTenant() !== state.tenantId || !state.readonly) {
      api.setTenant(state.tenantId); state.readonly = true;
      try { await loadMeta(); } catch (e) { mount(shell.content, h('div', { class: 'alert err' }, e.message)); return; }
    }
  } else if (state.readonly || api.getTenant()) {
    api.setTenant(null); state.readonly = false; state.tenantId = null;
    if (state.user.role === 'customer') await loadMeta();
  }
  if (my !== current) return;
  renderNav(page.startsWith('admin') && !readonly ? page.replace('/customers/x', '/customers') : page, tenantPrefix);
  const showScope = state.user.role === 'customer' || readonly;
  document.getElementById('scopeWrap')?.classList.toggle('hide', !showScope || ['settings', 'notifications'].includes(page));
  if (showScope && state.meta) scopeSelector();
  const banner = shell.banner;
  if (readonly) {
    banner.classList.remove('hide'); clear(banner);
    banner.append('🛡️ Yönetici görünümü — ', h('b', null, (state.meta.tenant && state.meta.tenant.name) || 'Müşteri'), ' · salt okunur; her erişim denetim kaydına alınır. ', h('a', { href: '#/admin/customers/' + state.tenantId }, 'Müşteri detayına dön'));
  } else banner.classList.add('hide');
  document.getElementById('fab').classList.toggle('hide', readonly || state.user.role !== 'customer' || !state.meta.workspaces.length);
  document.title = (PAGES[page] ? PAGES[page][0] + ' · ' : '') + 'FunBreak Ön Muhasebe';
  mount(shell.content, loading());
  try {
    const mod = await loader();
    if (my !== current) return;
    const el = h('div', { class: 'page' });
    const ctx = {
      el, params, query, state, api, readonly, go, refresh, page,
      href: (p) => (tenantPrefix ? tenantPrefix + '/' + p : '#/' + p),
      head(title, sub, actions) { return h('div', { class: 'page-head' }, h('div', null, h('h1', null, title), sub ? h('p', null, sub) : null), actions ? h('div', { class: 'row tight no-print' }, actions) : null); },
    };
    mount(shell.content, el);
    await mod.default(ctx);
    window.scrollTo(0, 0);
    updateBell();
  } catch (e) {
    if (my !== current) return;
    mount(shell.content, h('div', { class: 'alert err' }, e.message || 'Sayfa yüklenemedi.'), h('p', null, h('button', { class: 'btn', onclick: route }, 'Yeniden dene')));
  }
}

async function updateBell() {
  if (state.readonly || !shell || state.user.role !== 'customer') return;
  try {
    const r = await api.get('/reports/notifications');
    const n = r.notifications.filter((x) => x.level !== 'info').length;
    shell.bell.textContent = n ? '🔔 ' + n : '🔔';
  } catch { /* sessiz */ }
}

window.addEventListener('hashchange', () => { if (!state.user) boot(); });
window.addEventListener('beforeunload', () => {});
boot();
if ('serviceWorker' in navigator && (location.protocol === 'https:' || location.hostname === 'localhost' || location.hostname === '127.0.0.1')) {
  navigator.serviceWorker.register('/sw.js', { scope: '/' }).catch(() => {});
}
