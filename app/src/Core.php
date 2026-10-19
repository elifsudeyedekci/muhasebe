<?php
declare(strict_types=1);

namespace FB;

use PDO;

final class Cfg
{
    private static array $c = [];

    public static function load(): void
    {
        $path = getenv('FB_CONFIG') ?: (FB_APP_DIR . '/config.php');
        if (!is_file($path)) {
            throw new \RuntimeException('config_missing');
        }
        self::$c = require $path;
    }

    public static function get(string $k, mixed $d = null): mixed
    {
        return self::$c[$k] ?? $d;
    }

    public static function isProd(): bool
    {
        return (self::$c['env'] ?? 'production') === 'production';
    }
}

/** Beklenen, kullanıcıya gösterilebilen hata. */
final class ApiError extends \Exception
{
    public function __construct(
        public string $errCode,
        string $message,
        public int $status = 400,
        public ?string $field = null,
        public array $extra = []
    ) {
        parent::__construct($message);
    }
}

final class DB
{
    private static ?PDO $pdo = null;
    private static int $depth = 0;

    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            $d = Cfg::get('db');
            $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $d['host'], (int)($d['port'] ?? 3306), $d['name']);
            self::$pdo = new PDO($dsn, $d['user'], $d['pass'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_STRINGIFY_FETCHES => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4, time_zone='+00:00', sql_mode='STRICT_ALL_TABLES,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION'",
            ]);
        }
        return self::$pdo;
    }

    public static function reset(): void
    {
        self::$pdo = null;
        self::$depth = 0;
    }

    public static function q(string $sql, array $p = []): \PDOStatement
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($p);
        return $st;
    }

    public static function all(string $sql, array $p = []): array
    {
        return self::q($sql, $p)->fetchAll();
    }

    public static function one(string $sql, array $p = []): ?array
    {
        $r = self::q($sql, $p)->fetch();
        return $r === false ? null : $r;
    }

    public static function val(string $sql, array $p = []): mixed
    {
        $r = self::q($sql, $p)->fetch(PDO::FETCH_NUM);
        return $r === false ? null : $r[0];
    }

    public static function exec(string $sql, array $p = []): int
    {
        return self::q($sql, $p)->rowCount();
    }

    public static function insert(string $table, array $row): int
    {
        $cols = array_keys($row);
        $sql = 'INSERT INTO `' . $table . '` (`' . implode('`,`', $cols) . '`) VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')';
        self::q($sql, array_values($row));
        return (int)self::pdo()->lastInsertId();
    }

    public static function update(string $table, array $row, string $where, array $wp): int
    {
        $set = implode(',', array_map(fn($c) => "`$c`=?", array_keys($row)));
        return self::exec("UPDATE `$table` SET $set WHERE $where", [...array_values($row), ...$wp]);
    }

    /** İç içe çağrılabilir işlem. En dıştaki commit/rollback yapar. */
    public static function tx(callable $fn): mixed
    {
        $pdo = self::pdo();
        if (self::$depth > 0) {
            return $fn();
        }
        $pdo->beginTransaction();
        self::$depth = 1;
        try {
            $r = $fn();
            $pdo->commit();
            return $r;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        } finally {
            self::$depth = 0;
        }
    }
}

final class Util
{
    public static function now(): string
    {
        return gmdate('Y-m-d H:i:s');
    }

    public static function plus(int $sec): string
    {
        return gmdate('Y-m-d H:i:s', time() + $sec);
    }

    public static function today(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Istanbul')))->format('Y-m-d');
    }

    public static function rid(): string
    {
        return bin2hex(random_bytes(13));
    }

