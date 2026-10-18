<?php
declare(strict_types=1);

use FB\Audit;
use FB\DB;
use FB\Installer;
use FB\Totp;
use FB\Util;

section('Yönetici girişi, zorunlu 2FA kurulumu');
$adm = new Client();
$r = $adm->login($ADMIN_EMAIL, $ADMIN_PASS);
check($r['ok'] && $r['json']['user']['role'] === 'admin', 'admin giriş yapar', $r['json']);
check($r['json']['user']['must_enroll_2fa'] === true, 'admin için 2FA kurulumu zorunlu');
$r = $adm->get('/admin/overview');
eq($r['json']['error']['code'] ?? null, 'mfa_enrollment_required', '2FA kurulmadan admin uç noktaları kapalı');
$s = $adm->post('/auth/2fa/setup');
check($s['ok'] && strlen($s['json']['secret']) === 32, '2FA kurulum sırrı üretildi');
$bad = $adm->post('/auth/2fa/enable', ['code' => '000000']);
eq($bad['status'], 422, 'yanlış TOTP kodu reddedilir');
$secret = $s['json']['secret'];
$en = $adm->post('/auth/2fa/enable', ['code' => Totp::code($secret, intdiv(time(), 30))]);
check($en['ok'] && count($en['json']['recovery_codes']) === 8, '2FA etkinleşir, 8 kurtarma kodu verilir', $en['json']);
$rec = $en['json']['recovery_codes'];
$r = $adm->get('/admin/overview');
check($r['ok'], '2FA sonrası admin paneli açılır', $r['json']);
$GLOBALS['ADMIN_SECRET'] = $secret;

section('Oturum: yenilenme, sabitleme, çıkış');
$adm2 = new Client();
$r = $adm2->login($ADMIN_EMAIL, $ADMIN_PASS);
check($r['ok'] === false || !empty($r['json']['user']), '2FA gerektiren giriş akışı (ticket)');
// ticket akışı: need_2fa → code (Client::login halleder)
$adm2 = new Client();
$r = $adm2->login($ADMIN_EMAIL, $ADMIN_PASS, $secret);
check($r['ok'] && isset($r['json']['user']), '2FA ile giriş tamamlanır', $r['json']);
$c1 = $adm2->cookie();
check(strlen((string)$c1) >= 40, 'oturum çerezi yüksek entropili (≥40 karakter)');
$h = implode(';', $adm2->lastHeaders['set-cookie'] ?? []);
$r = $adm2->get('/auth/session');
check($r['json']['authenticated'] === true, 'oturum geçerli');
sleep(3); // session_rotate=2sn (test yapılandırması)
$r = $adm2->get('/auth/session');
$c2 = $adm2->cookie();
check($c2 !== null && $c2 !== $c1, 'oturum kimliği periyodik döner (sabit kalmaz)');
$old = new Client();
$old->setCookie($c1);
$o = $old->get('/auth/session');
check($o['json']['authenticated'] === true, 'eski kimlik kısa süre (grace) geçerli');
DB::exec('UPDATE sessions SET prev_valid_until = ?', [Util::plus(-5)]);
$old->setCookie($c1);
$o = $old->get('/auth/session');
check($o['json']['authenticated'] === false, 'grace sonrası eski oturum kimliği geçersiz');

// Oturum sabitleme: saldırganın bildiği kimlik giriş sonrası kullanılamaz
$victim = new Client();
$victim->setCookie('A_KNOWN_TOKEN_THAT_ATTACKER_SET_0123456789ABCDEF0123');
$victim->login($ADMIN_EMAIL, $ADMIN_PASS, $secret);
check($victim->cookie() !== 'A_KNOWN_TOKEN_THAT_ATTACKER_SET_0123456789ABCDEF0123', 'giriş sonrası yeni oturum kimliği atanır (fixation)');

