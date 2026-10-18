import { api } from '../api.js';
import { state } from '../state.js';
import { h, mount, loading, money, date, table, errToast, modal, signClass, todayStr, addDays, printBtn, debounce, plain } from '../ui.js';
import { contactForm } from '../forms_core.js';
import { wsName, loadMeta } from '../state.js';

async function statement(c, readonly) {
  const f = { from: '', to: '' };
  const body = h('div', { class: 'stack' });
  const m = modal({ title: 'Cari ekstre — ' + c.name, wide: true, body });
  const csv = (r) => {
    const lines = [['Tarih', 'İşlem', 'Açıklama', 'Vade', 'Tutar', 'Bakiye', 'Birim', 'Alan'].join(';')];
    r.groups.forEach((g) => g.rows.forEach((x) => lines.push([x.date, x.type, `"${(x.text || '').replace(/"/g, '""')}"`, x.due_date || '', plain(x.signed_minor), plain(x.balance_minor), g.currency, wsName(g.workspace_id)].join(';'))));
    const a = document.createElement('a'); a.href = URL.createObjectURL(new Blob(['﻿' + lines.join('\r\n')], { type: 'text/csv' })); a.download = `cari-ekstre-${c.name}.csv`; document.body.appendChild(a); a.click(); setTimeout(() => { URL.revokeObjectURL(a.href); a.remove(); }, 1000);
  };
  const load = async () => {
    mount(body, loading());
    try {
      const r = await api.get(`/contacts/${c.id}/statement`, f);
      mount(body,
        h('div', { class: 'filters no-print' }, h('div', { class: 'field' }, h('label', null, 'Başlangıç'), h('input', { class: 'input', type: 'date', value: f.from, onchange: (e) => { f.from = e.target.value; load(); } })),
          h('div', { class: 'field' }, h('label', null, 'Bitiş'), h('input', { class: 'input', type: 'date', value: f.to, onchange: (e) => { f.to = e.target.value; load(); } })),
          h('button', { class: 'btn', onclick: () => csv(r) }, '⬇ CSV'), printBtn()),
        h('div', { class: 'print-title' }, h('h2', null, 'Cari ekstre — ' + c.name)),
        h('div', { class: 'alert info small' }, r.note),
        r.groups.length ? r.groups.map((g) => h('div', { class: 'stack tight' }, h('h3', null, `${wsName(g.workspace_id)} · ${g.currency}`),
          table({
            cols: [{ label: 'Tarih', sortable: false, render: (x) => date(x.date) }, { label: 'İşlem', sortable: false, render: (x) => x.type }, { label: 'Açıklama', sortable: false, render: (x) => x.text || '—' },
              { label: 'Tutar', num: true, sortable: false, render: (x) => h('span', { class: signClass(x.signed_minor) }, money(x.signed_minor, g.currency, { sign: true })) }, { label: 'Bakiye', num: true, sortable: false, render: (x) => money(x.balance_minor, g.currency) }],
            rows: g.rows, foot: () => h('tr', null, h('td', { colspan: 4 }, 'Kapanış bakiyesi'), h('td', { class: 'num' }, money(g.closing_minor, g.currency))),
          }))) : h('div', { class: 'muted' }, 'Bu cari için hareket yok.'));
    } catch (e) { mount(body, h('div', { class: 'alert err' }, e.message)); }
  };
  load();
}

export default async function render(ctx) {
  const { el, readonly } = ctx;
  const f = { q: '', kind: '' };
  const out = h('div');
  const load = async () => {
    mount(out, loading());
    try {
      const r = await api.get('/contacts', f);
      mount(out, table({
        cols: [
          { key: 'name', label: 'Cari', render: (c) => h('div', null, h('b', null, c.name), c.archived ? h('span', { class: 'badge' }, 'Arşiv') : null, h('div', { class: 'xs muted' }, [c.tax_no, c.phone, c.email].filter(Boolean).join(' · '))) },
          { key: 'kind', label: 'Tür', render: (c) => ({ customer: 'Müşteri', supplier: 'Tedarikçi', both: 'Müşteri + Tedarikçi' })[c.kind] },
          { label: 'Alacak (bize borçlu)', num: true, sortable: false, render: (c) => Object.entries(c.balances).filter(([, v]) => v.receivable).map(([cur, v]) => h('div', { class: 'pos' }, money(v.receivable, cur))) },
          { label: 'Borç (biz borçluyuz)', num: true, sortable: false, render: (c) => Object.entries(c.balances).filter(([, v]) => v.payable).map(([cur, v]) => h('div', { class: 'neg' }, money(v.payable, cur))) },
          { label: '', act: true, sortable: false, render: (c) => h('div', { class: 'row tight' }, h('button', { class: 'btn sm', onclick: () => statement(c, readonly) }, 'Ekstre'), readonly ? null : h('button', { class: 'btn sm ghost', onclick: () => contactForm(c, load) }, 'Düzenle')) },
        ], rows: r.contacts,
        empty: { icon: '👥', title: 'Cari kart yok', text: 'Müşteri ve tedarikçilerinizi ekleyin; alacak, borç ve faturalar onlara bağlanır.', action: readonly ? null : h('button', { class: 'btn primary', onclick: () => contactForm(null, load) }, '＋ Cari ekle') },
      }));
    } catch (e) { mount(out, h('div', { class: 'alert err' }, e.message)); }
  };
  mount(el, ctx.head('Cariler', 'Müşteri ve tedarikçi kartları, bakiyeler ve cari ekstre.', readonly ? null : [h('button', { class: 'btn primary', onclick: () => contactForm(null, async () => { await loadMeta(); load(); }) }, '＋ Cari ekle')]),
    h('div', { class: 'filters' }, h('div', { class: 'field' }, h('label', null, 'Ara'), h('input', { class: 'input', type: 'search', placeholder: 'Ünvan veya vergi no', oninput: debounce((e) => { f.q = e.target.value; load(); }) })),
      h('div', { class: 'field' }, h('label', null, 'Tür'), h('select', { class: 'input', onchange: (e) => { f.kind = e.target.value; load(); } }, [['', 'Hepsi'], ['customer', 'Müşteriler'], ['supplier', 'Tedarikçiler']].map(([v, l]) => h('option', { value: v }, l))))), out);
  load();
}
