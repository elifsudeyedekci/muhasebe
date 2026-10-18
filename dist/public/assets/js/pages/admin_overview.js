import { api } from '../api.js';
import { h, mount, loading, money, multi, table, dateTime, date, emptyState } from '../ui.js';

const ACT = {
  login_failed: 'Başarısız giriş', account_locked: 'Hesap kilitlendi', admin_endpoint_denied: 'Yetkisiz admin erişimi', session_ua_mismatch: 'Oturum tarayıcı uyuşmazlığı', '2fa_failed': '2FA başarısız', login_locked: 'Kilitli hesaba giriş denemesi',
  tenant_created: 'Müşteri oluşturuldu', tenant_updated: 'Müşteri güncellendi', tenant_status_suspended: 'Müşteri askıya alındı', tenant_status_active: 'Müşteri aktifleştirildi', tenant_status_archived: 'Müşteri arşivlendi',
  tenant_password_reset_issued: 'Parola sıfırlama başlatıldı', admin_view_tenant_data: 'Müşteri verisi görüntülendi', admin_correction_void: 'Yönetici düzeltmesi', tenant_unlocked: 'Kilit açıldı', tenant_export: 'Veri dışa aktarıldı', admin_download_document: 'Belge indirildi',
};
const lbl = (a) => ACT[a] || a;

