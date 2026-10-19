// Ödeme / tahsilat: faturaya veya cari hesaba bağımsız, çok faturaya dağıtım, sonradan eşleştirme, ortak cari.
import { api, newKey } from './api.js';
import { state, loadMeta, contactOptions, projectOptions } from './state.js';
import { h, mount, clear, modal, toast, errToast, money, plain, parseMoney, date, dateTime, todayStr, addDays, confirmDialog, table, signClass } from './ui.js';

const METHODS = [['', 'Belirtilmedi'], ['transfer', 'Havale / EFT'], ['cash', 'Nakit'], ['card', 'Kart'], ['cheque', 'Çek / senet'], ['other', 'Diğer']];
const METHOD_TR = Object.fromEntries(METHODS);
const idv = (v) => (v === null || v === '' || v === undefined ? null : Number(v));

export function sourceLabel(a) {
  const personal = a.workspace_kind === 'personal';
  return (personal ? (a.kind === 'cash' ? 'Şahsi nakit' : 'Şahsi banka') : (a.kind === 'cash' ? 'İşletme kasası' : 'İşletme bankası'));
}
const accLabel = (a) => `${sourceLabel(a)} › ${a.workspace_name} › ${a.name} (${a.currency})`;

/** Dağıtım tablosu: her açık kaleme bu ödemeden düşen tutar girilir. */
function allocGrid({ onChange, onEdit = () => {} }) {
  const box = h('div', { class: 'stack tight' });
  let rows = []; // {e, input}
  const api_ = {
    el: box,
    values() {
      const out = []; let bad = null;
      rows.forEach((r) => {
        const raw = r.input.value.trim();
        if (raw === '') return;
        const v = parseMoney(raw);
        if (v === null || v < 0) { bad = 'Dağıtım tutarlarını kontrol edin.'; return; }
        if (v === 0) return;
        if (v > r.e.remaining_minor) bad = `“${r.e.title}” için kalan borçtan (${money(r.e.remaining_minor, r.e.currency)}) fazla tutar girildi.`;
        out.push({ entry_id: r.e.id, amount_minor: v });
      });
      return { list: out, error: bad };
    },
    sum() { return this.values().list.reduce((a, x) => a + x.amount_minor, 0); },
    autoFill(total) { // vadesi en yakın kalem önce (vadesizler sonda)
      let left = total;
      [...rows].sort((a, b) => (a.e.due_date || '9999') .localeCompare(b.e.due_date || '9999') || a.e.id - b.e.id).forEach((r) => {
        const v = Math.max(0, Math.min(left, r.e.remaining_minor));
        r.input.value = v > 0 ? plain(v) : '';
        left -= v;
      });
      onChange();
    },
    row(id) { return rows.find((r) => r.e.id === id); },
    clearAll() { rows.forEach((r) => { r.input.value = ''; }); onChange(); },
    set(entries, preset = {}) {
      const keep = new Map(rows.map((r) => [r.e.id, r.input.value])); // liste yeniden yüklenirken girilen dağıtımlar korunur
      rows = entries.map((e) => ({
        e, input: h('input', { class: 'input num', type: 'text', inputmode: 'decimal', placeholder: '0,00', 'aria-label': e.title + ' dağıtım tutarı', value: keep.get(e.id) || (preset[e.id] ? plain(preset[e.id]) : ''), oninput: () => { onEdit(); onChange(); } }),
      }));
      mount(box, rows.length ? h('div', { class: 'table-wrap' }, h('table', null,
        h('thead', null, h('tr', null, ['Fatura / kalem', 'Vade', 'Toplam', 'Ödenen', 'Kalan', 'Bu işlemde dağıtılacak', ''].map((t, i) => h('th', { class: i > 1 && i < 6 ? 'num' : '' }, t)))),
        h('tbody', null, rows.map((r) => h('tr', null,
          h('td', { 'data-label': 'Fatura / kalem' }, r.e.title), h('td', { 'data-label': 'Vade' }, r.e.due_date ? date(r.e.due_date) : 'Vadesiz'),
          h('td', { class: 'num', 'data-label': 'Toplam' }, money(r.e.amount_minor, r.e.currency)), h('td', { class: 'num', 'data-label': 'Ödenen' }, money(r.e.paid_minor, r.e.currency)),
          h('td', { class: 'num', 'data-label': 'Kalan' }, h('b', null, money(r.e.remaining_minor, r.e.currency))),
          h('td', { class: 'num', 'data-label': 'Bu işlemde' }, r.input),
          h('td', { class: 'act' }, h('button', { class: 'btn sm ghost', type: 'button', title: 'Kalanın tamamını yaz', onclick: () => { r.input.value = plain(r.e.remaining_minor); onChange(); } }, 'Kalanı yaz'))))))) : h('div', { class: 'muted small' }, 'Bu cari için açık fatura/kalem yok; tutar cari hesapta eşleştirilmemiş (avans) olarak kalır.'));
      onChange();
    },
  };
  return api_;
}

