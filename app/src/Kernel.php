<?php
declare(strict_types=1);

namespace FB;

final class Kernel
{
    public static function boot(): void
    {
        mb_internal_encoding('UTF-8');
        date_default_timezone_set('UTC');
        ini_set('display_errors', '0');
        ini_set('log_errors', '1');
        ini_set('zend.exception_ignore_args', '1'); // stack izlerinde parola/jeton argümanları loglanmasın
        ini_set('html_errors', '0');
        error_reporting(E_ALL);
        spl_autoload_register(function (string $c) {
            if (str_starts_with($c, 'FB\\')) {
                $f = FB_APP_DIR . '/src/' . substr($c, 3) . '.php';
                if (is_file($f)) {
                    require $f;
                }
            }
        });
        foreach (['Core', 'Security', 'AuthCtl'] as $f) {
            require_once FB_APP_DIR . "/src/$f.php";
        }
        Cfg::load();
        @ini_set('error_log', FB_APP_DIR . '/storage/logs/php-error.log');
    }

    private static function fail(\Throwable $e, string $rid): never
    {
        $line = '[' . Util::now() . "] $rid " . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine();
        @file_put_contents(FB_APP_DIR . '/storage/logs/app-error.log', $line . "\n", FILE_APPEND | LOCK_EX);
        Res::json(['ok' => false, 'error' => ['code' => 'server_error', 'message' => 'Beklenmeyen bir hata oluştu.', 'request_id' => $rid]], 500);
    }

    public static function run(): void
    {
        $rid = substr(bin2hex(random_bytes(6)), 0, 12);
        header('X-Request-Id: ' . $rid);
        try {
            self::boot();
            $req = new Req();
            $GLOBALS['FB_IP'] = $req->ip;
            $GLOBALS['FB_HTTPS'] = $req->isHttps();
            if (Cfg::isProd() && !$req->isHttps()) {
                throw new ApiError('https_required', 'Bu hizmet yalnızca HTTPS ile kullanılabilir.', 403);
            }
            $router = self::routes();
            $m = $router->match($req->method, $req->path);
            if (!$m) {
                throw new ApiError('not_found', 'Uç nokta bulunamadı.', 404);
            }
            [$handler, $params, $opt] = $m;
            RateLimit::gc();
            RateLimit::enforce('api_ip:' . $req->ip, (int)Cfg::get('rate_api_ip', 600), 60);
            $req->parseBody();
            $tid = null;
            $auth = $opt['auth'];
            if ($req->isMutation()) {
                Auth::checkOrigin($req);
            }
            if ($auth !== 'none') {
                Auth::authenticate($req);
                if ($auth === 'optional' && !Auth::$user) {
                    // oturumsuz izin verilir
                } else {
                    Auth::requireUser();
                    Auth::gate($req->path);
                    if ($req->isMutation()) {
                        Auth::checkCsrf($req);
                    }
                    RateLimit::enforce('api_user:' . Auth::$user['id'], (int)Cfg::get('rate_api_user', 900), 60);
                    if (Auth::$user['role'] === 'admin' && !Auth::ipAllowed($req->ip)) {
                        Audit::log('security', 'admin_ip_denied', ['ip' => $req->ip]);
                        throw new ApiError('forbidden', 'Bu adresten yönetici erişimi kapalı.', 403);
                    }
                    if ($auth === 'admin' && Auth::$user['role'] !== 'admin') {
                        Audit::log('security', 'admin_endpoint_denied', ['entity' => 'route', 'id' => $req->path, 'tenant' => Auth::$user['tenant_id'], 'ip' => $req->ip]);
                        throw new ApiError('forbidden', 'Bu işlem için yetkiniz yok.', 403);
                    }
                    if (Auth::$user['role'] === 'customer' && !str_starts_with($req->path, '/auth/') && DB::val("SELECT v FROM system_settings WHERE k='maintenance'") === '1') {
                        throw new ApiError('maintenance', 'Sistem kısa süreli bakımda. Lütfen daha sonra tekrar deneyin.', 503);
                    }
                    if ($auth === 'tenant') {
                        $tid = Auth::tenantId($req, $req->isMutation());
                        if (Auth::$user['role'] === 'customer') {
                            DB::exec('UPDATE tenants SET last_activity_at=? WHERE id=? AND (last_activity_at IS NULL OR last_activity_at < ?)', [Util::now(), $tid, Util::plus(-60)]);
                        }
                    }
                }
            }
            if (!empty($opt['idem']) && $tid !== null && ($key = $req->header('Idempotency-Key'))) {
                self::idempotency($tid, $key, $req);
            }
            $handler($req, $params, $tid);
            Res::ok();
        } catch (ApiError $e) {
            Res::err($e);
        } catch (\Throwable $e) {
            self::fail($e, $rid);
        }
    }

