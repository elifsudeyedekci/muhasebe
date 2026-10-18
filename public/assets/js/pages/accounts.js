import { api } from '../api.js';
import { state } from '../state.js';
import { h, mount, loading, money, date, table, emptyState, modal, errToast, toast, confirmDialog, KIND_TR, signClass, multi, menu, seg } from '../ui.js';
import { accountForm, workspaceForm, adjustForm } from '../forms_core.js';
import { loadMeta } from '../state.js';

async function ledger(a) {
  const body = h('div', null, h('div', { class: 'loading' }, h('span', { class: 'spinner' })));
  modal({ title: `${a.name} — hesap defteri`, wide: true, body });
  try {
    const r = await api.get(`/accounts/${a.id}/ledger`);
    mount(body, h('div', { class: 'stack' },
      h('div', { class: 'row between' }, h('div', { class: 'muted small' }, `Açılış: ${money(r.account.opening_minor, a.currency)} (${date(r.account.opening_date)})`), h('div', null, 'Güncel bakiye: ', h('b', null, money(r.balance_minor, a.currency)))),
      table({
        cols: [{ key: 'date', label: 'Tarih', render: (x) => date(x.date) }, { key: 'kind', label: 'Tür', render: (x) => KIND_TR[x.kind] || x.kind },
          { key: 'description', label: 'Açıklama', render: (x) => x.description || '—' },
          { key: 'signed_minor', label: 'Tutar', num: true, render: (x) => h('span', { class: signClass(x.signed_minor) }, money(x.signed_minor, a.currency, { sign: true })) },
          { key: 'balance_minor', label: 'Bakiye', num: true, render: (x) => money(x.balance_minor, a.currency) }],
        rows: r.rows, rowClass: (x) => (x.status === 'voided' ? 'voided' : ''),
        empty: { title: 'Hareket yok', text: 'Bu hesapta henüz işlem bulunmuyor.' },
      })));
  } catch (e) { mount(body, h('div', { class: 'alert err' }, e.message)); }
}

export default async function render(ctx) {
  const { el, readonly } = ctx;
  const accounts = state.meta.accounts;
  const wsList = state.meta.workspaces;
  const showArchived = { v: false };
  const draw = () => {
    const visible = accounts.filter((a) => showArchived.v || !a.archived);
    const byWs = wsList.filter((w) => showArchived.v || !w.archived).map((w) => ({ w, accs: visible.filter((a) => a.workspace_id === w.id) }));
    const totals = {};
    visible.filter((a) => !a.archived).forEach((a) => { totals[a.currency] = (totals[a.currency] || 0) + a.balance_minor; });
    mount(el,
      ctx.head('Hesaplar & Kasalar', 'Banka hesapları, nakit kasalar ve işletme alanlarınız. Bakiyeler hareketlerden hesaplanır.', readonly ? null : [
        h('button', { class: 'btn', onclick: () => workspaceForm(null, ctx.refresh) }, '＋ Alan'), h('button', { class: 'btn primary', onclick: () => accountForm(null, ctx.refresh) }, '＋ Hesap / kasa')]),
      h('div', { class: 'row between' }, h('div', { class: 'muted' }, 'Toplam: ', Object.keys(totals).length ? Object.entries(totals).map(([c, v]) => h('b', { class: 'nowrap' }, money(v, c) + '  ')) : '—'),
        h('label', { class: 'check small' }, h('input', { type: 'checkbox', checked: showArchived.v, onchange: (e) => { showArchived.v = e.target.checked; draw(); } }), 'Arşivlenenleri göster')),
      byWs.length ? byWs.map(({ w, accs }) => h('div', { class: 'card stack' },
        h('div', { class: 'card-title' }, h('span', null, w.name, ' ', h('span', { class: 'badge ' + (w.kind === 'personal' ? 'info' : 'pri') }, w.kind === 'personal' ? 'Şahsi' : 'İşletme'), w.archived ? h('span', { class: 'badge' }, 'Arşiv') : null),
          readonly ? null : h('div', { class: 'row tight' },
            h('button', { class: 'btn sm', onclick: () => workspaceForm(w, ctx.refresh) }, 'Düzenle'),
            h('button', { class: 'btn sm', onclick: async () => { try { await api.patch('/workspaces/' + w.id, { archived: !w.archived }); await loadMeta(); ctx.refresh(); } catch (e) { errToast(e); } } }, w.archived ? 'Geri al' : 'Arşivle'))),
        accs.length ? table({
          cols: [
            { key: 'name', label: 'Hesap', render: (a) => h('span', null, a.kind === 'cash' ? '💵 ' : '🏦 ', h('b', null, a.name), a.archived ? h('span', { class: 'badge' }, 'Arşiv') : null, h('div', { class: 'xs muted' }, [a.bank_name, a.iban].filter(Boolean).join(' · '))) },
            { key: 'currency', label: 'Birim' },
            { key: 'balance_minor', label: 'Bakiye', num: true, render: (a) => h('b', { class: signClass(a.balance_minor) }, money(a.balance_minor, a.currency)) },
            { label: '', act: true, sortable: false, render: (a) => h('div', { class: 'row tight' },
              h('button', { class: 'btn sm', onclick: () => ledger(a) }, 'Defter'),
              readonly ? null : h('button', { class: 'btn sm ghost', 'aria-label': 'Diğer işlemler', onclick: (e) => menu(e.currentTarget, [
                { label: '✏️ Düzenle', run: () => accountForm(a, ctx.refresh) }, { label: '⚖️ Bakiye düzeltme', run: () => adjustForm(a, ctx.refresh) },
                { label: '🏦 Ekstre içe aktar / mutabakat', run: () => ctx.go('/reconcile?account=' + a.id) },
                { label: a.archived ? '↩️ Arşivden çıkar' : '🗄 Arşivle', run: async () => { try { await api.patch('/accounts/' + a.id, { archived: !a.archived }); await loadMeta(); ctx.refresh(); toast('Güncellendi', 'ok'); } catch (e) { errToast(e); } } }]) }, '⋯')) },
          ], rows: accs,
        }) : h('div', { class: 'muted small' }, 'Bu alanda henüz hesap yok.'))) : emptyState({ icon: '🏦', title: 'Henüz alan veya hesap yok', text: 'Önce bir işletme/şahsi alan, sonra banka hesabı veya kasa ekleyin.', action: readonly ? null : h('button', { class: 'btn primary', onclick: () => workspaceForm(null, ctx.refresh) }, 'Alan oluştur') }));
  };
  draw();
}
