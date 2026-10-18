<?php
declare(strict_types=1);

namespace FB;

final class AuthCtl
{
    public static function userView(array $u): array
    {
        return [
            'id' => (int)$u['id'],
            'email' => $u['email'],
            'display_name' => $u['display_name'],
            'role' => $u['role'],
            'tenant_id' => $u['tenant_id'] !== null ? (int)$u['tenant_id'] : null,
            'tenant_name' => $u['tenant_name'] ?? null,
            'must_change_password' => (int)$u['must_change_password'] === 1,
            'totp_enabled' => (int)$u['totp_enabled'] === 1,
            'must_enroll_2fa' => $u['role'] === 'admin' && (bool)Cfg::get('require_admin_2fa', true) && (int)$u['totp_enabled'] !== 1,
            'last_login_at' => $u['prev_login_at'],
            'last_login_ip' => $u['prev_login_ip'],
        ];
    }

    public static function captcha(Req $r): never
    {
        RateLimit::enforce('captcha_ip:' . $r->ip, 40, 600);
        Res::ok(Captcha::create($r->ip));
    }

    private static function checkCaptcha(Req $r): void
    {
        $b = $r->body;
        if (!empty($b['website'])) { // bal küpü alanı
            RateLimit::hit('honeypot:' . $r->ip, 1, 3600);
            throw new ApiError('captcha_invalid', 'Doğrulama başarısız. Yeni kodu deneyin.', 400);
        }
        $ok = Captcha::verify((string)($b['captcha_id'] ?? ''), (string)($b['captcha_answer'] ?? ''), (string)($b['pow_nonce'] ?? ''), $r->ip);
        if (!$ok) {
            throw new ApiError('captcha_invalid', 'Doğrulama kodu hatalı veya süresi dolmuş. Yeni kodu deneyin.', 400);
        }
    }

    public static function login(Req $r): never
    {
        RateLimit::enforce('login_ip:' . $r->ip, 40, 600);
        $b = $r->body;
        $email = strtolower(trim((string)($b['email'] ?? '')));
        $pass = (string)($b['password'] ?? '');
        if ($email === '' || $pass === '' || strlen($email) > 190 || strlen($pass) > 256) {
            throw new ApiError('invalid_credentials', 'E-posta veya parola hatalı.', 401);
        }
        self::checkCaptcha($r);
        $idKey = Util::hmac($email, 'login');
        RateLimit::enforce('login_id:' . $idKey, 15, 900);

        $u = DB::one('SELECT u.*, t.status AS tenant_status, t.name AS tenant_name FROM users u LEFT JOIN tenants t ON t.id=u.tenant_id WHERE u.email=?', [$email]);
        $generic = new ApiError('invalid_credentials', 'E-posta veya parola hatalı ya da hesap geçici olarak kilitli.', 401);
        if (!$u) {
            Pw::dummy();
            Audit::log('security', 'login_failed', ['details' => ['id' => substr($idKey, 0, 12), 'reason' => 'unknown'], 'ip' => $r->ip]);
            throw $generic;
        }
        if ($u['locked_until'] !== null && $u['locked_until'] > Util::now()) {
            Pw::dummy();
            Audit::log('security', 'login_locked', ['actor' => (int)$u['id'], 'tenant' => $u['tenant_id'], 'ip' => $r->ip]);
            throw $generic;
        }
        if (!Pw::verify($pass, $u['password_hash'])) {
            $n = (int)$u['failed_attempts'] + 1;
            $upd = ['failed_attempts' => $n];
            if ($n >= 5) {
                $lvl = (int)$u['lock_level'] + 1;
                $upd['lock_level'] = $lvl;
                $upd['failed_attempts'] = 0;
                $upd['locked_until'] = Util::plus(min(15 * (2 ** ($lvl - 1)), 1440) * 60);
                Audit::log('security', 'account_locked', ['actor' => (int)$u['id'], 'tenant' => $u['tenant_id'], 'details' => ['level' => $lvl], 'ip' => $r->ip]);
            }
            DB::update('users', $upd, 'id=?', [$u['id']]);
            Audit::log('security', 'login_failed', ['actor' => (int)$u['id'], 'tenant' => $u['tenant_id'], 'details' => ['reason' => 'password'], 'ip' => $r->ip]);
            throw $generic;
        }
        if ($u['status'] !== 'active' || ($u['role'] === 'customer' && $u['tenant_status'] !== 'active')) {
            Audit::log('security', 'login_blocked_suspended', ['actor' => (int)$u['id'], 'tenant' => $u['tenant_id'], 'ip' => $r->ip]);
            throw new ApiError('account_suspended', 'Hesabınız askıya alınmış veya arşivlenmiş. Lütfen yönetici ile iletişime geçin.', 403);
        }
        if ($u['role'] === 'admin' && !Auth::ipAllowed($r->ip)) {
            Audit::log('security', 'admin_login_ip_denied', ['actor' => (int)$u['id'], 'role' => 'admin', 'ip' => $r->ip]);
            throw $generic;
        }
        if (Pw::needsRehash($u['password_hash'])) {
            DB::update('users', ['password_hash' => Pw::hash($pass)], 'id=?', [$u['id']]);
        }
        // Oturum sabitlemeye karşı: istekle gelen eski oturum çerezi iptal edilir
        Auth::revokeCurrent($r);

        if ((int)$u['totp_enabled'] === 1) {
            $tok = Util::token();
            DB::insert('login_tickets', ['token_hash' => Util::sha($tok), 'user_id' => $u['id'], 'ip' => $r->ip, 'expires_at' => Util::plus(300)]);
            Res::ok(['need_2fa' => true, 'ticket' => $tok]);
        }
        self::finishLogin($u, $r);
    }

