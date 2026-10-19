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

/**
 * Fatura formu (yeni + düzenleme). Özellikler: isteğe bağlı vade (vadesiz / gün / tarih), satırda mevcut ürün, YENİ ürün kartı oluşturma,
 * hizmet/serbest satır, “stoğa ekle” (alış) / “stoktan düş” (satış), stokta olmayan ürün uyarısı ve ertelenmiş teslimat.
 * edit: GET /invoices/{id} yanıtı verilirse düzenleme kipinde açılır (PATCH).
 */
export function invoiceForm({ kind, preset, cb, edit = null }) {
  const ws = state.meta.workspaces.filter((w) => !w.archived);
  if (!ws.length) return toast('Önce bir işletme alanı oluşturun.', 'err');
  if (!state.meta.contacts.some((c) => !c.archived)) return toast('Önce bir cari (müşteri/tedarikçi) ekleyin.', 'err');
  const I = edit ? edit.invoice : null;
  kind = I ? I.kind : kind;
  const sales = kind === 'sales';
  const hasPays = !!(edit && (edit.payments || []).length);
  const items = state.meta.items.filter((i) => !i.archived);
  const lines = [];
  const linesBox = h('div', { class: 'stack' });
  const totals = h('div', { class: 'card flat stack tight' });
  const inv = (state.meta.settings.invoice || {});
  const stockInfo = {}; // item_id → {wh:{id:qty}, qty}
  api.get('/stock/items').then((r) => { r.items.forEach((x) => { stockInfo[x.id] = x; }); lines.forEach((l) => l.refreshStock()); }).catch(() => {});

  // ---- vade: vadesiz / gün / tarih ----
  let dueDays = null;
  const defDays = Number(inv.default_due_days ?? 0);
  const dueInput = h('input', { class: 'input', type: 'date', value: I ? (I.due_date || '') : (defDays > 0 ? addDays(todayStr(), defDays) : '') });
  const dueDaysInput = h('input', { class: 'input', type: 'number', min: 0, max: 3650, placeholder: 'gün', 'aria-label': 'Vade günü' });
  const chipBox = h('div', { class: 'row tight' });
  const issueInput = h('input', { class: 'input', type: 'date', value: I ? I.issue_date : todayStr() });
  const setDueDays = (d) => { dueDays = d; dueInput.value = d === null ? '' : addDays(issueInput.value || todayStr(), d); dueDaysInput.value = d === null ? '' : String(d); drawChips(); };
  const drawChips = () => mount(chipBox, [[null, 'Vadesiz'], [7, '7 gün'], [15, '15 gün'], [30, '30 gün'], [45, '45 gün'], [60, '60 gün']].map(([d, l]) => h('button', { type: 'button', class: 'btn sm' + (dueDays === d && (d !== null || !dueInput.value) ? ' primary' : ''), onclick: () => setDueDays(d) }, l)));
  if (!I && defDays > 0) dueDays = defDays;
  dueInput.addEventListener('change', () => { dueDays = null; dueDaysInput.value = ''; drawChips(); });
  dueDaysInput.addEventListener('input', () => { const n = dueDaysInput.value === '' ? null : Number(dueDaysInput.value); if (n !== null && n >= 0) setDueDays(n); });
  issueInput.addEventListener('change', () => { if (dueDays !== null) dueInput.value = addDays(issueInput.value || todayStr(), dueDays); });
  drawChips();

  const header = {
    workspace_id: h('select', { class: 'input', disabled: hasPays }, ws.map((w) => h('option', { value: w.id, selected: I && w.id === I.workspace_id }, w.name))),
    contact_id: h('select', { class: 'input', disabled: hasPays }, contactOptions(sales ? 'customer' : 'supplier', 'Cari seçin').map(([v, l]) => h('option', { value: v, selected: I && Number(v) === I.contact_id }, l))),
    number: h('input', { class: 'input', type: 'text', maxlength: 60, placeholder: sales ? 'Fatura no' : 'Fatura no (zorunlu)', value: I ? (I.number || '') : '' }),
    currency: h('select', { class: 'input', disabled: hasPays }, CURRENCIES.map((c) => h('option', { value: c, selected: I && c === I.currency }, c))),
    project_id: h('select', { class: 'input' }, projectOptions().map(([v, l]) => h('option', { value: v, selected: I && Number(v) === Number(I.project_id) }, l))),
    status: h('select', { class: 'input' }, [['issued', 'Fatura kesildi / alındı'], ['planned', 'Planla (henüz kesilmedi)']].map(([v, l]) => h('option', { value: v }, l))),
    note: h('textarea', { class: 'input', rows: 2, maxlength: 2000 }, I ? (I.note || '') : (inv.note || '')),
  };
  if (preset && preset.contact_id) header.contact_id.value = preset.contact_id;
  const deferBox = h('input', { type: 'checkbox', checked: !!(I && Number(I.defer_delivery)) });
  const allStock = h('input', { type: 'checkbox', checked: false });
  const msg = h('div', { class: 'alert err hide', role: 'alert' });
  let lid = 0;
  const L = (label, el, cls = '') => { const id = 'inv_' + (++lid); el.id = id; return h('div', { class: 'field ' + cls }, h('label', { for: id }, label), el); };

  const recalc = () => {
    let sub = 0; let vat = 0;
    lines.forEach((l) => {
      const q = qtyMilli(l.qty.value); const p = parseMoney(l.price.value);
      const d = Math.round(Number(String(l.disc.value || '0').replace(',', '.')) * 100); const v = Number(l.vat.value);
      if (q && p !== null && p >= 0 && d >= 0 && d <= 10000) { const c = calcLine(q, p, d, v); l.total.textContent = money(c.total, header.currency.value); sub += c.net; vat += c.vat; } else l.total.textContent = '—';
      l.refreshStock();
    });
    mount(totals, h('div', { class: 'row between' }, 'KDV hariç ara toplam', h('b', null, money(sub, header.currency.value))), h('div', { class: 'row between' }, 'KDV', h('b', null, money(vat, header.currency.value))), h('div', { class: 'row between' }, h('b', null, 'Genel toplam'), h('b', null, money(sub + vat, header.currency.value))));
  };

  const addLine = (init = null) => {
    const l = { id: init ? init.id : null };
    l.item = h('select', { class: 'input' }, [h('option', { value: '' }, 'Serbest satır (stok kartı yok)'), h('option', { value: '__new' }, '➕ Yeni ürün / hizmet kartı oluştur'), ...items.map((i) => h('option', { value: i.id }, (i.code ? i.code + ' · ' : '') + i.name + (i.kind === 'service' ? ' (hizmet)' : '')))]);
    l.desc = h('input', { class: 'input', type: 'text', maxlength: 300, placeholder: 'Açıklama / ürün adı' });
    l.qty = h('input', { class: 'input', type: 'text', inputmode: 'decimal', value: '1' });
    l.price = h('input', { class: 'input', type: 'text', inputmode: 'decimal', placeholder: '0,00' });
    l.disc = h('input', { class: 'input', type: 'text', inputmode: 'decimal', value: '0' });
    l.vat = h('select', { class: 'input' }, vatOptions().map(([v, lab]) => h('option', { value: v, selected: v === 2000 }, lab)));
    l.newKind = h('select', { class: 'input' }, [['product', 'Ürün (stok takipli olabilir)'], ['service', 'Hizmet (nakliye, işçilik vb. — stok yok)']].map(([v, lab]) => h('option', { value: v }, lab)));
    l.newCode = h('input', { class: 'input', type: 'text', maxlength: 60, placeholder: 'Ürün kodu (isteğe bağlı)' });
    l.newUnit = h('input', { class: 'input', type: 'text', maxlength: 20, value: 'adet' });
    l.stock = h('input', { type: 'checkbox' });
    l.wh = h('select', { class: 'input' }, [...state.meta.warehouses.filter((w) => !w.archived).map((w) => h('option', { value: w.id }, w.name)), ...(state.meta.warehouses.some((w) => !w.archived) ? [] : [h('option', { value: '' }, 'Ana Depo (otomatik açılır)')])]);
    l.total = h('div', { class: 'num' }, '—');
    l.hint = h('div', { class: 'hint' });
    l.stockHint = h('div', { class: 'hint' });
    l.newBox = h('div', { class: 'form-grid hide' }, L('Kart türü', l.newKind), L('Kod', l.newCode), L('Birim', l.newUnit), h('div', { class: 'full' }, l.hint));
    l.stockBox = h('div', { class: 'form-grid hide' }, h('div', { class: 'field' }, h('label', { class: 'check' }, l.stock, sales ? 'Stoktan düş (teslim edilen miktar kadar)' : 'Stoğa ekle')), L('Depo', l.wh), h('div', { class: 'full' }, l.stockHint));
    const row = h('div', { class: 'card flat stack tight' },
      h('div', { class: 'form-grid' }, L('Ürün / hizmet', l.item), L('Açıklama *', l.desc), L('Miktar', l.qty), L('Birim fiyat', l.price), L('İskonto %', l.disc), L('KDV', l.vat)),
      l.newBox, l.stockBox,
      h('div', { class: 'row between' }, h('button', { class: 'btn sm ghost', type: 'button', onclick: () => { lines.splice(lines.indexOf(l), 1); row.remove(); recalc(); } }, '🗑 Satırı sil'), h('div', null, 'Satır toplamı: ', l.total)));
    l.row = row;
    const isProduct = () => { if (l.item.value === '__new') return l.newKind.value === 'product'; const it = items.find((i) => i.id === Number(l.item.value)); return !!(it && it.kind === 'product'); };
    l.syncUi = () => {
      l.newBox.classList.toggle('hide', l.item.value !== '__new');
      l.stockBox.classList.toggle('hide', !isProduct());
      l.wh.closest('.field').classList.toggle('hide', !l.stock.checked);
      l.refreshStock();
    };
    l.refreshStock = () => {
      if (!isProduct() || !l.stock.checked) { l.stockHint.textContent = ''; return; }
      if (l.item.value === '__new') { l.stockHint.textContent = sales ? 'Yeni kartın stoğu 0’dır: satış “karşılanmamış (bekleyen teslimat)” olarak izlenir.' : 'Yeni karta bu faturadaki miktar kadar stok girişi yapılır.'; return; }
      const si = stockInfo[Number(l.item.value)];
      if (!si) { l.stockHint.textContent = ''; return; }
      const whId = l.wh.value ? Number(l.wh.value) : null;
      const have = whId && si.by_warehouse && si.by_warehouse[whId] !== undefined ? si.by_warehouse[whId] : si.qty_milli;
      const q = qtyMilli(l.qty.value) || 0;
      if (sales && !deferBox.checked) {
        l.stockHint.textContent = q > have ? `⚠ Stokta ${fmtQty(Math.max(have, 0))} ${si.unit} var; ${fmtQty(q - Math.max(have, 0))} ${si.unit} karşılanamayacak ve “bekleyen teslimat” olarak ayrı izlenecek (stok eksiye düşmez).` : `Depoda ${fmtQty(have)} ${si.unit} var.`;
      } else l.stockHint.textContent = sales ? 'Teslimat ertelendi: stok teslim işlenince düşer.' : `Mevcut stok ${fmtQty(have)} ${si.unit}; fatura sonrası ${fmtQty(have + q)}.`;
    };
    l.item.addEventListener('change', () => {
      const it = items.find((i) => i.id === Number(l.item.value));
      if (it) { l.desc.value = it.name; if (sales) l.price.value = plain(it.sale_price_minor); l.vat.value = String(it.vat_rate_bp); l.stock.checked = it.kind === 'product' && (sales || allStock.checked); }
      if (l.item.value === '__new') l.stock.checked = (l.newKind.value === 'product') && (allStock.checked || sales);
      l.syncUi(); recalc();
    });
    l.newKind.addEventListener('change', () => { if (l.newKind.value === 'service') l.stock.checked = false; l.syncUi(); });
    l.stock.addEventListener('change', l.syncUi);
    l.wh.addEventListener('change', l.refreshStock);
    l.desc.addEventListener('blur', async () => { // aynı/benzer ürün kartı var mı?
      if (l.item.value !== '__new' || !l.desc.value.trim()) return;
      try {
        const r = await api.get('/stock/match', { name: l.desc.value.trim(), code: l.newCode.value.trim() });
        if (r.exact) l.hint.textContent = `“${r.exact.name}” adlı kart zaten var: bu satır mevcut karta bağlanır (yeni kart açılmaz).`;
        else if (r.similar.length) { mount(l.hint, 'Benzer kartlar var — aynı ürünse listeden seçin: ', r.similar.map((x) => h('button', { type: 'button', class: 'btn sm', onclick: () => { l.item.value = String(x.id); l.item.dispatchEvent(new Event('change')); } }, x.name))); } else l.hint.textContent = 'Yeni kart oluşturulacak.';
      } catch { /* öneri alınamadı: sorun değil */ }
    });
    row.addEventListener('input', recalc);
    row.addEventListener('change', recalc);
    lines.push(l); linesBox.appendChild(row);
    if (init) { // düzenleme: mevcut satırı doldur
      l.item.value = init.item_id ? String(init.item_id) : '';
      l.desc.value = init.description; l.qty.value = fmtQty(init.qty_milli); l.price.value = plain(init.unit_price_minor); l.disc.value = String(init.discount_bp / 100).replace('.', ','); l.vat.value = String(init.vat_rate_bp);
      l.stock.checked = !!init.tracked; if (init.warehouse_id) l.wh.value = String(init.warehouse_id);
    }
    l.syncUi(); recalc();
    return l;
  };
  header.currency.addEventListener('change', recalc);
  allStock.addEventListener('change', () => { lines.forEach((l) => { const prod = l.item.value === '__new' ? l.newKind.value === 'product' : (items.find((i) => i.id === Number(l.item.value)) || {}).kind === 'product'; if (prod) l.stock.checked = allStock.checked; l.syncUi(); }); });
  deferBox.addEventListener('change', recalc);
  if (edit) edit.lines.forEach((x) => addLine(x)); else addLine();
  const stockOpts = sales
    ? h('div', { class: 'card flat stack tight' }, h('label', { class: 'check' }, deferBox, 'Ürünleri şimdi teslim etme (stok, teslimat işlenince düşsün)'), h('div', { class: 'hint' }, 'Satış faturası ile ürünün teslimi ayrı izlenir. Stokta yeterli ürün yoksa satış yine kaydedilir; eksik miktar “bekleyen teslimat” olur, stok eksiye düşmez.'))
    : h('div', { class: 'card flat stack tight' }, h('label', { class: 'check' }, allStock, 'Faturadaki ürünleri stoğa ekle'), h('div', { class: 'hint' }, 'İşaretlerseniz ürün satırları stoğa girer; hizmet/nakliye satırları stok ürünü sayılmaz. Her satırda ayrıca seçebilirsiniz. Fatura kaydı ile stok hareketi ayrı şeylerdir.'));
  const body = h('div', { class: 'stack' }, msg,
    h('div', { class: 'form-grid' }, L('Alan', header.workspace_id), L(sales ? 'Müşteri *' : 'Tedarikçi *', header.contact_id), L('Fatura no', header.number), edit ? null : L('Durum', header.status), L('Fatura tarihi', issueInput),
      L('Para birimi', header.currency), L('Proje', header.project_id)),
    h('div', { class: 'card flat stack tight' }, h('div', { class: 'row between' }, h('b', null, 'Vade (isteğe bağlı)'), chipBox), h('div', { class: 'form-grid' }, L('Vade tarihi', dueInput, ''), L('veya vade günü', dueDaysInput, '')), h('div', { class: 'hint' }, 'Boş bırakırsanız fatura vadesiz kaydedilir; gecikmiş borç/alacak olarak raporlanmaz.')),
    L('Not', header.note, 'full'),
    hasPays ? h('div', { class: 'alert warn small' }, 'Bu faturada ödeme/tahsilat dağıtımı var: cari, alan ve para birimi değiştirilemez; toplam, ödenen tutarın altına düşemez.') : null,
    h('h3', null, 'Satırlar'), stockOpts, linesBox, h('div', null, h('button', { class: 'btn sm', type: 'button', onclick: () => addLine() }, '＋ Satır ekle')), totals);
  const key = (window.crypto.randomUUID ? window.crypto.randomUUID().replace(/-/g, '') : String(Math.random()).slice(2) + Date.now());
  const btn = h('button', { class: 'btn primary', type: 'button' }, edit ? 'Değişiklikleri kaydet' : sales ? 'Faturayı kaydet' : 'Alış faturasını kaydet');
  const m = modal({ title: (edit ? 'Faturayı düzenle — ' : '') + (sales ? 'Satış faturası' : 'Alış faturası'), wide: true, body, foot: [h('button', { class: 'btn', type: 'button', onclick: () => m.close() }, 'Vazgeç'), btn], dirty: () => true });
  btn.addEventListener('click', async () => {
    msg.classList.add('hide');
    const ls = [];
    for (const [i, l] of lines.entries()) {
      const q = qtyMilli(l.qty.value); const p = parseMoney(l.price.value); const d = Math.round(Number(String(l.disc.value || '0').replace(',', '.')) * 100);
      if (!l.desc.value.trim() || !q || p === null || p < 0 || !(d >= 0 && d <= 10000)) { msg.textContent = `${i + 1}. satırda açıklama, miktar, fiyat ve iskonto değerlerini kontrol edin.`; msg.classList.remove('hide'); return; }
      const o = { description: l.desc.value.trim(), qty_milli: q, unit_price_minor: p, discount_bp: d, vat_rate_bp: Number(l.vat.value) };
      if (l.id) o.id = l.id;
      if (l.item.value === '__new') { o.new_item = { kind: l.newKind.value, code: l.newCode.value.trim() || null, unit: l.newUnit.value.trim() || 'adet' }; if (l.newKind.value === 'product') o.stock = l.stock.checked; } else if (l.item.value) {
        o.item_id = Number(l.item.value);
        if ((items.find((x) => x.id === o.item_id) || {}).kind === 'product') o.stock = l.stock.checked;
      }
      if (o.stock) o.warehouse_id = l.wh.value ? Number(l.wh.value) : null;
      ls.push(o);
    }
    if (!header.contact_id.value) { msg.textContent = 'Cari seçin.'; msg.classList.remove('hide'); return; }
    btn.disabled = true;
    const common = { issue_date: issueInput.value, due_date: dueInput.value || null, number: header.number.value.trim() || null, project_id: idv(header.project_id.value), note: header.note.value, lines: ls, defer_delivery: sales ? deferBox.checked : false };
    try {
      let r;
      if (edit) r = await api.patch('/invoices/' + I.id, { ...common, ...(hasPays ? {} : { contact_id: Number(header.contact_id.value), workspace_id: Number(header.workspace_id.value), currency: header.currency.value }) });
      else r = await api.post('/invoices', { kind, workspace_id: Number(header.workspace_id.value), contact_id: Number(header.contact_id.value), currency: header.currency.value, status: header.status.value, ...common }, { idem: key });
      (r.warnings || []).forEach((w) => toast(w, 'err'));
      m.close(true); toast(edit ? 'Fatura güncellendi' : (r.new_items ? `Fatura kaydedildi; ${r.new_items} yeni ürün/hizmet kartı açıldı` : 'Fatura kaydedildi'), 'ok'); await loadMeta(); cb && cb();
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
