import { api } from '../api.js';
import { h, mount, loading, money, multi, table, dateTime, formModal, toast, errToast, confirmDialog, tabs, modal } from '../ui.js';
import { secretModal } from './admin_customers.js';

const PAGES = [['dashboard', '🏠 Ana ekran'], ['accounts', '🏦 Hesaplar & bakiyeler'], ['transactions', '💸 Gelir/gider & işlem geçmişi'], ['transfers', '🔁 Transferler'], ['contacts', '👥 Cariler & ekstreler'], ['plan', '📅 Alacak/borç & takvim'],
  ['invoices', '🧾 Faturalar'], ['bills', '🏡 Şahsi faturalar'], ['cards', '💳 Kartlar & krediler'], ['projects', '🏗️ Projeler'], ['stock', '📦 Stok'], ['reconcile', '✅ Banka mutabakatı'], ['reports', '📊 Raporlar (KDV, nakit akışı, bütçe…)']];

export default async function render(ctx) {
  const id = Number(ctx.params.id);
  const { el } = ctx;
  let tab = ctx.query.tab || 'summary';
  const root = h('div', { class: 'stack' });
  const load = async () => {
    mount(root, loading());
    try {
      const r = await api.get('/admin/tenants/' + id);
      const t = r.tenant; const u = r.user;
      const stBadge = h('span', { class: 'badge ' + ({ active: 'ok', suspended: 'warn', archived: '' })[t.status] }, { active: 'Aktif', suspended: 'Askıda', archived: 'Arşiv' }[t.status]);
      const act = async (fn, msg) => { try { await fn(); toast(msg, 'ok'); load(); } catch (e) { errToast(e); } };
      const actions = h('div', { class: 'row tight' },
        h('button', { class: 'btn sm', onclick: () => formModal({ title: 'Müşteriyi düzenle', fields: [{ name: 'name', label: 'Ad', required: true, value: t.name }, { name: 'company_name', label: 'Firma', value: t.company_name }, { name: 'phone', label: 'Telefon', value: t.phone }, { name: 'email', label: 'Giriş e-postası', type: 'email', value: u.email, hint: 'Değişirse tüm oturumlar kapanır' }], onSubmit: async (v) => { await api.patch('/admin/tenants/' + id, { name: v.name, company_name: v.company_name, phone: v.phone, email: v.email }); toast('Güncellendi', 'ok'); load(); } }) }, 'Düzenle'),
        t.status === 'active' ? h('button', { class: 'btn sm', onclick: async () => { if (await confirmDialog({ title: 'Hesabı askıya al', text: 'Müşteri giriş yapamaz ve açık oturumları anında kapanır. Verileri korunur.', ok: 'Askıya al', danger: true })) act(() => api.post(`/admin/tenants/${id}/status`, { status: 'suspended' }), 'Askıya alındı'); } }, 'Askıya al')
          : h('button', { class: 'btn sm primary', onclick: () => act(() => api.post(`/admin/tenants/${id}/status`, { status: 'active' }), 'Aktifleştirildi') }, 'Aktifleştir'),
        t.status !== 'archived' ? h('button', { class: 'btn sm', onclick: async () => { if (await confirmDialog({ title: 'Müşteriyi arşivle', text: 'Finansal kayıtlar silinmez, korunur. Müşteri giriş yapamaz.', ok: 'Arşivle', danger: true })) act(() => api.post(`/admin/tenants/${id}/status`, { status: 'archived' }), 'Arşivlendi'); } }, 'Arşivle') : null,
        h('button', { class: 'btn sm', onclick: async () => { try { const x = await api.post(`/admin/tenants/${id}/reset-password`); secretModal('Parola sıfırlama bağlantısı', [['Bağlantı (1 saat, tek kullanımlık)', x.reset_link]], x.notice); load(); } catch (e) { errToast(e); } } }, 'Parola sıfırla'),
        u.locked_until ? h('button', { class: 'btn sm', onclick: () => act(() => api.post(`/admin/tenants/${id}/unlock`), 'Kilit açıldı') }, 'Kilidi aç') : null,
        h('button', { class: 'btn sm', onclick: async () => { try { await api.download(`/admin/tenants/${id}/export`, `musteri-${id}.json`); } catch (e) { errToast(e); } } }, 'Verileri indir'),
        t.status === 'archived' ? h('button', { class: 'btn sm danger', onclick: async () => { const pw = await confirmDialog({ title: 'Kalıcı sil', text: 'Yalnızca hiç finansal kaydı olmayan arşivli müşteri silinebilir. Yönetici parolanızı girin:', input: 'Yönetici parolası', ok: 'Kalıcı sil', danger: true }); if (!pw) return; try { await api.post(`/admin/tenants/${id}/purge`, { admin_password: pw }); toast('Silindi', 'ok'); ctx.go('/admin/customers'); } catch (e) { errToast(e); } } }, 'Kalıcı sil') : null);
      const draw = () => {
        const view = tab === 'summary' ? summary(r) : tab === 'finance' ? finance(r) : audit(r);
        mount(root,
          h('div', { class: 'row between' }, h('div', null, h('h1', null, t.name, ' ', stBadge), h('div', { class: 'muted' }, [u.email, t.company_name].filter(Boolean).join(' · '))), actions),
          tabs([['summary', 'Özet'], ['finance', 'Finansal görünüm (salt okunur)'], ['audit', 'Denetim kayıtları']], tab, (k) => { tab = k; draw(); }), view);
      };
      draw();
    } catch (e) { mount(root, h('div', { class: 'alert err' }, e.message)); }
  };
  const summary = (r) => {
    const u = r.user; const t = r.tenant;
    return h('div', { class: 'stack' },
      h('div', { class: 'auto-grid' },
        h('div', { class: 'card' }, h('h2', null, 'Hesap & güvenlik'), h('dl', { class: 'kv' },
          h('dt', null, 'Oluşturulma'), h('dd', null, dateTime(t.created_at)), h('dt', null, 'Son giriş'), h('dd', null, u.last_login_at ? `${dateTime(u.last_login_at)} (IP ${u.last_login_ip || '—'})` : 'Hiç giriş yapmadı'),
          h('dt', null, 'Son aktivite'), h('dd', null, dateTime(t.last_activity_at)), h('dt', null, 'İki aşamalı doğrulama'), h('dd', null, Number(u.totp_enabled) ? 'Etkin' : 'Kapalı'),
          h('dt', null, 'Parola değişimi'), h('dd', null, Number(u.must_change_password) ? '⚠ İlk girişte zorunlu' : 'Tamam'), h('dt', null, 'E-posta doğrulama'), h('dd', null, u.email_verified_at ? 'Doğrulandı' : 'Bekliyor'),
          h('dt', null, 'Kilit'), h('dd', null, u.locked_until && u.locked_until > new Date().toISOString().replace('T', ' ') ? 'Kilitli: ' + dateTime(u.locked_until) : 'Yok'), h('dt', null, 'Açık oturum'), h('dd', null, String(r.active_sessions)))),
        h('div', { class: 'card' }, h('h2', null, 'Kayıt sayıları'), h('dl', { class: 'kv' }, ...Object.entries({ workspaces: 'İşletme/alan', accounts: 'Hesap & kasa', transactions: 'Hareket', entries: 'Alacak/borç kalemi', invoices: 'Fatura', contacts: 'Cari', projects: 'Proje', cards: 'Kart', loans: 'Kredi', documents: 'Belge' }).flatMap(([k, l]) => [h('dt', null, l), h('dd', null, String(r.counts[k] ?? 0))]))),
        h('div', { class: 'card stack' }, h('h2', null, 'Finansal durum (para birimleri ayrı)'), h('div', null, h('div', { class: 'muted small' }, 'Bakiyeler'), h('b', null, multi(r.balances))),
          ...Object.entries(r.obligations).map(([c, v]) => h('div', { class: 'small' }, h('b', null, c + ': '), `alacak ${money(v.receivable, c)} · borç ${money(v.payable, c)}`, v.overdue_payable ? h('span', { class: 'neg' }, ` · geciken borç ${money(v.overdue_payable, c)}`) : null)))));
  };
  const finance = (r) => h('div', { class: 'stack' }, h('div', { class: 'alert info' }, 'Müşterinin verileri girişe gerek kalmadan, salt okunur olarak incelenir. Her erişim denetim kaydına yazılır. Düzeltme gerekirse işlem silinmez; “Düzeltme (iptal)” ile ters kayıt/iptal oluşturulur.'),
    h('div', { class: 'auto-grid' }, PAGES.map(([p, l]) => h('a', { class: 'card', href: `#/admin/t/${id}/${p}` }, h('b', null, l)))));
  const audit = (r) => table({ cols: [{ key: 'ts', label: 'Zaman', render: (a) => dateTime(a.ts) }, { key: 'category', label: 'Tür' }, { key: 'action', label: 'İşlem' }, { key: 'entity', label: 'Kayıt', render: (a) => [a.entity, a.entity_id].filter(Boolean).join(' #') || '—' }, { key: 'actor_role', label: 'Kim', render: (a) => ({ admin: 'Yönetici', customer: 'Müşteri' })[a.actor_role] || 'Sistem' }, { key: 'ip', label: 'IP' }], rows: r.audit, empty: { title: 'Kayıt yok' } });
  mount(el, root); load();
}