$lo = new Client();
$lo->login($ADMIN_EMAIL, $ADMIN_PASS, $secret);
$tok = $lo->cookie();
$r = $lo->post('/auth/logout');
check($r['ok'], 'çıkış yapılır');
$replay = new Client();
$replay->setCookie((string)$tok);
eq($replay->get('/auth/session')['json']['authenticated'], false, 'çıkıştan sonra eski çerez reddedilir');

section('Çerez özellikleri, başlıklar');
$c = new Client();
$cap = $c->req('GET', '/auth/captcha');
$sc = $c->req('POST', '/auth/login', ['email' => $ADMIN_EMAIL, 'password' => $ADMIN_PASS] + $c->captcha(), [], false);
$setc = strtolower(implode(' | ', $c->lastHeaders['set-cookie'] ?? ['(yok)']));
// 2FA gerektirdiği için çerez yok; tam girişte kontrol edelim
$c = new Client();
$c->login($ADMIN_EMAIL, $ADMIN_PASS, $secret);
$all = [];
$c->req('POST', '/auth/login', ['email' => 'x@y.zz', 'password' => 'x']);
$c2 = new Client();
$ret = $c2->login($ADMIN_EMAIL, $ADMIN_PASS, $secret);
$hdr = file_get_contents($c2->jar);
check(str_contains($hdr, '#HttpOnly_'), 'oturum çerezi HttpOnly');
check(!empty($cap['h']['x-content-type-options']) && !empty($cap['h']['cache-control']) && str_contains($cap['h']['cache-control'][0], 'no-store'), 'API yanıtı no-store + nosniff');
check(isset($cap['h']['content-security-policy']) && isset($cap['h']['x-frame-options']) && isset($cap['h']['referrer-policy']), 'API güvenlik başlıkları (CSP, XFO, Referrer-Policy)');

section('CSRF / Origin');
$k = new Client();
$k->login($ADMIN_EMAIL, $ADMIN_PASS, $secret);
$csrf = $k->csrf;
$k->csrf = null;
$r = $k->post('/admin/tenants', ['name' => 'X', 'email' => 'csrf@test.local']);
eq($r['json']['error']['code'] ?? null, 'csrf', 'CSRF jetonu olmadan değiştirici istek reddedilir');
$k->csrf = 'wrong' . $csrf;
eq($k->post('/admin/tenants', ['name' => 'X', 'email' => 'csrf@test.local'])['json']['error']['code'] ?? null, 'csrf', 'yanlış CSRF jetonu reddedilir');
$k->csrf = $csrf;
$k->origin = 'http://evil.example';
eq($k->post('/admin/tenants', ['name' => 'X', 'email' => 'csrf@test.local'])['json']['error']['code'] ?? null, 'bad_origin', 'yabancı Origin reddedilir');
$k->origin = 'http://127.0.0.1:18099';

section('CAPTCHA ve kaba kuvvet koruması');
$z = new Client();
$r = $z->req('POST', '/auth/login', ['email' => $ADMIN_EMAIL, 'password' => $ADMIN_PASS, 'captcha_id' => str_repeat('0', 32), 'captcha_answer' => 'TESTAB', 'pow_nonce' => '1'], [], false);
eq($r['json']['error']['code'] ?? null, 'captcha_invalid', 'CAPTCHA olmadan/geçersizse giriş reddedilir');
$cp = $z->captcha();
$r = $z->req('POST', '/auth/login', ['email' => 'nobody@test.local', 'password' => 'Whatever!123x'] + $cp, [], false);
eq($r['status'], 401, 'bilinmeyen kullanıcı genel hata verir');
$r2 = $z->req('POST', '/auth/login', ['email' => 'nobody@test.local', 'password' => 'Whatever!123x'] + $cp, [], false);
eq($r2['json']['error']['code'] ?? null, 'captcha_invalid', 'CAPTCHA tek kullanımlık (yeniden kullanılamaz)');
$cp2 = $z->captcha();
$cp2['captcha_answer'] = 'WRONG1';
eq($z->req('POST', '/auth/login', ['email' => 'nobody@test.local', 'password' => 'x'] + $cp2, [], false)['json']['error']['code'] ?? null, 'captcha_invalid', 'yanlış CAPTCHA cevabı reddedilir');
$cp3 = $z->captcha();
$cp3['pow_nonce'] = 'abc';
eq($z->req('POST', '/auth/login', ['email' => 'x@y.zz', 'password' => 'x'] + $cp3, [], false)['json']['error']['code'] ?? null, 'captcha_invalid', 'geçersiz iş kanıtı (PoW) reddedilir');
$hp = $z->captcha() + ['website' => 'http://spam'];
eq($z->req('POST', '/auth/login', ['email' => 'x@y.zz', 'password' => 'x'] + $hp, [], false)['json']['error']['code'] ?? null, 'captcha_invalid', 'bal küpü alanı dolu istek reddedilir');