    private static function finishLogin(array $u, Req $r): never
    {
        DB::update('users', [
            'failed_attempts' => 0, 'lock_level' => 0, 'locked_until' => null,
            'prev_login_at' => $u['last_login_at'], 'prev_login_ip' => $u['last_login_ip'],
            'last_login_at' => Util::now(), 'last_login_ip' => $r->ip,
        ], 'id=?', [$u['id']]);
        $u = DB::one('SELECT u.*, t.name AS tenant_name FROM users u LEFT JOIN tenants t ON t.id=u.tenant_id WHERE u.id=?', [$u['id']]);
        $s = Auth::createSession($u, $r);
        Auth::$user = $u;
        if ($u['tenant_id']) {
            DB::exec('UPDATE tenants SET last_activity_at=? WHERE id=?', [Util::now(), $u['tenant_id']]);
        }
        Audit::log('security', 'login_success', ['actor' => (int)$u['id'], 'role' => $u['role'], 'tenant' => $u['tenant_id'], 'ip' => $r->ip]);
        Res::ok(['user' => self::userView($u), 'csrf' => $s['csrf']]);
    }

    public static function login2fa(Req $r): never
    {
        RateLimit::enforce('2fa_ip:' . $r->ip, 30, 600);
        $b = $r->body;
        $tok = (string)($b['ticket'] ?? '');
        $code = (string)($b['code'] ?? '');
        $t = DB::one('SELECT * FROM login_tickets WHERE token_hash=?', [Util::sha($tok)]);
        $bad = new ApiError('invalid_code', 'Doğrulama kodu hatalı veya süresi dolmuş.', 401);
        if (!$t || $t['used_at'] !== null || $t['expires_at'] < Util::now() || (int)$t['attempts'] >= 5) {
            throw $bad;
        }
        DB::exec('UPDATE login_tickets SET attempts=attempts+1 WHERE id=?', [$t['id']]);
        $u = DB::one('SELECT u.*, t.name AS tenant_name FROM users u LEFT JOIN tenants t ON t.id=u.tenant_id WHERE u.id=?', [$t['user_id']]);
        if (!$u || $u['status'] !== 'active') {
            throw $bad;
        }
        $secret = Crypto::decrypt((string)$u['totp_secret_enc'], 'totp');
        $ok = false;
        if ($secret !== null) {
            $step = Totp::verify($secret, $code, (int)($u['totp_last_step'] ?? 0));
            if ($step !== null) {
                DB::exec('UPDATE users SET totp_last_step=? WHERE id=?', [$step, $u['id']]);
                $ok = true;
            }
        }
        if (!$ok && ($b['recovery'] ?? false)) {
            $ok = self::useRecovery($u, $code);
        }
        if (!$ok) {
            Audit::log('security', '2fa_failed', ['actor' => (int)$u['id'], 'tenant' => $u['tenant_id'], 'ip' => $r->ip]);
            throw $bad;
        }
        DB::exec('UPDATE login_tickets SET used_at=? WHERE id=? AND used_at IS NULL', [Util::now(), $t['id']]);
        self::finishLogin($u, $r);
    }