async function openEntries(kind, contactId, currency) {
  if (!contactId) return [];
  const r = await api.get('/entries', { kind: kind === 'payment' ? 'payable' : 'receivable', contact_id: contactId, status: 'unpaid' });
  return r.entries.filter((e) => !currency || e.currency === currency);
}

export function paymentForm({ kind = 'payment', entry = null, contactId = null, cb } = {}) {
  const accs = state.meta.accounts.filter((a) => !a.archived && (!entry || a.currency === entry.currency));
  if (!accs.length) return toast(entry ? `${entry.currency} para biriminde hesap/kasa yok.` : 'Önce bir banka hesabı veya kasa ekleyin.', 'err');
  if (entry) { kind = entry.kind === 'receivable' ? 'collection' : 'payment'; contactId = entry.contact_id ? Number(entry.contact_id) : contactId; }
  const isRec = () => kind === 'collection';
  const key = newKey();
  let mode = entry ? 'alloc' : 'alloc'; // alloc | free
  const cur = { currency: accs[0].currency };
  const msg = h('div', { class: 'alert err hide', role: 'alert' });
  const summary = h('div', { class: 'card flat stack tight' });
  let autoSync = !!entry; // tek kalemli ödemede dağıtım, toplam tutarı izler (kullanıcı elle değiştirene kadar)
  const grid = allocGrid({ onChange: () => recalc(), onEdit: () => { autoSync = false; } });
  const gridBox = h('div', { class: 'stack tight' });
  let lid = 0;
  const L = (label, el, cls = '') => { const id = 'pay_' + (++lid) + Math.random().toString(36).slice(2, 6); el.id = id; return h('div', { class: 'field ' + cls }, h('label', { for: id }, label), el); };

  const f = {
    contact: h('select', { class: 'input' }),
    account: h('select', { class: 'input' }, accs.map((a) => h('option', { value: a.id, selected: a.id === state.meta.settings.default_account_id }, accLabel(a)))),
    forWs: h('select', { class: 'input' }),
    amount: h('input', { class: 'input', type: 'text', inputmode: 'decimal', placeholder: '0,00', value: entry ? plain(entry.remaining_minor) : '' }),
    date: h('input', { class: 'input', type: 'date', value: todayStr(), max: addDays(todayStr(), 1) }),
    method: h('select', { class: 'input' }, METHODS.map(([v, l]) => h('option', { value: v }, l))),
    reference: h('input', { class: 'input', type: 'text', maxlength: 100, placeholder: 'Dekont / işlem no' }),
    reason: h('input', { class: 'input', type: 'text', maxlength: 300, placeholder: 'Ör. ABC müşterisinin siparişi için malzeme' }),
    description: h('textarea', { class: 'input', rows: 2, maxlength: 300, placeholder: 'Not (sonradan aramada bulunur)' }, entry ? (isRec() ? 'Tahsilat: ' : 'Ödeme: ') + entry.title : ''),
    project: h('select', { class: 'input' }, projectOptions().map(([v, l]) => h('option', { value: v }, l))),
    file: h('input', { type: 'file', class: 'input', accept: '.pdf,.jpg,.jpeg,.png,.webp,.heic', 'aria-label': 'Dekont / belge' }),
  };
  const fillContacts = () => {
    clear(f.contact);
    contactOptions(isRec() ? 'customer' : 'supplier', 'Cari seçin').forEach(([v, l]) => f.contact.appendChild(h('option', { value: v, selected: Number(v) === contactId }, l)));
  };
  fillContacts();
  const acct = () => state.meta.accounts.find((a) => a.id === Number(f.account.value));
  const personal = () => acct() && acct().workspace_kind === 'personal';
  const forWsWrap = L('İşletme adına mı ödendi?', f.forWs, 'full');
  const forWsHint = h('div', { class: 'hint' }, 'Şahsi hesap seçildi: işletme banka/kasa bakiyesi azalmaz. İşletme adına yapılıyorsa hangi işletme olduğunu seçin; tutar “işletmenin size borcu (ortak cari)” olarak izlenir.');
  const syncForWs = () => {
    forWsWrap.classList.toggle('hide', !personal());
    forWsHint.classList.toggle('hide', !personal());
    if (!personal()) return;
    const biz = state.meta.workspaces.filter((w) => w.kind === 'business' && !w.archived);
    const prev = f.forWs.value;
    mount(f.forWs, h('option', { value: '' }, 'Hayır — şahsi harcama (işletmeyle ilgisi yok)'), biz.map((w) => h('option', { value: w.id }, 'Evet — ' + w.name + ' adına')));
    const sel = prev || (entry && biz.some((w) => w.id === Number(entry.workspace_id)) ? String(entry.workspace_id) : (biz.length === 1 ? String(biz[0].id) : ''));
    f.forWs.value = sel;
  };

  const loadEntries = async () => {
    const cid = idv(f.contact.value);
    const a = acct();
    if (mode !== 'alloc') return;
    try {
      let list = entry && !cid ? [entry] : await openEntries(kind, cid, a && a.currency);
      if (entry && cid && !list.some((e) => e.id === entry.id)) list = [entry, ...list];
      const preset = entry ? { [entry.id]: Math.min(entry.remaining_minor, parseMoney(f.amount.value) ?? entry.remaining_minor) } : {};
      grid.set(list, entry ? preset : {});
    } catch (e) { mount(gridBox, h('div', { class: 'alert err' }, e.message)); }
  };

  function recalc() {
    const total = parseMoney(f.amount.value);
    const a = acct(); const c = a ? a.currency : 'TRY';
    const v = mode === 'alloc' ? grid.values() : { list: [], error: null };
    const alloc = v.list.reduce((s, x) => s + x.amount_minor, 0);
    const un = (total || 0) - alloc;
    const bad = v.error || (total && alloc > total ? 'Dağıtılan toplam, ödeme tutarını aşıyor.' : null);
    mount(summary,
      h('div', { class: 'row between' }, isRec() ? 'Tahsilat tutarı' : 'Ödeme tutarı', h('b', null, total ? money(total, c) : '—')),
      h('div', { class: 'row between' }, 'Faturalara dağıtılan', h('b', null, money(alloc, c))),
      h('div', { class: 'row between' }, 'Eşleştirilmemiş (cari avans)', h('b', { class: un < 0 ? 'neg' : un > 0 ? 'warn-t' : '' }, total ? money(un, c) : '—')),
      bad ? h('div', { class: 'alert err small' }, bad) : (un > 0 && total ? h('div', { class: 'hint' }, 'Eşleştirilmeyen tutar kaybolmaz: cari hesapta avans olarak durur ve sonradan faturalara dağıtılabilir.') : null));
    return { total, alloc, bad };
  }

  const modeSeg = h('div', { class: 'seg', role: 'tablist' });
  const drawMode = () => {
    mount(modeSeg,
      h('button', { type: 'button', class: mode === 'alloc' ? 'on' : '', onclick: () => { mode = 'alloc'; drawMode(); loadEntries(); } }, 'Faturalara / kalemlere dağıt'),
      h('button', { type: 'button', class: mode === 'free' ? 'on' : '', onclick: () => { mode = 'free'; drawMode(); } }, 'Cari hesaba bağımsız (sonra eşleştir)'));
    gridBox.classList.toggle('hide', mode !== 'alloc');
    recalc();
  };
  const kindSeg = entry ? null : h('div', { class: 'seg', role: 'tablist' });
  const drawKind = () => {
    if (!kindSeg) return;
    mount(kindSeg,
      h('button', { type: 'button', class: kind === 'payment' ? 'on' : '', onclick: () => { kind = 'payment'; contactId = null; fillContacts(); drawKind(); loadEntries(); } }, 'Ödeme (tedarikçiye)'),
      h('button', { type: 'button', class: kind === 'collection' ? 'on' : '', onclick: () => { kind = 'collection'; contactId = null; fillContacts(); drawKind(); loadEntries(); } }, 'Tahsilat (müşteriden)'));
  };
  drawKind();
  mount(gridBox, h('div', { class: 'row between' }, h('h3', null, 'Hangi faturalara?'),
    h('div', { class: 'row tight' }, h('button', { class: 'btn sm', type: 'button', onclick: () => { const t = parseMoney(f.amount.value); if (!t) return toast('Önce tutarı girin.', 'err'); grid.autoFill(t); } }, 'Otomatik dağıt (vadesi yakın önce)'), h('button', { class: 'btn sm ghost', type: 'button', onclick: () => grid.clearAll() }, 'Temizle'))), grid.el);

  f.contact.addEventListener('change', loadEntries);
  f.account.addEventListener('change', () => { syncForWs(); loadEntries(); recalc(); });
  f.amount.addEventListener('input', () => {
    const t = parseMoney(f.amount.value);
    const r = autoSync && entry ? grid.row(entry.id) : null;
    if (r && t) r.input.value = plain(Math.min(t, r.e.remaining_minor));
    recalc();
  });
  const body = h('div', { class: 'stack' }, msg,
    entry ? h('div', { class: 'alert info' }, `Kalem: ${entry.title} — kalan ${money(entry.remaining_minor, entry.currency)}. İsterseniz “Cari hesaba bağımsız” seçerek tutarı faturaya bağlamadan kaydedebilirsiniz.`) : kindSeg,
    modeSeg,
    h('div', { class: 'form-grid' }, L('Cari *', f.contact), L(isRec() ? 'Hangi hesaba girdi? *' : 'Hangi hesaptan çıktı? *', f.account), forWsWrap, h('div', { class: 'full' }, forWsHint),
      L('Toplam tutar *', f.amount), L('Tarih *', f.date), L('Yöntem', f.method), L('Referans / dekont no', f.reference), L('Neden / hangi iş için', f.reason, 'full'), L('Açıklama', f.description, 'full'), L('Proje', f.project)),
    gridBox, summary,
    h('div', { class: 'field' }, h('label', null, 'Dekont / belge (isteğe bağlı)'), f.file, h('div', { class: 'hint' }, 'PDF, JPG, PNG, WEBP — en çok 10 MB')));
  const btn = h('button', { class: 'btn primary', type: 'button' }, isRec() ? 'Tahsilatı kaydet' : 'Ödemeyi kaydet');
  let dirty = false;
  body.addEventListener('input', () => { dirty = true; });
  const m = modal({ title: entry ? (isRec() ? 'Tahsilat: ' : 'Ödeme: ') + entry.title : (isRec() ? 'Tahsilat gir' : 'Ödeme yap'), wide: true, body, foot: [h('button', { class: 'btn', type: 'button', onclick: () => m.close() }, 'Vazgeç'), btn], dirty: () => dirty });
  if (entry) grid.set([entry], { [entry.id]: Math.min(entry.remaining_minor, parseMoney(f.amount.value) ?? entry.remaining_minor) }); // kalem hemen hazır (ağ beklenmez)
  syncForWs(); drawMode(); loadEntries();
  const submit = async (force) => {
    msg.classList.add('hide');
    const r = recalc();
    const total = r.total;
    if (!total || total <= 0) { msg.textContent = 'Geçerli bir tutar girin.'; msg.classList.remove('hide'); return; }
    if (r.bad) { msg.textContent = r.bad; msg.classList.remove('hide'); return; }
    const allocs = mode === 'alloc' ? grid.values().list : [];
    if (!idv(f.contact.value) && !allocs.length) { msg.textContent = 'Cari hesap seçin ya da bir faturaya dağıtım yapın.'; msg.classList.remove('hide'); return; }
    btn.disabled = true; dirty = false;
    const body_ = { kind, account_id: Number(f.account.value), contact_id: idv(f.contact.value), amount_minor: total, date: f.date.value, method: f.method.value || null, reference: f.reference.value.trim() || null,
      reason: f.reason.value.trim() || null, description: f.description.value.trim() || null, project_id: idv(f.project.value), for_workspace_id: personal() ? idv(f.forWs.value) : null, allocations: allocs, force: force || undefined };
    try {
      const res = await api.post('/payments', body_, { idem: key });
      if (f.file.files[0]) {
        const fd = new FormData(); fd.append('entity', 'transaction'); fd.append('entity_id', res.id); fd.append('file', f.file.files[0]);
        try { await api.upload('/documents', fd); } catch (e) { errToast(e); }
      }
      m.close(true);
      await loadMeta();
      toast(res.unallocated_minor > 0 ? `${isRec() ? 'Tahsilat' : 'Ödeme'} kaydedildi. ${money(res.unallocated_minor, acct().currency)} eşleştirilmemiş (avans) olarak cari hesapta duruyor.` : (isRec() ? 'Tahsilat kaydedildi' : 'Ödeme kaydedildi'), 'ok');
      cb && cb(res);
    } catch (e) {
      dirty = true; btn.disabled = false;
      if (e.code === 'duplicate_payment' && await confirmDialog({ title: 'Mükerrer olabilir', text: e.message + ' Yine de kaydedilsin mi?', ok: 'Yine de kaydet' })) return submit(true);
      msg.textContent = e.message; msg.classList.remove('hide');
    }
  };
  btn.addEventListener('click', () => submit(false));
}

