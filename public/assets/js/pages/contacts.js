import { api } from '../api.js';
import { state } from '../state.js';
import { h, mount, loading, money, date, table, errToast, toast, modal, signClass, printBtn, debounce, plain, confirmDialog, menu } from '../ui.js';
import { contactForm } from '../forms_core.js';
import { paymentForm, paymentDetail, allocateForm } from '../forms_pay.js';
import { wsName, loadMeta } from '../state.js';

async function statement(c, readonly, ctx, onChange) {
  const f = { from: '', to: '', type: 'all' };
  const body = h('div', { class: 'stack' });
  const m = modal({ title: 'Cari ekstre — ' + c.name, wide: true, body });
  const csv = (r) => {
    const lines = [['Tarih', 'İşlem', 'Açıklama', 'Vade', 'Tutar', 'Bakiye', 'Birim', 'Alan'].join(';')];
    r.groups.forEach((g) => g.rows.forEach((x) => lines.push([x.date, x.type, `"${(x.text || '').replace(/"/g, '""')}"`, x.due_date || '', plain(x.signed_minor), plain(x.balance_minor), g.currency, wsName(g.workspace_id)].join(';'))));
    const a = document.createElement('a'); a.href = URL.createObjectURL(new Blob(['﻿' + lines.join('\r\n')], { type: 'text/csv' })); a.download = `cari-ekstre-${c.name}.csv`; document.body.appendChild(a); a.click(); setTimeout(() => { URL.revokeObjectURL(a.href); a.remove(); }, 1000);
  };
  const open = (ref) => {
    if (!ref) return;
    if (ref.type === 'invoice') { m.close(true); ctx.go('/invoices?open=' + ref.id); } else if (ref.type === 'transaction') paymentDetail(ref.id, { readonly, cb: () => { load(); onChange && onChange(); }, openInvoice: (id) => { m.close(true); ctx.go('/invoices?open=' + id); } });
  };
  const load = async () => {
    mount(body, loading());
    try {
      const [r, un] = await Promise.all([api.get(`/contacts/${c.id}/statement`, f), api.get('/payments', { contact_id: c.id, alloc: 'unallocated', limit: 100 })]);
      mount(body,
        h('div', { class: 'filters no-print' }, h('div', { class: 'field' }, h('label', null, 'Başlangıç'), h('input', { class: 'input', type: 'date', value: f.from, onchange: (e) => { f.from = e.target.value; load(); } })),
          h('div', { class: 'field' }, h('label', null, 'Bitiş'), h('input', { class: 'input', type: 'date', value: f.to, onchange: (e) => { f.to = e.target.value; load(); } })),
          h('div', { class: 'field' }, h('label', null, 'Kayıt türü'), h('select', { class: 'input', onchange: (e) => { f.type = e.target.value; load(); } }, [['all', 'Faturalar + ödemeler'], ['invoices', 'Yalnızca faturalar/kalemler'], ['payments', 'Yalnızca ödeme/tahsilatlar']].map(([v, l]) => h('option', { value: v, selected: v === f.type }, l)))),
          h('button', { class: 'btn', onclick: () => csv(r) }, '⬇ CSV'), printBtn(),
          readonly ? null : h('button', { class: 'btn primary', onclick: () => { m.close(true); paymentForm({ kind: c.kind === 'customer' ? 'collection' : 'payment', contactId: c.id, cb: onChange }); } }, c.kind === 'customer' ? '＋ Tahsilat' : '＋ Ödeme')),
        h('div', { class: 'print-title' }, h('h2', null, 'Cari ekstre — ' + c.name)),
        h('div', { class: 'alert info small' }, r.note),
        un.payments.length ? h('div', { class: 'card flat stack tight' }, h('div', { class: 'card-title' }, 'Eşleştirilmemiş ödeme / tahsilatlar (avans)'),
          un.payments.map((p) => h('div', { class: 'list-item' }, h('div', { class: 'grow' }, `${date(p.txn_date)} · ${p.kind === 'payment' ? 'Ödeme' : 'Tahsilat'} ${money(p.amount_minor, p.currency)}`, h('div', { class: 'xs muted' }, p.description || '')), h('b', { class: 'warn-t' }, money(p.unallocated_minor, p.currency)),
            readonly ? null : h('button', { class: 'btn sm primary', onclick: () => allocateForm({ id: p.id, kind: p.kind, contact_id: c.id, currency: p.currency, unallocated_minor: p.unallocated_minor }, () => { load(); onChange && onChange(); }) }, 'Faturalara eşleştir')))) : null,
        r.groups.length ? r.groups.map((g) => h('div', { class: 'stack tight' }, h('h3', null, `${wsName(g.workspace_id)} · ${g.currency}`),
          table({
            cols: [{ label: 'Tarih', sortable: false, render: (x) => date(x.date) }, { label: 'İşlem', sortable: false, render: (x) => x.type },
              { label: 'Açıklama', sortable: false, render: (x) => (x.ref && x.status !== 'cancelled' ? h('a', { href: '#', onclick: (e) => { e.preventDefault(); open(x.ref); } }, x.text || 'Aç') : (x.text || '—')) },
              { label: 'Vade', sortable: false, render: (x) => (x.due_date ? date(x.due_date) : (x.type.startsWith('Alacak') || x.type.startsWith('Borç') ? 'Vadesiz' : '')) },
              { label: 'Tutar', num: true, sortable: false, render: (x) => h('span', { class: signClass(x.signed_minor) }, money(x.signed_minor, g.currency, { sign: true })) }, { label: 'Bakiye', num: true, sortable: false, render: (x) => money(x.balance_minor, g.currency) }],
            rows: g.rows, rowClass: (x) => (x.status === 'cancelled' || x.status === 'voided' ? 'voided' : ''),
            foot: () => h('tr', null, h('td', { colspan: 5 }, 'Kapanış bakiyesi'), h('td', { class: 'num' }, money(g.closing_minor, g.currency))),
          }))) : h('div', { class: 'muted' }, 'Bu cari için hareket yok.'));
    } catch (e) { mount(body, h('div', { class: 'alert err' }, e.message)); }
  };
  load();
}