    private static function useRecovery(array $u, string $code): bool
    {
        $codes = json_decode((string)$u['recovery_codes'], true) ?: [];
        $h = Util::hmac(strtoupper(preg_replace('/[\s-]/', '', $code) ?? ''), 'recovery');
        $i = array_search($h, $codes, true);
        if ($i === false) {
            return false;
        }
        unset($codes[$i]);
        DB::update('users', ['recovery_codes' => Util::json(array_values($codes))], 'id=?', [$u['id']]);
        Audit::log('security', '2fa_recovery_used', ['actor' => (int)$u['id'], 'tenant' => $u['tenant_id']]);
        return true;
    }

    public static function session(Req $r): never
    {
        if (!Auth::$user) {
            Res::ok(['authenticated' => false]);
        }
        Res::ok(['authenticated' => true, 'user' => self::userView(Auth::$user), 'csrf' => Auth::csrfFor(Auth::$session)]);
    }

    public static function logout(Req $r): never
    {
        $u = Auth::requireUser();
        DB::exec('UPDATE sessions SET revoked_at=? WHERE id=?', [Util::now(), Auth::$session['id']]);
        Auth::clearCookie();
        Audit::log('security', 'logout', ['actor' => (int)$u['id'], 'tenant' => $u['tenant_id'], 'ip' => $r->ip]);
        header('Clear-Site-Data: "cache", "storage"');
        Res::ok();
    }

    public static function changePassword(Req $r): never
    {
        $u = Auth::requireUser();
        $b = $r->body;
        RateLimit::enforce('pwchange:' . $u['id'], 10, 900);
        $cur = (string)($b['current_password'] ?? '');
        $new = (string)($b['new_password'] ?? '');
        if (!Pw::verify($cur, $u['password_hash'])) {
            Audit::log('security', 'password_change_failed', ['tenant' => $u['tenant_id']]);
            throw new ApiError('invalid_credentials', 'Mevcut parola hatalı.', 403, 'current_password');
        }
        if (hash_equals($cur, $new)) {
            throw new ApiError('weak_password', 'Yeni parola mevcut paroladan farklı olmalı.', 422, 'new_password');
        }
        Pw::policy($new, $u['email']);
        DB::update('users', [
            'password_hash' => Pw::hash($new), 'must_change_password' => 0,
            'session_epoch' => (int)$u['session_epoch'] + 1, 'password_changed_at' => Util::now(),
        ], 'id=?', [$u['id']]);
        // Tüm oturumlar düşer; bu cihaz için yeni kimlikle devam edilir
        DB::exec('UPDATE sessions SET revoked_at=? WHERE user_id=? AND revoked_at IS NULL', [Util::now(), $u['id']]);
        $nu = DB::one('SELECT u.*, t.name AS tenant_name FROM users u LEFT JOIN tenants t ON t.id=u.tenant_id WHERE u.id=?', [$u['id']]);
        $s = Auth::createSession($nu, $r);
        Auth::$user = $nu;
        Audit::log('security', 'password_changed', ['tenant' => $u['tenant_id']]);
        Res::ok(['user' => self::userView($nu), 'csrf' => $s['csrf']]);
    }

    public static function forgot(Req $r): never
    {
        RateLimit::enforce('forgot_ip:' . $r->ip, 10, 3600);
        self::checkCaptcha($r);
        $email = V::email($r->body);
        RateLimit::enforce('forgot_id:' . Util::hmac($email, 'login'), 3, 3600);
        $u = DB::one('SELECT * FROM users WHERE email=? AND status=\'active\'', [$email]);
        if ($u) {
            $link = self::issueToken((int)$u['id'], 'reset', 1800, null);
            Mailer::send($u['email'], 'FunBreak Ön Muhasebe — parola sıfırlama', "Parolanızı sıfırlamak için aşağıdaki bağlantıyı 30 dakika içinde kullanın:\n\n$link\n\nBu isteği siz yapmadıysanız bu e-postayı yok sayın.");
            Audit::log('security', 'password_reset_requested', ['actor' => (int)$u['id'], 'tenant' => $u['tenant_id'], 'ip' => $r->ip]);
        } else {
            Pw::dummy();
        }
        Res::ok(['message' => 'Bu e-posta kayıtlıysa sıfırlama bağlantısı gönderildi.']);
    }

