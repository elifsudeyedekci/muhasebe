// Parola, iki aşamalı doğrulama ve oturum yönetimi (müşteri ayarları ve yönetici sistem sayfasında ortak).
import { api } from './api.js';
import { state } from './state.js';
import { h, mount, clear, loading, toast, errToast, dateTime, confirmDialog, formModal, modal } from './ui.js';

export function securityPanel(user) {
  const root = h('div', { class: 'stack' });

  const pwCard = () => {
    const cur = h('input', { class: 'input', type: 'password', autocomplete: 'current-password', maxlength: 128 });
    const n1 = h('input', { class: 'input', type: 'password', autocomplete: 'new-password', maxlength: 128 });
    const n2 = h('input', { class: 'input', type: 'password', autocomplete: 'new-password', maxlength: 128 });
    const msg = h('div', { class: 'alert err hide', role: 'alert' });
    const btn = h('button', { class: 'btn primary', type: 'submit' }, 'Parolayı değiştir');
    const form = h('form', { class: 'stack', novalidate: true }, msg,
      h('div', { class: 'field' }, h('label', null, 'Mevcut parola'), cur), h('div', { class: 'field' }, h('label', null, 'Yeni parola'), n1, h('div', { class: 'hint' }, 'En az 12 karakter; büyük/küçük harf, rakam, sembolden en az üçü.')), h('div', { class: 'field' }, h('label', null, 'Yeni parola (tekrar)'), n2), h('div', null, btn));
    form.addEventListener('submit', async (e) => {
      e.preventDefault(); msg.classList.add('hide');
      if (n1.value !== n2.value) { msg.textContent = 'Yeni parolalar uyuşmuyor.'; msg.classList.remove('hide'); return; }
      btn.disabled = true;
      try { const r = await api.post('/auth/password', { current_password: cur.value, new_password: n1.value }); api.setCsrf(r.csrf); cur.value = n1.value = n2.value = ''; toast('Parola değiştirildi; diğer oturumlar kapatıldı.', 'ok'); draw(); }
      catch (err) { msg.textContent = err.message; msg.classList.remove('hide'); } finally { btn.disabled = false; }
    });
    return h('div', { class: 'card stack' }, h('h2', null, '🔑 Parola'), form);
  };

  const tfaCard = (me) => {
    const box = h('div', { class: 'card stack' }, h('h2', null, '🛡️ İki aşamalı doğrulama (TOTP)'));
    if (me.totp_enabled) {
      box.append(h('div', { class: 'alert ok' }, 'Etkin. Girişte doğrulayıcı uygulamadaki kod istenir.'));
      if (user.role !== 'admin') box.append(h('div', null, h('button', { class: 'btn danger sm', onclick: () => formModal({ title: '2FA kapat', note: 'Hesabınızın güvenliği azalır.', fields: [{ name: 'password', label: 'Parolanız', type: 'password', required: true, autocomplete: 'current-password' }], onSubmit: async (v) => { await api.post('/auth/2fa/disable', { password: v.password }); toast('2FA kapatıldı', 'ok'); draw(); } }) }, 'Kapat')));
      else box.append(h('div', { class: 'small muted' }, 'Yönetici hesabında 2FA zorunludur ve kapatılamaz.'));
    } else {
      box.append(h('div', { class: 'alert warn' }, 'Kapalı. Hesabınızı korumak için etkinleştirmeniz önerilir.'), h('div', null, h('button', { class: 'btn primary', onclick: setup }, 'Etkinleştir')));
    }
    return box;
  };
  async function setup() {
    try {
      const s = await api.post('/auth/2fa/setup');
      const m = formModal({
        title: '2FA kurulumu', submit: 'Etkinleştir', note: 'Doğrulayıcı uygulamanızda “anahtar gir / manuel giriş” ile aşağıdaki anahtarı ekleyin, sonra kodu yazın.',
        extra: h('div', { class: 'stack tight' }, h('div', { class: 'mono card flat' }, s.secret.match(/.{1,4}/g).join(' ')), h('a', { href: s.uri, class: 'small' }, 'Uygulamada aç (mobil)')),
        fields: [{ name: 'code', label: '6 haneli kod', required: true, inputmode: 'numeric', autocomplete: 'one-time-code' }],
        onSubmit: async (v) => { const r = await api.post('/auth/2fa/enable', { code: v.code }); setTimeout(() => showCodes(r.recovery_codes), 80); draw(); },
      });
    } catch (e) { errToast(e); }
  }
  function showCodes(codes) {
    modal({ title: 'Kurtarma kodları', body: h('div', { class: 'stack' }, h('div', { class: 'alert warn' }, 'Bu kodlar yalnızca şimdi gösterilir. Güvenli bir yere kaydedin; her biri tek kullanımlıktır.'), h('div', { class: 'grid g2 mono card flat' }, codes.map((c) => h('div', null, c)))) });
  }

  const sessCard = async () => {
    const box = h('div', { class: 'card stack' }, h('h2', null, '💻 Açık oturumlar'));
    try {
      const r = await api.get('/auth/sessions');
      box.append(h('div', { class: 'list' }, r.sessions.map((s) => h('div', { class: 'list-item' }, h('span', null, s.current ? '🟢' : '⚪'), h('div', { class: 'grow' }, s.label, s.current ? h('span', { class: 'badge ok' }, 'Bu cihaz') : null, h('div', { class: 'xs muted' }, `IP ${s.ip} · son etkinlik ${dateTime(s.last_seen_at)}`)),
        s.current ? null : h('button', { class: 'btn sm', onclick: async () => { await api.del('/auth/sessions/' + s.id); draw(); } }, 'Kapat')))));
      if (r.sessions.length > 1) box.append(h('div', null, h('button', { class: 'btn sm danger', onclick: async () => { await api.post('/auth/sessions/revoke-others'); toast('Diğer oturumlar kapatıldı', 'ok'); draw(); } }, 'Diğer tüm oturumları kapat')));
    } catch (e) { box.append(h('div', { class: 'alert err' }, e.message)); }
    return box;
  };

  async function draw() {
    mount(root, loading());
    try {
      const sess = await api.get('/auth/session');
      const me = sess.user;
      mount(root,
        h('div', { class: 'card' }, h('div', { class: 'kv' }, h('dt', null, 'E-posta'), h('dd', null, me.email), h('dt', null, 'Önceki giriş'), h('dd', null, me.last_login_at ? `${dateTime(me.last_login_at)} (IP ${me.last_login_ip || '—'})` : '—'))),
        pwCard(), tfaCard(me), await sessCard());
    } catch (e) { mount(root, h('div', { class: 'alert err' }, e.message)); }
  }
  draw();
  return root;
}
