<?php
declare(strict_types=1);

namespace FB;

final class Audit
{
    private const REDACT = '/pass|token|secret|csrf|totp|recovery|code|hash/i';

    private static function clean(mixed $v): mixed
    {
        if (!is_array($v)) {
            return is_string($v) && mb_strlen($v) > 500 ? mb_substr($v, 0, 500) . '…' : $v;
        }
        $o = [];
        foreach ($v as $k => $x) {
            $o[$k] = is_string($k) && preg_match(self::REDACT, $k) ? '[gizli]' : self::clean($x);
        }
        return $o;
    }

    /** $o: tenant, entity, id, details, ip */
    public static function log(string $cat, string $action, array $o = []): void
    {
        $u = Auth::$user;
        $row = [
            'ts' => Util::now(),
            'actor_user_id' => $o['actor'] ?? ($u['id'] ?? null),
            'actor_role' => $o['role'] ?? ($u['role'] ?? null),
            'tenant_id' => $o['tenant'] ?? null,
            'category' => $cat,
            'action' => $action,
            'entity' => $o['entity'] ?? null,
            'entity_id' => isset($o['id']) ? (string)$o['id'] : null,
            'details' => isset($o['details']) ? Util::json(self::clean($o['details'])) : null,
            'ip' => $o['ip'] ?? ($GLOBALS['FB_IP'] ?? null),
        ];
        DB::tx(function () use ($row) {
            $prev = (string)(DB::val('SELECT hash FROM audit_log ORDER BY id DESC LIMIT 1 FOR UPDATE') ?? str_repeat('0', 64));
            $row['prev_hash'] = $prev;
            $row['hash'] = hash_hmac('sha256', $prev . '|' . Util::json($row), (string)Cfg::get('pepper'));
            DB::insert('audit_log', $row);
        });
    }

    /** Zincir bütünlüğünü doğrular; bozulan ilk kayıt id'sini döner (yoksa null). */
    public static function verifyChain(int $limit = 5000): ?int
    {
        $prev = str_repeat('0', 64);
        $rows = DB::all('SELECT * FROM audit_log ORDER BY id ASC LIMIT ' . (int)$limit);
        foreach ($rows as $r) {
            $h = $r['hash'];
            $id = (int)$r['id'];
            unset($r['id'], $r['hash']);
            $r['prev_hash'] = $prev;
            $calc = hash_hmac('sha256', $prev . '|' . Util::json($r), (string)Cfg::get('pepper'));
            if (!hash_equals($calc, $h)) {
                return $id;
            }
            $prev = $h;
        }
        return null;
    }
}

final class RateLimit
{
    /** Pencere içindeki vuruş sayısını artırır; limit aşıldıysa false. */
    public static function hit(string $key, int $max, int $windowSec): bool
    {
        $k = substr($key, 0, 120);
        $now = Util::now();
        $old = Util::plus(-$windowSec);
        DB::q(
            'INSERT INTO rate_limits (k,hits,window_start) VALUES (?,1,?)
             ON DUPLICATE KEY UPDATE hits = IF(window_start < ?, 1, hits + 1), window_start = IF(window_start < ?, ?, window_start)',
            [$k, $now, $old, $old, $now]
        );
        return (int)DB::val('SELECT hits FROM rate_limits WHERE k=?', [$k]) <= $max;
    }

    public static function enforce(string $key, int $max, int $windowSec): void
    {
        if (!self::hit($key, $max, $windowSec)) {
            throw new ApiError('rate_limited', 'Çok fazla deneme yaptınız. Lütfen bir süre sonra tekrar deneyin.', 429);
        }
    }

    public static function gc(): void
    {
        if (random_int(1, 50) === 1) {
            DB::exec('DELETE FROM rate_limits WHERE window_start < ?', [Util::plus(-86400)]);
            DB::exec('DELETE FROM captchas WHERE expires_at < ?', [Util::plus(-3600)]);
            DB::exec('DELETE FROM login_tickets WHERE expires_at < ?', [Util::plus(-3600)]);
            DB::exec('DELETE FROM idempotency_keys WHERE created_at < ?', [Util::plus(-7 * 86400)]);
            DB::exec('DELETE FROM sessions WHERE expires_at < ? OR revoked_at < ?', [Util::plus(-86400), Util::plus(-7 * 86400)]);
        }
    }
}

