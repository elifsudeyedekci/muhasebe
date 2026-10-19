// Ortak formlar: gelir/gider, tahsilat/ödeme, transfer, döviz, hesap, alan, cari, alacak-borç, belgeler.
import { api } from './api.js';
import { paymentForm } from './forms_pay.js';
import { state, loadMeta, wsOptions, accOptions, catOptions, contactOptions, projectOptions, vatOptions, accName } from './state.js';
import { h, formModal, toast, errToast, money, date, todayStr, addDays, confirmDialog, modal, mount, clear, CURRENCIES, dateTime, plain, parseMoney } from './ui.js';

export const idv = (v) => (v === null || v === '' || v === undefined ? null : Number(v));
const done = (cb, msg) => async () => { await loadMeta(); if (msg) toast(msg, 'ok'); cb && cb(); };

export function txnForm({ kind, tx }, cb) {
  const isIncome = kind === 'income';
  const accs = state.meta.accounts.filter((a) => !a.archived);
  if (!accs.length) return toast('Önce bir banka hesabı veya kasa ekleyin.', 'err');
  const file = h('input', { type: 'file', class: 'input', accept: '.pdf,.jpg,.jpeg,.png,.webp,.heic', 'aria-label': 'Fiş / belge' });
  const cur = h('span', { class: 'badge' }, accs[0].currency);
  const m = formModal({
    title: (tx ? 'Düzenle: ' : '') + (isIncome ? 'Gelir ekle' : 'Gider ekle'), submit: tx ? 'Düzeltme olarak kaydet' : 'Kaydet',
    note: tx ? 'Düzenleme, eski kaydı iptal edip yenisini oluşturur; geçmiş korunur.' : null,
    fields: [
      { name: 'account_id', label: 'Hesap / kasa', type: 'select', required: true, value: tx ? tx.account_id : (state.meta.settings.default_account_id || accs[0].id), options: () => accOptions(), onChange: (v) => { const a = state.meta.accounts.find((x) => x.id === Number(v)); cur.textContent = a ? a.currency : ''; } },
      { name: 'amount_minor', label: 'Tutar', type: 'money', required: true, value: tx ? tx.amount_minor : null, hint: 'KDV dahil/hariç seçimine göre yorumlanır' },
      { name: 'txn_date', label: 'Tarih', type: 'date', required: true, value: tx ? tx.txn_date : todayStr(), max: addDays(todayStr(), 1) },
      { name: 'category_id', label: 'Kategori', type: 'select', value: tx ? tx.category_id : '', options: catOptions(isIncome ? 'income' : 'expense') },
      { name: 'vat_rate_bp', label: 'KDV oranı', type: 'select', value: tx ? tx.vat_rate_bp : 0, options: vatOptions },
      { name: 'vat_mode', label: 'KDV', type: 'select', value: 'included', options: [['included', 'Tutar KDV dahil'], ['excluded', 'Tutar KDV hariç (üstüne eklenir)']] },
      { name: 'contact_id', label: isIncome ? 'Müşteri' : 'Tedarikçi', type: 'select', value: tx ? tx.contact_id : '', options: contactOptions(isIncome ? 'customer' : 'supplier', 'Cari yok') },
      { name: 'project_id', label: 'Proje', type: 'select', value: tx ? tx.project_id : '', options: projectOptions() },
      ...(isIncome && !tx ? [{ name: 'standalone', label: 'Bağımsız gelir olarak kaydet (müşteri cari hesabına İŞLEME)', type: 'checkbox', full: true, hint: 'Varsayılan: müşteri seçtiyseniz tutar müşteri tahsilatı/avansı olarak cari hesaba işlenir ve gelir sayılmaz (gelir fatura ile oluşur). Yalnızca cari ile ilgisi olmayan gelirlerde işaretleyin.' }] : []),
      { name: 'description', label: 'Açıklama', type: 'textarea', full: true, value: tx ? tx.description : '', max: 300 },
    ],
    extra: tx ? null : h('div', { class: 'field' }, h('label', null, 'Fiş / fatura / belge (isteğe bağlı)'), file, h('div', { class: 'hint' }, 'PDF, JPG, PNG, WEBP — en çok 10 MB')),
    onSubmit: async (v, key) => {
      const body = { kind, account_id: idv(v.account_id), amount_minor: v.amount_minor, txn_date: v.txn_date, category_id: idv(v.category_id), vat_rate_bp: Number(v.vat_rate_bp), vat_mode: v.vat_mode, contact_id: idv(v.contact_id), project_id: idv(v.project_id), description: v.description, standalone: !!v.standalone };
      const r = tx ? await api.post(`/transactions/${tx.id}/replace`, body, { idem: key }) : await api.post('/transactions', body, { idem: key });
      if (file.files[0]) {
        const f = new FormData(); f.append('entity', 'transaction'); f.append('entity_id', r.id); f.append('file', file.files[0]);
        try { await api.upload('/documents', f); } catch (e) { errToast(e); }
      }
      if (r.warning) toast(r.warning, 'err');
      await done(cb, isIncome ? 'Gelir kaydedildi' : 'Gider kaydedildi')();
    },
  });
  return m;
}

