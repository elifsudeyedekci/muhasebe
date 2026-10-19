import { api } from '../api.js';
import { state, scopeParams, accOptions, catOptions, contactOptions, projectOptions } from '../state.js';
import { h, mount, loading, money, date, table, emptyState, errToast, toast, confirmDialog, KIND_TR, signClass, multi, menu, todayStr, addDays, debounce, printBtn } from '../ui.js';
import { txnForm, docsModal } from '../forms_core.js';
import { paymentDetail, paymentForm, ownerAccountModal } from '../forms_pay.js';
import { parseMoney } from '../ui.js';

export default async function render(ctx) {
  const { el, readonly } = ctx;
  const f = { from: addDays(todayStr(), -30), to: todayStr(), kind: '', account_id: '', category_id: '', contact_id: '', project_id: '', currency: '', q: '', status: 'posted', doc: '', amount_min: '', amount_max: '', personal: '', alloc: '' };
  const out = h('div');
  let offset = 0; let all = [];
  const kinds = [['', 'Tüm türler'], ...Object.entries(KIND_TR)];
  const inp = (label, name, type = 'text', extra = {}) => h('div', { class: 'field' }, h('label', null, label), h('input', { class: 'input', type, value: f[name], onchange: (e) => { f[name] = e.target.value; load(); }, ...extra }));
  const sel = (label, name, options) => h('div', { class: 'field' }, h('label', null, label), h('select', { class: 'input', onchange: (e) => { f[name] = e.target.value; load(); } }, options.map(([v, l]) => h('option', { value: v, selected: String(v) === String(f[name]) }, l))));
  const minor = (v) => { const x = parseMoney(v); return x === null ? '' : x; };
  const params = () => ({ ...f, amount_min: minor(f.amount_min), amount_max: minor(f.amount_max), ...scopeParams(), limit: 100, offset });
  const exportBtn = (fmt) => h('button', { class: 'btn', onclick: async () => { const q = new URLSearchParams({ ...f, ...scopeParams(), format: fmt }); for (const [k, v] of [...q]) if (!v) q.delete(k); try { await api.download('/export/transactions?' + q, `islemler.${fmt}`); } catch (e) { errToast(e); } } }, fmt === 'csv' ? '⬇ CSV' : '⬇ Excel');

  const draw = (res) => {
    const sums = res.sums;
    const sumLine = Object.entries(sums).map(([c, k]) => {
      const inn = (k.income || 0) + (k.collection || 0) + (k.loan_proceeds || 0); const outv = (k.expense || 0) + (k.payment || 0) + (k.card_payment || 0) + (k.loan_payment || 0);
      return h('div', null, h('b', null, c + ': '), h('span', { class: 'pos' }, 'Giriş ' + money(inn, c)), ' · ', h('span', { class: 'neg' }, 'Çıkış ' + money(outv, c)), k.transfer ? h('span', { class: 'muted' }, ` · Transfer ${money(k.transfer, c)} (gelir/gider değil)`) : null);
    });
    mount(out,
      h('div', { class: 'card flat small' }, sumLine.length ? sumLine : 'Seçili filtrede hareket yok.'),
      table({
        cols: [
          { key: 'txn_date', label: 'Tarih', render: (t) => date(t.txn_date) },
          { key: 'kind', label: 'Tür', render: (t) => h('div', null, h('span', { class: 'badge ' + ({ income: 'ok', collection: 'ok', loan_proceeds: 'ok', expense: 'bad', payment: 'bad', card_payment: 'bad', loan_payment: 'bad', owner_in: 'info', owner_out: 'info' }[t.kind] || '') }, KIND_TR[t.kind] || t.kind), t.for_workspace_id ? h('div', null, h('span', { class: 'badge info', title: 'Şahsi hesaptan işletme adına' }, 'şahsi → işletme')) : null) },
          { key: 'description', label: 'Açıklama', render: (t) => h('div', null, t.description || '—', t.reason ? h('div', { class: 'xs' }, 'Neden: ' + t.reason) : null, h('div', { class: 'xs muted' }, [t.category_name, t.contact_name, t.reference ? 'ref ' + t.reference : null, ['payment', 'collection'].includes(t.kind) && t.allocated_minor < t.amount_minor && t.status === 'posted' ? 'eşleştirilmemiş ' + money(t.amount_minor - t.allocated_minor, t.currency) : null].filter(Boolean).join(' · '),t.status === 'voided' ? ' · İPTAL: ' + (t.void_reason || '') : '', t.replaces_id ? ' · düzeltme' : '')) },
          { key: 'account_name', label: 'Hesap' },
          { key: 'amount_minor', label: 'Tutar', num: true, sort: (t) => (t.direction === 'in' ? 1 : -1) * t.amount_minor, render: (t) => h('span', { class: t.direction === 'in' ? 'pos' : 'neg' }, money((t.direction === 'in' ? 1 : -1) * t.amount_minor, t.currency, { sign: true }), t.vat_minor ? h('div', { class: 'xs muted' }, 'KDV ' + money(t.vat_minor, t.currency)) : null) },
          { label: '', act: true, sortable: false, render: (t) => h('div', { class: 'row tight' },
            ['payment', 'collection'].includes(t.kind) ? h('button', { class: 'btn sm', onclick: () => paymentDetail(t.id, { readonly, cb: load }) }, 'Ayrıntı') : null,
            h('button', { class: 'btn sm ghost', title: 'Belgeler', onclick: () => docsModal('Belgeler', 'transaction', t.id, readonly) }, '📎' + (t.doc_count ? ' ' + t.doc_count : '')),
            t.status === 'posted' ? (readonly && state.user.role === 'admin'
              ? h('button', { class: 'btn sm', onclick: () => adminVoid(t) }, 'Düzeltme (iptal)')
              : (readonly ? null : h('button', { class: 'btn sm ghost', onclick: (e) => menu(e.currentTarget, [
                ['income', 'expense'].includes(t.kind) ? { label: '✏️ Düzenle (düzeltme)', run: () => txnForm({ kind: t.kind, tx: t }, load) } : null,
                t.kind === 'income' && t.contact_id ? { label: '🔁 Cari tahsilatına çevir (gelir değil)', run: async () => { if (!await confirmDialog({ title: 'Cari tahsilatına çevir', text: 'Bu gelir kaydı müşteri tahsilatı/avansı olur: cari hesapta görünür ve gelir toplamından düşer. Para hareketi (banka bakiyesi) değişmez. Faturaya sonra eşleştirebilirsiniz.', ok: 'Çevir' })) return; try { await api.post(`/transactions//to-collection`); toast('Cari tahsilatına çevrildi', 'ok'); const m = await import('../state.js'); await m.loadMeta(); load(); } catch (e) { errToast(e); } } } : null,
                { label: '↩️ İptal et', run: () => voidTx(t) }]) }, '⋯'))) : null) },
        ], rows: all, rowClass: (t) => (t.status === 'voided' ? 'voided' : ''),
        empty: { icon: '💸', title: 'İşlem bulunamadı', text: 'Filtreleri değiştirin ya da yeni bir gelir/gider ekleyin.', action: readonly ? null : h('div', { class: 'row', style: null }, h('button', { class: 'btn primary', onclick: () => txnForm({ kind: 'income' }, load) }, '＋ Gelir'), h('button', { class: 'btn', onclick: () => txnForm({ kind: 'expense' }, load) }, '＋ Gider')) },
      }),
      res.count > all.length ? h('div', { class: 'row' }, h('button', { class: 'btn', onclick: () => { offset += 100; more(); } }, 'Daha fazla göster (' + (res.count - all.length) + ' kayıt daha)')) : null);
  };
  const voidTx = async (t) => {
    const reason = await confirmDialog({ title: 'İşlemi iptal et', text: 'İşlem silinmez; iptal edilmiş olarak geçmişte kalır ve bakiyeye etkisi geri alınır. Gerekçe yazın:', input: 'İptal gerekçesi', ok: 'İptal et', danger: true });
    if (!reason) return;
    try { await api.post(`/transactions/${t.id}/void`, { reason }); toast('İşlem iptal edildi', 'ok'); const m = await import('../state.js'); await m.loadMeta(); load(); } catch (e) { errToast(e); }
  };
  const adminVoid = async (t) => {
    const reason = await confirmDialog({ title: 'Yönetici düzeltmesi', text: 'Bu işlem iptal edilecek (silinmez), denetim kaydına yazılacak. Gerekçe:', input: 'Gerekçe', ok: 'Düzeltmeyi uygula', danger: true });
    if (!reason) return;
    try { await api.post(`/admin/tenants/${state.tenantId}/transactions/${t.id}/void`, { reason }); toast('Düzeltme uygulandı', 'ok'); load(); } catch (e) { errToast(e); }
  };
  const load = async () => {
    offset = 0; mount(out, loading());
    try { const r = await api.get('/transactions', params()); all = r.transactions; draw(r); } catch (e) { mount(out, h('div', { class: 'alert err' }, e.message)); }
  };
  const more = async () => { try { const r = await api.get('/transactions', params()); all = all.concat(r.transactions); draw(r); } catch (e) { errToast(e); } };

  mount(el,
    ctx.head('Gelir & Gider', 'Gerçekleşen para hareketleri. İleri tarihli ödemeler için “Alacak, Borç & Takvim” sayfasını kullanın.', [
      readonly ? null : h('button', { class: 'btn primary', onclick: () => txnForm({ kind: 'income' }, load) }, '＋ Gelir'), readonly ? null : h('button', { class: 'btn', onclick: () => txnForm({ kind: 'expense' }, load) }, '＋ Gider'), readonly ? null : h('button', { class: 'btn', onclick: () => paymentForm({ kind: 'payment', cb: load }) }, '＋ Ödeme / tahsilat'), h('button', { class: 'btn', onclick: () => ownerAccountModal() }, '🤝 Ortak cari'), exportBtn('csv'), exportBtn('xlsx'), printBtn()]),
    h('div', { class: 'filters' }, inp('Başlangıç', 'from', 'date'), inp('Bitiş', 'to', 'date'),
      sel('Tür', 'kind', kinds), sel('Hesap', 'account_id', accOptions('Tüm hesaplar')), sel('Kategori', 'category_id', [['', 'Tüm kategoriler'], ...state.meta.categories.map((c) => [c.id, c.name])]),
      sel('Cari', 'contact_id', contactOptions(null, 'Tüm cariler')), sel('Proje', 'project_id', projectOptions('Tüm projeler')), sel('Para birimi', 'currency', [['', 'Hepsi'], ['TRY', 'TRY'], ['EUR', 'EUR'], ['USD', 'USD']]),
      sel('Durum', 'status', [['posted', 'Aktif'], ['voided', 'İptal edilenler']]), sel('Belge', 'doc', [['', 'Hepsi'], ['with', 'Belgeli'], ['without', 'Belgesiz']]),
      inp('En az tutar', 'amount_min', 'text', { placeholder: '0,00' }), inp('En çok tutar', 'amount_max', 'text', { placeholder: '0,00' }), sel('Kaynak', 'personal', [['', 'Hepsi'], ['1', 'Şahsi hesaptan işletme adına']]), sel('Eşleştirme', 'alloc', [['', 'Hepsi'], ['unallocated', 'Eşleştirilmemiş ödeme/tahsilat'], ['allocated', 'Faturaya eşleşmiş']]),
      h('div', { class: 'field' }, h('label', null, 'Ara'), h('input', { class: 'input', type: 'search', placeholder: 'Açıklama, neden, referans, cari', oninput: debounce((e) => { f.q = e.target.value; load(); }) }))),
    out);
  load();
}