final class Pw
{
    private const COMMON = ['password', 'parola', '12345678', 'qwertyuiop', 'iloveyou', 'admin', 'welcome', 'letmein', 'funbreak', 'muhasebe', 'sifre', 'şifre', 'abc123', '111111', '123456789', 'qwerty123'];

    private static function pre(string $plain): string
    {
        return base64_encode(hash_hmac('sha256', $plain, (string)Cfg::get('pepper'), true));
    }

    private static function algo(): string|int
    {
        return defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT;
    }

    private static function opts(): array
    {
        return defined('PASSWORD_ARGON2ID')
            ? ['memory_cost' => 19456, 'time_cost' => 2, 'threads' => 1]
            : ['cost' => 12];
    }

    public static function hash(string $plain): string
    {
        return password_hash(self::pre($plain), self::algo(), self::opts());
    }

    public static function verify(string $plain, string $hash): bool
    {
        return password_verify(self::pre($plain), $hash);
    }

    public static function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, self::algo(), self::opts());
    }

    /** Sabit süreli sahte doğrulama (kullanıcı yoksa zamanlama farkını gizler). */
    public static function dummy(): void
    {
        static $h = null;
        $h ??= self::hash('dummy-' . bin2hex(random_bytes(8)));
        self::verify('x', $h);
    }

    public static function policy(string $plain, string $email = ''): void
    {
        $len = mb_strlen($plain);
        if ($len < 12) {
            throw new ApiError('weak_password', 'Parola en az 12 karakter olmalı.', 422, 'password');
        }
        if ($len > 128) {
            throw new ApiError('weak_password', 'Parola en fazla 128 karakter olabilir.', 422, 'password');
        }
        $classes = (preg_match('/\p{Ll}/u', $plain) ? 1 : 0) + (preg_match('/\p{Lu}/u', $plain) ? 1 : 0)
            + (preg_match('/\d/', $plain) ? 1 : 0) + (preg_match('/[^\p{L}\d]/u', $plain) ? 1 : 0);
        if ($classes < 3) {
            throw new ApiError('weak_password', 'Parola; büyük harf, küçük harf, rakam ve sembolden en az üçünü içermeli.', 422, 'password');
        }
        $low = mb_strtolower($plain);
        foreach (self::COMMON as $c) {
            if (str_contains($low, $c)) {
                throw new ApiError('weak_password', 'Parola çok tahmin edilebilir; yaygın bir sözcük içermemeli.', 422, 'password');
            }
        }
        if ($email !== '') {
            $local = strtolower(explode('@', $email)[0]);
            if (strlen($local) >= 4 && str_contains($low, $local)) {
                throw new ApiError('weak_password', 'Parola e-posta adresinizi içermemeli.', 422, 'password');
            }
        }
        if (preg_match('/(.)\1{3,}/u', $plain) || preg_match('/(0123|1234|2345|3456|4567|5678|6789|abcd|qwer)/i', $plain)) {
            throw new ApiError('weak_password', 'Parola ardışık veya tekrar eden karakter dizileri içermemeli.', 422, 'password');
        }
    }

    public static function generate(): string
    {
        $sets = ['ABCDEFGHJKLMNPQRSTUVWXYZ', 'abcdefghijkmnopqrstuvwxyz', '23456789', '!@#$%*+-?'];
        $all = implode('', $sets);
        $out = [];
        foreach ($sets as $s) {
            $out[] = $s[random_int(0, strlen($s) - 1)];
        }
        while (count($out) < 18) {
            $out[] = $all[random_int(0, strlen($all) - 1)];
        }
        for ($i = count($out) - 1; $i > 0; $i--) {
            $j = random_int(0, $i);
            [$out[$i], $out[$j]] = [$out[$j], $out[$i]];
        }
        return implode('', $out);
    }
}