/** Hızlı ödeme/tahsilat: cari + hesap + tutar seçilir; faturalara dağıtım ya da cariye bağımsız kayıt. */
export async function quickSettle(kind, cb) {
  paymentForm({ kind: kind === 'receivable' ? 'collection' : 'payment', cb });
}

/** Belirli bir kalem (fatura/borç/alacak) için ödeme; formdan “cari hesaba bağımsız” seçeneğine de geçilebilir. */
export function settleForm(e, cb) {
  paymentForm({ entry: e, cb });
}

export function rescheduleForm(e, cb) {
  formModal({
    title: 'Vadeyi ertele', note: `İlk vade: ${date(e.original_due_date)} · Mevcut: ${date(e.due_date)}`,
    fields: [{ name: 'due_date', label: 'Yeni vade (boş = vadesiz)', type: 'date', value: e.due_date }],
    onSubmit: async (v) => { await api.patch(`/entries/${e.id}`, { due_date: v.due_date }); toast('Vade güncellendi', 'ok'); cb && cb(); },
  });
}

export function transferForm(cb) {
  const accs = state.meta.accounts.filter((a) => !a.archived);
  if (accs.length < 2) return toast('Transfer için en az iki hesap/kasa gerekir.', 'err');
  const hint = h('div', { class: 'hint' });
  formModal({
    title: 'Hesaplar arası transfer', submit: 'Transfer et', note: 'Transfer gelir veya gider sayılmaz; iki bacak tek işlemde kaydedilir.',
    fields: [
      { name: 'from_account_id', label: 'Kaynak', type: 'select', required: true, options: () => accOptions(), value: accs[0].id },
      { name: 'to_account_id', label: 'Hedef (aynı para birimi)', type: 'select', required: true, options: () => accOptions(), value: accs[1].id },
      { name: 'amount_minor', label: 'Tutar', type: 'money', required: true },
      { name: 'date', label: 'Tarih', type: 'date', required: true, value: todayStr(), max: addDays(todayStr(), 1) },
      { name: 'description', label: 'Açıklama', type: 'text', full: true },
    ],
    extra: hint,
    onSubmit: async (v, key) => { await api.post('/transfers', { from_account_id: idv(v.from_account_id), to_account_id: idv(v.to_account_id), amount_minor: v.amount_minor, date: v.date, description: v.description }, { idem: key }); await done(cb, 'Transfer yapıldı')(); },
  });
}