    public static function token(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    public static function sha(string $s): string
    {
        return hash('sha256', $s);
    }

    public static function hmac(string $s, string $ctx = 'h'): string
    {
        return hash_hmac('sha256', $ctx . '|' . $s, (string)Cfg::get('pepper'));
    }

    public static function json(mixed $v): string
    {
        return json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    public static function addDays(string $date, int $n): string
    {
        return (new \DateTimeImmutable($date))->modify(($n >= 0 ? '+' : '') . $n . ' day')->format('Y-m-d');
    }

    /** Ay sonunu aşmadan n ay ekler (31 Ocak + 1 ay = 28/29 Şubat). */
    public static function addMonths(string $date, int $n, ?int $day = null): string
    {
        $d = new \DateTimeImmutable($date);
        $day ??= (int)$d->format('j');
        $first = $d->modify('first day of this month')->modify(($n >= 0 ? '+' : '') . $n . ' month');
        $dim = (int)$first->format('t');
        return $first->setDate((int)$first->format('Y'), (int)$first->format('n'), min($day, $dim))->format('Y-m-d');
    }
}

/** Para: tüm tutarlar küçük birim (kuruş) cinsinden tam sayıdır. */
final class Money
{
    public const MAX = 100000000000000; // 10^14 küçük birim

    public static function currencies(): array
    {
        return ['TRY', 'EUR', 'USD'];
    }

    /** Miktar (binde bir birim) → okunur metin. */
    public static function fmtQty(int $milli): string
    {
        return rtrim(rtrim(number_format($milli / 1000, 3, ',', '.'), '0'), ',');
    }

    /** Taşmaya dayanıklı a*b/div (bcmath varsa tam hassasiyet; yoksa float yedeği — yalnızca bilgi amaçlı dönüşümlerde kullanılır). */
    public static function mulDivBig(int $a, int $b, int $div): int
    {
        if (function_exists('bcmul')) {
            $p = bcmul((string)$a, (string)$b, 0);
            $neg = str_starts_with($p, '-');
            $p = ltrim($p, '-');
            $q = bcdiv(bcadd($p, bcdiv((string)$div, '2', 0), 0), (string)$div, 0);
            return $neg ? -(int)$q : (int)$q;
        }
        return (int)round($a * $b / $div);
    }

    /** a*b/div, yarım yukarı yuvarlama, taşma korumalı (intdiv + kalan). */
    public static function mulDiv(int $a, int $b, int $div): int
    {
        $neg = ($a < 0) !== ($b < 0);
        $a = abs($a);
        $b = abs($b);
        if ($a !== 0 && $b > intdiv(PHP_INT_MAX, $a)) {
            throw new ApiError('amount_too_large', 'Tutar çok büyük.', 422);
        }
        $p = $a * $b;
        $r = intdiv($p + intdiv($div, 2), $div);
        return $neg ? -$r : $r;
    }
}

/** Girdi doğrulama yardımcıları. Hatalar ApiError(422) olarak fırlatılır. */
final class V
{
    public static function str(array $b, string $k, int $max = 255, bool $req = true): ?string
    {
        $v = $b[$k] ?? null;
        if ($v === null || $v === '') {
            if ($req) {
                throw new ApiError('validation', 'Bu alan zorunludur.', 422, $k);
            }
            return null;
        }
        if (!is_string($v) && !is_int($v)) {
            throw new ApiError('validation', 'Geçersiz değer.', 422, $k);
        }
        $v = trim((string)$v);
        $v = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $v) ?? '';
        if ($v === '' && $req) {
            throw new ApiError('validation', 'Bu alan zorunludur.', 422, $k);
        }
        if (mb_strlen($v) > $max) {
            throw new ApiError('validation', "En fazla $max karakter olabilir.", 422, $k);
        }
        return $v === '' ? null : $v;
    }

    public static function int(array $b, string $k, int $min, int $max, bool $req = true, ?int $def = null): ?int
    {
        $v = $b[$k] ?? null;
        if ($v === null || $v === '') {
            if ($req && $def === null) {
                throw new ApiError('validation', 'Bu alan zorunludur.', 422, $k);
            }
            return $def;
        }
        if (is_string($v) && preg_match('/^-?\d{1,18}$/', $v)) {
            $v = (int)$v;
        }
        if (!is_int($v) || $v < $min || $v > $max) {
            throw new ApiError('validation', "Değer $min ile $max arasında olmalı.", 422, $k);
        }
        return $v;
    }

    /** Pozitif küçük birim tutarı. */
    public static function amount(array $b, string $k, bool $req = true, bool $allowZero = false): ?int
    {
        $v = self::int($b, $k, $allowZero ? 0 : 1, Money::MAX, $req);
        return $v;
    }

    public static function signedAmount(array $b, string $k): int
    {
        return (int)self::int($b, $k, -Money::MAX, Money::MAX, true);
    }

    public static function date(array $b, string $k, bool $req = true): ?string
    {
        $v = $b[$k] ?? null;
        if ($v === null || $v === '') {
            if ($req) {
                throw new ApiError('validation', 'Tarih zorunludur.', 422, $k);
            }
            return null;
        }
        if (!is_string($v) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m) || !checkdate((int)$m[2], (int)$m[3], (int)$m[1]) || (int)$m[1] < 2000 || (int)$m[1] > 2100) {
            throw new ApiError('validation', 'Geçersiz tarih (YYYY-AA-GG).', 422, $k);
        }
        return $v;
    }

