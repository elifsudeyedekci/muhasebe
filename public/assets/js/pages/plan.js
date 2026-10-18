import { api } from '../api.js';
import { state, scopeParams, loadMeta } from '../state.js';
import { h, mount, loading, money, multi, date, table, errToast, toast, confirmDialog, stateBadge, tabs, seg, modal, todayStr, addDays, MONTHS, monthLabel, debounce, formModal, CURRENCIES, menu, signClass } from '../ui.js';
import { settleForm, rescheduleForm, entryForm, docsModal } from '../forms_core.js';
import { wsOptions, contactOptions, catOptions } from '../state.js';

export default async function render(ctx) {
  const { el, readonly } = ctx;
  let tab = ctx.query.tab || 'receivable';
  const f = { window: '', status: 'unpaid', q: '' };
  const body = h('div', { class: 'stack' });
  const draw = () => {
    mount(el, ctx.head('Alacak, Borç & Ödeme Takvimi', 'Planlanan tahsilat ve ödemeler. Plan bankaya işlenene kadar bakiyeyi değiştirmez; yalnızca kalan tutar görünür.', readonly ? null : [
      h('button', { class: 'btn', onclick: () => entryForm({ kind: 'receivable' }, load) }, '＋ Alacak'), h('button', { class: 'btn primary', onclick: () => entryForm({ kind: 'payable' }, load) }, '＋ Borç')]),
      tabs([['receivable', 'Alacaklar'], ['payable', 'Borçlar'], ['calendar', 'Takvim'], ['recurring', 'Tekrarlayanlar']], tab, (k) => { tab = k; draw(); load(); }), body);
  };
  const entryTable = (r) => {
    const rec = tab === 'receivable';
    return table({
      cols: [
        { key: 'title', label: rec ? 'Alacak' : 'Borç', render: (e) => h('div', null, h('b', null, e.title), h('div', { class: 'xs muted' }, [e.contact_name, e.workspace_name, e.project_name].filter(Boolean).join(' · ')), e.seq ? h('span', { class: 'badge' }, `${e.seq}/${e.seq_total}`) : null) },
        { key: 'due_date', label: 'Vade', render: (e) => h('div', null, date(e.due_date), e.rescheduled ? h('div', { class: 'xs muted' }, 'ilk vade ' + date(e.original_due_date)) : null, e.days_overdue ? h('div', { class: 'xs neg' }, e.days_overdue + ' gün gecikmiş') : null) },
        { key: 'amount_minor', label: 'Tutar', num: true, render: (e) => money(e.amount_minor, e.currency) },
        { key: 'paid_minor', label: rec ? 'Tahsil edilen' : 'Ödenen', num: true, render: (e) => money(e.paid_minor, e.currency) },
        { key: 'remaining_minor', label: 'Kalan', num: true, render: (e) => h('b', { class: rec ? 'pos' : 'neg' }, money(e.remaining_minor, e.currency)) },
        { key: 'state', label: 'Durum', render: (e) => stateBadge(e.state) },
        { label: '', act: true, sortable: false, render: (e) => h('div', { class: 'row tight' },
          !readonly && e.state !== 'paid' && e.state !== 'cancelled' ? h('button', { class: 'btn sm primary', onclick: () => settleForm(e, load) }, rec ? 'Tahsil et' : 'Öde') : null,
          h('button', { class: 'btn sm ghost', onclick: (ev) => menu(ev.currentTarget, [
            { label: '📎 Belgeler', run: () => docsModal('Belgeler', 'entry', e.id, readonly) },
            !readonly && e.state !== 'paid' && e.state !== 'cancelled' ? { label: '📅 Vadeyi ertele', run: () => rescheduleForm(e, load) } : null,
            !readonly && e.source !== 'invoice' && e.state !== 'cancelled' && e.paid_minor === 0 ? { label: '🚫 İptal et', run: async () => { const reason = await confirmDialog({ title: 'Kalemi iptal et', text: 'Kalem silinmez, iptal edilmiş olarak saklanır.', input: 'Gerekçe', ok: 'İptal et', danger: true }); if (!reason) return; try { await api.post(`/entries/${e.id}/cancel`, { reason }); toast('İptal edildi', 'ok'); load(); } catch (er) { errToast(er); } } } : null].filter(Boolean)) }, '⋯')) },
      ], rows: r.entries, empty: { icon: rec ? '📥' : '📤', title: rec ? 'Açık alacak yok' : 'Açık borç yok', text: 'Filtreleri değiştirin ya da yeni kayıt ekleyin.' },
      foot: () => h('tr', null, h('td', { colspan: 4 }, 'Kalan toplam'), h('td', { class: 'num' }, multi(Object.fromEntries(Object.entries(r.sums).map(([c, v]) => [c, v[tab] || 0])))), h('td', { colspan: 2 })),
    });
  };
  const filters = () => h('div', { class: 'filters' },
    h('div', { class: 'field' }, h('label', null, 'Vade'), seg([['', 'Tümü'], ['today', 'Bugün'], ['7', '7 gün'], ['30', '30 gün'], ['overdue', 'Gecikmiş']], f.window, (v) => { f.window = v; load(); })),
    h('div', { class: 'field' }, h('label', null, 'Durum'), h('select', { class: 'input', onchange: (e) => { f.status = e.target.value; load(); } }, [['unpaid', 'Açık (kalanı olan)'], ['paid', 'Tamamen ödenmiş'], ['cancelled', 'İptal'], ['all', 'Hepsi']].map(([v, l]) => h('option', { value: v, selected: v === f.status }, l)))),
    h('div', { class: 'field' }, h('label', null, 'Ara'), h('input', { class: 'input', type: 'search', oninput: debounce((e) => { f.q = e.target.value; load(); }) })));

  async function load() {
    mount(body, loading());
    try {
      if (tab === 'receivable' || tab === 'payable') {
        const r = await api.get('/entries', { kind: tab, ...f, ...scopeParams() });
        mount(body, filters(), entryTable(r));
      } else if (tab === 'calendar') await calendar();
      else await recurring();
    } catch (e) { mount(body, h('div', { class: 'alert err' }, e.message)); }
  }

  let cal = { y: Number(todayStr().slice(0, 4)), m: Number(todayStr().slice(5, 7)) };
  async function calendar() {
    const first = `${cal.y}-${String(cal.m).padStart(2, '0')}-01`;
    const last = new Date(Date.UTC(cal.y, cal.m, 0)).getUTCDate();
    const to = `${cal.y}-${String(cal.m).padStart(2, '0')}-${String(last).padStart(2, '0')}`;
    const r = await api.get('/reports/schedule', { from: first, to, ...scopeParams() });
    const late = await api.get('/reports/schedule', { from: '2000-01-01', to: addDays(todayStr(), -1), ...scopeParams() });
    const byDay = {}; r.items.forEach((i) => { (byDay[i.date] ||= []).push(i); });
    const lead = (new Date(Date.UTC(cal.y, cal.m - 1, 1)).getUTCDay() + 6) % 7;
    const cells = [];
    for (let i = 0; i < lead; i++) cells.push(h('div', { class: 'day dim' }));
    for (let d = 1; d <= last; d++) {
      const ds = `${cal.y}-${String(cal.m).padStart(2, '0')}-${String(d).padStart(2, '0')}`; const items = byDay[ds] || [];
      const sums = {}; items.forEach((i) => { sums[i.currency] ||= { in: 0, out: 0 }; sums[i.currency][i.direction] += i.remaining_minor; });
      cells.push(h('div', { class: 'day' + (ds === todayStr() ? ' today' : ''), tabindex: 0, role: 'button', onclick: () => items.length && dayModal(ds, items) },
        h('div', { class: 'd' }, String(d)), ...Object.entries(sums).slice(0, 2).map(([c, v]) => h('div', null, v.in ? h('div', { class: 'in' }, '+' + money(v.in, c).replace(/,00/, '')) : null, v.out ? h('div', { class: 'out' }, '−' + money(v.out, c).replace(/,00/, '')) : null))));
    }
    const dayModal = (ds, items) => modal({ title: date(ds) + ' — planlanan hareketler', body: h('div', { class: 'list' }, items.map((i) => h('div', { class: 'list-item' }, h('span', { class: 'dot ' + (i.direction === 'in' ? 'ok' : 'warn') }), h('div', { class: 'grow' }, i.title, h('div', { class: 'xs muted' }, { receivable: 'Alacak', payable: 'Borç', bill: 'Şahsi fatura', card: 'Kart ekstresi', loan: 'Kredi taksiti' }[i.type])), h('b', { class: i.direction === 'in' ? 'pos' : 'neg' }, money(i.remaining_minor, i.currency))))) });
    const total = r.sums;
    mount(body,
      h('div', { class: 'row between' }, h('div', { class: 'row tight' }, h('button', { class: 'btn', onclick: () => { cal.m--; if (cal.m < 1) { cal.m = 12; cal.y--; } load(); } }, '‹'), h('h2', null, `${MONTHS[cal.m - 1]} ${cal.y}`), h('button', { class: 'btn', onclick: () => { cal.m++; if (cal.m > 12) { cal.m = 1; cal.y++; } load(); } }, '›')),
        h('div', { class: 'small' }, Object.entries(total).map(([c, v]) => h('div', null, h('b', null, c), ' giriş ', h('span', { class: 'pos' }, money(v.in_minor || 0, c)), ' · çıkış ', h('span', { class: 'neg' }, money(v.out_minor || 0, c)))))),
      h('div', { class: 'cal' }, ['Pzt', 'Sal', 'Çar', 'Per', 'Cum', 'Cmt', 'Paz'].map((x) => h('div', { class: 'dow' }, x)), ...cells),
      late.items.length ? h('div', { class: 'card' }, h('div', { class: 'card-title' }, '⚠️ Vadesi geçmiş (kalan tutarlar)'), h('div', { class: 'list' }, late.items.map((i) => h('div', { class: 'list-item' }, h('span', { class: 'dot danger' }), h('div', { class: 'grow' }, i.title, h('div', { class: 'xs muted' }, `Vade ${date(i.date)} · ${i.days_overdue} gün gecikmiş`)), h('b', { class: i.direction === 'in' ? 'pos' : 'neg' }, money(i.remaining_minor, i.currency)))))) : null);
  }

  async function recurring() {
    const r = await api.get('/recurring');
    mount(body, h('div', { class: 'alert info' }, 'Tekrarlayan şablonlar yalnızca ileriye dönük PLAN (alacak/borç kalemi) üretir; banka bakiyesini düşürmez. Ödeme günü geldiğinde “Öde / Tahsil et” ile gerçekleştirirsiniz.'),
      readonly ? null : h('div', null, h('button', { class: 'btn primary', onclick: () => recForm() }, '＋ Tekrarlayan şablon')),
      table({ cols: [{ key: 'title', label: 'Şablon' }, { label: 'Tür', render: (t) => (t.entry_kind === 'receivable' ? 'Alacak' : 'Borç') }, { label: 'Sıklık', render: (t) => ({ weekly: 'Haftalık', monthly: 'Aylık', yearly: 'Yıllık' })[t.frequency] },
        { key: 'amount_minor', label: 'Tutar', num: true, render: (t) => money(t.amount_minor, t.currency) }, { key: 'next_date', label: 'Sıradaki', render: (t) => date(t.next_date) }, { label: 'Durum', render: (t) => h('span', { class: 'badge ' + (t.active ? 'ok' : '') }, t.active ? 'Aktif' : 'Durduruldu') },
        { label: '', act: true, sortable: false, render: (t) => readonly ? null : h('button', { class: 'btn sm', onclick: async () => { await api.patch('/recurring/' + t.id, { active: !Number(t.active) }); load(); } }, Number(t.active) ? 'Durdur' : 'Başlat') }],
        rows: r.templates, empty: { icon: '🔁', title: 'Şablon yok', text: 'Kira, abonelik, maaş gibi düzenli ödemeleri bir kez tanımlayın.' } }));
  }
  function recForm() {
    const wl = state.meta.workspaces.filter((w) => !w.archived);
    if (!wl.length) return toast('Önce bir alan oluşturun.', 'err');
    formModal({ title: 'Tekrarlayan şablon', fields: [
      { name: 'entry_kind', label: 'Tür', type: 'select', options: [['payable', 'Borç / ödeme'], ['receivable', 'Alacak / tahsilat']], value: 'payable' },
      { name: 'workspace_id', label: 'Alan', type: 'select', options: wl.map((w) => [w.id, w.name]), value: wl[0].id }, { name: 'title', label: 'Başlık', required: true },
      { name: 'amount_minor', label: 'Tutar', type: 'money', required: true }, { name: 'currency', label: 'Birim', type: 'select', options: CURRENCIES.map((c) => [c, c]), value: 'TRY' },
      { name: 'frequency', label: 'Sıklık', type: 'select', options: [['monthly', 'Aylık'], ['weekly', 'Haftalık'], ['yearly', 'Yıllık']] }, { name: 'next_date', label: 'İlk vade', type: 'date', required: true, value: addDays(todayStr(), 7) },
      { name: 'end_date', label: 'Bitiş (isteğe bağlı)', type: 'date' }, { name: 'contact_id', label: 'Cari', type: 'select', options: contactOptions(null, 'Cari yok') }, { name: 'category_id', label: 'Kategori', type: 'select', options: [['', 'Kategorisiz'], ...state.meta.categories.map((c) => [c.id, c.name])] }],
      onSubmit: async (v, key) => { await api.post('/recurring', { entry_kind: v.entry_kind, workspace_id: Number(v.workspace_id), title: v.title, amount_minor: v.amount_minor, currency: v.currency, frequency: v.frequency, next_date: v.next_date, end_date: v.end_date, contact_id: v.contact_id ? Number(v.contact_id) : null, category_id: v.category_id ? Number(v.category_id) : null }, { idem: key }); toast('Şablon oluşturuldu; planlı kalemler üretildi', 'ok'); load(); } });
  }
  draw(); load();
}