    private static function idempotency(int $tid, string $key, Req $req): void
    {
        if (!preg_match('/^[A-Za-z0-9_-]{8,64}$/', $key)) {
            throw new ApiError('bad_idempotency_key', 'Geçersiz Idempotency-Key.', 400);
        }
        $route = substr($req->method . ' ' . $req->path, 0, 160);
        $ik = Util::sha($key);
        $ins = DB::exec('INSERT IGNORE INTO idempotency_keys (tenant_id,ikey,route,created_at) VALUES (?,?,?,?)', [$tid, $ik, $route, Util::now()]);
        if ($ins === 1) {
            Res::$idemHook = function (int $status, string $body) use ($tid, $ik) {
                DB::exec('UPDATE idempotency_keys SET status_code=?, response=? WHERE tenant_id=? AND ikey=?', [$status, $body, $tid, $ik]);
            };
            Res::$idemFailHook = function () use ($tid, $ik) {
                DB::exec('DELETE FROM idempotency_keys WHERE tenant_id=? AND ikey=? AND status_code IS NULL', [$tid, $ik]);
            };
            return;
        }
        $row = DB::one('SELECT * FROM idempotency_keys WHERE tenant_id=? AND ikey=?', [$tid, $ik]);
        if ($row && $row['route'] !== $route) {
            throw new ApiError('idempotency_conflict', 'Bu anahtar başka bir istek için kullanıldı.', 422);
        }
        if ($row && $row['response'] !== null && (int)$row['status_code'] < 500) {
            Res::replay((int)$row['status_code'], (string)$row['response']);
        }
        throw new ApiError('request_in_progress', 'Aynı istek işleniyor. Birkaç saniye sonra tekrar deneyin.', 409);
    }

    public static function routes(): Router
    {
        $r = new Router();
        $n = ['auth' => 'none'];
        $u = ['auth' => 'user'];
        // --- kimlik ---
        $r->add('GET', '/auth/captcha', fn($q) => AuthCtl::captcha($q), $n);
        $r->add('POST', '/auth/login', fn($q) => AuthCtl::login($q), $n);
        $r->add('POST', '/auth/2fa', fn($q) => AuthCtl::login2fa($q), $n);
        $r->add('POST', '/auth/forgot', fn($q) => AuthCtl::forgot($q), $n);
        $r->add('POST', '/auth/reset', fn($q) => AuthCtl::reset($q), $n);
        $r->add('GET', '/auth/session', fn($q) => AuthCtl::session($q), ['auth' => 'optional']);
        $r->add('POST', '/auth/logout', fn($q) => AuthCtl::logout($q), $u);
        $r->add('POST', '/auth/password', fn($q) => AuthCtl::changePassword($q), $u);
        $r->add('PATCH', '/auth/profile', fn($q) => AuthCtl::profile($q), $u);
        $r->add('GET', '/auth/sessions', fn($q) => AuthCtl::sessions($q), $u);
        $r->add('DELETE', '/auth/sessions/{id}', fn($q, $p) => AuthCtl::revokeSession($q, $p), $u);
        $r->add('POST', '/auth/sessions/revoke-others', fn($q) => AuthCtl::revokeOthers($q), $u);
        $r->add('POST', '/auth/2fa/setup', fn($q) => AuthCtl::setup2fa($q), $u);
        $r->add('POST', '/auth/2fa/enable', fn($q) => AuthCtl::enable2fa($q), $u);
        $r->add('POST', '/auth/2fa/disable', fn($q) => AuthCtl::disable2fa($q), $u);
        // --- diğer modüller ---
        foreach (['AdminCtl', 'SetupCtl', 'LedgerCtl', 'PayCtl', 'InvoiceCtl', 'CardCtl', 'StockCtl', 'ReportCtl', 'FileCtl', 'ImportCtl', 'SettingsCtl'] as $c) {
            $cls = 'FB\\' . $c;
            $cls::routes($r);
        }
        return $r;
    }
}
