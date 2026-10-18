import { api } from './api.js';

export const state = {
  user: null, meta: null, readonly: false, tenantId: null,
  scope: { kind: 'all', id: null },
};

const LS = {
  get(k) { try { return localStorage.getItem(k); } catch { return null; } },
  set(k, v) { try { localStorage.setItem(k, v); } catch { /* özel pencere vb. */ } },
};
export { LS };

export function loadScope() {
  try {
    const v = JSON.parse(LS.get('fb_scope') || 'null');
    if (v && ['all', 'business', 'personal', 'ws'].includes(v.kind)) state.scope = v;
  } catch { /* */ }
}
export function setScope(kind, id = null) {
  state.scope = { kind, id };
  LS.set('fb_scope', JSON.stringify(state.scope));
}
/** API filtre parametreleri */
export function scopeParams() {
  const s = state.scope;
  if (s.kind === 'ws' && s.id) return { workspace_id: s.id };
  return { scope: s.kind === 'ws' ? 'all' : s.kind };
}
export async function loadMeta() {
  state.meta = await api.get('/meta');
  // seçili alan artık yoksa sıfırla
  if (state.scope.kind === 'ws' && !state.meta.workspaces.some((w) => w.id === state.scope.id && !w.archived)) setScope('all');
  return state.meta;
}

// ---- seçenek üreticileri (select alanları için) ----
export const wsOptions = (blank) => [...(blank ? [['', blank]] : []), ...state.meta.workspaces.filter((w) => !w.archived).map((w) => [w.id, w.name + (w.kind === 'personal' ? ' (şahsi)' : '')])];
export const accOptions = (blank, cur) => [...(blank ? [['', blank]] : []), ...state.meta.accounts.filter((a) => !a.archived && (!cur || a.currency === cur)).map((a) => [a.id, `${a.workspace_name} › ${a.name} (${a.currency})`])];
export const catOptions = (kind, blank = 'Kategorisiz') => [['', blank], ...state.meta.categories.filter((c) => c.kind === kind && !c.archived).map((c) => [c.id, c.name])];
export const contactOptions = (kind, blank = 'Seçiniz') => [['', blank], ...state.meta.contacts.filter((c) => !c.archived && (!kind || c.kind === 'both' || c.kind === kind)).map((c) => [c.id, c.name])];
export const projectOptions = (blank = 'Proje yok') => [['', blank], ...state.meta.projects.filter((p) => !p.archived).map((p) => [p.id, p.name])];
export const vatOptions = () => {
  const rates = (state.meta.settings && state.meta.settings.vat_rates) || [0, 1000, 2000];
  return rates.map((r) => [r, r === 0 ? 'KDV yok' : `%${r / 100}`]);
};
export const hasBusinessWs = () => state.meta.workspaces.some((w) => w.kind === 'business' && !w.archived);
export const wsName = (id) => (state.meta.workspaces.find((w) => w.id === Number(id)) || {}).name || '';
export const accName = (id) => (state.meta.accounts.find((a) => a.id === Number(id)) || {}).name || '';