final class Totp
{
    private const B32 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public static function newSecret(): string
    {
        $bytes = random_bytes(20);
        $bits = '';
        foreach (str_split($bytes) as $c) {
            $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::B32[bindec(str_pad($chunk, 5, '0'))];
        }
        return $out;
    }

    private static function decode(string $s): string
    {
        $bits = '';
        foreach (str_split(strtoupper($s)) as $c) {
            $p = strpos(self::B32, $c);
            if ($p === false) {
                continue;
            }
            $bits .= str_pad(decbin($p), 5, '0', STR_PAD_LEFT);
        }
        $o = '';
        foreach (str_split($bits, 8) as $b) {
            if (strlen($b) === 8) {
                $o .= chr(bindec($b));
            }
        }
        return $o;
    }

    public static function code(string $secret, int $step): string
    {
        $h = hash_hmac('sha1', pack('J', $step), self::decode($secret), true);
        $o = ord($h[19]) & 15;
        $n = ((ord($h[$o]) & 0x7f) << 24) | (ord($h[$o + 1]) << 16) | (ord($h[$o + 2]) << 8) | ord($h[$o + 3]);
        return str_pad((string)($n % 1000000), 6, '0', STR_PAD_LEFT);
    }

    /** Geçerli adım numarasını döner (yeniden kullanım denetimi için) veya null. */
    public static function verify(string $secret, string $code, int $lastStep = 0, ?int $now = null): ?int
    {
        $code = preg_replace('/\s+/', '', $code) ?? '';
        if (!preg_match('/^\d{6}$/', $code)) {
            return null;
        }
        $cur = intdiv($now ?? time(), 30);
        for ($d = -1; $d <= 1; $d++) {
            $s = $cur + $d;
            if ($s > $lastStep && hash_equals(self::code($secret, $s), $code)) {
                return $s;
            }
        }
        return null;
    }

    public static function uri(string $secret, string $email): string
    {
        return 'otpauth://totp/' . rawurlencode('FunBreak Ön Muhasebe:' . $email) . '?secret=' . $secret . '&issuer=' . rawurlencode('FunBreak Ön Muhasebe') . '&algorithm=SHA1&digits=6&period=30';
    }
}

final class Captcha
{
    private const ALPHA = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    public static function create(string $ip): array
    {
        $text = '';
        for ($i = 0; $i < 6; $i++) {
            $text .= self::ALPHA[random_int(0, strlen(self::ALPHA) - 1)];
        }
        if (!Cfg::isProd() && Cfg::get('test_captcha')) {
            $text = (string)Cfg::get('test_captcha'); // yalnızca otomatik testlerde (üretimde yok sayılır)
        }
        $id = bin2hex(random_bytes(16));
        $seed = bin2hex(random_bytes(8));
        $bits = (int)Cfg::get('pow_bits', 16);
        DB::insert('captchas', [
            'id' => $id,
            'answer_hash' => Util::hmac(strtolower($text) . '|' . $id, 'captcha'),
            'pow_seed' => $seed,
            'pow_bits' => $bits,
            'ip_hash' => Util::hmac($ip, 'ip'),
            'expires_at' => Util::plus(180),
        ]);
        return ['id' => $id, 'image' => 'data:image/png;base64,' . base64_encode(self::render($text)), 'pow' => ['seed' => $seed, 'bits' => $bits]];
    }

