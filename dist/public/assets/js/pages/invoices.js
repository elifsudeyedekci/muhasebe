import { api } from '../api.js';
import { state, scopeParams, loadMeta } from '../state.js';
import { h, mount, loading, money, multi, date, table, errToast, toast, confirmDialog, stateBadge, tabs, modal, fmtQty, pct, printBtn, debounce, qtyMilli, todayStr } from '../ui.js';
import { settleForm, docsPanel } from '../forms_core.js';
import { paymentDetail, allocateForm } from '../forms_pay.js';
import { plannedDateForm } from './dashboard.js';
import { invoiceForm } from '../forms_more.js';

const PAY = { paid: ['ok', 'Ödendi'], partial: ['warn', 'Kısmi'], overdue: ['bad', 'Gecikmiş'], unpaid: ['info', 'Ödenmedi'], planned: ['', 'Planlı'], cancelled: ['', 'İptal'] };
const DELIVERY = (kind) => ({
  delivered: ['ok', kind === 'sales' ? 'Tam teslim' : 'Tam alındı'], partial: ['warn', 'Kısmi teslim'], ready: ['info', 'Stokta hazır'], awaiting_supply: ['bad', 'Bekleyen tedarik'], cancelled: ['', 'İptal'],
});

export default async function render(ctx) {
  const { el, readonly } = ctx;
  let tab = ctx.query.tab || 'sales';
  const f = { q: '' };
  const body = h('div', { class: 'stack' });
  const sales = () => tab === 'sales';
  const deliveryBadge = (i) => { const d = i.delivery; if (!d || d.state === 'none') return h('span', { class: 'muted xs' }, '—'); const [c, t] = DELIVERY(i.kind)[d.state] || ['', d.state]; return h('span', { class: 'badge ' + c, title: `Bekleyen: ${fmtQty(d.pending_milli)}` }, t); };
  const load = async () => {
    mount(body, loading());
    try {
      const p = { ...scopeParams(), q: f.q };
      if (tab === 'planned') p.status = 'planned'; else { p.kind = tab; }
      const r = await api.get('/invoices', p);
      const rows = tab === 'planned' ? r.invoices : r.invoices.filter((i) => i.status !== 'planned');
      mount(body, h('div', { class: 'filters' }, h('div', { class: 'field' }, h('label', null, 'Ara'), h('input', { class: 'input', type: 'search', placeholder: 'No, cari veya ürün', oninput: debounce((e) => { f.q = e.target.value; load(); }) }))),
        table({
          cols: [
            { key: 'number', label: 'No', render: (i) => h('a', { href: '#', onclick: (e) => { e.preventDefault(); view(i.id); } }, i.number || '#' + i.id) },
            { key: 'contact_name', label: 'Cari' }, { key: 'issue_date', label: 'Tarih', render: (i) => date(i.issue_date) }, { key: 'due_date', label: 'Vade', render: (i) => (i.due_date ? date(i.due_date) : h('span', { class: 'muted' }, 'Vadesiz')) },
            ...(tab === 'planned' ? [{ key: 'kind', label: 'Tür', render: (i) => (i.kind === 'sales' ? 'Satış' : 'Alış') }] : []),
            { key: 'subtotal_minor', label: 'KDV hariç', num: true, render: (i) => money(i.subtotal_minor, i.currency) }, { key: 'vat_minor', label: 'KDV', num: true, render: (i) => money(i.vat_minor, i.currency) },
            { key: 'total_minor', label: 'Toplam', num: true, render: (i) => h('b', null, money(i.total_minor, i.currency)) },
            { key: 'remaining_minor', label: 'Kalan', num: true, render: (i) => (i.status === 'issued' ? money(i.remaining_minor, i.currency) : '—') },
            { key: 'payment_state', label: tab === 'sales' ? 'Tahsilat' : 'Ödeme', render: (i) => { const [c, t] = PAY[i.payment_state] || ['', i.payment_state]; return h('span', { class: 'badge ' + c }, t); } },
            ...(tab === 'planned' ? [] : [{ key: 'delivery_state', label: tab === 'sales' ? 'Teslimat' : 'Stok girişi', render: deliveryBadge }]),
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

  /** Teslimat / stok girişi: bekleyen satırlar için miktar girilir; stok yalnızca bu işlemde bir kez hareket eder. */
  function deliverForm(id, r, after) {
    const sales_ = r.invoice.kind === 'sales';
    const pend = r.lines.filter((l) => l.tracked && l.pending_milli > 0);
    const inputs = pend.map((l) => ({ l, el: h('input', { class: 'input', type: 'text', inputmode: 'decimal', value: fmtQty(l.pending_milli), 'aria-label': l.description + ' miktar' }) }));
    const msg = h('div', { class: 'alert err hide', role: 'alert' });
    const dt = h('input', { class: 'input', type: 'date', value: todayStr() });
    const btn = h('button', { class: 'btn primary', type: 'button' }, sales_ ? 'Teslimatı işle' : 'Stok girişini işle');
    const key = crypto.randomUUID ? crypto.randomUUID().replace(/-/g, '') : String(Date.now()) + Math.random().toString(16).slice(2);
    const m = modal({ title: sales_ ? 'Teslimat işle' : 'Teslim alma / stok girişi', wide: true, body: h('div', { class: 'stack' }, msg,
      h('div', { class: 'alert info' }, sales_ ? 'Teslim edilen miktar kadar stoktan düşülür (bir kez). Ödeme durumu bundan bağımsızdır.' : 'Teslim alınan miktar kadar stoğa girer (bir kez).'),
      h('div', { class: 'field' }, h('label', null, 'Tarih'), dt),
      table({ cols: [{ label: 'Ürün', sortable: false, render: (x) => x.l.description }, { label: 'Fatura', num: true, sortable: false, render: (x) => fmtQty(x.l.qty_milli) + ' ' + x.l.unit }, { label: 'Daha önce', num: true, sortable: false, render: (x) => fmtQty(x.l.fulfilled_milli) },
        { label: 'Bekleyen', num: true, sortable: false, render: (x) => fmtQty(x.l.pending_milli) }, { label: 'Şimdi işlenecek', num: true, sortable: false, render: (x) => x.el }], rows: inputs })),
    foot: [h('button', { class: 'btn', type: 'button', onclick: () => m.close() }, 'Vazgeç'), btn] });
    btn.addEventListener('click', async () => {
      const ls = [];
      for (const x of inputs) { const raw = x.el.value.trim(); if (raw === '') continue; const q = qtyMilli(raw); if (q === null) { msg.textContent = 'Miktarları kontrol edin.'; msg.classList.remove('hide'); return; } if (q > 0) ls.push({ line_id: x.l.id, qty_milli: q }); }
      if (!ls.length) { msg.textContent = 'En az bir satıra miktar girin.'; msg.classList.remove('hide'); return; }
      btn.disabled = true;
      try { await api.post(`/invoices/${id}/deliver`, { date: dt.value, lines: ls }, { idem: key }); m.close(true); toast('İşlendi', 'ok'); await loadMeta(); after(); } catch (e) { msg.textContent = e.message; msg.classList.remove('hide'); btn.disabled = false; }
    });
  }

  async function view(id) {
    const box = h('div', null, loading());
    const m = modal({ title: 'Fatura', wide: true, body: box });
    const reload = () => { m.close(true); view(id); load(); };
    try {
      const r = await api.get('/invoices/' + id);
      const i = r.invoice; const cur = i.currency; const sl = i.kind === 'sales';
      const paid = r.paid_minor; const rem = r.remaining_minor;
      const tracked = r.lines.filter((l) => l.tracked);
      const dl = r.delivery;
      mount(box, h('div', { class: 'stack' },
        h('div', { class: 'print-title' }, h('h2', null, (sl ? 'Satış faturası ' : 'Alış faturası ') + (i.number || ''))),
        h('div', { class: 'row between' }, h('div', null, h('h2', null, (sl ? 'Satış' : 'Alış') + ' faturası ' + (i.number || '#' + i.id)), h('div', { class: 'muted' }, i.contact_name)),
          h('div', { class: 'row tight' }, h('span', { class: 'badge ' + ({ issued: 'pri', planned: '', cancelled: 'bad' })[i.status] }, { issued: 'Kesildi', planned: 'Planlı', cancelled: 'İptal' }[i.status]), printBtn())),
        h('dl', { class: 'kv' }, h('dt', null, 'Fatura tarihi'), h('dd', null, date(i.issue_date)), h('dt', null, 'Vade'), h('dd', null, i.due_date ? date(i.due_date) : 'Vadesiz'), r.entry ? [h('dt', null, 'Planlanan ödeme/tahsilat'), h('dd', null, r.entry.planned_date ? date(r.entry.planned_date) : 'Tarih girilmemiş')] : null, i.cancel_reason ? [h('dt', null, 'İptal gerekçesi'), h('dd', null, i.cancel_reason)] : null, i.note ? [h('dt', null, 'Not'), h('dd', null, i.note)] : null),
        table({ cols: [{ label: 'Açıklama', sortable: false, render: (l) => h('div', null, l.description, l.item_name && l.item_name !== l.description ? h('div', { class: 'xs muted' }, 'Kart: ' + l.item_name) : null) }, { label: 'Miktar', num: true, sortable: false, render: (l) => `${fmtQty(l.qty_milli)} ${l.unit}` }, { label: 'Birim fiyat', num: true, sortable: false, render: (l) => money(l.unit_price_minor, cur) },
          { label: 'İsk.', num: true, sortable: false, render: (l) => (l.discount_bp ? pct(l.discount_bp) : '—') }, { label: 'KDV', num: true, sortable: false, render: (l) => pct(l.vat_rate_bp) }, { label: 'Tutar', num: true, sortable: false, render: (l) => money(l.total_minor, cur) }], rows: r.lines,
          foot: () => [h('tr', null, h('td', { colspan: 5 }, 'KDV hariç'), h('td', { class: 'num' }, money(i.subtotal_minor, cur))), h('tr', null, h('td', { colspan: 5 }, 'KDV'), h('td', { class: 'num' }, money(i.vat_minor, cur))), h('tr', null, h('td', { colspan: 5 }, 'Genel toplam'), h('td', { class: 'num' }, money(i.total_minor, cur)))] }),
        i.status === 'issued' ? h('div', { class: 'card flat' }, h('div', { class: 'card-title' }, sl ? 'Tahsilat durumu' : 'Ödeme durumu', h('span', null, `Ödenen: ${money(paid, cur)} · Kalan: ${money(rem, cur)}`)),
          r.payments.length ? h('div', { class: 'list' }, r.payments.map((p) => h('div', { class: 'list-item' }, h('div', { class: 'grow' }, h('a', { href: '#', onclick: (e) => { e.preventDefault(); paymentDetail(p.id, { readonly, cb: reload, openInvoice: view }); } }, date(p.txn_date)), p.status !== 'posted' ? h('span', { class: 'badge' }, p.status === 'reversed' ? 'eşleştirme geri alındı' : 'iptal') : null, p.reference ? h('span', { class: 'xs muted' }, ' · ' + p.reference) : null), h('b', null, money(p.amount_minor, cur))))) : h('div', { class: 'muted small' }, 'Henüz ' + (sl ? 'tahsilat' : 'ödeme') + ' yok.'),
          !readonly && r.entry && rem > 0 ? h('div', { class: 'row tight' }, h('button', { class: 'btn sm', onclick: async () => { try { const un = await api.get('/payments', { contact_id: i.contact_id, alloc: 'unallocated', limit: 100 }); const list = un.payments.filter((p) => p.kind === (sl ? 'collection' : 'payment') && p.currency === cur); if (!list.length) return toast('Bu cari için eşleştirilmemiş ' + (sl ? 'avans/tahsilat' : 'avans/ödeme') + ' yok.', 'err'); m.close(true); const pick = modal({ title: 'Avansı bu faturayla eşleştir', body: h('div', { class: 'list' }, list.map((p) => h('div', { class: 'list-item' }, h('div', { class: 'grow' }, date(p.txn_date) + ' · ' + (p.description || (sl ? 'Tahsilat' : 'Ödeme'))), h('b', null, money(p.unallocated_minor, cur)), h('button', { class: 'btn sm primary', onclick: () => { pick.close(true); allocateForm({ id: p.id, kind: p.kind, contact_id: i.contact_id, currency: cur, unallocated_minor: p.unallocated_minor }, () => { load(); view(id); }, r.entry.id); } }, 'Seç')))) }); } catch (e) { errToast(e); } } }, 'Avansı eşleştir'), h('button', { class: 'btn sm ghost', onclick: () => { m.close(true); plannedDateForm({ id: r.entry.id, kind: r.entry.kind, planned_date: r.entry.planned_date }, () => { load(); view(id); }); } }, '📅 Planlanan tarih'), h('button', { class: 'btn primary sm', onclick: () => { m.close(true); settleForm({ ...r.entry, remaining_minor: rem, paid_minor: paid, title: 'Fatura ' + (i.number || '#' + i.id) }, load); } }, sl ? 'Tahsil et' : 'Öde')) : null) : null,
        tracked.length && i.status !== 'planned' ? h('div', { class: 'card flat stack tight' }, h('div', { class: 'card-title' }, sl ? 'Teslimat / stok durumu' : 'Stok girişi durumu', h('span', { class: 'badge ' + ((DELIVERY(i.kind)[dl.state] || [''])[0]) }, (DELIVERY(i.kind)[dl.state] || ['', dl.state])[1])),
          h('div', { class: 'small muted' }, `Fatura miktarı ${fmtQty(dl.tracked_milli)} · işlenen ${fmtQty(dl.fulfilled_milli)} · bekleyen ${fmtQty(dl.pending_milli)}. Ödeme ile teslimat birbirinden bağımsızdır.`),
          !readonly && dl.pending_milli > 0 && i.status === 'issued' ? h('div', null, h('button', { class: 'btn sm primary', onclick: () => deliverForm(id, r, reload) }, sl ? 'Teslimat işle' : 'Stok girişi işle')) : null) : null,
        r.stock_movements.length ? h('details', null, h('summary', null, 'Stok hareketleri (' + r.stock_movements.length + ')'), h('div', { class: 'list' }, r.stock_movements.map((s) => h('div', { class: 'list-item' }, h('div', { class: 'grow' }, `${s.item_name} — ${({ in: 'Giriş', out: 'Çıkış', return_in: 'İade girişi', return_out: 'İade çıkışı' })[s.kind]} ${fmtQty(s.qty_milli)}`, s.status === 'voided' ? h('span', { class: 'badge' }, 'iptal') : null, h('div', { class: 'xs muted' }, date(s.move_date) + (s.note ? ' · ' + s.note : ''))))))) : null,
        h('div', null, h('h3', null, 'Belgeler'), docsPanel('invoice', id, readonly)),
        !readonly && i.status !== 'cancelled' ? h('div', { class: 'row no-print' },
          h('button', { class: 'btn sm', onclick: () => { m.close(true); invoiceForm({ kind: i.kind, edit: r, cb: () => { load(); view(id); } }); } }, '✏️ Düzenle'),
          h('button', { class: 'btn danger sm', onclick: async () => { const reason = await confirmDialog({ title: 'Faturayı iptal et', text: 'Fatura silinmez; iptal edilmiş olarak saklanır (geçmiş korunur), alacak/borç kalemi kapanır ve stok hareketleri geri alınır. Ödeme dağıtımı varsa önce onu geri alın.', input: 'İptal gerekçesi', ok: 'İptal et', danger: true }); if (!reason) return; try { await api.post(`/invoices/${id}/cancel`, { reason }); toast('Fatura iptal edildi', 'ok'); m.close(true); await loadMeta(); load(); } catch (e) { errToast(e); } } }, 'Faturayı iptal et'),
          h('button', { class: 'btn danger sm ghost', onclick: async () => { if (!await confirmDialog({ title: 'Faturayı sil', text: 'Hatalı girilmiş fatura kalıcı olarak silinir (borç/alacak kalemi ve stok etkisi de kalkar). Yalnızca hiç ödeme/tahsilat geçmişi olmayan faturalar silinebilir; aksi halde “İptal et” kullanın.', ok: 'Kalıcı sil', danger: true })) return; try { await api.del('/invoices/' + id); toast('Fatura silindi', 'ok'); m.close(true); await loadMeta(); load(); } catch (e) { errToast(e); } } }, '🗑 Sil')) : null));
    } catch (e) { mount(box, h('div', { class: 'alert err' }, e.message)); }
  }
  const draw = () => mount(el, ctx.head('Faturalar', 'Satış ve alış faturaları. Fatura gelir/gider kaydı (KDV hariç) ve alacak/borç doğurur; para hareketi değildir.', readonly ? null : [h('button', { class: 'btn primary', onclick: newInv }, '＋ ' + (tab === 'purchase' ? 'Alış faturası' : 'Satış faturası'))]),
    tabs([['sales', 'Satış faturaları'], ['purchase', 'Alış faturaları'], ['planned', 'Planlanan']], tab, (k) => { tab = k; draw(); load(); }), body);
  draw(); load();
  if (ctx.query.new && !readonly) setTimeout(newInv, 100);
  if (ctx.query.open) setTimeout(() => view(Number(ctx.query.open)), 100);
}
