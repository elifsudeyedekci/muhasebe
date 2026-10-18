<?php
declare(strict_types=1);

namespace FB;

final class AdminCtl
{
    public static function routes(Router $r): void
    {
        $a = ['auth' => 'admin'];
        $r->add('GET', '/admin/overview', fn($q) => self::overview(), $a);
        $r->add('GET', '/admin/tenants', fn($q) => self::list($q), $a);
        $r->add('POST', '/admin/tenants', fn($q) => self::create($q), $a);
        $r->add('GET', '/admin/tenants/{id}', fn($q, $p) => self::detail($q, (int)$p['id']), $a);
        $r->add('PATCH', '/admin/tenants/{id}', fn($q, $p) => self::update($q, (int)$p['id']), $a);
        $r->add('POST', '/admin/tenants/{id}/status', fn($q, $p) => self::setStatus($q, (int)$p['id']), $a);
        $r->add('POST', '/admin/tenants/{id}/reset-password', fn($q, $p) => self::resetPassword($q, (int)$p['id']), $a);
        $r->add('POST', '/admin/tenants/{id}/unlock', fn($q, $p) => self::unlock($q, (int)$p['id']), $a);
        $r->add('POST', '/admin/tenants/{id}/purge', fn($q, $p) => self::purge($q, (int)$p['id']), $a);
        $r->add('POST', '/admin/tenants/{id}/transactions/{tid}/void', fn($q, $p) => self::voidTxn($q, (int)$p['id'], (int)$p['tid']), $a);
        $r->add('GET', '/admin/tenants/{id}/export', fn($q, $p) => self::export((int)$p['id']), $a);
        $r->add('GET', '/admin/audit', fn($q) => self::audit($q), $a);
        $r->add('GET', '/admin/audit/verify', fn($q) => Res::ok(['broken_at' => Audit::verifyChain(100000)]), $a);
        $r->add('GET', '/admin/system', fn($q) => self::system(), $a);
    }

    private static function tenantOr404(int $id): array
    {
        $t = DB::one('SELECT * FROM tenants WHERE id=?', [$id]);
        if (!$t) {
            throw new ApiError('not_found', 'Müşteri bulunamadı.', 404);
        }
        return $t;
    }

