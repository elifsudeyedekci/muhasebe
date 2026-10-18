import { api } from '../api.js';
import { state, scopeParams, accName } from '../state.js';
import { h, mount, loading, money, date, table, errToast, confirmDialog, toast, todayStr, addDays } from '../ui.js';
import { transferForm, fxForm } from '../forms_core.js';
import { loadMeta } from '../state.js';

export default async function render(ctx) {
  const { el, readonly } = ctx;
  const out = h('div', null, loading());
  mount(el, ctx.head('Transfer & Döviz', 'Hesaplar arası transferler ve döviz alım-satımları. Bunlar gelir/gider toplamlarını etkilemez.', readonly ? null : [h('button', { class: 'btn primary', onclick: () => transferForm(load) }, '🔁 Transfer'), h('button', { class: 'btn', onclick: () => fxForm(load) }, '💱 Döviz işlemi')]), out);
  async function load() {
    mount(out, loading());
    try {
      const from = addDays(todayStr(), -180);
      const [t, f] = await Promise.all([api.get('/transactions', { kind: 'transfer', from, limit: 300, ...scopeParams() }), api.get('/transactions', { kind: 'fx', from, limit: 300, ...scopeParams() })]);
      const groups = {};
      [...t.transactions, ...f.transactions].forEach((x) => { (groups[x.transfer_group] ||= []).push(x); });
      const rows = Object.entries(groups).map(([g, legs]) => {
        const o = legs.find((l) => l.direction === 'out'); const i = legs.find((l) => l.direction === 'in');
        return { g, date: (o || i).txn_date, kind: (o || i).kind, o, i, desc: (o || i).description, id: (o || i).id };
      }).sort((a, b) => (a.date < b.date ? 1 : -1));
      mount(out, table({
        cols: [{ key: 'date', label: 'Tarih', render: (r) => date(r.date) }, { key: 'kind', label: 'Tür', render: (r) => h('span', { class: 'badge info' }, r.kind === 'fx' ? 'Döviz' : 'Transfer') },
          { label: 'Kaynak → Hedef', sortable: false, render: (r) => `${r.o ? r.o.account_name : '?'} → ${r.i ? r.i.account_name : '?'}` },
          { label: 'Tutar', num: true, sortable: false, render: (r) => r.kind === 'fx' && r.o && r.i ? `${money(r.o.amount_minor, r.o.currency)} → ${money(r.i.amount_minor, r.i.currency)}` : money((r.o || r.i).amount_minor, (r.o || r.i).currency) },
          { key: 'desc', label: 'Açıklama' },
          { label: '', act: true, sortable: false, render: (r) => readonly ? null : h('button', { class: 'btn sm ghost', onclick: async () => {
            const reason = await confirmDialog({ title: 'Transferi iptal et', text: 'Her iki bacak birlikte iptal edilir (silinmez). Gerekçe:', input: 'Gerekçe', ok: 'İptal et', danger: true });
            if (!reason) return;
            try { await api.post(`/transactions/${r.id}/void`, { reason }); await loadMeta(); toast('İptal edildi', 'ok'); load(); } catch (e) { errToast(e); }
          } }, 'İptal') }],
        rows, empty: { icon: '🔁', title: 'Transfer yok', text: 'Banka → kasa, banka → banka veya döviz işlemlerinizi buradan kaydedin.' },
      }));
    } catch (e) { mount(out, h('div', { class: 'alert err' }, e.message)); }
  }
  load();
}
