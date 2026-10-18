// Fatura, kart, kredi, proje ve stok formları.
import { api } from './api.js';
import { state, loadMeta, wsOptions, accOptions, catOptions, contactOptions, projectOptions, vatOptions } from './state.js';
import { h, mount, clear, modal, formModal, toast, errToast, money, date, todayStr, addDays, CURRENCIES, qtyMilli, parseMoney, plain, fmtQty, pct } from './ui.js';
import { idv } from './forms_core.js';

const done = (cb, msg) => async () => { await loadMeta(); if (msg) toast(msg, 'ok'); cb && cb(); };

/** Fatura satırı hesabı (yalnızca önizleme; sunucu aynı formülle yeniden hesaplar — BigInt ile taşma yok). */
function calcLine(qtyM, priceMinor, discBp, vatBp) {
  const q = BigInt(qtyM); const p = BigInt(priceMinor);
  const gross = (q * p + 500n) / 1000n;
  const net = gross - (gross * BigInt(discBp) + 5000n) / 10000n;
  const vat = (net * BigInt(vatBp) + 5000n) / 10000n;
  return { net: Number(net), vat: Number(vat), total: Number(net + vat) };
}

export function invoiceForm({ kind, preset, cb }) {
  const ws = state.meta.workspaces.filter((w) => !w.archived);
  if (!ws.length) return toast('Önce bir işletme alanı oluşturun.', 'err');
  if (!state.meta.contacts.some((c) => !c.archived)) return toast('Önce bir cari (müşteri/tedarikçi) ekleyin.', 'err');
  const sales = kind === 'sales';
  const items = state.meta.items.filter((i) => !i.archived);
  const lines = [];
  const linesBox = h('div', { class: 'stack' });
  const totals = h('div', { class: 'card flat stack tight' });
  const inv = (state.meta.settings.invoice || {});
  const header = {
    workspace_id: h('select', { class: 'input' }, ws.map((w) => h('option', { value: w.id }, w.name))),
    contact_id: h('select', { class: 'input' }, contactOptions(sales ? 'customer' : 'supplier', 'Cari seçin').map(([v, l]) => h('option', { value: v }, l))),
    number: h('input', { class: 'input', type: 'text', maxlength: 60, placeholder: sales ? 'Fatura no' : 'Fatura no (zorunlu)' }),
    issue_date: h('input', { class: 'input', type: 'date', value: todayStr() }),
    due_date: h('input', { class: 'input', type: 'date', value: addDays(todayStr(), Number(inv.default_due_days ?? 30)) }),
    currency: h('select', { class: 'input' }, CURRENCIES.map((c) => h('option', { value: c }, c))),
    project_id: h('select', { class: 'input' }, projectOptions().map(([v, l]) => h('option', { value: v }, l))),
    status: h('select', { class: 'input' }, [['issued', 'Fatura kesildi / alındı'], ['planned', 'Planla (henüz kesilmedi)']].map(([v, l]) => h('option', { value: v }, l))),
    note: h('textarea', { class: 'input', rows: 2, maxlength: 2000 }, inv.note || ''),
  };
  if (preset && preset.contact_id) header.contact_id.value = preset.contact_id;
  const msg = h('div', { class: 'alert err hide', role: 'alert' });
  let lid = 0;
  const L = (label, el, cls = '') => { const id = 'inv_' + (++lid); el.id = id; return h('div', { class: 'field ' + cls }, h('label', { for: id }, label), el); };

  const recalc = () => {
    let sub = 0; let vat = 0;
    lines.forEach((l) => {
      const q = qtyMilli(l.qty.value); const p = parseMoney(l.price.value);
      const d = Math.round(Number(String(l.disc.value || '0').replace(',', '.')) * 100); const v = Number(l.vat.value);
      if (q && p !== null && p >= 0 && d >= 0 && d <= 10000) { const c = calcLine(q, p, d, v); l.total.textContent = money(c.total, header.currency.value); sub += c.net; vat += c.vat; } else l.total.textContent = '—';
    });
    mount(totals, h('div', { class: 'row between' }, 'KDV hariç ara toplam', h('b', null, money(sub, header.currency.value))), h('div', { class: 'row between' }, 'KDV', h('b', null, money(vat, header.currency.value))), h('div', { class: 'row between' }, h('b', null, 'Genel toplam'), h('b', null, money(sub + vat, header.currency.value))));
  };
  const addLine = (init = {}) => {
    const l = {
      item: h('select', { class: 'input' }, [h('option', { value: '' }, 'Serbest satır'), ...items.map((i) => h('option', { value: i.id }, (i.code ? i.code + ' · ' : '') + i.name))]),
      desc: h('input', { class: 'input', type: 'text', maxlength: 300, placeholder: 'Açıklama' }),
      qty: h('input', { class: 'input', type: 'text', inputmode: 'decimal', value: '1' }),
      price: h('input', { class: 'input', type: 'text', inputmode: 'decimal', placeholder: '0,00' }),
      disc: h('input', { class: 'input', type: 'text', inputmode: 'decimal', value: '0' }),
      vat: h('select', { class: 'input' }, vatOptions().map(([v, l]) => h('option', { value: v, selected: v === 2000 }, l))),
      wh: h('select', { class: 'input hide' }, [h('option', { value: '' }, 'Depo yok (stok izleme yok)'), ...state.meta.warehouses.filter((w) => !w.archived).map((w) => h('option', { value: w.id }, w.name))]),
      total: h('div', { class: 'num' }, '—'),
    };
    const row = h('div', { class: 'card flat stack tight' },
      h('div', { class: 'form-grid' }, L('Ürün / hizmet', l.item), L('Açıklama *', l.desc), L('Miktar', l.qty), L('Birim fiyat', l.price), L('İskonto %', l.disc), L('KDV', l.vat), L('Depo', l.wh, 'wh-wrap')),
      h('div', { class: 'row between' }, h('button', { class: 'btn sm ghost', type: 'button', onclick: () => { lines.splice(lines.indexOf(l), 1); row.remove(); recalc(); } }, '🗑 Satırı sil'), h('div', null, 'Satır toplamı: ', l.total)));
    l.row = row;
    l.item.addEventListener('change', () => {
      const it = items.find((i) => i.id === Number(l.item.value));
      l.wh.classList.toggle('hide', !(it && it.kind === 'product'));
      if (it) { l.desc.value = it.name; if (sales) l.price.value = plain(it.sale_price_minor); l.vat.value = String(it.vat_rate_bp); }
      recalc();
    });
    row.addEventListener('input', recalc);
    row.addEventListener('change', recalc);
    lines.push(l); linesBox.appendChild(row);
    if (init.desc) l.desc.value = init.desc;
    recalc();
  };
  header.currency.addEventListener('change', recalc);
  addLine();
  const body = h('div', { class: 'stack' }, msg,
    h('div', { class: 'form-grid' }, L('Alan', header.workspace_id), L(sales ? 'Müşteri *' : 'Tedarikçi *', header.contact_id), L('Fatura no', header.number), L('Durum', header.status), L('Fatura tarihi', header.issue_date), L('Vade', header.due_date), L('Para birimi', header.currency), L('Proje', header.project_id), L('Not', header.note, 'full')),
    h('h3', null, 'Satırlar'), linesBox, h('div', null, h('button', { class: 'btn sm', type: 'button', onclick: () => addLine() }, '＋ Satır ekle')), totals);
  const key = (window.crypto.randomUUID ? window.crypto.randomUUID().replace(/-/g, '') : String(Math.random()).slice(2) + Date.now());
  const btn = h('button', { class: 'btn primary', type: 'button' }, sales ? 'Faturayı kaydet' : 'Alış faturasını kaydet');
  const m = modal({ title: sales ? 'Satış faturası' : 'Alış faturası', wide: true, body, foot: [h('button', { class: 'btn', type: 'button', onclick: () => m.close() }, 'Vazgeç'), btn], dirty: () => true });
  btn.addEventListener('click', async () => {
    msg.classList.add('hide');
    const ls = [];
    for (const [i, l] of lines.entries()) {
      const q = qtyMilli(l.qty.value); const p = parseMoney(l.price.value); const d = Math.round(Number(String(l.disc.value || '0').replace(',', '.')) * 100);
      if (!l.desc.value.trim() || !q || p === null || p < 0 || !(d >= 0 && d <= 10000)) { msg.textContent = `${i + 1}. satırda açıklama, miktar, fiyat ve iskonto değerlerini kontrol edin.`; msg.classList.remove('hide'); return; }
      ls.push({ description: l.desc.value.trim(), item_id: idv(l.item.value), warehouse_id: idv(l.wh.value), qty_milli: q, unit_price_minor: p, discount_bp: d, vat_rate_bp: Number(l.vat.value) });
    }
    if (!header.contact_id.value) { msg.textContent = 'Cari seçin.'; msg.classList.remove('hide'); return; }
    btn.disabled = true;
    try {
      const r = await api.post('/invoices', { kind, workspace_id: Number(header.workspace_id.value), contact_id: Number(header.contact_id.value), number: header.number.value.trim() || null, issue_date: header.issue_date.value, due_date: header.due_date.value || null, currency: header.currency.value, project_id: idv(header.project_id.value), status: header.status.value, note: header.note.value, lines: ls }, { idem: key });
      (r.warnings || []).forEach((w) => toast(w, 'err'));
      m.close(true); toast('Fatura kaydedildi', 'ok'); await loadMeta(); cb && cb();
    } catch (e) { msg.textContent = e.message; msg.classList.remove('hide'); btn.disabled = false; }
  });
}