    public static function enum(array $b, string $k, array $allowed, bool $req = true, ?string $def = null): ?string
    {
        $v = $b[$k] ?? null;
        if ($v === null || $v === '') {
            if ($req && $def === null) {
                throw new ApiError('validation', 'Bu alan zorunludur.', 422, $k);
            }
            return $def;
        }
        if (!is_string($v) || !in_array($v, $allowed, true)) {
            throw new ApiError('validation', 'Geçersiz seçim.', 422, $k);
        }
        return $v;
    }

    public static function cur(array $b, string $k = 'currency'): string
    {
        return (string)self::enum($b, $k, Money::currencies(), false, 'TRY');
    }

    public static function id(mixed $v, string $k = 'id', bool $req = true): ?int
    {
        if ($v === null || $v === '') {
            if ($req) {
                throw new ApiError('validation', 'Kayıt seçimi zorunludur.', 422, $k);
            }
            return null;
        }
        if (is_string($v) && ctype_digit($v) && strlen($v) < 19) {
            $v = (int)$v;
        }
        if (!is_int($v) || $v < 1) {
            throw new ApiError('validation', 'Geçersiz kayıt.', 422, $k);
        }
        return $v;
    }

    public static function email(array $b, string $k = 'email'): string
    {
        $v = strtolower((string)self::str($b, $k, 190));
        if (!filter_var($v, FILTER_VALIDATE_EMAIL)) {
            throw new ApiError('validation', 'Geçerli bir e-posta adresi girin.', 422, $k);
        }
        return $v;
    }

    public static function bool(array $b, string $k, bool $def = false): int
    {
        if (!array_key_exists($k, $b)) {
            return $def ? 1 : 0;
        }
        return filter_var($b[$k], FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
    }
}

final class Req
{
    public string $method;
    public string $path;
    public array $query;
    public array $body = [];
    public string $ip;
    public string $ua;
    public ?string $rawBody = null;

    public function __construct()
    {
        $this->method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        $uri = rawurldecode($uri);
        $p = strpos($uri, '/api/');
        $this->path = '/' . ltrim($p !== false ? substr($uri, $p + 5) : $uri, '/');
        $this->path = rtrim($this->path, '/') ?: '/';
        $this->query = $_GET;
        $this->ip = self::detectIp();
        $this->ua = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 400);
    }

    private static function detectIp(): string
    {
        $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
        if (Cfg::get('trust_cloudflare') && !empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
            $c = (string)$_SERVER['HTTP_CF_CONNECTING_IP'];
            if (filter_var($c, FILTER_VALIDATE_IP)) {
                $ip = $c;
            }
        }
        return $ip;
    }

    public function header(string $name): ?string
    {
        $k = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        return isset($_SERVER[$k]) ? (string)$_SERVER[$k] : null;
    }

    public function isMutation(): bool
    {
        return in_array($this->method, ['POST', 'PUT', 'PATCH', 'DELETE'], true);
    }