/** Eşleştirilmemiş tutarı kalemlere dağıtır. */
export async function allocateForm(p, cb, focusEntryId = null) {
  const kind = p.kind;
  let entries = [];
  try { entries = await openEntries(kind, p.contact_id, p.currency); } catch (e) { return errToast(e); }
  const key = newKey();
  const msg = h('div', { class: 'alert err hide', role: 'alert' });
  const sum = h('div', { class: 'card flat stack tight' });
  const grid = allocGrid({ onChange: () => recalc() });
  const recalc = () => {
    const v = grid.values(); const a = v.list.reduce((s, x) => s + x.amount_minor, 0);
    const over = a > p.unallocated_minor;
    mount(sum, h('div', { class: 'row between' }, 'Eşleştirilmemiş tutar', h('b', null, money(p.unallocated_minor, p.currency))), h('div', { class: 'row between' }, 'Bu işlemde dağıtılan', h('b', null, money(a, p.currency))),
      h('div', { class: 'row between' }, 'Kalacak', h('b', { class: over ? 'neg' : '' }, money(p.unallocated_minor - a, p.currency))), v.error || over ? h('div', { class: 'alert err small' }, v.error || 'Dağıtım, eşleştirilmemiş tutarı aşıyor.') : null);
    return { v, over };
  };
  grid.set(entries);
  if (focusEntryId) { const fr = grid.row(focusEntryId); if (fr) fr.input.value = plain(Math.min(p.unallocated_minor, fr.e.remaining_minor)); recalc(); }
  const btn = h('button', { class: 'btn primary', type: 'button' }, 'Eşleştir');
  const m = modal({ title: 'Ödemeyi faturalara eşleştir', wide: true, body: h('div', { class: 'stack' }, h('div', { class: 'alert info' }, 'Eşleştirme yeni bir para hareketi oluşturmaz; kasa/banka bakiyesi değişmez. Yalnızca hangi faturaların kapandığını belirler.'), msg,
    h('div', { class: 'row tight' }, h('button', { class: 'btn sm', type: 'button', onclick: () => grid.autoFill(p.unallocated_minor) }, 'Otomatik dağıt')), grid.el, sum),
    foot: [h('button', { class: 'btn', type: 'button', onclick: () => m.close() }, 'Vazgeç'), btn] });
  btn.addEventListener('click', async () => {
    const { v, over } = recalc();
    if (v.error || over || !v.list.length) { msg.textContent = v.error || (over ? 'Dağıtım, eşleştirilmemiş tutarı aşıyor.' : 'En az bir satıra tutar girin.'); msg.classList.remove('hide'); return; }
    btn.disabled = true;
    try { await api.post(`/payments/${p.id}/allocate`, { allocations: v.list }, { idem: key }); m.close(true); await loadMeta(); toast('Eşleştirildi', 'ok'); cb && cb(); } catch (e) { msg.textContent = e.message; msg.classList.remove('hide'); btn.disabled = false; }
  });
}