    /** Tek kullanımlık, hash'lenmiş jeton oluşturur; bağlantıyı döner (fragment içinde: sunucu loglarına düşmez). */
    public static function issueToken(int $userId, string $purpose, int $ttl, ?int $by): string
    {
        DB::exec('UPDATE password_tokens SET used_at=? WHERE user_id=? AND used_at IS NULL', [Util::now(), $userId]);
        $tok = Util::token();
        DB::insert('password_tokens', [
            'token_hash' => Util::sha($tok), 'user_id' => $userId, 'purpose' => $purpose,
            'expires_at' => Util::plus($ttl), 'created_by' => $by, 'created_at' => Util::now(),
        ]);
        return rtrim((string)Cfg::get('base_url'), '/') . '/#/reset?token=' . $tok;
    }

    public static function reset(Req $r): never
    {
        RateLimit::enforce('reset_ip:' . $r->ip, 20, 3600);
        $b = $r->body;
        $tok = (string)($b['token'] ?? '');
        $new = (string)($b['password'] ?? '');
        $bad = new ApiError('invalid_token', 'Bağlantı geçersiz veya süresi dolmuş.', 400);
        $t = DB::one('SELECT * FROM password_tokens WHERE token_hash=?', [Util::sha($tok)]);
        if (!$t || $t['used_at'] !== null || $t['expires_at'] < Util::now()) {
            throw $bad;
        }
        $u = DB::one('SELECT * FROM users WHERE id=?', [$t['user_id']]);
        if (!$u || $u['status'] === 'archived') {
            throw $bad;
        }
        Pw::policy($new, $u['email']);
        DB::tx(function () use ($t, $u, $new, $r) {
            if (DB::exec('UPDATE password_tokens SET used_at=? WHERE token_hash=? AND used_at IS NULL', [Util::now(), $t['token_hash']]) !== 1) {
                throw new ApiError('invalid_token', 'Bağlantı geçersiz veya süresi dolmuş.', 400);
            }
            DB::update('users', [
                'password_hash' => Pw::hash($new), 'must_change_password' => 0, 'failed_attempts' => 0, 'lock_level' => 0,
                'locked_until' => null, 'session_epoch' => (int)$u['session_epoch'] + 1, 'password_changed_at' => Util::now(),
                'email_verified_at' => $u['email_verified_at'] ?? Util::now(),
            ], 'id=?', [$u['id']]);
            DB::exec('UPDATE sessions SET revoked_at=? WHERE user_id=? AND revoked_at IS NULL', [Util::now(), $u['id']]);
            Audit::log('security', $t['purpose'] === 'invite' ? 'invite_accepted' : 'password_reset_done', ['actor' => (int)$u['id'], 'role' => $u['role'], 'tenant' => $u['tenant_id'], 'ip' => $r->ip]);
        });
        Res::ok(['message' => 'Parolanız belirlendi. Şimdi giriş yapabilirsiniz.']);
    }

    public static function sessions(Req $r): never
    {
        $u = Auth::requireUser();
        $rows = DB::all('SELECT id,label,ip,created_at,last_seen_at FROM sessions WHERE user_id=? AND revoked_at IS NULL AND expires_at>? ORDER BY last_seen_at DESC', [$u['id'], Util::now()]);
        foreach ($rows as &$x) {
            $x['current'] = (int)$x['id'] === (int)Auth::$session['id'];
            $x['id'] = (int)$x['id'];
        }
        Res::ok(['sessions' => $rows]);
    }

    public static function revokeSession(Req $r, array $p): never
    {
        $u = Auth::requireUser();
        $n = DB::exec('UPDATE sessions SET revoked_at=? WHERE id=? AND user_id=? AND revoked_at IS NULL', [Util::now(), (int)$p['id'], $u['id']]);
        if ($n) {
            Audit::log('security', 'session_revoked', ['tenant' => $u['tenant_id'], 'entity' => 'session', 'id' => (int)$p['id']]);
        }
        Res::ok();
    }