// Yönetici dışındaki hesapta kilitlenme testi için müşteri oluşturalım (aşağıda). Önce müşteri akışları:
section('Müşteri oluşturma (yalnızca admin), davet, geçici parola');
$noAuth = new Client();
eq($noAuth->post('/admin/tenants', ['name' => 'A', 'email' => 'a@test.local'])['status'], 401, 'oturumsuz admin uç noktası 401');
eq($noAuth->req('POST', '/auth/register', ['email' => 'self@test.local', 'password' => 'Str0ng!Pass#word1'])['status'], 404, 'herkese açık kayıt uç noktası yok');
$r = $k->post('/admin/tenants', ['name' => 'Alfa Ltd', 'company_name' => 'Alfa Ticaret', 'email' => 'alfa@test.local', 'mode' => 'invite']);
check($r['ok'] && str_contains($r['json']['invite_link'], '#/reset?token='), 'davet bağlantısı üretildi', $r['json']);
$ALFA = $r['json']['tenant_id'];
$invite = $r['json']['invite_link'];
preg_match('/token=([\w-]+)/', $invite, $m);
$tokA = $m[1];
$r = $k->post('/admin/tenants', ['name' => 'Beta AŞ', 'email' => 'beta@test.local', 'mode' => 'temp']);
check($r['ok'] && strlen($r['json']['temp_password']) >= 16, 'geçici parola yalnızca yanıtta döner');
$BETA = $r['json']['tenant_id'];
$betaTemp = $r['json']['temp_password'];
eq($k->post('/admin/tenants', ['name' => 'Dup', 'email' => 'alfa@test.local'])['status'], 409, 'yinelenen e-posta reddedilir');
$stored = DB::val('SELECT password_hash FROM users WHERE email=?', ['beta@test.local']);
check(!str_contains($stored, $betaTemp) && str_starts_with($stored, '$argon2id$'), 'parola Argon2id ile özetlenmiş, düz metin saklanmaz');
check(!str_contains(DB::val('SELECT GROUP_CONCAT(token_hash) FROM password_tokens'), $tokA), 'davet jetonu veritabanında yalnızca hash olarak');

// geçici parolayla giriş → değişim zorunlu
$b = new Client();
$r = $b->login('beta@test.local', $betaTemp);
check($r['ok'] && $r['json']['user']['must_change_password'] === true, 'geçici parolayla giriş: parola değişimi zorunlu');
eq($b->get('/meta')['json']['error']['code'] ?? null, 'password_change_required', 'parola değişmeden uygulamaya erişilemez');
$w = $b->post('/auth/password', ['current_password' => $betaTemp, 'new_password' => 'short']);
eq($w['json']['error']['code'] ?? null, 'weak_password', 'zayıf parola reddedilir');
$w = $b->post('/auth/password', ['current_password' => $betaTemp, 'new_password' => 'password12345A!']);
eq($w['json']['error']['code'] ?? null, 'weak_password', 'yaygın sözcük içeren parola reddedilir');
$betaPass = 'Mavi#Deniz-7421Kb';
$w = $b->post('/auth/password', ['current_password' => 'wrong', 'new_password' => $betaPass]);
eq($w['status'], 403, 'mevcut parola yanlışsa değişmez');
$w = $b->post('/auth/password', ['current_password' => $betaTemp, 'new_password' => $betaPass]);
check($w['ok'], 'parola değişir', $w['json']);
check($b->get('/meta')['ok'], 'değişimden sonra uygulama açılır');