export function fxForm(cb) {
  const accs = state.meta.accounts.filter((a) => !a.archived);
  if (new Set(accs.map((a) => a.currency)).size < 2) return toast('Döviz işlemi için farklı para birimli iki hesap gerekir.', 'err');
  const rate = h('div', { class: 'hint', id: 'fxrate' }, 'Kur, tutarlardan hesaplanır.');
  const m = formModal({
    title: 'Döviz alım-satım', submit: 'Kaydet', note: 'Döviz işlemi gelir/gider değildir; yalnızca masraf gider yazılır.',
    fields: [
      { name: 'from_account_id', label: 'Çıkış hesabı', type: 'select', required: true, options: () => accOptions(), value: accs[0].id },
      { name: 'to_account_id', label: 'Giriş hesabı', type: 'select', required: true, options: () => accOptions(), value: (accs.find((a) => a.currency !== accs[0].currency) || accs[1]).id },
      { name: 'from_amount_minor', label: 'Çıkan tutar', type: 'money', required: true },
      { name: 'to_amount_minor', label: 'Giren tutar', type: 'money', required: true },
      { name: 'fee_minor', label: 'Masraf (çıkış para biriminde)', type: 'money' },
      { name: 'date', label: 'Tarih', type: 'date', required: true, value: todayStr(), max: addDays(todayStr(), 1) },
    ],
    extra: rate,
    onSubmit: async (v, key) => { await api.post('/fx', { from_account_id: idv(v.from_account_id), to_account_id: idv(v.to_account_id), from_amount_minor: v.from_amount_minor, to_amount_minor: v.to_amount_minor, fee_minor: v.fee_minor || 0, date: v.date }, { idem: key }); await done(cb, 'Döviz işlemi kaydedildi')(); },
  });
  const upd = () => { const a = parseMoney(m.ctx.fields.from_amount_minor.input.value); const b = parseMoney(m.ctx.fields.to_amount_minor.input.value); rate.textContent = a && b ? `Kur: 1 birim = ${(b / a).toLocaleString('tr-TR', { maximumFractionDigits: 6 })}` : 'Kur, tutarlardan hesaplanır.'; };
  m.el.addEventListener('input', upd);
}

export function workspaceForm(w, cb) {
  formModal({
    title: w ? 'Alanı düzenle' : 'Yeni işletme / alan', note: 'Resmî şirket, marka veya şahsi alan olarak kullanabilirsiniz; ayrı tüzel kişilik olması gerekmez.',
    fields: [
      { name: 'name', label: 'Ad', required: true, value: w && w.name, max: 120 },
      { name: 'kind', label: 'Tür', type: 'select', value: w ? w.kind : 'business', options: [['business', 'İşletme / marka'], ['personal', 'Şahsi']] },
      { name: 'legal_name', label: 'Resmî ünvan', value: w && w.legal_name }, { name: 'tax_no', label: 'Vergi no', value: w && w.tax_no },
      { name: 'color', label: 'Renk', type: 'color', value: (w && w.color) || '#1d4ed8' },
    ],
    onSubmit: async (v, key) => {
      const b = { name: v.name, kind: v.kind, legal_name: v.legal_name, tax_no: v.tax_no, color: v.color };
      if (w) { delete b.kind; await api.patch('/workspaces/' + w.id, b); } else await api.post('/workspaces', b, { idem: key });
      await done(cb, 'Kaydedildi')();
    },
  });
}

