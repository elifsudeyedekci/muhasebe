import { api } from '../api.js';
import { state } from '../state.js';
import { h, mount, loading, money, date, table, errToast, toast, tabs, fmtQty, pct, confirmDialog } from '../ui.js';
import { itemForm, moveForm, warehouseForm } from '../forms_more.js';
import { loadMeta } from '../state.js';

const MV = { in: 'Giriş', out: 'Çıkış', return_in: 'İade girişi', return_out: 'İade çıkışı' };

export default async function render(ctx) {
  const { el, readonly } = ctx;
  let tab = ctx.query.tab || 'items';
  const body = h('div', { class: 'stack' });
  const refresh = async () => { await loadMeta(); load(); };
  const draw = () => mount(el, ctx.head('Stok, Depo & Malzeme', 'Ağırlıklı ortalama maliyetle stok izleme. Stoklu alış gider değildir; tüketim/satış anında maliyet olur (çift sayım yok).', readonly ? null : [
    h('button', { class: 'btn primary', onclick: () => itemForm(null, refresh) }, '＋ Ürün / hizmet'), h('button', { class: 'btn', onclick: () => moveForm(refresh) }, '↕ Stok hareketi'), h('button', { class: 'btn', onclick: () => warehouseForm(refresh) }, '＋ Depo')]),
    tabs([['items', 'Ürünler & stok'], ['moves', 'Hareketler'], ['wh', 'Depolar'], ['report', 'Stok raporu']], tab, (k) => { tab = k; draw(); load(); }), body);
  async function load() {
    mount(body, loading());
    try {
      if (tab === 'items') {
        const r = await api.get('/stock/items');
        const whn = Object.fromEntries(r.warehouses.map((w) => [w.id, w.name]));
        const neg = r.items.filter((i) => i.negative);
        mount(body, neg.length ? h('div', { class: 'alert err' }, '⚠ Negatif stok: ' + neg.map((i) => i.name).join(', ')) : null,
          table({ cols: [
            { key: 'name', label: 'Ürün / hizmet', render: (i) => h('div', null, h('b', null, i.name), i.archived ? h('span', { class: 'badge' }, 'Arşiv') : null, h('div', { class: 'xs muted' }, [i.code, i.barcode, i.kind === 'service' ? 'Hizmet' : null].filter(Boolean).join(' · '))) },
            { key: 'qty_milli', label: 'Stok', num: true, render: (i) => (i.kind === 'service' ? '—' : h('span', { class: i.negative ? 'neg' : i.low ? 'warn-t' : '' }, `${fmtQty(i.qty_milli)} ${i.unit}`, i.low && !i.negative ? ' ⚠' : '')) },
            { label: 'Depolara göre', sortable: false, render: (i) => Object.entries(i.by_warehouse).filter(([, q]) => q).map(([w, q]) => h('div', { class: 'xs' }, `${whn[w] || w}: ${fmtQty(q)}`)) },
            { key: 'backorder_milli', label: 'Karşılanmamış satış', num: true, render: (i) => (i.kind === 'service' || !i.backorder_milli ? '—' : h('span', { class: 'neg', title: 'Satış faturalarında teslim edilemeyen miktar; fiziksel stoktan ayrıdır' }, fmtQty(i.backorder_milli) + ' ' + i.unit)) },
            { key: 'avg_cost_minor', label: 'Ort. maliyet', num: true, render: (i) => (i.kind === 'service' ? '—' : money(i.avg_cost_minor, i.currency)) },
            { key: 'value_minor', label: 'Stok değeri', num: true, render: (i) => (i.kind === 'service' ? '—' : money(i.value_minor, i.currency)) },
            { key: 'sale_price_minor', label: 'Satış fiyatı', num: true, render: (i) => `${money(i.sale_price_minor, i.currency)} (+${pct(i.vat_rate_bp)})` },
            { label: '', act: true, sortable: false, render: (i) => (readonly ? null : h('div', { class: 'row tight' }, h('button', { class: 'btn sm', onclick: () => itemForm(i, refresh) }, 'Düzenle'), h('button', { class: 'btn sm ghost', title: 'Hareketi yoksa silinir', onclick: async () => { if (!await confirmDialog({ title: 'Kartı sil', text: i.name + ' silinsin mi? Hareketi veya fatura satırı olan kartlar silinemez; arşivleyebilirsiniz.', ok: 'Sil', danger: true })) return; try { await api.del('/stock/items/' + i.id); toast('Silindi', 'ok'); refresh(); } catch (e) { errToast(e); } } }, '🗑'), i.kind === 'product' ? h('button', { class: 'btn sm ghost', onclick: () => moveForm(refresh, i) }, 'Hareket') : null)) }],
            rows: r.items, empty: { icon: '📦', title: 'Ürün yok', text: 'Ürün veya hizmet tanımlayın; faturalarda ve stok hareketlerinde kullanılır.', action: readonly ? null : h('button', { class: 'btn primary', onclick: () => itemForm(null, refresh) }, '＋ Ürün ekle') } }));
      } else if (tab === 'moves') {
        const r = await api.get('/stock/movements');
        mount(body, table({ cols: [{ key: 'move_date', label: 'Tarih', render: (m) => date(m.move_date) }, { key: 'item_name', label: 'Ürün' }, { label: 'Hareket', sortable: false, render: (m) => MV[m.kind] }, { key: 'warehouse_name', label: 'Depo' },
          { label: 'Miktar', num: true, sortable: false, render: (m) => `${fmtQty(m.qty_milli)} ${m.unit}` }, { label: 'Birim maliyet', num: true, sortable: false, render: (m) => money(m.unit_cost_minor) }, { key: 'project_name', label: 'Proje', render: (m) => m.project_name || '—' },
          { label: '', act: true, sortable: false, render: (m) => (m.status === 'voided' ? h('span', { class: 'badge' }, 'İptal') : readonly || m.invoice_id ? (m.invoice_id ? h('span', { class: 'xs muted' }, m.invoice_number ? 'fatura ' + m.invoice_number : 'fatura') : null) : h('button', { class: 'btn sm ghost', onclick: async () => { if (!await confirmDialog({ title: 'Hareketi iptal et', text: 'Hareket silinmez, iptal edilir.', ok: 'İptal et', danger: true })) return; try { await api.post(`/stock/movements/${m.id}/void`); refresh(); } catch (e) { errToast(e); } } }, 'İptal')) }],
          rows: r.movements, rowClass: (m) => (m.status === 'voided' ? 'voided' : ''), empty: { icon: '↕', title: 'Hareket yok' } }));
      } else if (tab === 'report') {
        const r = await api.get('/reports/stock');
        mount(body, h('div', { class: 'alert info small' }, r.note), table({ cols: [{ key: 'name', label: 'Ürün' }, { label: 'Mevcut', num: true, sortable: false, render: (x) => fmtQty(x.qty_milli) + ' ' + x.unit }, { label: 'Giriş', num: true, sortable: false, render: (x) => fmtQty(x.in_milli) }, { label: 'Çıkış', num: true, sortable: false, render: (x) => fmtQty(x.out_milli) },
          { label: 'Kaynak', sortable: false, render: (x) => Object.entries(x.by_source || {}).map(([k, v]) => h('div', { class: 'xs' }, ({ purchase_invoice: 'Alış faturası', sales_invoice: 'Satış faturası', manual: 'Manuel' })[k] + ': ' + fmtQty(v))) }, { label: 'Karşılanmamış satış', num: true, sortable: false, render: (x) => (x.backorder_milli ? h('span', { class: 'neg' }, fmtQty(x.backorder_milli)) : '—') },
          { label: 'İptal edilen hareket', num: true, sortable: false, render: (x) => x.voided_count || '—' }, { label: 'Değer', num: true, sortable: false, render: (x) => money(x.value_minor) }], rows: r.items, empty: { icon: '📦', title: 'Stoklu ürün yok' } }));
      } else {
        const r = await api.get('/stock/items');
        mount(body, table({ cols: [{ key: 'name', label: 'Depo' }, { label: 'Durum', sortable: false, render: (w) => (Number(w.archived) ? 'Arşiv' : 'Aktif') }, { label: '', act: true, sortable: false, render: (w) => (readonly ? null : h('div', { class: 'row tight' }, h('button', { class: 'btn sm', onclick: async () => { await api.patch('/stock/warehouses/' + w.id, { archived: !Number(w.archived) }); refresh(); } }, Number(w.archived) ? 'Geri al' : 'Arşivle'), h('button', { class: 'btn sm ghost', onclick: async () => { if (!await confirmDialog({ title: 'Depoyu sil', text: 'Hareketi olmayan depo silinir; aksi halde arşivleyin.', ok: 'Sil', danger: true })) return; try { await api.del('/stock/warehouses/' + w.id); refresh(); } catch (e) { errToast(e); } } }, '🗑'))) }], rows: r.warehouses, empty: { icon: '🏭', title: 'Depo yok', text: 'Stoklu ürünler için en az bir depo ekleyin.' } }));
      }
    } catch (e) { mount(body, h('div', { class: 'alert err' }, e.message)); }
  }
  draw(); load();
}