    public static function revokeOthers(Req $r): never
    {
        $u = Auth::requireUser();
        DB::exec('UPDATE sessions SET revoked_at=? WHERE user_id=? AND id<>? AND revoked_at IS NULL', [Util::now(), $u['id'], Auth::$session['id']]);
        Audit::log('security', 'sessions_revoked_others', ['tenant' => $u['tenant_id']]);
        Res::ok();
    }

    public static function profile(Req $r): never
    {
        $u = Auth::requireUser();
        $name = V::str($r->body, 'display_name', 160, false);
        DB::update('users', ['display_name' => $name], 'id=?', [$u['id']]);
        Res::ok();
    }

    // ---- İki aşamalı doğrulama (TOTP) ----
    public static function setup2fa(Req $r): never
    {
        $u = Auth::requireUser();
        if ((int)$u['totp_enabled'] === 1) {
            throw new ApiError('already_enabled', 'İki aşamalı doğrulama zaten etkin.', 409);
        }
        $secret = Totp::newSecret();
        DB::update('users', ['totp_secret_enc' => Crypto::encrypt($secret, 'totp'), 'totp_last_step' => 0], 'id=?', [$u['id']]);
        Res::ok(['secret' => $secret, 'uri' => Totp::uri($secret, $u['email'])]);
    }

    public static function enable2fa(Req $r): never
    {
        $u = Auth::requireUser();
        RateLimit::enforce('2fa_enable:' . $u['id'], 10, 900);
        if ((int)$u['totp_enabled'] === 1 || !$u['totp_secret_enc']) {
            throw new ApiError('invalid_state', 'Önce kurulumu başlatın.', 409);
        }
        $secret = Crypto::decrypt($u['totp_secret_enc'], 'totp');
        $step = $secret ? Totp::verify($secret, (string)($r->body['code'] ?? ''), 0) : null;
        if ($step === null) {
            throw new ApiError('invalid_code', 'Doğrulama kodu hatalı.', 422, 'code');
        }
        $plain = [];
        $hashes = [];
        for ($i = 0; $i < 8; $i++) {
            $c = strtoupper(bin2hex(random_bytes(5)));
            $plain[] = substr($c, 0, 5) . '-' . substr($c, 5);
            $hashes[] = Util::hmac($c, 'recovery');
        }
        DB::update('users', ['totp_enabled' => 1, 'totp_last_step' => $step, 'recovery_codes' => Util::json($hashes), 'session_epoch' => (int)$u['session_epoch'] + 1], 'id=?', [$u['id']]);
        DB::exec('UPDATE sessions SET revoked_at=? WHERE user_id=? AND id<>? AND revoked_at IS NULL', [Util::now(), $u['id'], Auth::$session['id']]);
        DB::exec('UPDATE sessions SET epoch=epoch+1 WHERE id=?', [Auth::$session['id']]);
        Audit::log('security', '2fa_enabled', ['tenant' => $u['tenant_id']]);
        Res::ok(['recovery_codes' => $plain]);
    }

    public static function disable2fa(Req $r): never
    {
        $u = Auth::requireUser();
        if ($u['role'] === 'admin' && Cfg::get('require_admin_2fa', true)) {
            throw new ApiError('forbidden', 'Yönetici hesabında iki aşamalı doğrulama kapatılamaz.', 403);
        }
        if (!Pw::verify((string)($r->body['password'] ?? ''), $u['password_hash'])) {
            throw new ApiError('invalid_credentials', 'Parola hatalı.', 403, 'password');
        }
        DB::update('users', ['totp_enabled' => 0, 'totp_secret_enc' => null, 'recovery_codes' => null, 'session_epoch' => (int)$u['session_epoch'] + 1], 'id=?', [$u['id']]);
        DB::exec('UPDATE sessions SET revoked_at=? WHERE user_id=? AND id<>? AND revoked_at IS NULL', [Util::now(), $u['id'], Auth::$session['id']]);
        DB::exec('UPDATE sessions SET epoch=epoch+1 WHERE id=?', [Auth::$session['id']]);
        Audit::log('security', '2fa_disabled', ['tenant' => $u['tenant_id']]);
        Res::ok();
    }
}
