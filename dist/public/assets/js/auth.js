// Giriş, şifremi unuttum, parola belirleme, zorunlu parola değişimi, 2FA kurulumu.
import { api, ApiError } from './api.js';
import { h, mount, toast, errToast } from './ui.js';
import { solvePow } from './pow.js';

function brand(sub) {
  return h('div', { class: 'brand' }, h('div', { class: 'logo' }, 'F'), h('div', null, 'FunBreak Ön Muhasebe', h('small', null, sub || 'Güvenli finans yönetimi')));
}
function authShell(...kids) {
  return h('div', { class: 'auth' }, h('div', { class: 'auth-card card stack' }, ...kids));
}

/** CAPTCHA + iş kanıtı bileşeni. getSolution() Promise döner. */
function captchaBox() {
  const img = h('img', { class: 'captcha-img', alt: 'Doğrulama kodu görseli', width: 220, height: 70 });
  const input = h('input', { class: 'input', name: 'captcha', autocomplete: 'off', autocapitalize: 'characters', spellcheck: 'false', maxlength: 8, 'aria-label': 'Görseldeki kod', placeholder: 'Görseldeki kodu yazın', required: true });
  const status = h('div', { class: 'pow' });
  let cur = null; let powPromise = null;
  const load = async () => {
    status.textContent = 'Güvenlik denetimi hazırlanıyor…';
    input.value = '';
    try {
      const r = await api.get('/auth/captcha');
      cur = r;
      img.src = r.image;
      powPromise = solvePow(r.pow.seed, r.pow.bits).then((n) => { status.textContent = '✓ Tarayıcı doğrulaması tamamlandı'; return n; });
    } catch (e) { status.textContent = e.message; }
  };
  load();
  const el = h('div', { class: 'stack tight' }, img,
    h('div', { class: 'row tight' }, h('div', { class: 'grow' }, input), h('button', { class: 'btn sm', type: 'button', onclick: load, 'aria-label': 'Kodu yenile' }, '↻')),
    status,
    h('input', { class: 'hp', type: 'text', name: 'website', tabindex: '-1', autocomplete: 'off', 'aria-hidden': 'true' }));
  el.solution = async () => {
    if (!cur || !powPromise) throw new ApiError(0, { error: { message: 'Doğrulama kodu yüklenemedi, yenileyin.' } });
    const nonce = await powPromise;
    return { captcha_id: cur.id, captcha_answer: input.value.trim(), pow_nonce: nonce, website: el.querySelector('.hp').value };
  };
  el.reload = load;
  return el;
}

let uid = 0;
function fld(label, input) { const id = 'a_' + (++uid); input.id = id; return h('div', { class: 'field' }, h('label', { for: id }, label), input); }
function pwField(label, name, autocomplete) {
  const input = h('input', { class: 'input', type: 'password', name, autocomplete, required: true, maxlength: 128 });
  const tgl = h('button', { class: 'btn sm', type: 'button', 'aria-label': 'Parolayı göster/gizle', onclick: () => { input.type = input.type === 'password' ? 'text' : 'password'; } }, '👁');
  const id = 'a_' + (++uid); input.id = id;
  return { input, el: h('div', { class: 'field' }, h('label', { for: id }, label), h('div', { class: 'row tight' }, h('div', { class: 'grow' }, input), tgl)) };
}

export function renderLogin(root, { notice, onSuccess, go }) {
  const email = h('input', { class: 'input', type: 'email', name: 'email', autocomplete: 'username', required: true, autocapitalize: 'none', spellcheck: 'false', maxlength: 190 });
  const pw = pwField('Parola', 'password', 'current-password');
  const cap = captchaBox();
  const msg = h('div', { class: 'alert err hide', role: 'alert' });
  const btn = h('button', { class: 'btn primary block', type: 'submit' }, 'Giriş yap');
  const form = h('form', { class: 'stack', novalidate: true },
    notice ? h('div', { class: 'alert info' }, notice) : null, msg,
    fld('E-posta', email), pw.el, cap, btn,
    h('div', { class: 'small muted', style: null }, h('a', { href: '#/forgot' }, 'Parolamı unuttum')));
  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    msg.classList.add('hide');
    btn.disabled = true; btn.textContent = 'Doğrulanıyor…';
    try {
      const sol = await cap.solution();
      const r = await api.post('/auth/login', { email: email.value.trim(), password: pw.input.value, ...sol });
      pw.input.value = '';
      if (r.need_2fa) return render2fa(root, r.ticket, onSuccess);
      api.setCsrf(r.csrf);
      onSuccess(r.user);
    } catch (err) {
      pw.input.value = '';
      msg.textContent = err.message; msg.classList.remove('hide');
      cap.reload();
    } finally { btn.disabled = false; btn.textContent = 'Giriş yap'; }
  });
  mount(root, authShell(brand(), h('h1', { class: 'small' }, 'Hesabınıza giriş yapın'), form,
    h('p', { class: 'xs muted' }, '🔒 Bağlantı şifrelidir. Hesaplar yalnızca yönetici tarafından açılır; herkese açık kayıt yoktur.')));
}