export function accountForm(a, cb) {
  if (!state.meta.workspaces.filter((w) => !w.archived).length) return toast('Önce bir işletme/şahsi alan oluşturun.', 'err');
  formModal({
    title: a ? 'Hesabı düzenle' : 'Yeni banka hesabı / kasa',
    fields: [
      { name: 'workspace_id', label: 'İşletme / alan', type: 'select', required: true, value: a ? a.workspace_id : '', options: () => wsOptions(), ...(a ? { disabled: true } : {}) },
      { name: 'name', label: 'Hesap adı', required: true, value: a && a.name, max: 120 },
      { name: 'kind', label: 'Tür', type: 'select', value: a ? a.kind : 'bank', options: [['bank', 'Banka hesabı'], ['cash', 'Nakit kasa']] },
      { name: 'currency', label: 'Para birimi', type: 'select', value: a ? a.currency : 'TRY', options: CURRENCIES.map((c) => [c, c]), hint: a ? 'Hareketi olan hesabın para birimi değiştirilemez' : undefined },
      { name: 'bank_name', label: 'Banka', value: a && a.bank_name }, { name: 'iban', label: 'IBAN (isteğe bağlı)', value: a && a.iban, hint: 'TR ile başlayan 26 haneli; doğruluğu kontrol edilir' },
      ...(a ? [] : [{ name: 'opening_minor', label: 'Açılış bakiyesi', type: 'money', value: 0, hint: 'Açılış bakiyesi gelir sayılmaz' }, { name: 'opening_date', label: 'Açılış tarihi', type: 'date', required: true, value: todayStr() }]),
      { name: 'is_default', label: 'Varsayılan hesap', type: 'checkbox', value: a && a.is_default },
    ],
    onSubmit: async (v, key) => {
      if (a) await api.patch('/accounts/' + a.id, { name: v.name, bank_name: v.bank_name, iban: v.iban, is_default: v.is_default, kind: v.kind, currency: v.currency });
      else await api.post('/accounts', { workspace_id: idv(v.workspace_id), name: v.name, kind: v.kind, currency: v.currency, bank_name: v.bank_name, iban: v.iban, opening_minor: v.opening_minor || 0, opening_date: v.opening_date, is_default: v.is_default }, { idem: key });
      await done(cb, 'Hesap kaydedildi')();
    },
  });
}

export function adjustForm(a, cb) {
  formModal({
    title: 'Bakiye düzeltme: ' + a.name, note: 'Düzeltme kaydı gelir/gider sayılmaz ve geçmişte görünür. Eksiltmek için negatif tutar girin.',
    fields: [{ name: 'delta_minor', label: 'Düzeltme tutarı (±)', type: 'money', required: true }, { name: 'reason', label: 'Neden', required: true, full: true }, { name: 'date', label: 'Tarih', type: 'date', value: todayStr(), required: true }],
    onSubmit: async (v, key) => { await api.post(`/accounts/${a.id}/adjust`, { delta_minor: v.delta_minor, reason: v.reason, date: v.date }, { idem: key }); await done(cb, 'Düzeltme kaydedildi')(); },
  });
}

export function contactForm(c, cb) {
  formModal({
    title: c ? 'Cariyi düzenle' : 'Yeni cari kart', wide: true,
    fields: [
      { name: 'name', label: 'Ünvan / ad', required: true, value: c && c.name, max: 200 },
      { name: 'kind', label: 'Tür', type: 'select', value: c ? c.kind : 'customer', options: [['customer', 'Müşteri'], ['supplier', 'Tedarikçi'], ['both', 'Müşteri + Tedarikçi']] },
      { name: 'tax_no', label: 'Vergi / TC no', value: c && c.tax_no }, { name: 'tax_office', label: 'Vergi dairesi', value: c && c.tax_office },
      { name: 'email', label: 'E-posta', type: 'email', value: c && c.email }, { name: 'phone', label: 'Telefon', type: 'tel', value: c && c.phone },
      { name: 'address', label: 'Adres', full: true, value: c && c.address }, { name: 'note', label: 'Not', type: 'textarea', full: true, value: c && c.note },
    ],
    onSubmit: async (v, key) => {
      const b = { name: v.name, kind: v.kind, tax_no: v.tax_no, tax_office: v.tax_office, email: v.email, phone: v.phone, address: v.address, note: v.note };
      if (c) await api.patch('/contacts/' + c.id, b); else await api.post('/contacts', b, { idem: key });
      await done(cb, 'Cari kaydedildi')();
    },
  });
}