export default async function render(ctx) {
  const { el, readonly } = ctx;
  const f = { q: '', kind: '' };
  const out = h('div');
  const reload = async () => { await loadMeta(); load(); };
  const del = async (c) => {
    if (!await confirmDialog({ title: 'Cariyi sil', text: `“${c.name}” silinsin mi? Fatura, ödeme veya başka kaydı olan cariler silinemez (geçmiş korunur); onları arşivleyebilirsiniz.`, ok: 'Sil', danger: true })) return;
    try { await api.del('/contacts/' + c.id); toast('Cari silindi', 'ok'); reload(); } catch (e) { errToast(e); }
  };
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
          { label: 'Avans (eşleşmemiş)', num: true, sortable: false, render: (c) => Object.entries(c.balances).flatMap(([cur, v]) => [v.advance_out ? h('div', { class: 'warn-t', title: 'Tedarikçiye ödenen, faturaya bağlanmamış' }, 'Verilen ' + money(v.advance_out, cur)) : null, v.advance_in ? h('div', { class: 'warn-t', title: 'Müşteriden alınan, faturaya bağlanmamış' }, 'Alınan ' + money(v.advance_in, cur)) : null]) },
          { label: '', act: true, sortable: false, render: (c) => h('div', { class: 'row tight' }, h('button', { class: 'btn sm', onclick: () => statement(c, readonly, ctx, reload) }, 'Ekstre'),
            readonly ? null : h('button', { class: 'btn sm ghost', 'aria-label': 'Diğer işlemler', onclick: (e) => menu(e.currentTarget, [
              { label: c.kind === 'customer' ? '💰 Tahsilat gir' : '💸 Ödeme yap', run: () => paymentForm({ kind: c.kind === 'customer' ? 'collection' : 'payment', contactId: c.id, cb: reload }) },
              c.kind === 'both' ? { label: '💰 Tahsilat gir', run: () => paymentForm({ kind: 'collection', contactId: c.id, cb: reload }) } : null,
              { label: '✏️ Düzenle', run: () => contactForm(c, reload) },
              { label: c.archived ? '↩️ Arşivden çıkar' : '🗄 Arşivle', run: async () => { try { await api.patch('/contacts/' + c.id, { archived: !c.archived }); reload(); } catch (er) { errToast(er); } } },
              { label: '🗑 Sil (kullanılmıyorsa)', run: () => del(c) }]) }, '⋯')) },
        ], rows: r.contacts,
        empty: { icon: '👥', title: 'Cari kart yok', text: 'Müşteri ve tedarikçilerinizi ekleyin; alacak, borç ve faturalar onlara bağlanır.', action: readonly ? null : h('button', { class: 'btn primary', onclick: () => contactForm(null, load) }, '＋ Cari ekle') },
      }));
    } catch (e) { mount(out, h('div', { class: 'alert err' }, e.message)); }
  };
  mount(el, ctx.head('Cariler', 'Müşteri ve tedarikçi kartları, bakiyeler, avanslar ve cari hesap hareketleri.', readonly ? null : [h('button', { class: 'btn primary', onclick: () => contactForm(null, reload) }, '＋ Cari ekle')]),
    h('div', { class: 'filters' }, h('div', { class: 'field' }, h('label', null, 'Ara'), h('input', { class: 'input', type: 'search', placeholder: 'Ünvan veya vergi no', oninput: debounce((e) => { f.q = e.target.value; load(); }) })),
      h('div', { class: 'field' }, h('label', null, 'Tür'), h('select', { class: 'input', onchange: (e) => { f.kind = e.target.value; load(); } }, [['', 'Hepsi'], ['customer', 'Müşteriler'], ['supplier', 'Tedarikçiler']].map(([v, l]) => h('option', { value: v }, l))))), out);
  load();
}
