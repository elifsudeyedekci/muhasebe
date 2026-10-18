import { api } from '../api.js';
import { state, scopeParams } from '../state.js';
import { h, mount, loading, money, multi, date, stateBadge, barChart, legend, emptyState, signClass, monthLabel, errToast, seg, todayStr, addDays } from '../ui.js';
import { settleForm, accountForm, workspaceForm, entryForm } from '../forms_core.js';

export default async function render(ctx) {
  const { el, readonly } = ctx;
  const meta = state.meta;
  if (!meta.workspaces.length) {
    mount(el, ctx.head('Hoş geldiniz 👋', 'Birkaç adımda kendi muhasebenizi kurun.'),
      h('div', { class: 'card stack' },
        h('h2', null, 'Başlangıç'),
        h('ol', { class: 'stack tight' },
          h('li', null, h('b', null, 'İşletme / alan ekleyin'), ' — şirketiniz, markanız veya “Şahsi” alanınız. Dilediğiniz kadar ekleyebilirsiniz.'),
          h('li', null, h('b', null, 'Banka hesabı veya kasa ekleyin'), ' — TL, EUR, USD; açılış bakiyesiyle.'),
          h('li', null, h('b', null, 'Gelir, gider, alacak, borç ve faturalarınızı girin'), ' — raporlar otomatik oluşur.')),
        readonly ? h('div', { class: 'alert info' }, 'Müşteri henüz alan oluşturmamış.') : h('div', { class: 'row' }, h('button', { class: 'btn primary', onclick: () => workspaceForm(null, ctx.refresh) }, '＋ İlk alanı oluştur'))));
    return;
  }
  mount(el, loading());
  const d = await api.get('/reports/dashboard', scopeParams());
  const scopeLabel = { all: 'tüm alanlar', business: 'şirketler', personal: 'şahsi alan', ws: 'seçili alan' }[state.scope.kind];
  const cur = Object.keys(d.balances);
  const act = (e) => (readonly ? null : h('button', { class: 'btn sm', onclick: () => settleForm(e, ctx.refresh) }, e.kind === 'receivable' ? 'Tahsil et' : 'Öde'));
  const itemList = (title, items, empty, hrefKey) => h('div', { class: 'card' }, h('div', { class: 'card-title' }, title, h('a', { href: ctx.href('plan'), class: 'small' }, 'Tümü →')),
    items.length ? h('div', { class: 'list' }, items.map((e) => h('div', { class: 'list-item' },
      h('div', { class: 'grow' }, h('div', null, e.title), h('div', { class: 'xs muted' }, 'Vade ' + date(e.due_date) + (e.days_overdue ? ` · ${e.days_overdue} gün gecikmiş` : ''))),
      h('div', { class: 'num ' + (e.kind === 'receivable' ? 'pos' : 'neg') }, money(e.remaining_minor, e.currency)), act(e)))) : h('div', { class: 'muted small' }, empty));

  const periodRow = (label, p) => {
    const keys = Object.keys(p || {});
    return h('div', { class: 'list-item' }, h('div', { class: 'grow' }, label),
      h('div', { class: 'num' }, keys.length ? keys.map((c) => h('div', null, h('span', { class: 'pos' }, '+' + money(p[c].income_minor || 0, c)), ' ', h('span', { class: 'neg' }, '−' + money(p[c].expense_minor || 0, c)))) : h('span', { class: 'muted' }, '—')));
  };
  // nakit akışı grafiği (ana para birimi seçilebilir)
  const cfBox = h('div', { class: 'stack' });
  const drawCf = (c) => {
    const rows = d.cashflow.realized.filter((r) => r.currency === c);
    const months = [...new Set(d.cashflow.realized.map((r) => r.month))].sort();
    const by = (m) => rows.find((r) => r.month === m) || { cash_in_minor: 0, cash_out_minor: 0 };
    mount(cfBox, months.length ? h('div', null, barChart({ labels: months.map((m) => monthLabel(m).slice(0, 3)), series: [{ name: 'Giriş', cls: 'b-in', values: months.map((m) => by(m).cash_in_minor) }, { name: 'Çıkış', cls: 'b-out', values: months.map((m) => by(m).cash_out_minor) }] }), legend([['b-in', 'Gerçekleşen giriş'], ['b-out', 'Gerçekleşen çıkış']])) : h('div', { class: 'muted small' }, 'Henüz hareket yok.'));
  };
  const cfCur = cur[0] || 'TRY';
  drawCf(cfCur);

  const balCards = cur.length ? cur.map((c) => h('div', { class: 'stat' }, h('div', { class: 'label' }, 'Toplam bakiye · ' + c), h('div', { class: 'value ' + signClass(d.balances[c].balance_minor) }, money(d.balances[c].balance_minor, c)))) : [h('div', { class: 'muted' }, 'Henüz hesap yok')];
  const ob = d.obligations;
  const obRow = (label, key, cls) => h('div', { class: 'list-item' }, h('div', { class: 'grow' }, label), h('div', { class: 'num ' + cls }, Object.keys(ob).filter((c) => ob[c][key]).map((c) => h('div', null, money(ob[c][key], c))).concat(Object.keys(ob).some((c) => ob[c][key]) ? [] : [h('span', { class: 'muted' }, '—')])));

  mount(el,
    ctx.head('Ana Ekran', `Görünüm: ${scopeLabel}. ${d.note}`, readonly ? null : [h('button', { class: 'btn', onclick: () => accountForm(null, ctx.refresh) }, '＋ Hesap'), h('button', { class: 'btn primary', onclick: () => entryForm({ kind: 'payable' }, ctx.refresh) }, '＋ Borç / Plan')]),
    !meta.accounts.length ? h('div', { class: 'alert warn' }, 'Henüz banka hesabı veya kasa yok. ', readonly ? null : h('a', { href: '#', onclick: (e) => { e.preventDefault(); accountForm(null, ctx.refresh); } }, 'İlk hesabı ekleyin →')) : null,
    h('div', { class: 'auto-grid' },
      h('div', { class: 'card stack' }, h('div', { class: 'card-title' }, 'Eldeki para (gerçekleşmiş)', h('a', { class: 'small', href: ctx.href('accounts') }, 'Hesaplar →')), ...balCards,
        h('div', { class: 'xs muted' }, 'Banka + kasa bakiyeleri; planlanan tahsilatlar dahil değildir. Para birimleri toplanmaz.')),
      h('div', { class: 'card' }, h('div', { class: 'card-title' }, 'Gelir – Gider (gerçekleşen)', h('span', { class: 'xs muted' }, 'şirketler')), h('div', { class: 'list' }, periodRow('Bugün', d.period.today), periodRow('Son 7 gün', d.period.week), periodRow('Bu ay', d.period.month), periodRow('Planlanan (ileri tarihli)', d.period.planned))),
      h('div', { class: 'card' }, h('div', { class: 'card-title' }, 'Alacak & borç durumu'), h('div', { class: 'list' },
        obRow('Açık alacaklar (müşteri)', 'receivable', 'pos'), obRow('Vadesi geçmiş alacak', 'overdue_receivable', 'warn-t'), obRow('Açık borçlar (tedarikçi)', 'payable', 'neg'), obRow('Vadesi geçmiş borç', 'overdue_payable', 'neg'), obRow('30 gün içinde ödenecek', 'due30_payable', '')))),
    h('div', { class: 'card', style: null }, h('div', { class: 'card-title' }, 'Aylık nakit akışı (gerçekleşen)', cur.length > 1 ? seg(cur.map((c) => [c, c]), cfCur, drawCf) : null), cfBox),
    h('div', { class: 'auto-grid' },
      itemList('⚠️ Gecikmiş borçlar', d.overdue_payables, 'Gecikmiş borç yok 🎉'),
      itemList('📤 Yaklaşan ödemeler (14 gün)', d.upcoming_payables, 'Önümüzdeki 14 günde ödeme yok.'),
      itemList('📥 Beklenen tahsilatlar (30 gün)', d.expected_receivables, 'Beklenen tahsilat yok.'),
      itemList('🏡 Şahsi fatura hatırlatmaları', d.personal_bills, 'Yaklaşan şahsi fatura yok.'),
      h('div', { class: 'card' }, h('div', { class: 'card-title' }, '🧾 Kesilmesi gereken faturalar', h('a', { href: ctx.href('invoices'), class: 'small' }, 'Faturalar →')),
        d.invoices_to_issue.length ? h('div', { class: 'list' }, d.invoices_to_issue.map((i) => h('div', { class: 'list-item' }, h('div', { class: 'grow' }, i.contact_name, h('div', { class: 'xs muted' }, date(i.issue_date))), h('div', { class: 'num' }, money(i.total_minor, i.currency))))) : h('div', { class: 'muted small' }, 'Planlanmış fatura yok.')),
      h('div', { class: 'card' }, h('div', { class: 'card-title' }, '💳 Kartlar', h('a', { href: ctx.href('cards'), class: 'small' }, 'Kartlar & krediler →')),
        d.cards.length ? h('div', { class: 'list' }, d.cards.map((c) => h('div', { class: 'list-item' }, h('div', { class: 'grow' }, c.name, h('div', { class: 'xs muted' }, c.next_due ? `Son ödeme ${date(c.next_due.due_date)} · ${money(c.next_due.remaining_minor, c.currency)}` : 'Ekstre borcu yok')), h('div', { class: 'num neg' }, money(c.debt_minor, c.currency))))) : h('div', { class: 'muted small' }, 'Kart tanımlı değil.')),
      h('div', { class: 'card' }, h('div', { class: 'card-title' }, '📅 Önümüzdeki 30 gün', h('a', { href: ctx.href('plan') + '', class: 'small' }, 'Takvim →')),
        d.schedule.length ? h('div', { class: 'list' }, d.schedule.map((s) => h('div', { class: 'list-item' }, h('span', { class: 'dot ' + (s.days_overdue ? 'danger' : s.direction === 'in' ? 'ok' : 'warn') }), h('div', { class: 'grow' }, s.title, h('div', { class: 'xs muted' }, date(s.date))), h('div', { class: 'num ' + (s.direction === 'in' ? 'pos' : 'neg') }, money(s.remaining_minor, s.currency))))) : h('div', { class: 'muted small' }, 'Planlı ödeme/tahsilat yok.')),
      h('div', { class: 'card' }, h('div', { class: 'card-title' }, '🏗️ Proje kârlılığı', h('a', { href: ctx.href('projects'), class: 'small' }, 'Projeler →')),
        d.projects.length ? h('div', { class: 'list' }, d.projects.map((p) => h('div', { class: 'list-item' }, h('div', { class: 'grow' }, p.name, h('div', { class: 'xs muted' }, `Gelir ${money(p.revenue_minor, p.currency)} · Gider ${money(p.cost_minor, p.currency)}`)), h('div', { class: 'num ' + signClass(p.profit_minor) }, money(p.profit_minor, p.currency))))) : h('div', { class: 'muted small' }, 'Henüz proje yok.'))),
    h('div', { class: 'card' }, h('div', { class: 'card-title' }, 'Hesaplar'), d.accounts.length ? h('div', { class: 'list' }, d.accounts.map((a) => h('div', { class: 'list-item' }, h('div', { class: 'grow' }, a.name, h('div', { class: 'xs muted' }, a.workspace_name + (a.bank_name ? ' · ' + a.bank_name : ''))), h('div', { class: 'num ' + signClass(a.balance_minor) }, money(a.balance_minor, a.currency))))) : h('div', { class: 'muted small' }, 'Bu görünümde hesap yok.')));
}