export default async function render(ctx) {
  const { el } = ctx;
  mount(el, ctx.head('Yönetim Özeti', 'Tüm müşteriler, güvenlik olayları ve sistem durumu.'), loading());
  const r = await api.get('/admin/overview');
  const c = r.counts;
  const bytes = (n) => (n > 1048576 ? (n / 1048576).toFixed(1) + ' MB' : Math.round(n / 1024) + ' KB');
  mount(el, ctx.head('Yönetim Özeti', 'Tüm müşteriler, güvenlik olayları ve sistem durumu.', [h('a', { class: 'btn primary', href: '#/admin/customers' }, '＋ Müşteri / Yönet')]),
    h('div', { class: 'auto-grid' },
      h('div', { class: 'card stat' }, h('div', { class: 'label' }, 'Toplam müşteri'), h('div', { class: 'value' }, String(c.total || 0)), h('div', { class: 'sub' }, `Son 30 günde yeni: ${c.new30 || 0}`)),
      h('div', { class: 'card stat' }, h('div', { class: 'label' }, 'Aktif'), h('div', { class: 'value pos' }, String(c.active || 0))),
      h('div', { class: 'card stat' }, h('div', { class: 'label' }, 'Askıda / Arşiv'), h('div', { class: 'value' }, `${c.suspended || 0} / ${c.archived || 0}`)),
      h('div', { class: 'card stat' }, h('div', { class: 'label' }, 'Veritabanı & yedek'), h('div', { class: 'value' }, bytes(r.ops.db_size_bytes)), h('div', { class: 'sub' }, r.ops.last_backup ? 'Son yedek: ' + r.ops.last_backup + ' UTC' : '⚠ Henüz yedek yok'))),
    r.ops.last_backup ? null : h('div', { class: 'alert warn' }, 'Otomatik yedekleme yapılandırılmamış görünüyor. cPanel › Cron Jobs ile app/bin/backup.php dosyasını günlük çalıştırın.'),
    h('div', { class: 'card stack' }, h('div', { class: 'card-title' }, 'Müşteri bazında finansal özet', h('span', { class: 'xs muted' }, 'Para birimleri toplanmaz; TL / EUR / USD ayrı gösterilir')),
      table({ cols: [
        { key: 'name', label: 'Müşteri', render: (t) => h('a', { href: '#/admin/customers/' + t.id }, t.name) }, { key: 'status', label: 'Durum', render: (t) => h('span', { class: 'badge ' + ({ active: 'ok', suspended: 'warn', archived: '' })[t.status] }, { active: 'Aktif', suspended: 'Askıda', archived: 'Arşiv' }[t.status]) },
        { label: 'Bakiyeler', num: true, sortable: false, render: (t) => multi(t.balances) },
        { label: 'Açık alacak', num: true, sortable: false, render: (t) => Object.entries(t.obligations).filter(([, v]) => v.receivable).map(([k, v]) => h('div', null, money(v.receivable, k))) },
        { label: 'Açık borç', num: true, sortable: false, render: (t) => Object.entries(t.obligations).filter(([, v]) => v.payable).map(([k, v]) => h('div', null, money(v.payable, k))) },
        { label: 'Geciken', num: true, sortable: false, render: (t) => Object.entries(t.obligations).filter(([, v]) => v.overdue_payable || v.overdue_receivable).map(([k, v]) => h('div', { class: 'neg' }, `${v.overdue_payable ? 'borç ' + money(v.overdue_payable, k) : ''} ${v.overdue_receivable ? 'alacak ' + money(v.overdue_receivable, k) : ''}`)) },
      ], rows: r.tenants, empty: { icon: '👥', title: 'Müşteri yok', text: 'İlk müşteriyi oluşturun.' } })),
    h('div', { class: 'auto-grid' },
      h('div', { class: 'card' }, h('div', { class: 'card-title' }, 'Son giriş yapanlar'), h('div', { class: 'list' }, r.recent_logins.length ? r.recent_logins.map((x) => h('div', { class: 'list-item' }, h('div', { class: 'grow' }, h('a', { href: '#/admin/customers/' + x.id }, x.name), h('div', { class: 'xs muted' }, x.email)), h('span', { class: 'xs' }, dateTime(x.last_login_at)))) : [h('div', { class: 'muted small' }, 'Henüz giriş yok')])),
      h('div', { class: 'card' }, h('div', { class: 'card-title' }, 'Yeni eklenen müşteriler'), h('div', { class: 'list' }, r.newest.length ? r.newest.map((x) => h('div', { class: 'list-item' }, h('div', { class: 'grow' }, h('a', { href: '#/admin/customers/' + x.id }, x.name), h('div', { class: 'xs muted' }, x.email)), h('span', { class: 'xs' }, dateTime(x.created_at)))) : [h('div', { class: 'muted small' }, '—')])),
      h('div', { class: 'card' }, h('div', { class: 'card-title' }, '🔒 Güvenlik olayları', h('a', { href: '#/admin/audit', class: 'small' }, 'Tümü →')), h('div', { class: 'list' }, r.security_events.length ? r.security_events.map((x) => h('div', { class: 'list-item' }, h('span', { class: 'dot ' + (x.action.includes('denied') || x.action.includes('locked') ? 'danger' : 'warn') }), h('div', { class: 'grow' }, lbl(x.action), h('div', { class: 'xs muted' }, 'IP ' + (x.ip || '—'))), h('span', { class: 'xs' }, dateTime(x.ts)))) : [h('div', { class: 'muted small' }, 'Olay yok')])),
      h('div', { class: 'card' }, h('div', { class: 'card-title' }, 'Son yönetici işlemleri'), h('div', { class: 'list' }, r.admin_actions.length ? r.admin_actions.map((x) => h('div', { class: 'list-item' }, h('div', { class: 'grow' }, lbl(x.action), h('div', { class: 'xs muted' }, x.tenant_id ? 'Müşteri #' + x.tenant_id : '')), h('span', { class: 'xs' }, dateTime(x.ts)))) : [h('div', { class: 'muted small' }, 'Kayıt yok')]))),
    h('div', { class: 'card' }, h('div', { class: 'card-title' }, 'Sistem hataları (son kayıtlar)'), r.errors.length ? h('pre', { class: 'mono small card flat' }, r.errors.join('\n')) : h('div', { class: 'alert ok' }, 'Kayıtlı sistem hatası yok.')));
}