    /** GD yerleşik yazı tipiyle; ölçekleme, döndürme, dalga bozulması ve gürültü ile çizer. */
    private static function render(string $text): string
    {
        $w = 220;
        $h = 70;
        $img = imagecreatetruecolor($w, $h);
        imagefilledrectangle($img, 0, 0, $w, $h, imagecolorallocate($img, 245, 247, 250));
        $x = 12;
        foreach (str_split($text) as $ch) {
            $cw = 9;
            $chh = 15;
            $small = imagecreatetruecolor($cw, $chh);
            imagefilledrectangle($small, 0, 0, $cw, $chh, imagecolorallocate($small, 255, 255, 255));
            imagechar($small, 5, 1, 0, $ch, imagecolorallocate($small, 0, 0, 0));
            $scale = random_int(30, 38) / 10;
            $bw = (int)($cw * $scale);
            $bh = (int)($chh * $scale);
            $big = imagecreatetruecolor($bw, $bh);
            imagecopyresampled($big, $small, 0, 0, 0, 0, $bw, $bh, $cw, $chh);
            $rot = imagerotate($big, random_int(-24, 24), imagecolorallocate($big, 255, 255, 255));
            $rw = imagesx($rot);
            $rh = imagesy($rot);
            $col = [random_int(20, 120), random_int(20, 120), random_int(60, 160)];
            for ($px = 0; $px < $rw; $px++) {
                for ($py = 0; $py < $rh; $py++) {
                    $c = imagecolorat($rot, $px, $py) & 0xFF;
                    if ($c < 140) {
                        $dx = $x + $px;
                        $dy = 8 + $py + random_int(-2, 6) * 0 + (int)(sin(($dx) / 9.0) * 4);
                        if ($dx < $w && $dy < $h && $dy >= 0) {
                            imagesetpixel($img, $dx, $dy, imagecolorallocate($img, $col[0], $col[1], $col[2]));
                        }
                    }
                }
            }
            $x += (int)($rw * 0.72) + random_int(0, 4);
        }
        for ($i = 0; $i < 7; $i++) {
            imageline($img, random_int(0, $w), random_int(0, $h), random_int(0, $w), random_int(0, $h), imagecolorallocate($img, random_int(90, 200), random_int(90, 200), random_int(90, 200)));
        }
        for ($i = 0; $i < 350; $i++) {
            imagesetpixel($img, random_int(0, $w - 1), random_int(0, $h - 1), imagecolorallocate($img, random_int(80, 210), random_int(80, 210), random_int(80, 210)));
        }
        ob_start();
        imagepng($img);
        return (string)ob_get_clean();
    }

    public static function powOk(string $seed, string $nonce, int $bits): bool
    {
        if (!preg_match('/^\d{1,12}$/', $nonce)) {
            return false;
        }
        $bin = hash('sha256', $seed . ':' . $nonce, true);
        $full = intdiv($bits, 8);
        for ($i = 0; $i < $full; $i++) {
            if ($bin[$i] !== "\0") {
                return false;
            }
        }
        $rem = $bits % 8;
        return $rem === 0 || (ord($bin[$full]) >> (8 - $rem)) === 0;
    }

    /** Tek kullanımlık: doğru ya da yanlış, çözümleme denemesi kaydı tüketilir. */
    public static function verify(string $id, string $answer, string $nonce, string $ip): bool
    {
        if (!preg_match('/^[0-9a-f]{32}$/', $id)) {
            return false;
        }
        $row = DB::one('SELECT * FROM captchas WHERE id=?', [$id]);
        if (!$row) {
            return false;
        }
        $used = DB::exec('UPDATE captchas SET used_at=? WHERE id=? AND used_at IS NULL AND expires_at > ?', [Util::now(), $id, Util::now()]);
        if ($used !== 1) {
            return false;
        }
        if (!hash_equals($row['ip_hash'], Util::hmac($ip, 'ip'))) {
            return false;
        }
        if (!self::powOk($row['pow_seed'], $nonce, (int)$row['pow_bits'])) {
            return false;
        }
        return hash_equals($row['answer_hash'], Util::hmac(strtolower(trim($answer)) . '|' . $id, 'captcha'));
    }
}

final class Mailer
{
    public static function send(string $to, string $subject, string $text): bool
    {
        $from = (string)Cfg::get('mail_from', 'no-reply@funbreak.com.tr');
        if (Cfg::get('mail_transport', 'mail') === 'log') {
            @file_put_contents(FB_APP_DIR . '/storage/logs/mail.log', '[' . Util::now() . "] TO:$to SUBJECT:$subject\n$text\n\n", FILE_APPEND | LOCK_EX);
            return true;
        }
        $enc = '=?UTF-8?B?' . base64_encode($subject) . '?=';
        $h = "From: FunBreak Ön Muhasebe <$from>\r\nContent-Type: text/plain; charset=UTF-8\r\nMIME-Version: 1.0\r\nX-Mailer: FBMuh";
        return @mail($to, $enc, $text, $h);
    }
}

