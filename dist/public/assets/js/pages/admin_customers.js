import { api } from '../api.js';
import { h, mount, loading, table, dateTime, formModal, modal, toast, errToast, debounce, emptyState } from '../ui.js';

export function secretModal(title, rows, notice) {
  const items = rows.map(([label, value]) => {
    const inp = h('input', { class: 'input mono', readOnly: true, value, onfocus: (e) => e.target.select() });
    return h('div', { class: 'field' }, h('label', null, label), h('div', { class: 'row tight' }, h('div', { class: 'grow' }, inp), h('button', { class: 'btn sm', type: 'button', onclick: async () => { try { await navigator.clipboard.writeText(value); toast('Kopyalandı', 'ok'); } catch { inp.select(); toast('Seçildi — Ctrl+C ile kopyalayın'); } } }, 'Kopyala')));
  });
  return modal({ title, body: h('div', { class: 'stack' }, h('div', { class: 'alert warn' }, notice || 'Bu bilgi yalnızca şimdi gösterilir. Güvenli bir kanalla müşteriye iletin; parolayı e-postayla düz metin göndermeyin.'), ...items) });
}

export default async function render(ctx) {
  const { el } = ctx;
  const f = { q: '', status: '' };
  const out = h('div');
  const load = async () => {
    mount(out, loading());
    try {
      const r = await api.get('/admin/tenants', f);
      mount(out, table({
        cols: [
          { key: 'name', label: 'Müşteri', render: (t) => h('div', null, h('a', { href: '#/admin/customers/' + t.id }, h('b', null, t.name)), h('div', { class: 'xs muted' }, t.email + (t.company_name ? ' · ' + t.company_name : ''))) },
          { key: 'status', label: 'Durum', render: (t) => h('span', { class: 'badge ' + ({ active: 'ok', suspended: 'warn', archived: '' })[t.status] }, { active: 'Aktif', suspended: 'Askıda', archived: 'Arşiv' }[t.status]) },
          { key: 'workspaces', label: 'Alan', num: true }, { key: 'accounts', label: 'Hesap', num: true },
          { key: 'last_login_at', label: 'Son giriş', render: (t) => dateTime(t.last_login_at) }, { key: 'created_at', label: 'Oluşturma', render: (t) => dateTime(t.created_at) },
          { label: '2FA', sortable: false, render: (t) => (Number(t.totp_enabled) ? '✓' : '—') },
          { label: '', act: true, sortable: false, render: (t) => h('a', { class: 'btn sm', href: '#/admin/customers/' + t.id }, 'Aç') },
        ], rows: r.tenants, empty: { icon: '👥', title: 'Müşteri bulunamadı', text: 'Yeni müşteri oluşturmak için “＋ Yeni müşteri” düğmesini kullanın.' },
      }));
    } catch (e) { mount(out, h('div', { class: 'alert err' }, e.message)); }
  };
  const create = () => formModal({
    title: 'Yeni müşteri hesabı', submit: 'Hesabı oluştur', wide: true,
    note: 'Müşteri kendi kendine kayıt olamaz. Hesap oluşturulunca davet bağlantısı veya geçici parola yalnızca bir kez gösterilir; ilk girişte parola değişimi zorunludur.',
    fields: [
      { name: 'name', label: 'Müşteri adı', required: true, max: 160 }, { name: 'company_name', label: 'Firma / ünvan' },
      { name: 'email', label: 'Giriş e-postası', type: 'email', required: true }, { name: 'phone', label: 'Telefon' },
      { name: 'mode', label: 'İlk giriş yöntemi', type: 'select', full: true, options: [['invite', 'Güvenli davet bağlantısı (önerilen) — müşteri parolasını kendisi belirler'], ['temp', 'Geçici parola üret — ilk girişte değiştirilir']], value: 'invite' },
    ],
    onSubmit: async (v, key) => {
      const r = await api.post('/admin/tenants', { name: v.name, company_name: v.company_name, email: v.email, phone: v.phone, mode: v.mode });
      setTimeout(() => secretModal('Hesap oluşturuldu', r.invite_link ? [['Davet bağlantısı (72 saat geçerli, tek kullanımlık)', r.invite_link]] : [['Geçici parola', r.temp_password], ['Giriş e-postası', v.email]], r.notice), 80);
      load();
    },
  });
  mount(el, ctx.head('Müşteriler', 'Müşteri hesaplarını oluşturun, yönetin, askıya alın veya arşivleyin.', [h('button', { class: 'btn primary', onclick: create }, '＋ Yeni müşteri')]),
    h('div', { class: 'filters' }, h('div', { class: 'field' }, h('label', null, 'Ara'), h('input', { class: 'input', type: 'search', placeholder: 'Ad, firma veya e-posta', oninput: debounce((e) => { f.q = e.target.value; load(); }) })),
      h('div', { class: 'field' }, h('label', null, 'Durum'), h('select', { class: 'input', onchange: (e) => { f.status = e.target.value; load(); } }, [['', 'Hepsi'], ['active', 'Aktif'], ['suspended', 'Askıda'], ['archived', 'Arşiv']].map(([v, l]) => h('option', { value: v }, l))))), out);
  load();
}
