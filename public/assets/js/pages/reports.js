import { api } from '../api.js';
import { state, hasBusinessWs, catOptions, loadMeta } from '../state.js';
import { h, mount, loading, money, multi, date, table, errToast, toast, barChart, legend, monthLabel, signClass, printBtn, seg, pct, todayStr, addDays, CURRENCIES, formModal, emptyState } from '../ui.js';

const REPORTS = [
  ['income-expense', '📈 Gelir – Gider (aylık)'], ['cashflow', '💧 Nakit akışı'], ['vat', '🧮 KDV dökümü'], ['aging-receivable', '⏳ Alacak yaşlandırma'], ['aging-payable', '⏳ Borç yaşlandırma'],
  ['projects', '🏗️ Proje kârlılığı'], ['budget', '🎯 Bütçe – gerçekleşen'], ['converted', '💱 Ortak para birimi özeti'],
];

export default async function render(ctx) {
  const { el, readonly } = ctx;
  const yearStart = todayStr().slice(0, 4) + '-01-01';
  const f = { report: ctx.query.r || 'income-expense', from: yearStart, to: todayStr(), scope: hasBusinessWs() ? 'business' : 'all', month: todayStr().slice(0, 7), to_cur: 'TRY' };
  const out = h('div', { class: 'stack' });
  const exportable = ['income-expense', 'cashflow', 'vat', 'aging-receivable', 'aging-payable', 'projects', 'budget'];
  const dl = async (fmt) => {
    const [name, kind] = f.report.startsWith('aging') ? ['aging', f.report.split('-')[1]] : [f.report, null];
    const q = new URLSearchParams({ from: f.from, to: f.to, scope: f.scope, format: fmt, month: f.month }); if (kind) q.set('kind', kind);
    try { await api.download(`/export/${name}?${q}`, `${name}.${fmt}`); } catch (e) { errToast(e); }
  };
  const usesRange = ['income-expense', 'cashflow', 'vat'].includes(f.report);
  const head = () => mount(el, ctx.head('Raporlar', 'Tüm rakamlar veritabanındaki kayıtlardan hesaplanır. PDF için “Yazdır / PDF” kullanın; CSV ve Excel dışa aktarma bulunur.', [exportable.includes(f.report) ? h('button', { class: 'btn', onclick: () => dl('csv') }, '⬇ CSV') : null, exportable.includes(f.report) ? h('button', { class: 'btn', onclick: () => dl('xlsx') }, '⬇ Excel') : null, printBtn()]),
    h('div', { class: 'filters' },
      h('div', { class: 'field' }, h('label', null, 'Rapor'), h('select', { class: 'input', onchange: (e) => { f.report = e.target.value; head(); load(); } }, REPORTS.map(([v, l]) => h('option', { value: v, selected: v === f.report }, l)))),
      ['income-expense', 'cashflow', 'vat'].includes(f.report) ? [h('div', { class: 'field' }, h('label', null, 'Başlangıç'), h('input', { class: 'input', type: 'date', value: f.from, onchange: (e) => { f.from = e.target.value; load(); } })), h('div', { class: 'field' }, h('label', null, 'Bitiş'), h('input', { class: 'input', type: 'date', value: f.to, onchange: (e) => { f.to = e.target.value; load(); } }))] : null,
      f.report === 'budget' ? h('div', { class: 'field' }, h('label', null, 'Ay'), h('input', { class: 'input', type: 'month', value: f.month, onchange: (e) => { f.month = e.target.value; load(); } })) : null,
      f.report === 'converted' ? h('div', { class: 'field' }, h('label', null, 'Hedef para birimi'), h('select', { class: 'input', onchange: (e) => { f.to_cur = e.target.value; load(); } }, CURRENCIES.map((c) => h('option', { value: c, selected: c === f.to_cur }, c)))) : null,
      !['converted'].includes(f.report) ? h('div', { class: 'field' }, h('label', null, 'Kapsam'), h('select', { class: 'input', onchange: (e) => { f.scope = e.target.value; load(); } }, [['business', 'Yalnızca şirketler (şahsi hariç)'], ['personal', 'Yalnızca şahsi'], ['all', 'Hepsi']].map(([v, l]) => h('option', { value: v, selected: v === f.scope }, l)))) : null),
    out);

  async function load() {
    mount(out, loading());
    const p = { from: f.from, to: f.to, scope: f.scope };
    try {
      if (f.report === 'income-expense') {
        const r = await api.get('/reports/income-expense', p);
        const curs = Object.keys(r.totals);
        mount(out,
          h('div', { class: 'print-title' }, h('h2', null, 'Gelir – Gider raporu ' + date(f.from) + ' – ' + date(f.to))),
          h('div', { class: 'auto-grid' }, curs.length ? curs.map((c) => h('div', { class: 'card stat' }, h('div', { class: 'label' }, 'Dönem · ' + c), h('div', { class: 'value ' + signClass(r.totals[c].profit_minor) }, money(r.totals[c].profit_minor, c)), h('div', { class: 'sub' }, `Gelir ${money(r.totals[c].income_minor, c)} · Gider ${money(r.totals[c].expense_minor, c)}`),
            r.planned[c] ? h('div', { class: 'sub' }, `Bunun planlanan (ileri tarihli) kısmı: gelir ${money(r.planned[c].income_minor || 0, c)}, gider ${money(r.planned[c].expense_minor || 0, c)}`) : null)) : h('div', { class: 'muted' }, 'Bu aralıkta kayıt yok.')),
          ...curs.map((c) => {
            const rows = r.rows.filter((x) => x.currency === c);
            return h('div', { class: 'card stack' }, h('div', { class: 'card-title' }, 'Aylık gelir – gider · ' + c), barChart({ labels: rows.map((x) => monthLabel(x.month).slice(0, 3)), series: [{ name: 'Gelir', cls: 'b-in', values: rows.map((x) => x.income_minor) }, { name: 'Gider', cls: 'b-out', values: rows.map((x) => x.expense_minor) }] }), legend([['b-in', 'Gelir (KDV hariç)'], ['b-out', 'Gider (KDV hariç)']]),
              table({ cols: [{ label: 'Ay', sortable: false, render: (x) => monthLabel(x.month) }, { label: 'Gelir', num: true, sortable: false, render: (x) => money(x.income_minor, c) }, { label: 'Gider', num: true, sortable: false, render: (x) => money(x.expense_minor, c) }, { label: 'Kâr / zarar', num: true, sortable: false, render: (x) => h('b', { class: signClass(x.profit_minor) }, money(x.profit_minor, c)) }], rows }));
          }),
          Object.keys(r.by_category.expense || {}).length ? h('div', { class: 'card stack' }, h('div', { class: 'card-title' }, 'Gider kategorileri'), ...Object.entries(r.by_category.expense).map(([c, cats]) => table({ cols: [{ label: 'Kategori · ' + c, sortable: false, render: (x) => x[0] }, { label: 'Tutar', num: true, sortable: false, render: (x) => money(x[1], c) }, { label: 'Pay', num: true, sortable: false, render: (x) => { const t = Object.values(cats).reduce((a, b) => a + b, 0); return t ? Math.round((x[1] * 100) / t) + '%' : ''; } }], rows: Object.entries(cats).sort((a, b) => b[1] - a[1]) }))) : null,
          h('div', { class: 'alert info small' }, r.method));
      } else if (f.report === 'cashflow') {
        const r = await api.get('/reports/cashflow', p);
        const curs = [...new Set(r.realized.map((x) => x.currency))];
        mount(out, h('div', { class: 'alert info small' }, r.note),
          ...curs.map((c) => { const rows = r.realized.filter((x) => x.currency === c); return h('div', { class: 'card stack' }, h('div', { class: 'card-title' }, 'Gerçekleşen nakit akışı · ' + c),
            barChart({ labels: rows.map((x) => monthLabel(x.month).slice(0, 3)), series: [{ name: 'Giriş', cls: 'b-in', values: rows.map((x) => x.cash_in_minor) }, { name: 'Çıkış', cls: 'b-out', values: rows.map((x) => x.cash_out_minor) }] }), legend([['b-in', 'Giriş'], ['b-out', 'Çıkış']]),
            table({ cols: [{ label: 'Ay', sortable: false, render: (x) => monthLabel(x.month) }, { label: 'Giriş', num: true, sortable: false, render: (x) => money(x.cash_in_minor, c) }, { label: 'Çıkış', num: true, sortable: false, render: (x) => money(x.cash_out_minor, c) }, { label: 'Net', num: true, sortable: false, render: (x) => h('b', { class: signClass(x.net_minor) }, money(x.net_minor, c)) }], rows })); }),
          h('div', { class: 'card stack' }, h('div', { class: 'card-title' }, 'Beklenen (tahmini) hareketler — eldeki para değildir'),
            table({ cols: [{ label: 'Ay', sortable: false, render: (x) => monthLabel(x.month) }, { label: 'Birim', sortable: false, render: (x) => x.currency }, { label: 'Beklenen tahsilat', num: true, sortable: false, render: (x) => money(x.expected_in_minor, x.currency) }, { label: 'Beklenen ödeme', num: true, sortable: false, render: (x) => money(x.expected_out_minor, x.currency) }], rows: r.forecast, empty: { title: 'Planlı hareket yok' } })));
      } else if (f.report === 'vat') {
        const r = await api.get('/reports/vat', p);
        mount(out, h('div', { class: 'alert warn small' }, 'Bu döküm yönetim amaçlıdır; resmî KDV beyannamesi yerine geçmez. Oranlar kayıtlardan gelir, sabitlenmemiştir.'),
          h('div', { class: 'auto-grid' }, Object.entries(r.summary).map(([c, s]) => h('div', { class: 'card stat' }, h('div', { class: 'label' }, 'Ödenecek / (devreden) KDV · ' + c), h('div', { class: 'value ' + signClass(-s.payable_vat_minor) }, money(s.payable_vat_minor, c)), h('div', { class: 'sub' }, `Hesaplanan ${money(s.output_vat_minor, c)} − İndirilecek ${money(s.input_vat_minor, c)}`)))),
          table({ cols: [{ label: 'Yön', sortable: false, render: (x) => (x.side === 'output' ? 'Hesaplanan (satış)' : 'İndirilecek (alış/gider)') }, { label: 'Birim', sortable: false, render: (x) => x.currency }, { label: 'Oran', num: true, sortable: false, render: (x) => pct(x.rate_bp) }, { label: 'KDV hariç', num: true, sortable: false, render: (x) => money(x.net_minor, x.currency) }, { label: 'KDV', num: true, sortable: false, render: (x) => money(x.vat_minor, x.currency) }, { label: 'KDV dahil', num: true, sortable: false, render: (x) => money(x.gross_minor, x.currency) }, { label: 'Belge', num: true, sortable: false, render: (x) => x.docs }], rows: r.rows, empty: { icon: '🧮', title: 'KDV kaydı yok' } }));
      } else if (f.report.startsWith('aging')) {
        const kind = f.report.split('-')[1];
        const r = await api.get('/reports/aging', { kind, scope: f.scope });
        const bk = [['current', 'Vadesi gelmemiş'], ['d30', '1-30 gün'], ['d60', '31-60 gün'], ['d90', '61-90 gün'], ['d90p', '90+ gün']];
        mount(out, table({ cols: [{ key: 'contact', label: 'Cari' }, { key: 'currency', label: 'Birim' }, ...bk.map(([k, l]) => ({ key: k, label: l, num: true, render: (x) => (x[k] ? money(x[k], x.currency) : '—') })), { key: 'total_minor', label: 'Toplam', num: true, render: (x) => h('b', null, money(x.total_minor, x.currency)) }], rows: r.rows,
          empty: { icon: '⏳', title: 'Açık kalem yok' }, foot: () => Object.entries(r.totals).map(([c, t]) => h('tr', null, h('td', null, 'Toplam'), h('td', null, c), ...bk.map(([k]) => h('td', { class: 'num' }, money(t[k] || 0, c))), h('td', { class: 'num' }, money(t.total_minor, c)))) }));
      } else if (f.report === 'projects') {
        const r = await api.get('/reports/projects');
        mount(out, table({ cols: [{ key: 'name', label: 'Proje' }, { key: 'currency', label: 'Birim' }, { key: 'contract_minor', label: 'Sözleşme', num: true, render: (x) => money(x.contract_minor, x.currency) }, { key: 'revenue_minor', label: 'Gelir', num: true, render: (x) => money(x.revenue_minor, x.currency) }, { key: 'cost_minor', label: 'Maliyet', num: true, render: (x) => money(x.cost_minor, x.currency) }, { key: 'profit_minor', label: 'Kâr', num: true, render: (x) => h('b', { class: signClass(x.profit_minor) }, money(x.profit_minor, x.currency)) }, { key: 'cash_net_minor', label: 'Nakit net', num: true, render: (x) => money(x.cash_net_minor, x.currency) }, { key: 'pending_receivable_minor', label: 'Bekleyen alacak', num: true, render: (x) => money(x.pending_receivable_minor, x.currency) }], rows: r.projects, empty: { icon: '🏗️', title: 'Proje yok' } }));
      } else if (f.report === 'budget') {
        const r = await api.get('/reports/budget', { month: f.month, scope: f.scope });
        mount(out, readonly ? null : h('div', null, h('button', { class: 'btn primary', onclick: () => budgetForm() }, '＋ Bütçe belirle')),
          table({ cols: [{ key: 'category', label: 'Kategori' }, { key: 'currency', label: 'Birim' }, { key: 'budget_minor', label: 'Bütçe', num: true, render: (x) => money(x.budget_minor, x.currency) }, { key: 'actual_minor', label: 'Gerçekleşen', num: true, render: (x) => money(x.actual_minor, x.currency) }, { key: 'diff_minor', label: 'Kalan / (aşım)', num: true, render: (x) => h('b', { class: signClass(x.diff_minor) }, money(x.diff_minor, x.currency)) },
            { label: 'Kullanım', sortable: false, render: (x) => { const b = h('div', { class: 'bar' + (x.used_pct > 100 ? ' over' : '') }, h('i')); b.firstChild.style.width = Math.min(100, x.used_pct || 0) + '%'; return h('div', null, b, h('span', { class: 'xs muted' }, (x.used_pct ?? '—') + '%')); } }], rows: r.rows, empty: { icon: '🎯', title: 'Bu ay için bütçe yok', text: 'Kategori bazında aylık bütçe belirleyin.' } }));
      } else if (f.report === 'converted') {
        const r = await api.get('/reports/converted', { to: f.to_cur });
        mount(out, h('div', { class: 'alert info small' }, r.note), r.missing_rates.length ? h('div', { class: 'alert warn' }, `${r.missing_rates.join(', ')} için kur girilmemiş. Ayarlar › Kurlar bölümünden ekleyin veya TCMB'den çekin.`) : null,
          table({ cols: [{ key: 'currency', label: 'Birim' }, { label: 'Bakiye', num: true, sortable: false, render: (x) => money(x.amount_minor, x.currency) }, { label: 'Kur', num: true, sortable: false, render: (x) => x.rate }, { label: 'Kur tarihi', sortable: false, render: (x) => (x.rate_date ? date(x.rate_date) + ' (' + x.rate_source + ')' : '—') }, { label: `${f.to_cur} karşılığı`, num: true, sortable: false, render: (x) => money(x.converted_minor, f.to_cur) }], rows: r.rows, foot: () => h('tr', null, h('td', { colspan: 4 }, 'Toplam (' + f.to_cur + ')'), h('td', { class: 'num' }, money(r.total_minor, f.to_cur))), empty: { title: 'Hesap yok' } }));
      }
    } catch (e) { mount(out, h('div', { class: 'alert err' }, e.message)); }
  }
  function budgetForm() {
    formModal({ title: 'Aylık bütçe', note: f.month + ' ayı için. 0 girerseniz bütçe kaldırılır.', fields: [
      { name: 'category_id', label: 'Gider kategorisi', type: 'select', options: catOptions('expense', 'Seçin'), required: true }, { name: 'currency', label: 'Birim', type: 'select', options: CURRENCIES.map((c) => [c, c]) }, { name: 'amount_minor', label: 'Bütçe tutarı', type: 'money', required: true }],
      onSubmit: async (v) => { await api.put('/budgets', { month: f.month, category_id: Number(v.category_id), currency: v.currency, amount_minor: v.amount_minor }); toast('Bütçe kaydedildi', 'ok'); load(); } });
  }
  head(); load();
}