function render2fa(root, ticket, onSuccess) {
  const code = h('input', { class: 'input', name: 'code', inputmode: 'numeric', autocomplete: 'one-time-code', maxlength: 12, required: true, 'aria-label': 'Doğrulama kodu' });
  const rec = h('input', { type: 'checkbox' });
  const msg = h('div', { class: 'alert err hide', role: 'alert' });
  const btn = h('button', { class: 'btn primary block', type: 'submit' }, 'Doğrula');
  const form = h('form', { class: 'stack', novalidate: true }, msg,
    h('p', { class: 'muted small' }, 'Doğrulayıcı uygulamanızdaki 6 haneli kodu girin.'),
    fld('Kod', code),
    h('label', { class: 'check small' }, rec, 'Kurtarma kodu kullanıyorum'), btn);
  form.addEventListener('submit', async (e) => {
    e.preventDefault(); msg.classList.add('hide'); btn.disabled = true;
    try {
      const r = await api.post('/auth/2fa', { ticket, code: code.value.trim(), recovery: rec.checked });
      api.setCsrf(r.csrf); onSuccess(r.user);
    } catch (err) { msg.textContent = err.message; msg.classList.remove('hide'); } finally { btn.disabled = false; }
  });
  mount(root, authShell(brand('İki aşamalı doğrulama'), form));
  code.focus();
}

export function renderForgot(root, go) {
  const email = h('input', { class: 'input', type: 'email', name: 'email', autocomplete: 'username', required: true });
  const cap = captchaBox();
  const msg = h('div', { class: 'alert hide', role: 'status' });
  const btn = h('button', { class: 'btn primary block', type: 'submit' }, 'Sıfırlama bağlantısı gönder');
  const form = h('form', { class: 'stack', novalidate: true }, msg, fld('E-posta', email), cap, btn, h('a', { href: '#/login', class: 'small' }, '← Girişe dön'));
  form.addEventListener('submit', async (e) => {
    e.preventDefault(); btn.disabled = true;
    try {
      const sol = await cap.solution();
      const r = await api.post('/auth/forgot', { email: email.value.trim(), ...sol });
      msg.className = 'alert ok'; msg.textContent = r.message;
    } catch (err) { msg.className = 'alert err'; msg.textContent = err.message; cap.reload(); } finally { btn.disabled = false; }
  });
  mount(root, authShell(brand('Parola sıfırlama'), form));
}

export function renderReset(root, token, go) {
  const a = pwField('Yeni parola', 'password', 'new-password');
  const b = pwField('Yeni parola (tekrar)', 'password2', 'new-password');
  const msg = h('div', { class: 'alert err hide', role: 'alert' });
  const btn = h('button', { class: 'btn primary block', type: 'submit' }, 'Parolayı belirle');
  const form = h('form', { class: 'stack', novalidate: true }, msg, policyNote(), a.el, b.el, btn);
  form.addEventListener('submit', async (e) => {
    e.preventDefault(); msg.classList.add('hide');
    if (a.input.value !== b.input.value) { msg.textContent = 'Parolalar uyuşmuyor.'; msg.classList.remove('hide'); return; }
    btn.disabled = true;
    try {
      await api.post('/auth/reset', { token, password: a.input.value });
      history.replaceState(null, '', location.pathname);
      toast('Parolanız belirlendi. Giriş yapabilirsiniz.', 'ok');
      go('/login');
    } catch (err) { msg.textContent = err.message; msg.classList.remove('hide'); } finally { btn.disabled = false; }
  });
  mount(root, authShell(brand('Parola belirleme'), form));
}

const policyNote = () => h('div', { class: 'alert info small' }, 'En az 12 karakter; büyük harf, küçük harf, rakam ve sembolden en az üçünü kullanın. Yaygın sözcük, e-posta adresiniz ya da ardışık karakterler (1234, aaaa) içermemeli.');