export function cardForm(cb) {
  if (!state.meta.workspaces.length) return toast('Önce bir alan oluşturun.', 'err');
  formModal({ title: 'Kredi kartı tanımla', fields: [
    { name: 'workspace_id', label: 'Alan', type: 'select', options: () => wsOptions(), required: true }, { name: 'name', label: 'Kart adı', required: true }, { name: 'bank_name', label: 'Banka' },
    { name: 'currency', label: 'Para birimi', type: 'select', options: CURRENCIES.map((c) => [c, c]), value: 'TRY' }, { name: 'limit_minor', label: 'Kart limiti', type: 'money' },
    { name: 'statement_day', label: 'Hesap kesim günü (1-28)', type: 'number', min: 1, max: 28, value: 5, required: true }, { name: 'due_day', label: 'Son ödeme günü (1-28)', type: 'number', min: 1, max: 28, value: 15, required: true }],
    onSubmit: async (v, key) => { await api.post('/cards', { workspace_id: Number(v.workspace_id), name: v.name, bank_name: v.bank_name, currency: v.currency, limit_minor: v.limit_minor || 0, statement_day: Number(v.statement_day), due_day: Number(v.due_day) }, { idem: key }); await done(cb, 'Kart eklendi')(); } });
}
export function cardPurchaseForm(card, cb) {
  formModal({ title: 'Kartla alışveriş: ' + card.name, note: 'Harcama alışveriş tarihinde gider olur; ekstre ödemesi ikinci gider yazmaz.', fields: [
    { name: 'description', label: 'Açıklama', required: true, full: true }, { name: 'amount_minor', label: 'Tutar (' + card.currency + ')', type: 'money', required: true },
    { name: 'installments', label: 'Taksit', type: 'number', min: 1, max: 36, value: 1 }, { name: 'txn_date', label: 'Tarih', type: 'date', value: todayStr(), required: true },
    { name: 'category_id', label: 'Kategori', type: 'select', options: catOptions('expense') }, { name: 'vat_rate_bp', label: 'KDV oranı', type: 'select', options: vatOptions(), value: 0 },
    { name: 'vat_mode', label: 'KDV', type: 'select', options: [['included', 'Tutar KDV dahil'], ['excluded', 'Tutar KDV hariç']] }, { name: 'project_id', label: 'Proje', type: 'select', options: projectOptions() }],
    onSubmit: async (v, key) => { const r = await api.post(`/cards/${card.id}/purchases`, { description: v.description, amount_minor: v.amount_minor, installments: Number(v.installments || 1), txn_date: v.txn_date, category_id: idv(v.category_id), vat_rate_bp: Number(v.vat_rate_bp), vat_mode: v.vat_mode, project_id: idv(v.project_id) }, { idem: key }); if (r.warning) toast(r.warning, 'err'); await done(cb, 'Alışveriş kaydedildi')(); } });
}
export function cardPayForm(card, debt, cb) {
  const opts = accOptions(null, card.currency);
  if (!opts.length) return toast(`${card.currency} para biriminde hesap yok.`, 'err');
  formModal({ title: 'Kart borcu öde: ' + card.name, note: `Güncel borç: ${money(debt, card.currency)}. Ödeme banka bakiyesini düşürür, ikinci bir gider oluşturmaz.`, fields: [
    { name: 'account_id', label: 'Ödeme hesabı', type: 'select', options: opts, required: true }, { name: 'amount_minor', label: 'Tutar', type: 'money', required: true, value: debt > 0 ? debt : null }, { name: 'date', label: 'Tarih', type: 'date', value: todayStr(), required: true }],
    onSubmit: async (v, key) => { await api.post(`/cards/${card.id}/pay`, { account_id: Number(v.account_id), amount_minor: v.amount_minor, date: v.date }, { idem: key }); await done(cb, 'Kart ödemesi kaydedildi')(); } });
}
export function loanForm(cb) {
  if (!state.meta.workspaces.length) return toast('Önce bir alan oluşturun.', 'err');
  formModal({ title: 'Banka kredisi', note: 'Eşit taksitli plan oluşturulur (anapara/faiz ayrımlı). Faiz oranını bankanızın sözleşmesindeki aylık oran olarak girin; vergi/masraf kalemleri dahil değildir.', fields: [
    { name: 'workspace_id', label: 'Alan', type: 'select', options: () => wsOptions(), required: true }, { name: 'name', label: 'Kredi adı', required: true }, { name: 'bank_name', label: 'Banka' },
    { name: 'currency', label: 'Para birimi', type: 'select', options: CURRENCIES.map((c) => [c, c]), value: 'TRY' }, { name: 'principal_minor', label: 'Kredi tutarı (anapara)', type: 'money', required: true },
    { name: 'rate', label: 'Aylık faiz oranı (%)', type: 'text', value: '0', hint: 'Örn. 2,49' }, { name: 'term_months', label: 'Vade (ay)', type: 'number', min: 1, max: 360, value: 12, required: true },
    { name: 'start_date', label: 'Kullandırım tarihi (ilk taksit +1 ay)', type: 'date', value: todayStr(), required: true }],
    onSubmit: async (v, key) => {
      const rate = Number(String(v.rate || '0').replace(',', '.'));
      if (!(rate >= 0 && rate <= 50)) throw new Error('Faiz oranı 0-50 arasında olmalı.');
      await api.post('/loans', { workspace_id: Number(v.workspace_id), name: v.name, bank_name: v.bank_name, currency: v.currency, principal_minor: v.principal_minor, monthly_rate_bp: Math.round(rate * 100), term_months: Number(v.term_months), start_date: v.start_date }, { idem: key }); await done(cb, 'Kredi ve taksit planı oluşturuldu')(); } });
}
export function loanPayForm(loan, inst, cb) {
  const opts = accOptions(null, loan.currency);
  if (!opts.length) return toast(`${loan.currency} para biriminde hesap yok.`, 'err');
  formModal({ title: `Taksit ${inst.seq}/${loan.term_months} öde`, note: `Anapara ${money(inst.principal_minor, loan.currency)} (gider değil) + faiz ${money(inst.interest_minor, loan.currency)} (gider).`, fields: [
    { name: 'account_id', label: 'Ödeme hesabı', type: 'select', options: opts, required: true }, { name: 'date', label: 'Tarih', type: 'date', value: todayStr(), required: true }],
    onSubmit: async (v, key) => { await api.post(`/loans/${loan.id}/pay`, { account_id: Number(v.account_id), installment_id: inst.id, date: v.date }, { idem: key }); await done(cb, 'Taksit ödendi')(); } });
}
export function disburseForm(loan, cb) {
  const opts = accOptions(null, loan.currency);
  if (!opts.length) return toast(`${loan.currency} para biriminde hesap yok.`, 'err');
  formModal({ title: 'Kredi kullandırımı', note: 'Kredi tutarı hesaba girer; gelir sayılmaz.', fields: [{ name: 'account_id', label: 'Hangi hesaba yattı?', type: 'select', options: opts, required: true }, { name: 'date', label: 'Tarih', type: 'date', value: loan.start_date, required: true }],
    onSubmit: async (v, key) => { await api.post(`/loans/${loan.id}/disburse`, { account_id: Number(v.account_id), date: v.date }, { idem: key }); await done(cb, 'Kullandırım kaydedildi')(); } });
}