    public function parseBody(): void
    {
        if (!$this->isMutation()) {
            return;
        }
        $ct = strtolower((string)($_SERVER['CONTENT_TYPE'] ?? ''));
        if (str_starts_with($ct, 'multipart/form-data')) {
            $this->body = $_POST;
            return;
        }
        $raw = file_get_contents('php://input', false, null, 0, 2 * 1024 * 1024 + 1);
        if ($raw === false || $raw === '') {
            return;
        }
        if (strlen($raw) > 2 * 1024 * 1024) {
            throw new ApiError('too_large', 'İstek çok büyük.', 413);
        }
        if (!str_contains($ct, 'application/json')) {
            throw new ApiError('bad_content_type', 'İçerik türü application/json olmalı.', 415);
        }
        try {
            $d = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            throw new ApiError('bad_json', 'Geçersiz JSON.', 400);
        }
        if (!is_array($d)) {
            throw new ApiError('bad_json', 'Geçersiz JSON.', 400);
        }
        $this->body = $d;
    }

    public function host(): string
    {
        return strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
    }

    public function isHttps(): bool
    {
        return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (Cfg::get('trust_proxy_proto') && ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    }
}

final class Res
{
    public static array $cookies = [];

    public static function securityHeaders(): void
    {
        header_remove('X-Powered-By');
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: no-referrer');
        header('Cross-Origin-Opener-Policy: same-origin');
        header('Cross-Origin-Resource-Policy: same-origin');
        header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=(), usb=()');
        header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'; base-uri 'none'");
        header('Cache-Control: no-store, no-cache, must-revalidate, private');
        header('Pragma: no-cache');
        if (Cfg::isProd()) {
            header('Strict-Transport-Security: max-age=63072000; includeSubDomains; preload');
        }
    }

    public static $idemHook = null;
    public static $idemFailHook = null;

    public static function json(mixed $data, int $status = 200): never
    {
        self::securityHeaders();
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        $body = Util::json($data);
        if ($status < 400 && self::$idemHook) {
            (self::$idemHook)($status, $body);
        } elseif ($status >= 400 && self::$idemFailHook) {
            (self::$idemFailHook)();
        }
        echo $body;
        exit;
    }

    public static function replay(int $status, string $body): never
    {
        self::securityHeaders();
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Idempotent-Replayed: true');
        echo $body;
        exit;
    }

    public static function ok(array $data = [], int $status = 200): never
    {
        self::json(['ok' => true] + $data, $status);
    }

    public static function err(ApiError $e): never
    {
        $out = ['ok' => false, 'error' => ['code' => $e->errCode, 'message' => $e->getMessage()]];
        if ($e->field !== null) {
            $out['error']['field'] = $e->field;
        }
        if ($e->extra) {
            $out['error'] += $e->extra;
        }
        self::json($out, $e->status);
    }
}

final class Crypto
{
    private static function key(string $ctx): string
    {
        return hash_hmac('sha256', $ctx, base64_decode((string)Cfg::get('app_key')), true);
    }

    public static function encrypt(string $plain, string $ctx): string
    {
        $iv = random_bytes(12);
        $tag = '';
        $ct = openssl_encrypt($plain, 'aes-256-gcm', self::key($ctx), OPENSSL_RAW_DATA, $iv, $tag);
        return base64_encode($iv . $tag . $ct);
    }

    public static function decrypt(string $b64, string $ctx): ?string
    {
        $raw = base64_decode($b64, true);
        if ($raw === false || strlen($raw) < 29) {
            return null;
        }
        $r = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', self::key($ctx), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
        return $r === false ? null : $r;
    }
}

final class Router
{
    private array $routes = [];

    public function add(string $method, string $pattern, callable $h, array $opt = []): void
    {
        $re = '#^' . preg_replace('#\{(\w+)\}#', '(?P<$1>[^/]+)', $pattern) . '$#';
        $this->routes[] = [$method, $re, $h, $opt + ['auth' => 'tenant']];
    }

    public function match(string $method, string $path): ?array
    {
        $allowed = false;
        foreach ($this->routes as [$m, $re, $h, $opt]) {
            if (preg_match($re, $path, $mm)) {
                if ($m === $method) {
                    $params = array_filter($mm, 'is_string', ARRAY_FILTER_USE_KEY);
                    return [$h, $params, $opt];
                }
                $allowed = true;
            }
        }
        if ($allowed) {
            throw new ApiError('method_not_allowed', 'Yöntem desteklenmiyor.', 405);
        }
        return null;
    }
}
