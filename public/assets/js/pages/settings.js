import { api } from '../api.js';
import { state, loadMeta, wsOptions, accOptions } from '../state.js';
import { h, mount, loading, money, date, table, errToast, toast, tabs, formModal, CURRENCIES, todayStr, confirmDialog, parseMoney } from '../ui.js';
import { securityPanel } from '../security_panel.js';
import { applyTheme } from '../app.js';

export default async function render(ctx) {
  const { el } = ctx;
  let tab = ctx.query.tab || 'profile';
  const body = h('div', { class: 'stack' });
  const draw = () => mount(el, ctx.head('Ayarlar', 'Hesabınız, güvenlik, tercihler ve veriler.'),
    tabs([['profile', 'Profil'], ['security', 'Güvenlik'], ['prefs', 'Tercihler'], ['cats', 'Kategoriler'], ['fx', 'Kurlar'], ['data', 'Veri']], tab, (k) => { tab = k; draw(); load(); }), body);

  async function load() {
    mount(body, loading());
    try {
      if (tab === 'security') return mount(body, securityPanel(state.user));
      const r = await api.get('/settings'); const s = r.settings;
      if (tab === 'profile') {
        const name = h('input', { class: 'input', value: r.user.display_name || '' }); const comp = h('input', { class: 'input', value: r.tenant.company_name || '' }); const ph = h('input', { class: 'input', value: r.tenant.phone || '' });
        mount(body, h('div', { class: 'card stack' }, h('div', { class: 'kv' }, h('dt', null, 'E-posta (giriş)'), h('dd', null, r.user.email), h('dt', null, 'Hesap oluşturma'), h('dd', null, date(r.tenant.created_at.slice(0, 10)))),
          h('div', { class: 'form-grid' }, h('div', { class: 'field' }, h('label', null, 'Görünen ad'), name), h('div', { class: 'field' }, h('label', null, 'Firma / ünvan'), comp), h('div', { class: 'field' }, h('label', null, 'Telefon'), ph)),
          h('div', null, h('button', { class: 'btn primary', onclick: async () => { try { await api.patch('/auth/profile', { display_name: name.value }); await api.put('/settings', { company_name: comp.value, phone: ph.value }); toast('Profil kaydedildi', 'ok'); } catch (e) { errToast(e); } } }, 'Kaydet')),
          h('div', { class: 'small muted' }, 'E-posta adresini yalnızca yönetici değiştirebilir.')));
      } else if (tab === 'prefs') prefs(r);
      else if (tab === 'cats') cats();
      else if (tab === 'fx') fx();
      else if (tab === 'data') data();
    } catch (e) { mount(body, h('div', { class: 'alert err' }, e.message)); }
  }

  function prefs(r) {
    const s = r.settings;
    const sel = (opts, v) => h('select', { class: 'input' }, opts.map(([a, b]) => h('option', { value: a, selected: String(a) === String(v ?? '') }, b)));
    const theme = sel([['auto', 'Otomatik (cihaz)'], ['light', 'Açık'], ['dark', 'Koyu']], s.theme);
    const defWs = sel(wsOptions('Seçilmedi'), s.default_workspace_id); const defAcc = sel(accOptions('Seçilmedi'), s.default_account_id);
    const cur = sel(CURRENCIES.map((c) => [c, c]), s.preferred_currency);
    const vat = h('input', { class: 'input', value: (s.vat_rates || []).map((x) => x / 100).join(', ') });
    const chk = (k, label) => { const i = h('input', { type: 'checkbox', checked: !!s.notify[k] }); i.dataset.k = k; return h('label', { class: 'check' }, i, label); };
    const nboxes = [chk('due', 'Yaklaşan vadeler (3 gün)'), chk('overdue', 'Gecikmiş kalemler'), chk('card', 'Kart son ödeme tarihleri'), chk('bills', 'Şahsi fatura hatırlatmaları'), chk('email', 'E-posta ile günlük özet (tutar içermez)')];
    const prefix = h('input', { class: 'input', value: s.invoice.prefix, maxlength: 12 }); const dd = h('input', { class: 'input', type: 'number', min: 0, max: 365, value: s.invoice.default_due_days }); const note = h('textarea', { class: 'input', rows: 2, maxlength: 500 }, s.invoice.note);
    mount(body, h('div', { class: 'card stack' }, h('h2', null, 'Görünüm & varsayılanlar'),
      h('div', { class: 'form-grid' }, h('div', { class: 'field' }, h('label', null, 'Tema'), theme), h('div', { class: 'field' }, h('label', null, 'Ana para birimi tercihi'), cur), h('div', { class: 'field' }, h('label', null, 'Varsayılan alan'), defWs), h('div', { class: 'field' }, h('label', null, 'Varsayılan hesap'), defAcc),
        h('div', { class: 'field full' }, h('label', null, 'KDV oranları (virgülle, %)'), vat, h('div', { class: 'hint' }, 'Örn: 0, 10, 20 — kod içinde sabit değildir; burada tanımlarsınız.'))),
      h('h2', null, 'Bildirimler'), h('div', { class: 'stack tight' }, nboxes),
      h('h2', null, 'Fatura & belge tercihleri'), h('div', { class: 'form-grid' }, h('div', { class: 'field' }, h('label', null, 'Numara ön eki'), prefix), h('div', { class: 'field' }, h('label', null, 'Varsayılan vade (gün)'), dd), h('div', { class: 'field full' }, h('label', null, 'Varsayılan fatura notu'), note)),
      h('div', { class: 'small muted' }, 'Tarih ve sayı biçimi: Türkiye standardı (GG.AA.YYYY, 1.234,56); saat dilimi Europe/Istanbul.'),
      h('div', null, h('button', { class: 'btn primary', onclick: async () => {
        const notify = {}; nboxes.forEach((l) => { const i = l.querySelector('input'); notify[i.dataset.k] = i.checked; });
        const rates = vat.value.split(',').map((x) => Math.round(Number(x.trim().replace(',', '.')) * 100)).filter((x) => Number.isFinite(x) && x >= 0 && x <= 10000);
        try { await api.put('/settings', { theme: theme.value, preferred_currency: cur.value, default_workspace_id: defWs.value ? Number(defWs.value) : null, default_account_id: defAcc.value ? Number(defAcc.value) : null, vat_rates: rates, notify, invoice: { prefix: prefix.value, default_due_days: Number(dd.value), note: note.value } }); applyTheme(theme.value); await loadMeta(); toast('Tercihler kaydedildi', 'ok'); } catch (e) { errToast(e); }
      } }, 'Kaydet'))));
  }

  async function cats() {
    const draw2 = async () => {
      const r = await api.get('/categories');
      const col = (kind, title) => h('div', { class: 'card stack' }, h('div', { class: 'card-title' }, title, h('button', { class: 'btn sm', onclick: () => formModal({ title: 'Yeni kategori', fields: [{ name: 'name', label: 'Ad', required: true }], onSubmit: async (v) => { await api.post('/categories', { kind, name: v.name }); await loadMeta(); load(); } }) }, '＋')),
        h('div', { class: 'list' }, r.categories.filter((c) => c.kind === kind).map((c) => h('div', { class: 'list-item' }, h('div', { class: 'grow' }, c.name, Number(c.archived) ? h('span', { class: 'badge' }, 'Arşiv') : null),
          h('button', { class: 'btn sm ghost', onclick: () => formModal({ title: 'Kategoriyi düzenle', fields: [{ name: 'name', label: 'Ad', required: true, value: c.name }], onSubmit: async (v) => { await api.patch('/categories/' + c.id, { name: v.name }); await loadMeta(); load(); } }) }, '✏️'),
          h('button', { class: 'btn sm ghost', onclick: async () => { await api.patch('/categories/' + c.id, { archived: !Number(c.archived) }); await loadMeta(); load(); } }, Number(c.archived) ? '↩' : '🗄')))));
      mount(body, h('div', { class: 'auto-grid' }, col('income', 'Gelir kategorileri'), col('expense', 'Gider kategorileri')));
    };
    await draw2();
  }

  async function fx() {
    const r = await api.get('/fx-rates');
    mount(body, h('div', { class: 'alert info small' }, 'Kurlar yalnızca ortak para birimine çevrilmiş özetlerde kullanılır; bakiyeler döviz cinsinden ayrı tutulur. 1 birim döviz = kaç TL.'),
      h('div', { class: 'row' }, h('button', { class: 'btn primary', onclick: () => formModal({ title: 'Kur gir', fields: [{ name: 'currency', label: 'Döviz', type: 'select', options: [['EUR', 'EUR'], ['USD', 'USD']] }, { name: 'rate', label: 'Kur (TL)', required: true, hint: 'Örn. 38,4521' }, { name: 'rate_date', label: 'Tarih', type: 'date', value: todayStr(), required: true }],
        onSubmit: async (v) => { await api.post('/fx-rates', { currency: v.currency, rate: String(v.rate).replace(',', '.'), rate_date: v.rate_date }); load(); } }) }, '＋ Kur ekle'),
        h('button', { class: 'btn', onclick: async () => { try { const x = await api.post('/fx-rates/fetch'); toast(`${x.updated} kur güncellendi (TCMB)`, 'ok'); load(); } catch (e) { errToast(e); } } }, '🏛 TCMB’den çek')),
      table({ cols: [{ key: 'rate_date', label: 'Tarih', render: (x) => date(x.rate_date) }, { key: 'currency', label: 'Döviz' }, { key: 'rate', label: 'Kur', num: true, render: (x) => Number(x.rate).toLocaleString('tr-TR', { minimumFractionDigits: 2, maximumFractionDigits: 6 }) }, { key: 'source', label: 'Kaynak', render: (x) => (x.source === 'tcmb' ? 'TCMB' : 'Elle') }], rows: r.rates, empty: { icon: '💱', title: 'Kur girilmemiş' } }));
  }

  function data() {
    mount(body, h('div', { class: 'card stack' }, h('h2', null, 'Verilerimi dışa aktar'), h('p', { class: 'muted' }, 'Tüm kayıtlarınızı (hesaplar, işlemler, faturalar, cariler…) JSON olarak indirebilirsiniz. Belge dosyaları ilgili kayıtlardan tek tek indirilir.'),
      h('div', null, h('button', { class: 'btn primary', onclick: async () => { try { await api.download('/settings/export', 'funbreak-veri.json'); } catch (e) { errToast(e); } } }, '⬇ JSON dışa aktar'))),
      h('div', { class: 'card stack' }, h('h2', null, 'Gizlilik'), h('p', { class: 'muted small' }, 'Verileriniz yalnızca sizin hesabınıza bağlıdır. Sistem yöneticisi destek ve denetim amacıyla kayıtlarınızı salt okunur görüntüleyebilir; her erişim denetim kaydına yazılır ve değişiklikler silinmeden ters kayıtla yapılır.')));
  }
  draw(); load();
}