export function renderForcePassword(root, user, onDone) {
  const cur = pwField('Mevcut (geçici) parola', 'current', 'current-password');
  const a = pwField('Yeni parola', 'password', 'new-password');
  const b = pwField('Yeni parola (tekrar)', 'password2', 'new-password');
  const msg = h('div', { class: 'alert err hide', role: 'alert' });
  const btn = h('button', { class: 'btn primary block', type: 'submit' }, 'Parolayı değiştir');
  const form = h('form', { class: 'stack', novalidate: true }, h('div', { class: 'alert warn' }, 'Güvenliğiniz için devam etmeden önce yeni bir parola belirlemelisiniz.'), msg, policyNote(), cur.el, a.el, b.el, btn);
  form.addEventListener('submit', async (e) => {
    e.preventDefault(); msg.classList.add('hide');
    if (a.input.value !== b.input.value) { msg.textContent = 'Parolalar uyuşmuyor.'; msg.classList.remove('hide'); return; }
    btn.disabled = true;
    try {
      const r = await api.post('/auth/password', { current_password: cur.input.value, new_password: a.input.value });
      api.setCsrf(r.csrf); onDone(r.user);
    } catch (err) { msg.textContent = err.message; msg.classList.remove('hide'); } finally { btn.disabled = false; }
  });
  mount(root, authShell(brand('Parola değişimi'), form));
}

/** Yönetici için zorunlu 2FA kurulumu. */
export function renderEnroll2fa(root, user, onDone) {
  const secretBox = h('div', { class: 'mono card flat' }, 'Hazırlanıyor…');
  const link = h('a', { class: 'small', href: '#' }, 'Doğrulayıcı uygulamada aç');
  const code = h('input', { class: 'input', name: 'code', inputmode: 'numeric', autocomplete: 'one-time-code', maxlength: 8, placeholder: '6 haneli kod' });
  const msg = h('div', { class: 'alert err hide', role: 'alert' });
  const btn = h('button', { class: 'btn primary block', type: 'submit' }, 'Etkinleştir');
  const form = h('form', { class: 'stack', novalidate: true },
    h('div', { class: 'alert warn' }, 'Yönetici hesabı için iki aşamalı doğrulama zorunludur.'), msg,
    h('ol', { class: 'small' }, h('li', null, 'Google Authenticator, Microsoft Authenticator veya 1Password gibi bir uygulama açın.'), h('li', null, '“Anahtar gir / Manuel giriş” seçeneğiyle aşağıdaki anahtarı ekleyin (zaman tabanlı, 6 hane).'), h('li', null, 'Uygulamanın gösterdiği kodu girin.')),
    secretBox, link, fld('Doğrulama kodu', code), btn);
  (async () => {
    try {
      const r = await api.post('/auth/2fa/setup');
      secretBox.textContent = r.secret.match(/.{1,4}/g).join(' ');
      link.href = r.uri;
    } catch (e) { secretBox.textContent = e.message; }
  })();
  form.addEventListener('submit', async (e) => {
    e.preventDefault(); msg.classList.add('hide'); btn.disabled = true;
    try {
      const r = await api.post('/auth/2fa/enable', { code: code.value.trim() });
      showRecovery(root, r.recovery_codes, onDone);
    } catch (err) { msg.textContent = err.message; msg.classList.remove('hide'); } finally { btn.disabled = false; }
  });
  mount(root, authShell(brand('İki aşamalı doğrulama kurulumu'), form));
}

export function showRecovery(root, codes, onDone) {
  const ok = h('input', { type: 'checkbox' });
  const btn = h('button', { class: 'btn primary block', type: 'button', disabled: true, onclick: onDone }, 'Devam et');
  ok.addEventListener('change', () => { btn.disabled = !ok.checked; });
  mount(root, authShell(brand('Kurtarma kodları'),
    h('div', { class: 'alert warn' }, 'Bu kodlar yalnızca şimdi gösterilir. Telefonunuza erişemezseniz giriş için gerekir. Güvenli bir yere kaydedin; her biri bir kez kullanılır.'),
    h('div', { class: 'grid g2 mono card flat' }, codes.map((c) => h('div', null, c))),
    h('label', { class: 'check small' }, ok, 'Kodları güvenli bir yere kaydettim'), btn));
}

export { errToast };