export function projectForm(p, cb) {
  formModal({ title: p ? 'Projeyi düzenle' : 'Yeni proje / iş', wide: true, fields: [
    { name: 'name', label: 'Proje adı', required: true, value: p && p.name }, { name: 'contact_id', label: 'Müşteri', type: 'select', options: contactOptions('customer', 'Müşteri yok'), value: p && p.contact_id },
    { name: 'workspace_id', label: 'Alan', type: 'select', options: () => wsOptions('Alan yok'), value: p && p.workspace_id }, { name: 'currency', label: 'Para birimi', type: 'select', options: CURRENCIES.map((c) => [c, c]), value: p ? p.currency : 'TRY' },
    { name: 'contract_minor', label: 'Sözleşme tutarı', type: 'money', value: p ? p.contract_minor : 0 }, { name: 'est_cost_minor', label: 'Tahmini maliyet', type: 'money', value: p ? p.est_cost_minor : 0 },
    { name: 'status', label: 'Durum', type: 'select', value: p ? p.status : 'active', options: [['planned', 'Planlandı'], ['active', 'Devam ediyor'], ['done', 'Tamamlandı'], ['cancelled', 'İptal']] },
    { name: 'start_date', label: 'Başlangıç', type: 'date', value: p && p.start_date }, { name: 'end_date', label: 'Bitiş', type: 'date', value: p && p.end_date }, { name: 'note', label: 'Not', type: 'textarea', full: true, value: p && p.note }],
    onSubmit: async (v, key) => { const b = { name: v.name, contact_id: idv(v.contact_id), workspace_id: idv(v.workspace_id), currency: v.currency, contract_minor: v.contract_minor || 0, est_cost_minor: v.est_cost_minor || 0, status: v.status, start_date: v.start_date, end_date: v.end_date, note: v.note };
      if (p) await api.patch('/projects/' + p.id, b); else await api.post('/projects', b, { idem: key }); await done(cb, 'Proje kaydedildi')(); } });
}
export function milestoneForm(p, cb) {
  formModal({ title: 'Hakediş / ara ödeme planı', note: 'Alacak kalemi oluşturur ve proje gelirine tahakkuk eder; tahsilat “Alacak, Borç & Takvim” sayfasından girilir.', fields: [
    { name: 'title', label: 'Başlık (ör. 1. Hakediş)', required: true }, { name: 'amount_minor', label: 'Tutar (' + p.currency + ')', type: 'money', required: true }, { name: 'due_date', label: 'Vade', type: 'date', required: true, value: addDays(todayStr(), 30) }],
    onSubmit: async (v, key) => { await api.post(`/projects/${p.id}/milestones`, { title: v.title, amount_minor: v.amount_minor, due_date: v.due_date }, { idem: key }); await done(cb, 'Hakediş planlandı')(); } });
}
export function quoteForm(p, cb) {
  formModal({ title: 'Teklif / sipariş kaydı', fields: [
    { name: 'kind', label: 'Tür', type: 'select', options: [['quote', 'Teklif'], ['order', 'Sipariş']] }, { name: 'number', label: 'No' }, { name: 'quote_date', label: 'Tarih', type: 'date', value: todayStr(), required: true },
    { name: 'amount_minor', label: 'Tutar (' + p.currency + ')', type: 'money', required: true }, { name: 'status', label: 'Durum', type: 'select', options: [['draft', 'Taslak'], ['sent', 'Gönderildi'], ['accepted', 'Kabul'], ['rejected', 'Red']] }, { name: 'note', label: 'Not', full: true }],
    onSubmit: async (v, key) => { await api.post(`/projects/${p.id}/quotes`, { kind: v.kind, number: v.number, quote_date: v.quote_date, amount_minor: v.amount_minor, status: v.status, note: v.note }, { idem: key }); await done(cb, 'Kaydedildi')(); } });
}