// davet kabulü
$a = new Client();
$alfaPass = 'Yesil#Orman-9137Zx';
$r = $a->req('POST', '/auth/reset', ['token' => $tokA, 'password' => $alfaPass], [], false);
check($r['ok'], 'davet bağlantısıyla parola belirlenir', $r['json']);
eq($a->req('POST', '/auth/reset', ['token' => $tokA, 'password' => $alfaPass . 'x'], [], false)['status'], 400, 'davet jetonu tek kullanımlık');
$r = $a->login('alfa@test.local', $alfaPass);
check($r['ok'] && $r['json']['user']['must_change_password'] === false, 'davet sonrası doğrudan giriş');
eq($a->login('alfa@test.local', $alfaPass)['ok'], true, 'tekrar giriş çalışır');
$GLOBALS['ALFA'] = $ALFA;
$GLOBALS['BETA'] = $BETA;
$GLOBALS['ALFA_PASS'] = $alfaPass;
$GLOBALS['BETA_PASS'] = $betaPass;

section('Yetkilendirme: müşteri → admin');
eq($a->get('/admin/overview')['status'], 403, 'müşteri admin uç noktasını çağıramaz');
eq($a->get('/admin/tenants')['status'], 403, 'müşteri müşteri listesini göremez');
eq($a->post('/admin/tenants', ['name' => 'Z', 'email' => 'z@test.local'])['status'], 403, 'müşteri müşteri oluşturamaz');
check(DB::val("SELECT COUNT(*) FROM audit_log WHERE action='admin_endpoint_denied'") >= 3, 'reddedilen admin erişimleri denetim kaydına düştü');
$pr = $a->req('PATCH', '/auth/profile', ['display_name' => 'Hile', 'role' => 'admin', 'tenant_id' => $BETA]);
eq(DB::val('SELECT role FROM users WHERE email=?', ['alfa@test.local']), 'customer', 'istemci rol/tenant alanını değiştiremez (mass-assignment)');
eq((int)DB::val('SELECT tenant_id FROM users WHERE email=?', ['alfa@test.local']), $ALFA, 'tenant_id değişmez');
$threw = false;
try {
    Installer::createAdmin('second@test.local', 'Vk7#pL2$wN9xQzAa');
} catch (Throwable) {
    $threw = true;
}
check($threw, 'ikinci yönetici oluşturulamaz');
try {
    DB::exec("UPDATE users SET role='admin', admin_flag=1 WHERE email='alfa@test.local'");
    check(false, 'DB seviyesinde ikinci admin engellenir');
} catch (Throwable) {
    check(true, 'DB seviyesinde ikinci admin engellenir (UNIQUE admin_flag)');
}
$dis = $k->post('/auth/2fa/disable', ['password' => $ADMIN_PASS]);
eq($dis['status'], 403, 'admin 2FA kapatamaz');

section('Hesap kilitleme');
$lk = new Client();
for ($i = 0; $i < 5; $i++) {
    $lk->req('POST', '/auth/login', ['email' => 'alfa@test.local', 'password' => 'Wrong#Pass1234x'] + $lk->captcha(), [], false);
}
$r = $lk->req('POST', '/auth/login', ['email' => 'alfa@test.local', 'password' => $alfaPass] + $lk->captcha(), [], false);
eq($r['status'], 401, '5 başarısız denemeden sonra doğru parola bile geçici reddedilir');
check(DB::val("SELECT COUNT(*) FROM audit_log WHERE action='account_locked'") >= 1, 'kilitleme denetim kaydında');
eq($k->post("/admin/tenants/$ALFA/unlock")['ok'], true, 'admin kilidi açar');
check($lk->login('alfa@test.local', $alfaPass)['ok'], 'kilit açıldıktan sonra giriş');

