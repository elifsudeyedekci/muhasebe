import { api } from '../api.js';
import { state } from '../state.js';
import { h, mount, loading, money, date, table, errToast, modal, signClass, stateBadge } from '../ui.js';
import { projectForm, milestoneForm, quoteForm } from '../forms_more.js';
import { loadMeta } from '../state.js';

const ST = { planned: 'Planlandı', active: 'Devam ediyor', done: 'Tamamlandı', cancelled: 'İptal' };
const SRC = { txn: 'Gelir/gider hareketleri', invoice: 'Faturalar (KDV hariç)', entry: 'Hakediş / planlı kalemler', card: 'Kart harcamaları', interest: 'Kredi faizi', stock: 'Malzeme tüketimi' };

export default async function render(ctx) {
  const { el, readonly } = ctx;
  const out = h('div');
  const load = async () => {
    mount(out, loading());
    try {
      const r = await api.get('/projects');
      mount(out, table({
        cols: [
          { key: 'name', label: 'Proje', render: (p) => h('div', null, h('a', { href: '#', onclick: (e) => { e.preventDefault(); detail(p.id); } }, h('b', null, p.name)), h('div', { class: 'xs muted' }, p.contact_name || '')) },
          { key: 'status', label: 'Durum', render: (p) => h('span', { class: 'badge ' + (p.status === 'active' ? 'info' : p.status === 'done' ? 'ok' : '') }, ST[p.status]) },
          { key: 'contract_minor', label: 'Sözleşme', num: true, render: (p) => money(p.contract_minor, p.currency) },
          { key: 'revenue_minor', label: 'Gelir', num: true, render: (p) => money(p.revenue_minor, p.currency) }, { key: 'cost_minor', label: 'Maliyet', num: true, render: (p) => money(p.cost_minor, p.currency) },
          { key: 'profit_minor', label: 'Tahmini kâr', num: true, render: (p) => h('b', { class: signClass(p.profit_minor) }, money(p.profit_minor, p.currency)) },
          { label: '', act: true, sortable: false, render: (p) => h('div', { class: 'row tight' }, h('button', { class: 'btn sm', onclick: () => detail(p.id) }, 'Aç'), readonly ? null : h('button', { class: 'btn sm ghost', onclick: () => projectForm(p, load) }, 'Düzenle')) },
        ], rows: r.projects,
        empty: { icon: '🏗️', title: 'Proje yok', text: 'İş/proje oluşturun; gelir, gider, hakediş ve malzeme tüketimlerini projeye bağlayın.', action: readonly ? null : h('button', { class: 'btn primary', onclick: () => projectForm(null, load) }, '＋ Proje oluştur') },
      }));
    } catch (e) { mount(out, h('div', { class: 'alert err' }, e.message)); }
  };
  async function detail(id) {
    const box = h('div', null, loading());
    const m = modal({ title: 'Proje', wide: true, body: box });
    const draw = async () => {
      try {
        const r = await api.get('/projects/' + id); const p = r.project; const f = r.figures; const c = p.currency;
        mount(box, h('div', { class: 'stack' },
          h('div', { class: 'row between' }, h('div', null, h('h2', null, p.name), h('div', { class: 'muted' }, [p.contact_name, ST[p.status]].filter(Boolean).join(' · '))),
            readonly ? null : h('div', { class: 'row tight' }, h('button', { class: 'btn sm', onclick: () => milestoneForm(p, async () => { await loadMeta(); draw(); load(); }) }, '＋ Hakediş'), h('button', { class: 'btn sm', onclick: () => quoteForm(p, draw) }, '＋ Teklif/Sipariş'))),
          h('div', { class: 'alert info small' }, 'Kâr (tahakkuk) ile nakit ayrıdır: kâr = gelir − maliyet (KDV hariç, tahsilat/ödeme tekrar sayılmaz). Nakit = gerçekleşen giriş − çıkış.'),
          h('div', { class: 'auto-grid' },
            h('div', { class: 'card stat' }, h('div', { class: 'label' }, 'Gelir (tahakkuk)'), h('div', { class: 'value' }, money(f.revenue_minor, c)), h('div', { class: 'sub' }, 'Sözleşme ' + money(f.contract_minor, c))),
            h('div', { class: 'card stat' }, h('div', { class: 'label' }, 'Maliyet'), h('div', { class: 'value' }, money(f.cost_minor, c)), h('div', { class: 'sub' }, 'Tahmini ' + money(f.est_cost_minor, c))),
            h('div', { class: 'card stat' }, h('div', { class: 'label' }, 'Kâr'), h('div', { class: 'value ' + signClass(f.profit_minor) }, money(f.profit_minor, c)), h('div', { class: 'sub' }, 'Tahmini kâr (sözleşme−maliyet) ' + money(f.est_profit_minor, c))),
            h('div', { class: 'card stat' }, h('div', { class: 'label' }, 'Gerçekleşen nakit'), h('div', { class: 'value ' + signClass(f.cash_net_minor) }, money(f.cash_net_minor, c)), h('div', { class: 'sub' }, `Giriş ${money(f.cash_in_minor, c)} · Çıkış ${money(f.cash_out_minor, c)}`)),
            h('div', { class: 'card stat' }, h('div', { class: 'label' }, 'Bekleyen'), h('div', { class: 'value' }, money(f.pending_receivable_minor, c)), h('div', { class: 'sub' }, 'tahsil edilecek · ödenecek ' + money(f.pending_payable_minor, c)))),
          h('h3', null, 'Kaynağa göre dağılım'),
          table({ cols: [{ label: 'Kaynak', sortable: false, render: (x) => SRC[x[0]] || x[0] }, { label: 'Gelir', num: true, sortable: false, render: (x) => money(x[1].income || 0, c) }, { label: 'Gider', num: true, sortable: false, render: (x) => money(x[1].expense || 0, c) }], rows: Object.entries(f.by_source), empty: { title: 'Henüz kayıt yok', text: 'Gelir, gider, fatura veya malzeme tüketimini bu projeye bağlayın.' } }),
          h('h3', null, 'Hakediş / ara ödeme planı'),
          table({ cols: [{ label: 'Başlık', sortable: false, render: (x) => x.title }, { label: 'Vade', sortable: false, render: (x) => date(x.due_date) }, { label: 'Tutar', num: true, sortable: false, render: (x) => money(x.amount_minor, c) }, { label: 'Tahsil edilen', num: true, sortable: false, render: (x) => money(x.paid_minor, c) }], rows: r.milestones, empty: { title: 'Hakediş planı yok' } }),
          h('h3', null, 'Teklif & siparişler'),
          table({ cols: [{ label: 'Tür', sortable: false, render: (x) => (x.kind === 'quote' ? 'Teklif' : 'Sipariş') }, { label: 'No', sortable: false, render: (x) => x.number || '—' }, { label: 'Tarih', sortable: false, render: (x) => date(x.quote_date) }, { label: 'Tutar', num: true, sortable: false, render: (x) => money(x.amount_minor, c) }, { label: 'Durum', sortable: false, render: (x) => ({ draft: 'Taslak', sent: 'Gönderildi', accepted: 'Kabul', rejected: 'Red' })[x.status] }], rows: r.quotes, empty: { title: 'Kayıt yok' } })));
      } catch (e) { mount(box, h('div', { class: 'alert err' }, e.message)); }
    };
    draw();
  }
  mount(el, ctx.head('Projeler & İşler', 'Proje bazında gelir, maliyet, kâr ve nakit.', readonly ? null : [h('button', { class: 'btn primary', onclick: () => projectForm(null, load) }, '＋ Proje')]), out);
  load();
}
