import { api } from '../api.js';
import { state, scopeParams } from '../state.js';
import { h, mount, loading, money, multi, date, table, stateBadge, errToast, toast, confirmDialog, menu, seg } from '../ui.js';
import { entryForm, settleForm, rescheduleForm, docsModal } from '../forms_core.js';

export default async function render(ctx) {
  const { el, readonly } = ctx;
  const out = h('div');
  let status = 'unpaid';
  const personal = state.meta.workspaces.filter((w) => w.kind === 'personal' && !w.archived);
  const load = async () => {
    mount(out, loading());
    try {
      const r = await api.get('/entries', { source: 'personal_bill', status, scope: 'personal' });
      mount(out, seg([['unpaid', 'Ödenecekler'], ['paid', 'Ödenenler'], ['all', 'Hepsi']], status, (v) => { status = v; load(); }),
        table({
          cols: [
            { key: 'title', label: 'Fatura', render: (e) => h('div', null, h('b', null, e.title), h('div', { class: 'xs muted' }, [e.provider, e.period].filter(Boolean).join(' · '))) },
            { key: 'due_date', label: 'Son ödeme', render: (e) => h('div', null, date(e.due_date), e.days_overdue ? h('div', { class: 'xs neg' }, e.days_overdue + ' gün gecikmiş') : null) },
            { key: 'amount_minor', label: 'Tutar', num: true, render: (e) => money(e.amount_minor, e.currency) }, { key: 'remaining_minor', label: 'Kalan', num: true, render: (e) => h('b', null, money(e.remaining_minor, e.currency)) },
            { key: 'state', label: 'Durum', render: (e) => stateBadge(e.state) },
            { label: '', act: true, sortable: false, render: (e) => h('div', { class: 'row tight' }, !readonly && e.remaining_minor > 0 && e.state !== 'cancelled' ? h('button', { class: 'btn sm primary', onclick: () => settleForm(e, load) }, 'Öde') : null,
              h('button', { class: 'btn sm ghost', onclick: (ev) => menu(ev.currentTarget, [{ label: '📎 Belgeler', run: () => docsModal('Belge', 'entry', e.id, readonly) }, !readonly && e.state !== 'paid' ? { label: '📅 Ertele', run: () => rescheduleForm(e, load) } : null].filter(Boolean)) }, '⋯')) },
          ], rows: r.entries,
          empty: { icon: '🏡', title: 'Şahsi fatura yok', text: 'Elektrik, su, doğalgaz, internet, kira, aidat, sigorta ve abonelikler burada izlenir. Şirket giderlerinize eklenmez.', action: readonly ? null : h('button', { class: 'btn primary', onclick: () => add() }, '＋ Şahsi fatura ekle') },
          foot: r.entries.length ? () => h('tr', null, h('td', { colspan: 3 }, 'Kalan toplam'), h('td', { class: 'num' }, multi(Object.fromEntries(Object.entries(r.sums).map(([c, v]) => [c, v.payable || 0])))), h('td', { colspan: 2 })) : null,
        }));
    } catch (e) { mount(out, h('div', { class: 'alert err' }, e.message)); }
  };
  const add = () => entryForm({ kind: 'payable', source: 'personal_bill' }, load);
  mount(el, ctx.head('Şahsi Faturalar', 'Şirket giderlerinden ayrı takip edilir; genel ödeme takvimine dahildir.', readonly ? null : [h('button', { class: 'btn primary', onclick: add }, '＋ Şahsi fatura')]),
    !personal.length ? h('div', { class: 'alert warn' }, 'Şahsi fatura için “Şahsi” türünde bir alan gerekir. ', readonly ? null : h('a', { href: ctx.href('accounts') }, 'Hesaplar sayfasından alan ekleyin →')) : null, out);
  load();
}