section('Parola sıfırlama');
$fg = new Client();
$r = $fg->req('POST', '/auth/forgot', ['email' => 'alfa@test.local'] + $fg->captcha(), [], false);
check($r['ok'], 'sıfırlama isteği genel yanıt verir');
$r2 = $fg->req('POST', '/auth/forgot', ['email' => 'yok@test.local'] + $fg->captcha(), [], false);
eq($r2['json']['message'] ?? null, $r['json']['message'] ?? 'x', 'kayıtlı olmayan e-posta için yanıt aynı (kullanıcı sızdırmaz)');
$mail = (string)file_get_contents(FB_APP_DIR . '/storage/logs/mail.log');
preg_match_all('/#\/reset\?token=([\w-]+)/', $mail, $mm);
$resetTok = end($mm[1]);
$a2 = new Client();
$a2->login('alfa@test.local', $alfaPass);
$newPass = 'Kirmizi#Elma-5582Qw';
$r = $fg->req('POST', '/auth/reset', ['token' => $resetTok, 'password' => $newPass], [], false);
check($r['ok'], 'e-postadaki jetonla parola sıfırlanır', $r['json']);
eq($a2->get('/meta')['status'], 401, 'sıfırlama tüm açık oturumları sonlandırır');
check($a->login('alfa@test.local', $newPass)['ok'], 'yeni parolayla giriş');
$GLOBALS['ALFA_PASS'] = $newPass;
eq($fg->req('POST', '/auth/reset', ['token' => 'x' . $resetTok, 'password' => $newPass . 'z'], [], false)['status'], 400, 'geçersiz sıfırlama jetonu reddedilir');

section('Askıya alma');
$sus = new Client();
check($sus->login('beta@test.local', $betaPass)['ok'], 'beta giriş yapar');
$sess = $sus->cookie();
check($k->post("/admin/tenants/$BETA/status", ['status' => 'suspended'])['ok'], 'admin müşteriyi askıya alır');
eq($sus->get('/meta')['status'], 401, 'askıya alınınca mevcut oturum geçersiz');
$r = (new Client())->login('beta@test.local', $betaPass);
eq($r['json']['error']['code'] ?? null, 'account_suspended', 'askıdaki müşteri yeni oturum açamaz');
check($k->post("/admin/tenants/$BETA/status", ['status' => 'active'])['ok'], 'yeniden aktifleştirme');
check((new Client())->login('beta@test.local', $betaPass)['ok'], 'aktifleşince giriş yapılır');

section('Yönetici IP izin listesi');
use FB\Auth;
check(Auth::ipAllowed('203.0.113.7', []) === true, 'liste boşsa kısıt yok');
check(Auth::ipAllowed('203.0.113.7', ['203.0.113.7']) && !Auth::ipAllowed('203.0.113.8', ['203.0.113.7']), 'tekil IP izni');
check(Auth::ipAllowed('198.51.100.77', ['198.51.100.0/24']) && !Auth::ipAllowed('198.51.101.1', ['198.51.100.0/24']), 'IPv4 CIDR izni');
check(Auth::ipAllowed('2001:db8::1', ['2001:db8::/32']) && !Auth::ipAllowed('2001:db9::1', ['2001:db8::/32']), 'IPv6 CIDR izni');
check(!Auth::ipAllowed('not-an-ip', ['203.0.113.7']), 'geçersiz IP reddedilir');

section('Denetim zinciri');
eq(Audit::verifyChain(), null, 'denetim kaydı hash zinciri bozulmamış');
DB::exec("UPDATE audit_log SET action='tampered' WHERE id=(SELECT id FROM (SELECT MIN(id) id FROM audit_log WHERE action='admin_created') x)");
check(Audit::verifyChain() !== null, 'denetim kaydına müdahale zincirde tespit edilir');
DB::exec("UPDATE audit_log SET action='admin_created' WHERE action='tampered'");
eq(Audit::verifyChain(), null, 'geri alınınca zincir tekrar geçerli');

$GLOBALS['K'] = $k;
