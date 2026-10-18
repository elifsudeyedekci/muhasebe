import { api } from '../api.js';
import { h, mount, loading, toast, errToast, tabs } from '../ui.js';
import { securityPanel } from '../security_panel.js';
import { state } from '../state.js';

export default async function render(ctx) {
  const { el } = ctx;
  let tab = ctx.query.tab || 'system';
  const body = h('div', { class: 'stack' });
  const draw = () => mount(el, ctx.head('Sistem & Güvenlik', 'Operasyon durumu, genel ayarlar ve yönetici hesabınızın güvenliği.'), tabs([['system', 'Sistem durumu'], ['settings', 'Genel ayarlar'], ['account', 'Yönetici hesabı']], tab, (k) => { tab = k; draw(); load(); }), body);
  async function load() {
    mount(body, loading());
    try {
      if (tab === 'account') return mount(body, securityPanel(state.user));
      if (tab === 'settings') {
        const { settings } = await api.get('/admin/settings');
        const ann = h('textarea', { class: 'input', rows: 2, maxlength: 500 }, settings.announcement || '');
        const maint = h('input', { type: 'checkbox', checked: settings.maintenance === '1' });
        return mount(body, h('div', { class: 'card stack' }, h('h2', null, 'Genel sistem ayarları'),
          h('div', { class: 'field' }, h('label', null, 'Tüm müşterilere duyuru (üst şeritte gösterilir)'), ann),
          h('label', { class: 'check' }, maint, 'Bakım modu — müşteriler geçici olarak işlem yapamaz (yönetici etkilenmez)'),
          h('div', null, h('button', { class: 'btn primary', onclick: async () => { try { await api.put('/admin/settings', { announcement: ann.value, maintenance: maint.checked }); toast('Kaydedildi', 'ok'); } catch (e) { errToast(e); } } }, 'Kaydet'))));
      }
      const r = await api.get('/admin/system');
      const okb = (v) => h('span', { class: 'badge ' + (v ? 'ok' : 'bad') }, v ? 'Tamam' : 'Eksik');
      mount(body, h('div', { class: 'auto-grid' },
        h('div', { class: 'card' }, h('h2', null, 'Sunucu'), h('dl', { class: 'kv' }, h('dt', null, 'PHP'), h('dd', null, r.ops.php), h('dt', null, 'Ortam'), h('dd', null, r.ops.env), h('dt', null, 'Veritabanı boyutu'), h('dd', null, (r.ops.db_size_bytes / 1048576).toFixed(2) + ' MB'),
          h('dt', null, 'Son yedek'), h('dd', null, r.ops.last_backup || '⚠ yok'), h('dt', null, 'Yedek sayısı'), h('dd', null, String(r.ops.backup_count)), h('dt', null, 'Yönetici 2FA zorunlu'), h('dd', null, okb(r.require_admin_2fa)))),
        h('div', { class: 'card' }, h('h2', null, 'Eklentiler'), h('dl', { class: 'kv' }, ...Object.entries({ gd: 'GD (CAPTCHA)', zip: 'ZIP (XLSX/yedek)', openssl: 'OpenSSL', argon2id: 'Argon2id' }).flatMap(([k, l]) => [h('dt', null, l), h('dd', null, okb(r.extensions[k]))]))),
        h('div', { class: 'card' }, h('h2', null, 'Dizinler'), h('dl', { class: 'kv' }, ...Object.entries(r.writable).flatMap(([k, v]) => [h('dt', null, 'storage/' + k), h('dd', null, okb(v))]))),
        h('div', { class: 'card' }, h('h2', null, 'Denetim zinciri'), r.audit_broken_at ? h('div', { class: 'alert err' }, 'Bozulma tespit edildi: kayıt #' + r.audit_broken_at) : h('div', { class: 'alert ok' }, 'Sağlam — kayıtlara müdahale edilmemiş.'))));
    } catch (e) { mount(body, h('div', { class: 'alert err' }, e.message)); }
  }
  draw(); load();
}
