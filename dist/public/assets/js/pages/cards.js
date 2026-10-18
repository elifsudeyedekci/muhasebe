import { api } from '../api.js';
import { state } from '../state.js';
import { h, mount, loading, money, date, table, errToast, toast, confirmDialog, tabs, modal, stateBadge, signClass, pct } from '../ui.js';
import { cardForm, cardPurchaseForm, cardPayForm, loanForm, loanPayForm, disburseForm } from '../forms_more.js';
import { loadMeta } from '../state.js';

export default async function render(ctx) {
  const { el, readonly } = ctx;
  let tab = ctx.query.tab || 'cards';
  const body = h('div', { class: 'stack' });
  const draw = () => mount(el, ctx.head('Kartlar & Krediler', 'Kart ekstre ödemesi ikinci gider yazmaz; kredi anaparası gider sayılmaz, yalnızca faiz gider olur.', readonly ? null : [tab === 'cards' ? h('button', { class: 'btn primary', onclick: () => cardForm(load) }, '＋ Kart') : h('button', { class: 'btn primary', onclick: () => loanForm(load) }, '＋ Kredi')]),
    tabs([['cards', 'Kredi kartları'], ['loans', 'Banka kredileri']], tab, (k) => { tab = k; draw(); load(); }), body);

  async function load() {
    mount(body, loading());
    try {
      if (tab === 'cards') {
        const r = await api.get('/cards');
        mount(body, r.cards.length ? h('div', { class: 'auto-grid' }, r.cards.map((c) => {
          const used = c.limit_minor > 0 ? Math.min(100, Math.round((c.debt_minor * 100) / c.limit_minor)) : 0;
          const bar = h('div', { class: 'bar' + (used > 90 ? ' over' : '') }, h('i'));
          bar.firstChild.style.width = used + '%';
          return h('div', { class: 'card stack' }, h('div', { class: 'card-title' }, h('span', null, '💳 ' + c.name, c.archived ? h('span', { class: 'badge' }, 'Arşiv') : null), h('span', { class: 'xs muted' }, c.workspace_name)),
            h('div', { class: 'stat' }, h('div', { class: 'label' }, 'Güncel borç'), h('div', { class: 'value neg' }, money(c.debt_minor, c.currency))), bar,
            h('div', { class: 'small muted' }, `Limit ${money(c.limit_minor, c.currency)} · Kullanılabilir ${money(c.available_minor, c.currency)}`),
            h('div', { class: 'small' }, c.next_due ? `Sıradaki son ödeme: ${date(c.next_due.due_date)} — ${money(c.next_due.remaining_minor, c.currency)}` : 'Ekstre borcu yok'),
            h('div', { class: 'row tight' }, h('button', { class: 'btn sm primary', onclick: () => cardDetail(c) }, 'Ayrıntı'), readonly ? null : h('button', { class: 'btn sm', onclick: () => cardPurchaseForm(c, load) }, '＋ Alışveriş'), readonly || c.debt_minor <= 0 ? null : h('button', { class: 'btn sm', onclick: () => cardPayForm(c, c.debt_minor, load) }, 'Öde')));
        })) : h('div', { class: 'empty card flat' }, h('div', { class: 'big' }, '💳'), h('h3', null, 'Kredi kartı tanımlı değil'), h('p', null, 'Kart limitini, hesap kesim ve son ödeme günlerini girin; taksitli alışverişler ekstre takvimine yansır.'), readonly ? null : h('button', { class: 'btn primary', onclick: () => cardForm(load) }, '＋ Kart ekle')));
      } else {
        const r = await api.get('/loans');
        mount(body, r.loans.length ? h('div', { class: 'auto-grid' }, r.loans.map((l) => h('div', { class: 'card stack' },
          h('div', { class: 'card-title' }, '🏦 ' + l.name, h('span', { class: 'badge ' + (l.status === 'active' ? 'info' : '') }, l.status === 'active' ? 'Aktif' : 'Kapalı')),
          h('div', { class: 'stat' }, h('div', { class: 'label' }, 'Kalan anapara'), h('div', { class: 'value neg' }, money(l.remaining_principal_minor, l.currency))),
          h('div', { class: 'small muted' }, `Kredi ${money(l.principal_minor, l.currency)} · ${l.paid_count}/${l.term_months} taksit ödendi`),
          h('div', { class: 'small' }, l.next ? `Sıradaki: ${date(l.next.due_date)} — ${money(Number(l.next.principal_minor) + Number(l.next.interest_minor), l.currency)}` : 'Tüm taksitler ödendi'),
          h('div', { class: 'row tight' }, h('button', { class: 'btn sm primary', onclick: () => loanDetail(l) }, 'Taksit planı'))))) : h('div', { class: 'empty card flat' }, h('div', { class: 'big' }, '🏦'), h('h3', null, 'Kredi kaydı yok'), h('p', null, 'Kredi tutarı, aylık faiz ve vade ile taksit planı oluşturun; taksitler ödeme takvimine girer.'), readonly ? null : h('button', { class: 'btn primary', onclick: () => loanForm(load) }, '＋ Kredi ekle')));
      }
    } catch (e) { mount(body, h('div', { class: 'alert err' }, e.message)); }
  }

  async function cardDetail(c) {
    const box = h('div', null, loading());
    modal({ title: c.name, wide: true, body: box });
    const redraw = async () => {
      try {
        const r = await api.get('/cards/' + c.id);
        mount(box, h('div', { class: 'stack' },
          h('h3', null, 'Ekstre dönemleri'),
          table({ cols: [{ label: 'Son ödeme', sortable: false, render: (s) => date(s.due_date) }, { label: 'Hesap kesim', sortable: false, render: (s) => date(s.closing_date) }, { label: 'Tutar', num: true, sortable: false, render: (s) => money(s.amount_minor, c.currency) },
            { label: 'Ödenen', num: true, sortable: false, render: (s) => money(s.paid_minor, c.currency) }, { label: 'Kalan', num: true, sortable: false, render: (s) => money(s.remaining_minor, c.currency) }, { label: 'Durum', sortable: false, render: (s) => stateBadge(s.state) }],
            rows: r.statements, empty: { title: 'Ekstre yok', text: 'Kartla alışveriş kaydedildiğinde taksitler burada görünür.' } }),
          h('h3', null, 'Alışverişler'),
          table({ cols: [{ label: 'Tarih', sortable: false, render: (p) => date(p.txn_date) }, { label: 'Açıklama', sortable: false, render: (p) => p.description }, { label: 'Taksit', sortable: false, render: (p) => p.installments + 'x' }, { label: 'Tutar', num: true, sortable: false, render: (p) => money(p.amount_minor, c.currency) },
            { label: '', act: true, sortable: false, render: (p) => (readonly || p.status === 'voided' ? (p.status === 'voided' ? h('span', { class: 'badge' }, 'İptal') : null) : h('button', { class: 'btn sm ghost', onclick: async () => { if (!await confirmDialog({ title: 'Alışverişi iptal et', text: 'Kayıt silinmez, iptal edilmiş olarak kalır.', ok: 'İptal et', danger: true })) return; try { await api.post(`/card-purchases/${p.id}/void`); toast('İptal edildi', 'ok'); await loadMeta(); redraw(); load(); } catch (e) { errToast(e); } } }, 'İptal')) }],
            rows: r.purchases, rowClass: (p) => (p.status === 'voided' ? 'voided' : ''), empty: { title: 'Alışveriş yok' } }),
          h('div', { class: 'muted small' }, 'Kredi kartı borcu: ' + money(r.debt_minor, c.currency) + (r.credit_minor ? ` (fazla ödeme ${money(r.credit_minor, c.currency)})` : ''))));
      } catch (e) { mount(box, h('div', { class: 'alert err' }, e.message)); }
    };
    redraw();
  }

  async function loanDetail(l) {
    const box = h('div', null, loading());
    modal({ title: l.name + ' — taksit planı', wide: true, body: box });
    const redraw = async () => {
      try {
        const r = await api.get('/loans/' + l.id);
        const again = () => { redraw(); load(); };
        mount(box, h('div', { class: 'stack' },
          !r.disbursed && !readonly ? h('div', { class: 'alert info row between' }, 'Kredi henüz hesaba girmedi olarak görünüyor.', h('button', { class: 'btn sm primary', onclick: () => disburseForm(l, again) }, 'Kullandırımı kaydet')) : null,
          h('div', { class: 'small muted' }, `Toplam anapara ${money(r.totals.principal, l.currency)} · toplam faiz ${money(r.totals.interest, l.currency)}`),
          table({ cols: [{ label: '#', sortable: false, render: (i) => i.seq }, { label: 'Vade', sortable: false, render: (i) => date(i.due_date) }, { label: 'Anapara', num: true, sortable: false, render: (i) => money(i.principal_minor, l.currency) }, { label: 'Faiz', num: true, sortable: false, render: (i) => money(i.interest_minor, l.currency) },
            { label: 'Taksit', num: true, sortable: false, render: (i) => h('b', null, money(Number(i.principal_minor) + Number(i.interest_minor), l.currency)) },
            { label: 'Durum', sortable: false, render: (i) => (i.txn_id ? h('span', { class: 'badge ok' }, 'Ödendi ' + date(i.paid_date)) : stateBadge(i.due_date < new Date().toISOString().slice(0, 10) ? 'overdue' : 'open')) },
            { label: '', act: true, sortable: false, render: (i) => (readonly || i.txn_id ? null : h('button', { class: 'btn sm primary', onclick: () => loanPayForm(l, i, again) }, 'Öde')) }], rows: r.installments })));
      } catch (e) { mount(box, h('div', { class: 'alert err' }, e.message)); }
    };
    redraw();
  }
  draw(); load();
}
