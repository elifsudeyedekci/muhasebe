import { api } from '../api.js';
import { h, mount, loading, table, dateTime, toast, errToast, debounce } from '../ui.js';

export default async function render(ctx) {
  const { el } = ctx;
  const f = { tenant: '', category: '', q: '', page: 1 };
  const out = h('div');
  const load = async () => {
    mount(out, loading());
    try {
      const r = await api.get('/admin/audit', f);
      mount(out, table({
        cols: [{ key: 'id', label: '#', num: true }, { key: 'ts', label: 'Zaman', render: (a) => dateTime(a.ts) },
          { key: 'category', label: 'Tür', render: (a) => h('span', { class: 'badge ' + ({ security: 'warn', admin: 'info', access: 'pri', data: '' })[a.category] }, { security: 'Güvenlik', admin: 'Yönetim', data: 'Veri', access: 'Erişim', system: 'Sistem' }[a.category]) },
          { key: 'action', label: 'İşlem' }, { key: 'tenant_id', label: 'Müşteri', render: (a) => (a.tenant_id ? h('a', { href: '#/admin/customers/' + a.tenant_id }, '#' + a.tenant_id) : '—') },
          { label: 'Kayıt', sortable: false, render: (a) => [a.entity, a.entity_id].filter(Boolean).join(' #') || '—' }, { key: 'actor_role', label: 'Kim', render: (a) => ({ admin: 'Yönetici', customer: 'Müşteri' })[a.actor_role] || '—' }, { key: 'ip', label: 'IP' },
          { label: 'Ayrıntı', sortable: false, render: (a) => (a.details ? h('code', { class: 'xs' }, a.details.length > 120 ? a.details.slice(0, 120) + '…' : a.details) : '') }],
        rows: r.rows, empty: { icon: '📜', title: 'Kayıt yok' },
      }), h('div', { class: 'row' }, f.page > 1 ? h('button', { class: 'btn', onclick: () => { f.page--; load(); } }, '‹ Önceki') : null, r.rows.length === 100 ? h('button', { class: 'btn', onclick: () => { f.page++; load(); } }, 'Sonraki ›') : null));
    } catch (e) { mount(out, h('div', { class: 'alert err' }, e.message)); }
  };
  mount(el, ctx.head('Denetim Kayıtları', 'Eklemeli (silinemez) kayıt; her satır bir öncekine hash ile zincirlenir.', [h('button', { class: 'btn', onclick: async () => { try { const r = await api.get('/admin/audit/verify'); r.broken_at ? toast('UYARI: zincir ' + r.broken_at + ' numaralı kayıtta bozuk!', 'err') : toast('Denetim zinciri sağlam ✓', 'ok'); } catch (e) { errToast(e); } } }, '🔗 Zinciri doğrula')]),
    h('div', { class: 'filters' }, h('div', { class: 'field' }, h('label', null, 'Müşteri no'), h('input', { class: 'input', type: 'number', min: 1, onchange: (e) => { f.tenant = e.target.value; f.page = 1; load(); } })),
      h('div', { class: 'field' }, h('label', null, 'Tür'), h('select', { class: 'input', onchange: (e) => { f.category = e.target.value; f.page = 1; load(); } }, [['', 'Hepsi'], ['security', 'Güvenlik'], ['admin', 'Yönetim'], ['access', 'Erişim'], ['data', 'Veri']].map(([v, l]) => h('option', { value: v }, l)))),
      h('div', { class: 'field' }, h('label', null, 'İşlem ara'), h('input', { class: 'input', type: 'search', oninput: debounce((e) => { f.q = e.target.value; f.page = 1; load(); }) }))), out);
  load();
}