/** Ödeme / tahsilat ayrıntısı: dağıtımlar, geri alma, açıklama düzenleme, belgeler. */
export async function paymentDetail(id, { readonly = false, cb, openInvoice } = {}) {
  const box = h('div', null, h('div', { class: 'loading' }, h('span', { class: 'spinner' })));
  const m = modal({ title: 'Ödeme / tahsilat ayrıntısı', wide: true, body: box });
  const load = async () => {
    try {
      const r = await api.get('/payments/' + id);
      const p = r.payment; const isRec = p.kind === 'collection';
      const { docsPanel } = await import('./forms_core.js');
      const save = async (patch) => { try { await api.patch('/payments/' + id, patch); toast('Kaydedildi', 'ok'); load(); cb && cb(); } catch (e) { errToast(e); } };
      const reason = h('input', { class: 'input', 'aria-label': 'Neden', value: p.reason || '', maxlength: 300 }); const ref = h('input', { class: 'input', 'aria-label': 'Referans', value: p.reference || '', maxlength: 100 }); const desc = h('textarea', { class: 'input', 'aria-label': 'Açıklama', rows: 2, maxlength: 300 }, p.description || '');
      const meth = h('select', { class: 'input', 'aria-label': 'Yöntem' }, METHODS.map(([v, l]) => h('option', { value: v, selected: v === (p.method || '') }, l)));
      mount(box, h('div', { class: 'stack' },
        h('div', { class: 'row between' }, h('div', null, h('h2', null, (isRec ? 'Tahsilat ' : 'Ödeme ') + money(p.amount_minor, p.currency)), h('div', { class: 'muted' }, `${date(p.txn_date)} · ${p.contact_name || 'Cari yok'} · ${p.account_ws_kind === 'personal' ? 'Şahsi' : 'İşletme'} › ${p.account_name}`)),
          h('div', { class: 'row tight' }, h('span', { class: 'badge ' + (p.status === 'voided' ? 'bad' : 'ok') }, p.status === 'voided' ? 'İptal' : 'Geçerli'), p.account_ws_kind === 'personal' ? h('span', { class: 'badge info' }, 'Şahsi hesaptan') : null)),
        p.for_workspace_name ? h('div', { class: 'alert info small' }, `İşletme adına yapıldı: ${p.for_workspace_name}. İşletme banka bakiyesi etkilenmedi; ortak cari (işletmenin size borcu) hesabına yazıldı.`) : null,
        h('div', { class: 'card flat stack tight' }, h('div', { class: 'row between' }, 'Dağıtılan', h('b', null, money(p.allocated_minor, p.currency))),
          h('div', { class: 'row between' }, 'Eşleştirilmemiş (avans)', h('b', { class: p.unallocated_minor > 0 && p.status === 'posted' ? 'warn-t' : '' }, money(p.status === 'posted' ? p.unallocated_minor : 0, p.currency)))),
        h('h3', null, 'Fatura / kalem dağıtımları'),
        r.allocations.length ? table({ cols: [
          { label: 'Kalem', sortable: false, render: (a) => h('span', null, a.invoice_id && openInvoice ? h('a', { href: '#', onclick: (e) => { e.preventDefault(); m.close(true); openInvoice(a.invoice_id); } }, a.invoice_number ? 'Fatura ' + a.invoice_number : a.title) : a.title) },
          { label: 'Vade', sortable: false, render: (a) => (a.due_date ? date(a.due_date) : 'Vadesiz') }, { label: 'Tutar', num: true, sortable: false, render: (a) => money(a.amount_minor, p.currency) },
          { label: 'Durum', sortable: false, render: (a) => h('span', { class: 'badge ' + (a.status === 'active' ? 'ok' : '') }, a.status === 'active' ? 'Aktif' : 'Geri alındı') },
          { label: '', act: true, sortable: false, render: (a) => (!readonly && a.status === 'active' && p.status === 'posted' ? h('button', { class: 'btn sm', onclick: async () => { if (!await confirmDialog({ title: 'Eşleştirmeyi geri al', text: 'Bu tutar faturadan çıkarılır ve eşleştirilmemiş (avans) olur. Kasa/banka bakiyesi değişmez; ödeme silinmez.', ok: 'Geri al', danger: true })) return; try { await api.post(`/allocations/${a.id}/reverse`); await loadMeta(); toast('Eşleştirme geri alındı', 'ok'); load(); cb && cb(); } catch (e) { errToast(e); } } }, 'Geri al') : null) }], rows: r.allocations })
          : h('div', { class: 'muted small' }, 'Henüz hiçbir faturaya eşleştirilmedi.'),
        !readonly && p.status === 'posted' && p.unallocated_minor > 0 && p.contact_id ? h('div', null, h('button', { class: 'btn primary sm', onclick: () => allocateForm({ id: p.id, kind: p.kind, contact_id: p.contact_id, currency: p.currency, unallocated_minor: p.unallocated_minor }, () => { load(); cb && cb(); }) }, 'Faturalara eşleştir')) : null,
        h('h3', null, 'Açıklama ve izlenebilirlik'),
        readonly ? h('dl', { class: 'kv' }, h('dt', null, 'Neden'), h('dd', null, p.reason || '—'), h('dt', null, 'Referans'), h('dd', null, p.reference || '—'), h('dt', null, 'Yöntem'), h('dd', null, METHOD_TR[p.method || ''] || '—'), h('dt', null, 'Açıklama'), h('dd', null, p.description || '—'))
          : h('div', { class: 'form-grid' }, h('div', { class: 'field full' }, h('label', null, 'Neden / hangi iş için'), reason), h('div', { class: 'field' }, h('label', null, 'Referans'), ref), h('div', { class: 'field' }, h('label', null, 'Yöntem'), meth), h('div', { class: 'field full' }, h('label', null, 'Açıklama'), desc),
            h('div', { class: 'full' }, h('button', { class: 'btn sm', type: 'button', onclick: () => save({ reason: reason.value, reference: ref.value, method: meth.value || null, description: desc.value }) }, 'Açıklamayı kaydet'))),
        h('dl', { class: 'kv' }, h('dt', null, 'Kaydeden'), h('dd', null, p.created_by_name || '—'), h('dt', null, 'Kayıt zamanı'), h('dd', null, dateTime(p.created_at)), p.status === 'voided' ? [h('dt', null, 'İptal gerekçesi'), h('dd', null, p.void_reason || '—')] : null),
        h('h3', null, 'Dekont ve belgeler'), docsPanel('transaction', id, readonly)));
    } catch (e) { mount(box, h('div', { class: 'alert err' }, e.message)); }
  };
  load();
}