/** Alacak / borç / şahsi fatura kalemi */
export function entryForm({ kind, source = 'manual', entry = null }, cb) {
  const bill = source === 'personal_bill';
  const wsList = state.meta.workspaces.filter((w) => !w.archived && (bill ? w.kind === 'personal' : true));
  if (!wsList.length) return toast(bill ? 'Önce “Şahsi” türünde bir alan oluşturun (Hesaplar sayfası).' : 'Önce bir işletme alanı oluşturun.', 'err');
  const rec = kind === 'receivable';
  const edit = !!entry;
  const locked = edit && Number(entry.paid_minor) > 0; // ödeme dağıtımı varsa para birimi/cari kilitli (sunucu da denetler)
  formModal({
    title: edit ? (bill ? 'Şahsi faturayı düzenle' : 'Kalemi düzenle') : bill ? 'Şahsi fatura ekle' : rec ? 'Alacak kaydı ekle' : 'Borç kaydı ekle', wide: true,
    note: edit ? 'Düzenleme geçmişi denetim kaydına yazılır. Ödenen tutardan küçük bir tutar girilemez.' : bill ? 'Şahsi faturalar şirket giderlerine eklenmez; ödeme takviminde görünür.' : 'Burada ödeme planı oluşturulur; ödeme yapılana kadar banka bakiyesi değişmez.',
    fields: [
      { name: 'workspace_id', label: 'Alan', type: 'select', required: true, options: wsList.map((w) => [w.id, w.name]), value: edit ? entry.workspace_id : wsList[0].id, disabled: edit },
      { name: 'title', label: bill ? 'Fatura (ör. Elektrik)' : 'Başlık', required: true, max: 200, value: edit ? entry.title : '' },
      ...(bill ? [{ name: 'provider', label: 'Sağlayıcı', max: 120, value: edit ? entry.provider : '' }, { name: 'period', label: 'Dönem (ör. 2026-10)', max: 20, value: edit ? entry.period : '' }]
        : [{ name: 'contact_id', label: rec ? 'Müşteri' : 'Tedarikçi', type: 'select', options: contactOptions(rec ? 'customer' : 'supplier', 'Cari yok'), value: edit ? entry.contact_id : '', disabled: locked }]),
      { name: 'amount_minor', label: 'Tutar', type: 'money', required: true, value: edit ? entry.amount_minor : null, disabled: edit && entry.source === 'invoice' },
      { name: 'currency', label: 'Para birimi', type: 'select', options: CURRENCIES.map((c) => [c, c]), value: edit ? entry.currency : 'TRY', disabled: locked },
      { name: 'due_date', label: 'Vade / son ödeme (isteğe bağlı)', type: 'date', value: edit ? entry.due_date : addDays(todayStr(), 7), hint: 'Boş bırakırsanız vadesiz kaydedilir; gecikmiş sayılmaz.' },
      { name: 'planned_date', label: edit ? 'Planlanan ödeme/tahsilat tarihi (vadeden ayrı)' : 'Planlanan tarih (isteğe bağlı, vadeden ayrı)', type: 'date', value: edit ? entry.planned_date : '', hint: 'Boş = tarih girilmemiş' },
      ...(bill ? [] : [...(edit ? [] : [{ name: 'installments', label: 'Taksit sayısı (aylık)', type: 'number', min: 1, max: 120, value: 1, hint: 'Taksit için vade tarihi gerekir' }]), { name: 'priority', label: 'Öncelik', type: 'select', value: edit ? entry.priority : 2, options: [[1, 'Yüksek'], [2, 'Normal'], [3, 'Düşük']] },
        { name: 'project_id', label: 'Proje', type: 'select', options: projectOptions(), value: edit ? entry.project_id : '' }, { name: 'category_id', label: 'Kategori', type: 'select', options: catOptions(rec ? 'income' : 'expense'), value: edit ? entry.category_id : '' },
        { name: 'affects_pl', label: 'Gelir/gider olarak da say (kâr hesabına dahil et)', type: 'checkbox', full: true, value: edit ? Number(entry.affects_pl) : 0 }]),
      { name: 'note', label: 'Not', type: 'textarea', full: true, value: edit ? entry.note : '' },
    ],
    onSubmit: async (v, key) => {
      if (edit) {
        const b = { title: v.title, amount_minor: v.amount_minor, due_date: v.due_date, planned_date: v.planned_date || null, note: v.note };
        if (!locked) b.currency = v.currency;
        if (bill) Object.assign(b, { provider: v.provider, period: v.period });
        else Object.assign(b, { priority: Number(v.priority), project_id: idv(v.project_id), category_id: idv(v.category_id), affects_pl: v.affects_pl, ...(locked ? {} : { contact_id: idv(v.contact_id) }) });
        if (entry.source === 'invoice') delete b.amount_minor;
        await api.patch('/entries/' + entry.id, b);
        return done(cb, 'Güncellendi')();
      }
      const b = { kind, source, workspace_id: idv(v.workspace_id), title: v.title, amount_minor: v.amount_minor, currency: v.currency, due_date: v.due_date, planned_date: v.planned_date || null, note: v.note };
      if (bill) Object.assign(b, { provider: v.provider, period: v.period });
      else Object.assign(b, { contact_id: idv(v.contact_id), installments: Number(v.installments || 1), priority: Number(v.priority), project_id: idv(v.project_id), category_id: idv(v.category_id), affects_pl: v.affects_pl });
      await api.post('/entries', b, { idem: key });
      await done(cb, 'Kaydedildi')();
    },
  });
}