final class Auth
{
    public static ?array $user = null;
    public static ?array $session = null;
    public static bool $rotated = false;

    public static function cookieName(): string
    {
        return Cfg::isProd() ? '__Host-fbsid' : 'fbsid';
    }

    private static function secureCookie(): bool
    {
        return Cfg::isProd() || (isset($GLOBALS['FB_HTTPS']) && $GLOBALS['FB_HTTPS']);
    }

    public static function setCookie(string $token): void
    {
        setcookie(self::cookieName(), $token, [
            'expires' => 0,
            'path' => '/',
            'secure' => self::secureCookie(),
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
    }

    public static function clearCookie(): void
    {
        setcookie(self::cookieName(), '', ['expires' => 1, 'path' => '/', 'secure' => self::secureCookie(), 'httponly' => true, 'samesite' => 'Strict']);
    }

    public static function csrfFor(array $session): string
    {
        return hash_hmac('sha256', 'csrf|' . $session['csrf_secret'], (string)Cfg::get('pepper'));
    }

    public static function uaLabel(string $ua): string
    {
        $os = preg_match('/iPhone|iPad/i', $ua) ? 'iOS' : (preg_match('/Android/i', $ua) ? 'Android' : (preg_match('/Windows/i', $ua) ? 'Windows' : (preg_match('/Mac OS/i', $ua) ? 'macOS' : (preg_match('/Linux/i', $ua) ? 'Linux' : 'Bilinmeyen'))));
        $br = preg_match('/Edg\//', $ua) ? 'Edge' : (preg_match('/Chrome\//', $ua) ? 'Chrome' : (preg_match('/Firefox\//', $ua) ? 'Firefox' : (preg_match('/Safari\//', $ua) ? 'Safari' : 'Tarayıcı')));
        return "$br / $os";
    }

    /** Yeni oturum açar (her girişte yeni, tahmin edilemez kimlik). */
    public static function createSession(array $user, Req $req): array
    {
        $idle = (int)Cfg::get('session_absolute', 43200);
        $token = Util::token();
        $csrf = bin2hex(random_bytes(32));
        $now = Util::now();
        DB::insert('sessions', [
            'token_hash' => Util::sha($token),
            'user_id' => $user['id'],
            'epoch' => $user['session_epoch'],
            'csrf_secret' => $csrf,
            'ip' => $req->ip,
            'ua_hash' => Util::sha($req->ua),
            'label' => self::uaLabel($req->ua),
            'created_at' => $now,
            'last_seen_at' => $now,
            'rotated_at' => $now,
            'expires_at' => Util::plus($idle),
        ]);
        // Eşzamanlı oturum sınırı: en eski oturumları iptal et
        $max = (int)Cfg::get('max_sessions', 8);
        $ids = DB::all('SELECT id FROM sessions WHERE user_id=? AND revoked_at IS NULL AND expires_at > ? ORDER BY id DESC', [$user['id'], $now]);
        foreach (array_slice($ids, $max) as $r) {
            DB::exec('UPDATE sessions SET revoked_at=? WHERE id=?', [$now, $r['id']]);
        }
        self::setCookie($token);
        return ['csrf' => hash_hmac('sha256', 'csrf|' . $csrf, (string)Cfg::get('pepper'))];
    }

    public static function revokeCurrent(Req $req): void
    {
        $t = $_COOKIE[self::cookieName()] ?? null;
        if (is_string($t) && $t !== '') {
            $h = Util::sha($t);
            DB::exec('UPDATE sessions SET revoked_at=? WHERE (token_hash=? OR prev_token_hash=?) AND revoked_at IS NULL', [Util::now(), $h, $h]);
        }
    }

    /** İsteğin oturumunu çözer. Başarılıysa self::$user / $session dolar. */
    public static function authenticate(Req $req): ?array
    {
        $t = $_COOKIE[self::cookieName()] ?? null;
        if (!is_string($t) || strlen($t) < 40 || strlen($t) > 64) {
            return null;
        }
        $h = Util::sha($t);
        $now = Util::now();
        $s = DB::one(
            'SELECT s.*, (s.token_hash = ?) AS is_current FROM sessions s WHERE s.token_hash = ? OR (s.prev_token_hash = ? AND s.prev_valid_until > ?) LIMIT 1',
            [$h, $h, $h, $now]
        );
        if (!$s || $s['revoked_at'] !== null) {
            return null;
        }
        $idleMax = (int)Cfg::get('session_idle', 3600);
        if ($s['expires_at'] <= $now || strtotime($s['last_seen_at'] . ' UTC') + $idleMax < time()) {
            DB::exec('UPDATE sessions SET revoked_at=? WHERE id=?', [$now, $s['id']]);
            return null;
        }
        if (!hash_equals($s['ua_hash'], Util::sha($req->ua))) {
            DB::exec('UPDATE sessions SET revoked_at=? WHERE id=?', [$now, $s['id']]);
            Audit::log('security', 'session_ua_mismatch', ['actor' => (int)$s['user_id'], 'ip' => $req->ip]);
            return null;
        }
        $u = DB::one('SELECT u.*, t.status AS tenant_status, t.name AS tenant_name FROM users u LEFT JOIN tenants t ON t.id = u.tenant_id WHERE u.id=?', [$s['user_id']]);
        if (!$u || $u['status'] !== 'active' || (int)$u['session_epoch'] !== (int)$s['epoch'] || ($u['role'] === 'customer' && $u['tenant_status'] !== 'active')) {
            DB::exec('UPDATE sessions SET revoked_at=? WHERE id=?', [$now, $s['id']]);
            return null;
        }
        // Oturum kimliği periyodik döner (sabit kalmaz); eski kimlik kısa süre geçerli kalır
        $rot = (int)Cfg::get('session_rotate', 600);
        if ((int)$s['is_current'] === 1 && strtotime($s['rotated_at'] . ' UTC') + $rot < time()) {
            $new = Util::token();
            $ok = DB::exec(
                'UPDATE sessions SET prev_token_hash=token_hash, prev_valid_until=?, token_hash=?, rotated_at=?, last_seen_at=?, ip=? WHERE id=? AND token_hash=?',
                [Util::plus(60), Util::sha($new), $now, $now, $req->ip, $s['id'], $h]
            );
            if ($ok === 1) {
                self::setCookie($new);
                self::$rotated = true;
            }
        } elseif (strtotime($s['last_seen_at'] . ' UTC') + 30 < time()) {
            DB::exec('UPDATE sessions SET last_seen_at=?, ip=? WHERE id=?', [$now, $req->ip, $s['id']]);
        }
        self::$user = $u;
        self::$session = $s;
        return $u;
    }

    /** Yönetici IP izin listesi (isteğe bağlı): config 'admin_allow_ips' => ['203.0.113.7', '198.51.100.0/24', '2001:db8::/32']. Boşsa kısıt yok. */
    public static function ipAllowed(string $ip, ?array $list = null): bool
    {
        $list ??= (array)Cfg::get('admin_allow_ips', []);
        if (!$list) {
            return true;
        }
        $bin = @inet_pton($ip);
        if ($bin === false) {
            return false;
        }
        foreach ($list as $e) {
            $e = trim((string)$e);
            if (!str_contains($e, '/')) {
                if ($e === $ip) {
                    return true;
                }
                continue;
            }
            [$net, $bits] = explode('/', $e, 2);
            $nb = @inet_pton($net);
            $bits = (int)$bits;
            if ($nb === false || strlen($nb) !== strlen($bin) || $bits < 0 || $bits > strlen($nb) * 8) {
                continue;
            }
            $full = intdiv($bits, 8);
            if (substr($bin, 0, $full) !== substr($nb, 0, $full)) {
                continue;
            }
            $rem = $bits % 8;
            if ($rem === 0 || ((ord($bin[$full]) ^ ord($nb[$full])) >> (8 - $rem)) === 0) {
                return true;
            }
        }
        return false;
    }

    public static function checkCsrf(Req $req): void
    {
        $tok = (string)($req->header('X-CSRF-Token') ?? '');
        if (!self::$session || $tok === '' || !hash_equals(self::csrfFor(self::$session), $tok)) {
            throw new ApiError('csrf', 'Güvenlik doğrulaması başarısız. Sayfayı yenileyip tekrar deneyin.', 403);
        }
    }

    /** Origin / Fetch-Metadata doğrulaması (oturumsuz POST'lar için de geçerli). */
    public static function checkOrigin(Req $req): void
    {
        $origin = $req->header('Origin');
        if ($origin !== null && $origin !== '') {
            $h = parse_url($origin, PHP_URL_HOST);
            $p = parse_url($origin, PHP_URL_PORT);
            $host = strtolower((string)$h) . ($p ? ':' . $p : '');
            if ($host !== $req->host()) {
                throw new ApiError('bad_origin', 'İstek kaynağı geçersiz.', 403);
            }
            return;
        }
        $sfs = $req->header('Sec-Fetch-Site');
        if ($sfs !== null && !in_array($sfs, ['same-origin', 'none'], true)) {
            throw new ApiError('bad_origin', 'İstek kaynağı geçersiz.', 403);
        }
        if ($sfs === null && Cfg::isProd()) {
            throw new ApiError('bad_origin', 'İstek kaynağı doğrulanamadı.', 403);
        }
    }

    public static function requireUser(): array
    {
        if (!self::$user) {
            throw new ApiError('unauthenticated', 'Oturum açmanız gerekiyor.', 401);
        }
        return self::$user;
    }

    /** Parola değişimi veya MFA kaydı zorunluysa yalnızca izinli uç noktalara izin verir. */
    public static function gate(string $path): void
    {
        $u = self::requireUser();
        $ok = ['auth/session', 'auth/logout', 'auth/password', 'auth/2fa/setup', 'auth/2fa/enable'];
        if ((int)$u['must_change_password'] === 1 && !in_array(ltrim($path, '/'), $ok, true)) {
            throw new ApiError('password_change_required', 'Devam etmek için parolanızı değiştirmelisiniz.', 403);
        }
        if ($u['role'] === 'admin' && Cfg::get('require_admin_2fa', true) && (int)$u['totp_enabled'] !== 1 && !in_array(ltrim($path, '/'), $ok, true)) {
            throw new ApiError('mfa_enrollment_required', 'Yönetici hesabı için iki aşamalı doğrulama kurulumu zorunludur.', 403);
        }
    }

    public static function tenantId(Req $req, bool $write): int
    {
        $u = self::requireUser();
        if ($u['role'] === 'customer') {
            return (int)$u['tenant_id'];
        }
        // Yönetici: yalnızca okuma ve denetim kaydıyla, açıkça seçilmiş müşteri
        $tid = V::id($req->query['tenant'] ?? $req->header('X-Tenant-Id'), 'tenant');
        if ($write) {
            throw new ApiError('forbidden', 'Yönetici müşteri verisini yalnızca düzeltme uç noktalarıyla değiştirebilir.', 403);
        }
        $t = DB::one('SELECT id FROM tenants WHERE id=?', [$tid]);
        if (!$t) {
            throw new ApiError('not_found', 'Müşteri bulunamadı.', 404);
        }
        self::logAdminAccess($tid, $req);
        return $tid;
    }

    private static function logAdminAccess(int $tid, Req $req): void
    {
        $mod = explode('/', trim($req->path, '/'))[0] ?: 'x';
        $key = 'adm_acc:' . self::$user['id'] . ':' . $tid . ':' . $mod;
        $last = DB::val('SELECT window_start FROM rate_limits WHERE k=?', [$key]);
        if ($last === null || strtotime($last . ' UTC') < time() - 300) {
            DB::q('INSERT INTO rate_limits (k,hits,window_start) VALUES (?,1,?) ON DUPLICATE KEY UPDATE hits=1, window_start=?', [$key, Util::now(), Util::now()]);
            Audit::log('access', 'admin_view_tenant_data', ['tenant' => $tid, 'entity' => $mod, 'ip' => $req->ip]);
        }
    }
}