/** Ortak cari: şahsi hesapla işletme arasındaki hareketler. */
export async function ownerAccountModal() {
  const box = h('div', null, h('div', { class: 'loading' }, h('span', { class: 'spinner' })));
  modal({ title: 'Ortak cari — işletme sahibi ↔ işletme', wide: true, body: box });
  try {
    const r = await api.get('/owner-account');
    mount(box, h('div', { class: 'stack' }, h('div', { class: 'alert info' }, r.note),
      r.balances.length ? h('div', { class: 'card flat stack tight' }, r.balances.map((b) => h('div', { class: 'row between' }, `${b.workspace_name} · ${b.currency}`, h('b', { class: b.balance_minor > 0 ? 'warn-t' : b.balance_minor < 0 ? 'neg' : '' }, b.balance_minor > 0 ? `İşletme size ${money(b.balance_minor, b.currency)} borçlu` : b.balance_minor < 0 ? `Siz işletmeye ${money(-b.balance_minor, b.currency)} borçlusunuz` : 'Hesap kapalı')))) : null,
      table({ cols: [{ label: 'Tarih', sortable: false, render: (x) => date(x.date) }, { label: 'İşlem', sortable: false, render: (x) => h('div', null, x.type, h('div', { class: 'xs muted' }, [x.contact_name, x.reason, x.reference].filter(Boolean).join(' · '))) },
        { label: 'Hesap', sortable: false, render: (x) => x.account_name }, { label: 'Açıklama', sortable: false, render: (x) => x.description || '—' },
        { label: 'Tutar', num: true, sortable: false, render: (x) => h('span', { class: signClass(x.signed_minor) }, money(x.signed_minor, x.currency, { sign: true })) }, { label: 'Bakiye', num: true, sortable: false, render: (x) => money(x.balance_minor, x.currency) }],
      rows: r.rows, empty: { icon: '🤝', title: 'Hareket yok', text: 'Şahsi hesaptan işletme adına ödeme yaptığınızda ya da işletmeye para aktardığınızda burada görünür.' } })));
  } catch (e) { mount(box, h('div', { class: 'alert err' }, e.message)); }
}