/** Silme: güvenli ise siler; ödeme geçmişi varsa neden açıklanır ve iptal önerilir. */
export async function deleteEntry(e, cb) {
  if (!await confirmDialog({ title: 'Kaydı sil', text: `“${e.title}” kalıcı olarak silinsin mi? Yalnızca hiç ödeme/tahsilat geçmişi olmayan, hatalı girilmiş kayıtlar silinebilir. Geçmişi olan kayıtlar için “İptal et” kullanın.`, ok: 'Sil', danger: true })) return;
  try { await api.del('/entries/' + e.id); await loadMeta(); toast('Silindi', 'ok'); cb && cb(); } catch (er) { errToast(er); }
}

// ---------- belgeler ----------
export function docsPanel(entity, id, readonly) {
  const box = h('div', { class: 'stack tight' });
  const draw = async () => {
    clear(box);
    let docs = [];
    try { docs = (await api.get('/documents', { entity, entity_id: id })).documents; } catch (e) { box.appendChild(h('div', { class: 'alert err' }, e.message)); return; }
    if (!docs.length) box.appendChild(h('div', { class: 'muted small' }, 'Ekli belge yok.'));
    docs.forEach((d) => box.appendChild(h('div', { class: 'list-item' }, h('span', null, '📎'),
      h('a', { href: '#', class: 'grow', onclick: async (e) => { e.preventDefault(); try { await api.download(`/documents/${d.id}/download`, d.orig_name); } catch (er) { errToast(er); } } }, d.orig_name),
      h('span', { class: 'xs muted' }, Math.max(1, Math.round(d.size_bytes / 1024)) + ' KB · ' + dateTime(d.created_at)),
      readonly ? null : h('button', { class: 'btn sm ghost', type: 'button', onclick: async () => { if (await confirmDialog({ title: 'Belgeyi sil', text: d.orig_name + ' silinsin mi?', ok: 'Sil', danger: true })) { await api.del('/documents/' + d.id); draw(); } } }, '🗑'))));
    if (!readonly) {
      const f = h('input', { type: 'file', class: 'hide', accept: '.pdf,.jpg,.jpeg,.png,.webp,.heic,.csv,.xlsx', onchange: async () => {
        if (!f.files[0]) return;
        const fd = new FormData(); fd.append('entity', entity); fd.append('entity_id', id); fd.append('file', f.files[0]);
        try { await api.upload('/documents', fd); toast('Belge yüklendi', 'ok'); draw(); } catch (e) { errToast(e); }
      } });
      box.append(f, h('div', null, h('button', { class: 'btn sm', type: 'button', onclick: () => f.click() }, '＋ Belge ekle')));
    }
  };
  draw();
  return box;
}

export function docsModal(title, entity, id, readonly) {
  return modal({ title, body: docsPanel(entity, id, readonly) });
}