    private static function overview(): never
    {
        $counts = DB::one("SELECT COUNT(*) total, SUM(status='active') active, SUM(status='suspended') suspended, SUM(status='archived') archived,
                           SUM(created_at > ?) new30 FROM tenants", [Util::plus(-30 * 86400)]);
        $recent = DB::all("SELECT t.id,t.name,u.email,u.last_login_at FROM tenants t JOIN users u ON u.tenant_id=t.id WHERE u.last_login_at IS NOT NULL ORDER BY u.last_login_at DESC LIMIT 8");
        $newest = DB::all("SELECT t.id,t.name,u.email,t.created_at FROM tenants t JOIN users u ON u.tenant_id=t.id ORDER BY t.id DESC LIMIT 8");
        $per = [];
        foreach (DB::all("SELECT id,name,status FROM tenants WHERE status<>'archived' ORDER BY id DESC LIMIT 100") as $t) {
            $tid = (int)$t['id'];
            $per[] = ['id' => $tid, 'name' => $t['name'], 'status' => $t['status'], 'balances' => Fin::balancesByCurrency($tid), 'obligations' => Fin::obligations($tid)];
        }
        $sec = DB::all("SELECT ts,action,ip,tenant_id FROM audit_log WHERE category='security' AND action IN ('login_failed','account_locked','admin_endpoint_denied','session_ua_mismatch','2fa_failed','login_locked') ORDER BY id DESC LIMIT 15");
        $acts = DB::all("SELECT ts,action,tenant_id,entity,entity_id FROM audit_log WHERE category IN ('admin','access') ORDER BY id DESC LIMIT 15");
        $log = FB_APP_DIR . '/storage/logs/app-error.log';
        $errs = is_file($log) ? array_slice(file($log, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [], -8) : [];
        Res::ok(['counts' => $counts, 'recent_logins' => $recent, 'newest' => $newest, 'tenants' => $per, 'security_events' => $sec, 'admin_actions' => $acts, 'errors' => $errs, 'ops' => self::ops()]);
    }

    private static function ops(): array
    {
        $dir = FB_APP_DIR . '/storage/backups';
        $files = is_dir($dir) ? glob($dir . '/*.sql.gz') : [];
        rsort($files);
        $size = DB::val('SELECT ROUND(SUM(data_length+index_length)) FROM information_schema.tables WHERE table_schema=DATABASE()');
        return [
            'db_ok' => true, 'db_size_bytes' => (int)$size,
            'last_backup' => $files ? gmdate('Y-m-d H:i:s', (int)filemtime($files[0])) : null,
            'backup_count' => count($files),
            'php' => PHP_VERSION, 'env' => Cfg::get('env'),
        ];
    }

    private static function system(): never
    {
        $writable = [];
        foreach (['uploads', 'logs', 'backups'] as $d) {
            $writable[$d] = is_writable(FB_APP_DIR . '/storage/' . $d);
        }
        Res::ok(['ops' => self::ops(), 'writable' => $writable, 'require_admin_2fa' => (bool)Cfg::get('require_admin_2fa', true), 'audit_broken_at' => Audit::verifyChain(20000), 'extensions' => ['gd' => extension_loaded('gd'), 'zip' => class_exists('ZipArchive'), 'openssl' => extension_loaded('openssl'), 'argon2id' => defined('PASSWORD_ARGON2ID')]]);
    }

    private static function list(Req $r): never
    {
        $q = trim((string)($r->query['q'] ?? ''));
        $st = (string)($r->query['status'] ?? '');
        $w = ['1=1'];
        $p = [];
        if ($q !== '') {
            $w[] = '(t.name LIKE ? OR t.company_name LIKE ? OR u.email LIKE ?)';
            $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%';
            array_push($p, $like, $like, $like);
        }
        if (in_array($st, ['active', 'suspended', 'archived'], true)) {
            $w[] = 't.status=?';
            $p[] = $st;
        }
        $rows = DB::all('SELECT t.id,t.name,t.company_name,t.status,t.created_at,t.last_activity_at,u.id AS user_id,u.email,u.last_login_at,u.totp_enabled,u.locked_until,
            (SELECT COUNT(*) FROM workspaces x WHERE x.tenant_id=t.id) AS workspaces,(SELECT COUNT(*) FROM accounts x WHERE x.tenant_id=t.id) AS accounts
            FROM tenants t JOIN users u ON u.tenant_id=t.id WHERE ' . implode(' AND ', $w) . ' ORDER BY t.id DESC LIMIT 500', $p);
        Res::ok(['tenants' => $rows]);
    }

    private static function create(Req $r): never
    {
        $b = $r->body;
        $name = (string)V::str($b, 'name', 160);
        $company = V::str($b, 'company_name', 200, false);
        $phone = V::str($b, 'phone', 40, false);
        $email = V::email($b);
        $mode = (string)V::enum($b, 'mode', ['invite', 'temp'], false, 'invite');
        if (DB::val('SELECT id FROM users WHERE email=?', [$email])) {
            throw new ApiError('duplicate', 'Bu e-posta adresi zaten kayıtlı.', 409, 'email');
        }
        $admin = Auth::$user;
        $res = DB::tx(function () use ($name, $company, $phone, $email, $mode, $admin) {
            $tid = DB::insert('tenants', ['name' => $name, 'company_name' => $company, 'phone' => $phone, 'status' => 'active', 'created_by' => $admin['id'], 'created_at' => Util::now(), 'settings' => Util::json(['locale' => 'tr-TR', 'notify' => ['due' => true, 'overdue' => true, 'card' => true, 'bills' => true, 'email' => false]])]);
            $temp = $mode === 'temp' ? Pw::generate() : null;
            $uid = DB::insert('users', [
                'tenant_id' => $tid, 'email' => $email, 'display_name' => $name, 'role' => 'customer', 'admin_flag' => null,
                'password_hash' => Pw::hash($temp ?? bin2hex(random_bytes(32))), 'must_change_password' => 1, 'status' => 'active',
                'created_at' => Util::now(), 'session_epoch' => 1,
            ]);
            Fin::seedCategories($tid);
            $link = $mode === 'invite' ? AuthCtl::issueToken($uid, 'invite', 72 * 3600, (int)$admin['id']) : null;
            Audit::log('admin', 'tenant_created', ['tenant' => $tid, 'entity' => 'tenant', 'id' => $tid, 'details' => ['mode' => $mode, 'email' => $email]]);
            return ['tenant_id' => $tid, 'user_id' => $uid, 'temp_password' => $temp, 'invite_link' => $link];
        });
        if ($res['invite_link']) {
            Mailer::send($email, 'FunBreak Ön Muhasebe — hesabınız hazır', "Merhaba,\n\nFunBreak Ön Muhasebe hesabınız oluşturuldu. Parolanızı belirlemek için bağlantıyı 72 saat içinde kullanın:\n\n{$res['invite_link']}\n");
        }
        Res::ok($res + ['notice' => 'Bu giriş bilgisi yalnızca şimdi gösterilir; güvenli bir kanalla müşteriye iletin.'], 201);
    }

    private static function detail(Req $r, int $id): never
    {
        $t = self::tenantOr404($id);
        $u = DB::one('SELECT id,email,display_name,status,last_login_at,last_login_ip,locked_until,failed_attempts,totp_enabled,must_change_password,email_verified_at,created_at FROM users WHERE tenant_id=?', [$id]);
        $cnt = [];
        foreach (['workspaces', 'accounts', 'transactions', 'entries', 'invoices', 'contacts', 'projects', 'cards', 'loans', 'documents'] as $tb) {
            $cnt[$tb] = (int)DB::val("SELECT COUNT(*) FROM `$tb` WHERE tenant_id=?", [$id]);
        }
        $sess = (int)DB::val('SELECT COUNT(*) FROM sessions s JOIN users u ON u.id=s.user_id WHERE u.tenant_id=? AND s.revoked_at IS NULL AND s.expires_at>?', [$id, Util::now()]);
        $audit = DB::all('SELECT ts,category,action,entity,entity_id,ip,actor_role FROM audit_log WHERE tenant_id=? ORDER BY id DESC LIMIT 30', [$id]);
        unset($t['settings']);
        Res::ok(['tenant' => $t, 'user' => $u, 'counts' => $cnt, 'active_sessions' => $sess, 'balances' => Fin::balancesByCurrency($id), 'obligations' => Fin::obligations($id), 'audit' => $audit]);
    }

    private static function update(Req $r, int $id): never
    {
        $t = self::tenantOr404($id);
        $b = $r->body;
        $upd = [];
        if (array_key_exists('name', $b)) {
            $upd['name'] = V::str($b, 'name', 160);
        }
        if (array_key_exists('company_name', $b)) {
            $upd['company_name'] = V::str($b, 'company_name', 200, false);
        }
        if (array_key_exists('phone', $b)) {
            $upd['phone'] = V::str($b, 'phone', 40, false);
        }
        DB::tx(function () use ($id, $b, $upd, $t) {
            if ($upd) {
                DB::update('tenants', $upd, 'id=?', [$id]);
            }
            if (!empty($b['email'])) {
                $email = V::email($b);
                $u = DB::one('SELECT * FROM users WHERE tenant_id=?', [$id]);
                if ($u['email'] !== $email) {
                    if (DB::val('SELECT id FROM users WHERE email=? AND id<>?', [$email, $u['id']])) {
                        throw new ApiError('duplicate', 'Bu e-posta adresi zaten kayıtlı.', 409, 'email');
                    }
                    DB::update('users', ['email' => $email, 'session_epoch' => (int)$u['session_epoch'] + 1, 'email_verified_at' => null], 'id=?', [$u['id']]);
                    DB::exec('UPDATE sessions SET revoked_at=? WHERE user_id=? AND revoked_at IS NULL', [Util::now(), $u['id']]);
                    Audit::log('admin', 'tenant_email_changed', ['tenant' => $id, 'entity' => 'user', 'id' => $u['id']]);
                }
            }
            Audit::log('admin', 'tenant_updated', ['tenant' => $id, 'entity' => 'tenant', 'id' => $id, 'details' => ['before' => ['name' => $t['name'], 'company_name' => $t['company_name']], 'after' => $upd]]);
        });
        Res::ok();
    }

    private static function setStatus(Req $r, int $id): never
    {
        $t = self::tenantOr404($id);
        $st = (string)V::enum($r->body, 'status', ['active', 'suspended', 'archived']);
        DB::tx(function () use ($id, $st, $t) {
            DB::update('tenants', ['status' => $st, 'archived_at' => $st === 'archived' ? Util::now() : null], 'id=?', [$id]);
            DB::exec('UPDATE users SET status=? WHERE tenant_id=?', [$st === 'active' ? 'active' : ($st === 'archived' ? 'archived' : 'active'), $id]);
            if ($st !== 'active') {
                DB::exec('UPDATE users SET session_epoch=session_epoch+1 WHERE tenant_id=?', [$id]);
                DB::exec('UPDATE sessions s JOIN users u ON u.id=s.user_id SET s.revoked_at=? WHERE u.tenant_id=? AND s.revoked_at IS NULL', [Util::now(), $id]);
            }
            Audit::log('admin', 'tenant_status_' . $st, ['tenant' => $id, 'entity' => 'tenant', 'id' => $id, 'details' => ['from' => $t['status']]]);
        });
        Res::ok();
    }

    private static function resetPassword(Req $r, int $id): never
    {
        self::tenantOr404($id);
        $u = DB::one('SELECT * FROM users WHERE tenant_id=?', [$id]);
        $link = AuthCtl::issueToken((int)$u['id'], 'reset', 3600, (int)Auth::$user['id']);
        DB::update('users', ['must_change_password' => 1, 'session_epoch' => (int)$u['session_epoch'] + 1], 'id=?', [$u['id']]);
        DB::exec('UPDATE sessions SET revoked_at=? WHERE user_id=? AND revoked_at IS NULL', [Util::now(), $u['id']]);
        Mailer::send($u['email'], 'FunBreak Ön Muhasebe — parola sıfırlama', "Yönetici tarafından parola sıfırlama başlatıldı. Bağlantı 1 saat geçerlidir:\n\n$link\n");
        Audit::log('admin', 'tenant_password_reset_issued', ['tenant' => $id, 'entity' => 'user', 'id' => $u['id']]);
        Res::ok(['reset_link' => $link, 'notice' => 'Bağlantı yalnızca şimdi gösterilir ve 1 saat geçerlidir.']);
    }

    private static function unlock(Req $r, int $id): never
    {
        self::tenantOr404($id);
        DB::exec('UPDATE users SET failed_attempts=0, lock_level=0, locked_until=NULL WHERE tenant_id=?', [$id]);
        Audit::log('admin', 'tenant_unlocked', ['tenant' => $id]);
        Res::ok();
    }

    /** Kalıcı silme: yalnızca arşivli ve hiç finansal kaydı olmayan müşteri. */
    private static function purge(Req $r, int $id): never
    {
        $t = self::tenantOr404($id);
        if ($t['status'] !== 'archived') {
            throw new ApiError('invalid_state', 'Önce müşteriyi arşivleyin.', 409);
        }
        if (!Pw::verify((string)($r->body['admin_password'] ?? ''), Auth::$user['password_hash'])) {
            throw new ApiError('invalid_credentials', 'Yönetici parolası hatalı.', 403);
        }
        foreach (['transactions', 'entries', 'invoices', 'card_purchases', 'loans', 'stock_movements', 'documents', 'statement_lines'] as $tb) {
            if ((int)DB::val("SELECT COUNT(*) FROM `$tb` WHERE tenant_id=?", [$id]) > 0) {
                throw new ApiError('has_financial_records', 'Finansal kaydı olan müşteri kalıcı silinemez; arşivde tutulur.', 409);
            }
        }
        DB::tx(function () use ($id) {
            foreach (['fx_rates', 'budgets', 'project_milestones', 'project_quotes', 'recurring_templates', 'stock_items', 'warehouses', 'cards', 'projects', 'contacts', 'categories', 'accounts', 'workspaces'] as $tb) {
                if ($tb === 'cards') {
                    DB::exec('DELETE FROM card_installments WHERE tenant_id=?', [$id]);
                }
                DB::exec("DELETE FROM `$tb` WHERE tenant_id=?", [$id]);
            }
            DB::exec('DELETE FROM users WHERE tenant_id=?', [$id]);
            DB::exec('DELETE FROM tenants WHERE id=?', [$id]);
            Audit::log('admin', 'tenant_purged', ['tenant' => $id, 'entity' => 'tenant', 'id' => $id]);
        });
        Res::ok();
    }

    private static function voidTxn(Req $r, int $id, int $txnId): never
    {
        self::tenantOr404($id);
        $reason = (string)V::str($r->body, 'reason', 300);
        $ids = Fin::voidTxn($id, $txnId, '[Yönetici düzeltmesi] ' . $reason);
        Audit::log('admin', 'admin_correction_void', ['tenant' => $id, 'entity' => 'transaction', 'id' => $txnId, 'details' => ['reason' => $reason, 'legs' => $ids]]);
        Res::ok(['voided' => $ids]);
    }

    private static function export(int $id): never
    {
        self::tenantOr404($id);
        Audit::log('admin', 'tenant_export', ['tenant' => $id]);
        Res::ok(['data' => Fin::exportTenant($id)]);
    }

    private static function audit(Req $r): never
    {
        $w = ['1=1'];
        $p = [];
        if (!empty($r->query['tenant'])) {
            $w[] = 'tenant_id=?';
            $p[] = (int)$r->query['tenant'];
        }
        if (in_array($r->query['category'] ?? '', ['security', 'admin', 'data', 'access', 'system'], true)) {
            $w[] = 'category=?';
            $p[] = $r->query['category'];
        }
        if (!empty($r->query['q'])) {
            $w[] = 'action LIKE ?';
            $p[] = '%' . str_replace(['%', '_'], ['\\%', '\\_'], (string)$r->query['q']) . '%';
        }
        $page = max(1, (int)($r->query['page'] ?? 1));
        $rows = DB::all('SELECT id,ts,actor_user_id,actor_role,tenant_id,category,action,entity,entity_id,details,ip FROM audit_log WHERE ' . implode(' AND ', $w) . ' ORDER BY id DESC LIMIT 100 OFFSET ' . (($page - 1) * 100), $p);
        Res::ok(['rows' => $rows, 'page' => $page]);
    }
}
