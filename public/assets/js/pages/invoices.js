import { api } from '../api.js';
import { state, scopeParams, loadMeta } from '../state.js';
import { h, mount, loading, money, multi, date, table, errToast, toast, confirmDialog, stateBadge, tabs, modal, fmtQty, pct, printBtn, debounce } from '../ui.js';
import { settleForm, docsPanel } from '../forms_core.js';
import { invoiceForm } from '../forms_more.js';

const PAY = { paid: ['ok', 'Ödendi'], partial: ['warn', 'Kısmi'], overdue: ['bad', 'Gecikmiş'], unpaid: ['info', 'Ödenmedi'], planned: ['', 'Planlı'], cancelled: ['', 'İptal'] };

export default async function render(ctx) {
  const { el, readonly } = ctx;
  let tab = ctx.query.tab || 'sales';
  const f = { q: '' };
  const body = h('div', { class: 'stack' });
  const sales = () => tab === 'sales';
  const load = async () => {
    mount(body, loading());
    try {
      const p = { ...scopeParams(), q: f.q };
      if (tab === 'planned') p.status = 'planned'; else { p.kind = tab; }
      const r = await api.get('/invoices', p);
      const rows = tab === 'planned' ? r.invoices : r.invoices.filter((i) => i.status !== 'planned');
      mount(body, h('div', { class: 'filters' }, h('div', { class: 'field' }, h('label', null, 'Ara'), h('input', { class: 'input', type: 'search', placeholder: 'No veya cari', oninput: debounce((e) => { f.q = e.target.value; load(); }) }))),
        table({
          cols: [
            { key: 'number', label: 'No', render: (i) => h('a', { href: '#', onclick: (e) => { e.preventDefault(); view(i.id); } }, i.number || '#' + i.id) },
            { key: 'contact_name', label: 'Cari' }, { key: 'issue_date', label: 'Tarih', render: (i) => date(i.issue_date) }, { key: 'due_date', label: 'Vade', render: (i) => date(i.due_date) },
            ...(tab === 'planned' ? [{ key: 'kind', label: 'Tür', render: (i) => (i.kind === 'sales' ? 'Satış' : 'Alış') }] : []),
            { key: 'subtotal_minor', label: 'KDV hariç', num: true, render: (i) => money(i.subtotal_minor, i.currency) }, { key: 'vat_minor', label: 'KDV', num: true, render: (i) => money(i.vat_minor, i.currency) },
            { key: 'total_minor', label: 'Toplam', num: true, render: (i) => h('b', null, money(i.total_minor, i.currency)) },
            { key: 'remaining_minor', label: 'Kalan', num: true, render: (i) => (i.status === 'issued' ? money(i.remaining_minor, i.currency) : '—') },
            { key: 'payment_state', label: 'Durum', render: (i) => { const [c, t] = PAY[i.payment_state] || ['', i.payment_state]; return h('span', { class: 'badge ' + c }, t); } },
            { label: '', act: true, sortable: false, render: (i) => h('div', { class: 'row tight' }, h('button', { class: 'btn sm', onclick: () => view(i.id) }, 'Aç'),
              !readonly && i.status === 'planned' ? h('button', { class: 'btn sm primary', onclick: () => issue(i) }, 'Faturayı kes') : null) },
          ], rows, rowClass: (i) => (i.status === 'cancelled' ? 'voided' : ''),
          empty: { icon: '🧾', title: tab === 'planned' ? 'Planlanmış fatura yok' : 'Fatura yok', text: 'Fatura kesmek tahsilat değildir: yalnızca alacak/borç doğurur. Tahsilat/ödemeyi ayrıca girersiniz.', action: readonly || tab === 'planned' ? null : h('button', { class: 'btn primary', onclick: () => newInv() }, '＋ Yeni fatura') },
          foot: rows.length ? () => h('tr', null, h('td', { colspan: tab === 'planned' ? 7 : 6 }, 'Toplam (iptal edilenler hariç)'), h('td', { class: 'num' }, multi(rows.filter((x) => x.status !== 'cancelled').reduce((a, x) => { a[x.currency] = (a[x.currency] || 0) + x.total_minor; return a; }, {}))), h('td', { colspan: 3 })) : null,
        }));
    } catch (e) { mount(body, h('div', { class: 'alert err' }, e.message)); }
  };
  const newInv = () => invoiceForm({ kind: sales() ? 'sales' : 'purchase', cb: load });
  const issue = async (i) => {
    const number = await confirmDialog({ title: 'Faturayı kes', text: `${i.contact_name} — ${money(i.total_minor, i.currency)}. Fatura numarasını girin:`, input: i.number || 'Fatura no', ok: 'Kes' });
    if (!number) return;
    try { const r = await api.post(`/invoices/${i.id}/issue`, { number }); (r.warnings || []).forEach((w) => toast(w, 'err')); toast('Fatura kesildi; alacak/borç oluşturuldu', 'ok'); await loadMeta(); load(); } catch (e) { errToast(e); }
  };
  async function view(id) {
    const box = h('div', null, loading());
    const m = modal({ title: 'Fatura', wide: true, body: box });
    try {
      const r = await api.get('/invoices/' + id);
      const i = r.invoice; const cur = i.currency;
      const paid = r.payments.filter((p) => p.status === 'posted').reduce((a, p) => a + p.amount_minor, 0);
      mount(box, h('div', { class: 'stack' },
        h('div', { class: 'print-title' }, h('h2', null, (i.kind === 'sales' ? 'Satış faturası ' : 'Alış faturası ') + (i.number || ''))),
        h('div', { class: 'row between' }, h('div', null, h('h2', null, (i.kind === 'sales' ? 'Satış' : 'Alış') + ' faturası ' + (i.number || '#' + i.id)), h('div', { class: 'muted' }, i.contact_name)),
          h('div', { class: 'row tight' }, h('span', { class: 'badge ' + ({ issued: 'pri', planned: '', cancelled: 'bad' })[i.status] }, { issued: 'Kesildi', planned: 'Planlı', cancelled: 'İptal' }[i.status]), printBtn())),
        h('dl', { class: 'kv' }, h('dt', null, 'Fatura tarihi'), h('dd', null, date(i.issue_date)), h('dt', null, 'Vade'), h('dd', null, date(i.due_date)), i.cancel_reason ? [h('dt', null, 'İptal gerekçesi'), h('dd', null, i.cancel_reason)] : null, i.note ? [h('dt', null, 'Not'), h('dd', null, i.note)] : null),
        table({ cols: [{ label: 'Açıklama', sortable: false, render: (l) => l.description }, { label: 'Miktar', num: true, sortable: false, render: (l) => `${fmtQty(l.qty_milli)} ${l.unit}` }, { label: 'Birim fiyat', num: true, sortable: false, render: (l) => money(l.unit_price_minor, cur) },
          { label: 'İsk.', num: true, sortable: false, render: (l) => (l.discount_bp ? pct(l.discount_bp) : '—') }, { label: 'KDV', num: true, sortable: false, render: (l) => pct(l.vat_rate_bp) }, { label: 'Tutar', num: true, sortable: false, render: (l) => money(l.total_minor, cur) }], rows: r.lines,
          foot: () => [h('tr', null, h('td', { colspan: 5 }, 'KDV hariç'), h('td', { class: 'num' }, money(i.subtotal_minor, cur))), h('tr', null, h('td', { colspan: 5 }, 'KDV'), h('td', { class: 'num' }, money(i.vat_minor, cur))), h('tr', null, h('td', { colspan: 5 }, 'Genel toplam'), h('td', { class: 'num' }, money(i.total_minor, cur)))] }),
        i.status === 'issued' ? h('div', { class: 'card flat' }, h('div', { class: 'card-title' }, i.kind === 'sales' ? 'Tahsilat durumu' : 'Ödeme durumu', h('span', null, `Kalan: ${money(i.total_minor - paid, cur)}`)),
          r.payments.length ? h('div', { class: 'list' }, r.payments.map((p) => h('div', { class: 'list-item' }, h('div', { class: 'grow' }, date(p.txn_date), p.status === 'voided' ? h('span', { class: 'badge' }, 'iptal') : null), h('b', null, money(p.amount_minor, cur))))) : h('div', { class: 'muted small' }, 'Henüz ' + (i.kind === 'sales' ? 'tahsilat' : 'ödeme') + ' yok.'),
          !readonly && r.entry && i.total_minor - paid > 0 ? h('div', null, h('button', { class: 'btn primary sm', onclick: () => { m.close(true); settleForm({ ...r.entry, remaining_minor: i.total_minor - paid, title: 'Fatura ' + (i.number || '#' + i.id) }, load); } }, i.kind === 'sales' ? 'Tahsil et' : 'Öde')) : null) : null,
        h('div', null, h('h3', null, 'Belgeler'), docsPanel('invoice', id, readonly)),
        !readonly && i.status !== 'cancelled' ? h('div', { class: 'row no-print' }, h('button', { class: 'btn danger sm', onclick: async () => { const reason = await confirmDialog({ title: 'Faturayı iptal et', text: 'Fatura silinmez; iptal edilmiş olarak saklanır, alacak/borç kalemi ve stok hareketleri kapatılır. Tahsilat/ödemesi varsa önce onları iptal edin.', input: 'İptal gerekçesi', ok: 'İptal et', danger: true }); if (!reason) return; try { await api.post(`/invoices/${id}/cancel`, { reason }); toast('Fatura iptal edildi', 'ok'); m.close(true); await loadMeta(); load(); } catch (e) { errToast(e); } } }, 'Faturayı iptal et')) : null));
    } catch (e) { mount(box, h('div', { class: 'alert err' }, e.message)); }
  }
  const draw = () => mount(el, ctx.head('Faturalar', 'Satış ve alış faturaları. Fatura gelir/gider kaydı (KDV hariç) ve alacak/borç doğurur; para hareketi değildir.', readonly ? null : [h('button', { class: 'btn primary', onclick: newInv }, '＋ ' + (tab === 'purchase' ? 'Alış faturası' : 'Satış faturası'))]),
    tabs([['sales', 'Satış faturaları'], ['purchase', 'Alış faturaları'], ['planned', 'Planlanan']], tab, (k) => { tab = k; draw(); load(); }), body);
  draw(); load();
  if (ctx.query.new && !readonly) setTimeout(newInv, 100);
}
