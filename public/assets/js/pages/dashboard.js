import { api } from '../api.js';
import { state } from '../state.js';
import { h, mount, loading, money, date, emptyState, KIND_TR, todayStr, formModal, toast, errToast } from '../ui.js';
import { accountForm, workspaceForm, entryForm, settleForm } from '../forms_core.js';
import { loadMeta } from '../state.js';

const STATE_UI = {
  overdue: ['bad', 'Gecikmiş'], today: ['warn', 'Bugün'], upcoming: ['info', 'Yaklaşan'], undated: ['', 'Tarih girilmemiş'],
};

/** Planlanan ödeme/tahsilat tarihi (fatura vadesinden ayrı; boş = tarih girilmemiş). */
export function plannedDateForm(e, cb) {
  formModal({
    title: 'Planlanan tarih', note: 'Bu tarih fatura vadesini değiştirmez. Boş bırakırsanız “tarih girilmemiş” olarak kalır; sistem tarih üretmez.',
    fields: [{ name: 'planned_date', label: e.kind === 'receivable' ? 'Planlanan tahsilat tarihi' : 'Planlanan ödeme tarihi', type: 'date', value: e.planned_date || '' }],
    onSubmit: async (v) => { await api.patch(`/entries/${e.id}`, { planned_date: v.planned_date || null }); toast('Planlanan tarih kaydedildi', 'ok'); cb && cb(); },
  });
}

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
  const d = await api.get('/home');
  const sums = (obj) => { const k = Object.keys(obj || {}).filter((c) => obj[c]); return k.length ? k.map((c) => h('div', null, money(obj[c], c))) : [h('div', { class: 'muted' }, money(0))]; };
  const tt = d.today_summary;

  // ---- üst kartlar ----
  const accList = h('div', { class: 'list hide' }, d.cash.accounts.map((a) => h('div', { class: 'list-item' }, h('div', { class: 'grow' }, (a.kind === 'cash' ? '💵 ' : '🏦 ') + a.name, h('div', { class: 'xs muted' }, a.workspace_name)), h('div', { class: 'num' }, money(a.balance_minor, a.currency)))));
  const toggle = h('button', { class: 'btn sm ghost', type: 'button', onclick: () => { accList.classList.toggle('hide'); toggle.textContent = accList.classList.contains('hide') ? 'Hesapları göster' : 'Gizle'; } }, 'Hesapları göster');
  const advText = (obj, label) => { const k = Object.keys(obj || {}); return k.length ? h('div', { class: 'xs muted' }, label + ': ' + k.map((c) => money(obj[c], c)).join(' · ')) : null; };
  const cards = h('div', { class: 'home-cards' },
    h('div', { class: 'card stat stack tight' }, h('div', { class: 'label' }, 'Kasa + banka'), h('div', { class: 'value' }, ...sums(d.cash.totals)), h('div', { class: 'xs muted' }, 'Yalnızca işletme hesapları (şahsi hariç)'),
      d.cash.accounts.length ? [toggle, accList] : h('a', { href: '#', onclick: (e) => { e.preventDefault(); accountForm(null, ctx.refresh); } }, 'Hesap ekleyin →')),
    h('div', { class: 'card stat stack tight' }, h('div', { class: 'label' }, 'Toplam alacak'), h('div', { class: 'value pos' }, ...sums(d.receivable.total)), h('div', { class: 'xs muted' }, d.receivable.count + ' açık kalem · kalan tutar'), advText(d.advances.in, 'Alınan avans (ayrı)')),
    h('div', { class: 'card stat stack tight' }, h('div', { class: 'label' }, 'Toplam borç'), h('div', { class: 'value neg' }, ...sums(d.payable.total)), h('div', { class: 'xs muted' }, d.payable.count + ' açık kalem · kalan tutar'), advText(d.advances.out, 'Verilen avans (ayrı)')),
    h('div', { class: 'card stat stack tight' }, h('div', { class: 'label' }, 'Bugünkü işlemler'),
      h('div', { class: 'value' }, `${tt.payable_count} ödeme · ${tt.receivable_count} tahsilat`),
      h('div', { class: 'xs muted' }, [tt.payable_count ? 'Ödenecek ' + Object.entries(tt.payable_sum).map(([c, v]) => money(v, c)).join(' · ') : null, tt.receivable_count ? 'Tahsil edilecek ' + Object.entries(tt.receivable_sum).map(([c, v]) => money(v, c)).join(' · ') : null].filter(Boolean).join(' — ') || 'Bugüne planlı işlem yok'),
      (tt.overdue_payable || tt.overdue_receivable) ? h('div', { class: 'xs neg' }, `Gecikmiş: ${tt.overdue_payable} ödeme · ${tt.overdue_receivable} tahsilat`) : null));

  // ---- bekleyenler ----
  const openRef = (x) => (x.invoice_id ? ctx.go('/invoices?open=' + x.invoice_id + '&tab=' + (x.kind === 'receivable' ? 'sales' : 'purchase')) : ctx.go('/plan?tab=' + (x.kind === 'receivable' ? 'receivable' : 'payable')));
  const row = (x, kind) => {
    const [cls, label] = STATE_UI[x.state];
    const item = { ...x, kind };
    const dates = h('div', { class: 'xs muted' },
      x.planned_date ? `Planlanan ${date(x.planned_date)}` : 'Planlanan: tarih girilmemiş', ' · ', x.due_date ? `Vade ${date(x.due_date)}` : 'Vadesiz',
      x.days !== null && x.state === 'overdue' ? ` · ${-x.days} gün gecikmiş` : '');
    return h('div', { class: 'list-item wrap' },
      h('div', { class: 'grow' },
        h('a', { href: '#', onclick: (e) => { e.preventDefault(); openRef(item); } }, h('b', null, x.party)),
        h('div', { class: 'xs' }, x.invoice_number ? 'Fatura ' + x.invoice_number : x.title), dates),
      h('div', { class: 'right' }, h('div', { class: 'num ' + (kind === 'receivable' ? 'pos' : 'neg') }, money(x.remaining_minor, x.currency)),
        h('div', { class: 'row tight' }, h('span', { class: 'badge ' + cls }, label), x.partial ? h('span', { class: 'badge warn', title: `Toplam ${money(x.amount_minor, x.currency)}` }, kind === 'receivable' ? 'Kısmen tahsil' : 'Kısmen ödendi') : null),
        readonly ? null : h('div', { class: 'row tight' },
          h('button', { class: 'btn sm primary', onclick: () => settleForm({ id: x.id, kind, title: x.title, contact_id: x.contact_id, currency: x.currency, remaining_minor: x.remaining_minor, paid_minor: x.paid_minor, amount_minor: x.amount_minor, workspace_id: null }, ctx.refresh) }, kind === 'receivable' ? 'Tahsil et' : 'Öde'),
          h('button', { class: 'btn sm ghost', onclick: () => plannedDateForm({ id: x.id, kind, planned_date: x.planned_date }, ctx.refresh) }, '📅'))));
  };
  const panel = (title, kind, data, allLabel, allHref) => h('div', { class: 'card stack' },
    h('div', { class: 'card-title' }, title, h('a', { class: 'small', href: allHref }, allLabel + ' →')),
    data.items.length ? h('div', { class: 'list' }, data.items.map((x) => row(x, kind))) : h('div', { class: 'muted small' }, kind === 'receivable' ? 'Bekleyen tahsilat yok 🎉' : 'Bekleyen ödeme yok 🎉'),
    data.count > data.items.length ? h('div', { class: 'xs muted' }, `+${data.count - data.items.length} kayıt daha — tümü için bağlantıyı kullanın`) : null);

  // ---- son hareketler ----
  const recent = h('div', { class: 'card stack' }, h('div', { class: 'card-title' }, 'Son finansal hareketler', h('a', { class: 'small', href: ctx.href('transactions') }, 'Tümünü ara / gör →')),
    d.recent.length ? h('div', { class: 'list' }, d.recent.map((t) => h('div', { class: 'list-item' },
      h('div', { class: 'grow' }, h('div', null, KIND_TR[t.kind] || t.kind, t.contact_name ? ' · ' + t.contact_name : ''), h('div', { class: 'xs muted' }, date(t.txn_date) + ' · ' + (t.wkind === 'personal' ? 'Şahsi › ' : '') + t.account_name + (t.reason || t.description ? ' · ' + (t.reason || t.description) : ''))),
      h('div', { class: 'num ' + (t.direction === 'in' ? 'pos' : 'neg') }, money((t.direction === 'in' ? 1 : -1) * t.amount_minor, t.currency, { sign: true }))))) : h('div', { class: 'muted small' }, 'Henüz hareket yok.'));

  mount(el,
    ctx.head('Ana Ekran', 'İşletmenizin güncel finansal durumu. Ayrıntılar ilgili sayfalarda.', readonly ? null : [h('button', { class: 'btn', onclick: () => accountForm(null, ctx.refresh) }, '＋ Hesap'), h('button', { class: 'btn primary', onclick: () => entryForm({ kind: 'payable' }, ctx.refresh) }, '＋ Borç / Plan')]),
    !d.has_accounts ? h('div', { class: 'alert warn' }, 'Henüz işletme banka hesabı veya kasası yok. ', readonly ? null : h('a', { href: '#', onclick: (e) => { e.preventDefault(); accountForm(null, ctx.refresh); } }, 'İlk hesabı ekleyin →')) : null,
    cards,
    h('div', { class: 'home-split' }, panel('📤 Ödeme Bekleyenler', 'payable', d.payable, 'Tüm ödemeleri gör', ctx.href('plan') + '?tab=payable'), panel('📥 Tahsilat Yapılacaklar', 'receivable', d.receivable, 'Tüm tahsilatları gör', ctx.href('plan') + '?tab=receivable')),
    recent);
}