export function itemForm(it, cb) {
  formModal({ title: it ? 'Ürün / hizmeti düzenle' : 'Yeni ürün / hizmet', wide: true, fields: [
    { name: 'kind', label: 'Tür', type: 'select', value: it ? it.kind : 'product', options: [['product', 'Ürün (stok izlenir)'], ['service', 'Hizmet']] }, { name: 'code', label: 'Ürün kodu', value: it && it.code },
    { name: 'name', label: 'Ad', required: true, value: it && it.name, full: true }, { name: 'unit', label: 'Birim', value: it ? it.unit : 'adet' }, { name: 'barcode', label: 'Barkod (isteğe bağlı)', value: it && it.barcode },
    { name: 'sale_price_minor', label: 'Satış fiyatı (KDV hariç)', type: 'money', value: it ? it.sale_price_minor : 0 }, { name: 'vat_rate_bp', label: 'KDV', type: 'select', options: vatOptions(), value: it ? it.vat_rate_bp : 2000 },
    { name: 'min_qty', label: 'Asgari stok uyarı miktarı', type: 'qty', value: it ? it.min_qty_milli : 0 }],
    onSubmit: async (v, key) => { const b = { kind: v.kind, code: v.code, name: v.name, unit: v.unit, barcode: v.barcode, sale_price_minor: v.sale_price_minor || 0, vat_rate_bp: Number(v.vat_rate_bp), min_qty_milli: v.min_qty || 0 };
      if (it) await api.patch('/stock/items/' + it.id, b); else await api.post('/stock/items', b, { idem: key }); await done(cb, 'Kaydedildi')(); } });
}
export function moveForm(cb, item) {
  const prods = state.meta.items.filter((i) => i.kind === 'product' && !i.archived);
  const whs = state.meta.warehouses.filter((w) => !w.archived);
  if (!prods.length) return toast('Önce bir ürün ekleyin.', 'err');
  if (!whs.length) return toast('Önce bir depo ekleyin.', 'err');
  formModal({ title: 'Stok hareketi', fields: [
    { name: 'item_id', label: 'Ürün', type: 'select', options: prods.map((i) => [i.id, i.name]), value: item && item.id, required: true }, { name: 'warehouse_id', label: 'Depo', type: 'select', options: whs.map((w) => [w.id, w.name]), required: true },
    { name: 'kind', label: 'Hareket', type: 'select', options: [['in', 'Stok girişi'], ['out', 'Stok çıkışı / tüketim'], ['return_in', 'İade girişi'], ['return_out', 'İade çıkışı (tedarikçiye)']] },
    { name: 'qty', label: 'Miktar', type: 'qty', required: true }, { name: 'unit_cost_minor', label: 'Birim maliyet (girişlerde)', type: 'money', hint: 'Çıkışlarda ortalama maliyet otomatik uygulanır' },
    { name: 'project_id', label: 'Proje (tüketimde maliyete yazılır)', type: 'select', options: projectOptions() }, { name: 'move_date', label: 'Tarih', type: 'date', value: todayStr(), required: true }, { name: 'note', label: 'Not', full: true }],
    onSubmit: async (v, key) => { const r = await api.post('/stock/movements', { item_id: Number(v.item_id), warehouse_id: Number(v.warehouse_id), kind: v.kind, qty_milli: v.qty, unit_cost_minor: v.unit_cost_minor || 0, project_id: idv(v.project_id), move_date: v.move_date, note: v.note }, { idem: key }); if (r.warning) toast(r.warning, 'err'); await done(cb, 'Stok hareketi kaydedildi')(); } });
}
export function warehouseForm(cb) {
  formModal({ title: 'Yeni depo', fields: [{ name: 'name', label: 'Depo adı', required: true }], onSubmit: async (v) => { await api.post('/stock/warehouses', { name: v.name }); await done(cb, 'Depo eklendi')(); } });
}
