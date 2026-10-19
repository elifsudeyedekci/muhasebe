<?php
declare(strict_types=1);

namespace FB;

/** Müşteri ayarları, kur tablosu, veri dışa aktarma ve (yalnızca yönetici) sistem ayarları. */
final class SettingsCtl
{
    private const DEFAULTS = [
        'locale' => 'tr-TR', 'theme' => 'auto', 'date_format' => 'DD.MM.YYYY', 'default_workspace_id' => null, 'default_account_id' => null,
        'vat_rates' => [0, 1000, 2000], 'preferred_currency' => 'TRY',
        'notify' => ['due' => true, 'overdue' => true, 'card' => true, 'bills' => true, 'email' => false],
        'invoice' => ['prefix' => '', 'default_due_days' => 30, 'note' => ''],
        'stock' => ['allow_negative' => false],
    ];

    public static function routes(Router $r): void
    {
        $t = ['auth' => 'tenant'];
        $r->add('GET', '/settings', fn($q, $p, $tid) => self::get($tid), $t);
        $r->add('PUT', '/settings', fn($q, $p, $tid) => self::put($q, $tid), $t);
        $r->add('GET', '/settings/export', fn($q, $p, $tid) => self::export($tid), $t);
        $r->add('GET', '/fx-rates', fn($q, $p, $tid) => Res::ok(['rates' => DB::all('SELECT id,currency,rate_date,rate,source FROM fx_rates WHERE tenant_id=? ORDER BY rate_date DESC, currency LIMIT 200', [$tid])]), $t);
        $r->add('POST', '/fx-rates', fn($q, $p, $tid) => self::rateSet($q, $tid), $t);
        $r->add('POST', '/fx-rates/fetch', fn($q, $p, $tid) => self::rateFetch($tid), $t);
        $r->add('GET', '/reports/converted', fn($q, $p, $tid) => self::converted($q, $tid), $t);
        $r->add('GET', '/admin/settings', fn($q) => Res::ok(['settings' => self::system()]), ['auth' => 'admin']);
        $r->add('PUT', '/admin/settings', fn($q) => self::systemPut($q), ['auth' => 'admin']);
        $r->add('GET', '/system/status', fn($q) => Res::ok(['announcement' => self::system()['announcement'] ?? '']), ['auth' => 'user']);
    }

    public static function system(): array
    {
        $out = ['announcement' => '', 'maintenance' => '0'];
        foreach (DB::all('SELECT k,v FROM system_settings') as $x) {
            $out[$x['k']] = $x['v'];
        }
        return $out;
    }

    private static function systemPut(Req $r): never
    {
        $b = $r->body;
        foreach (['announcement' => 500, 'maintenance' => 1] as $k => $m) {
            if (array_key_exists($k, $b)) {
                $v = $k === 'maintenance' ? (string)V::bool($b, $k) : (string)V::str($b, $k, $m, false);
                DB::q('INSERT INTO system_settings (k,v,updated_at) VALUES (?,?,?) ON DUPLICATE KEY UPDATE v=VALUES(v), updated_at=VALUES(updated_at)', [$k, $v, Util::now()]);
                Audit::log('admin', 'system_setting_changed', ['entity' => 'setting', 'id' => $k, 'details' => ['value' => $v]]);
            }
        }
        Res::ok();
    }

    private static function load(int $tid): array
    {
        $cur = json_decode((string)DB::val('SELECT settings FROM tenants WHERE id=?', [$tid]), true) ?: [];
        return array_replace_recursive(self::DEFAULTS, $cur);
    }

    private static function get(int $tid): never
    {
        $t = DB::one('SELECT id,name,company_name,phone,created_at FROM tenants WHERE id=?', [$tid]);
        $u = DB::one('SELECT id,email,display_name,last_login_at,prev_login_at,prev_login_ip,totp_enabled FROM users WHERE tenant_id=?', [$tid]);
        Res::ok(['tenant' => $t, 'user' => $u, 'settings' => self::load($tid), 'currencies' => Money::currencies()]);
    }

    private static function put(Req $r, int $tid): never
    {
        $b = $r->body;
        $cur = self::load($tid);
        $new = $cur;
        if (isset($b['theme'])) {
            $new['theme'] = V::enum($b, 'theme', ['auto', 'light', 'dark']);
        }
        if (isset($b['date_format'])) {
            $new['date_format'] = V::enum($b, 'date_format', ['DD.MM.YYYY', 'YYYY-MM-DD', 'DD/MM/YYYY']);
        }
        if (isset($b['preferred_currency'])) {
            $new['preferred_currency'] = V::enum($b, 'preferred_currency', Money::currencies());
        }
        foreach (['default_workspace_id' => 'workspaces', 'default_account_id' => 'accounts'] as $k => $tb) {
            if (array_key_exists($k, $b)) {
                $new[$k] = Fin::ownedId($tb, V::id($b[$k], $k, false), $tid, $k);
            }
        }
        if (isset($b['vat_rates']) && is_array($b['vat_rates'])) {
            $rates = [];
            foreach ($b['vat_rates'] as $x) {
                $rates[] = (int)V::int(['x' => $x], 'x', 0, 10000);
            }
            $new['vat_rates'] = array_values(array_unique(array_slice($rates, 0, 12)));
            sort($new['vat_rates']);
        }
        if (isset($b['notify']) && is_array($b['notify'])) {
            foreach (['due', 'overdue', 'card', 'bills', 'email'] as $k) {
                if (array_key_exists($k, $b['notify'])) {
                    $new['notify'][$k] = (bool)V::bool($b['notify'], $k);
                }
            }
        }
        if (isset($b['invoice']) && is_array($b['invoice'])) {
            $new['invoice']['prefix'] = (string)V::str($b['invoice'], 'prefix', 12, false);
            $new['invoice']['default_due_days'] = (int)V::int($b['invoice'], 'default_due_days', 0, 365, false, 30);
            $new['invoice']['note'] = (string)V::str($b['invoice'], 'note', 500, false);
        }
        if (isset($b['stock']) && is_array($b['stock']) && array_key_exists('allow_negative', $b['stock'])) {
            $new['stock']['allow_negative'] = (bool)V::bool($b['stock'], 'allow_negative');
        }
        DB::exec('UPDATE tenants SET settings=? WHERE id=?', [Util::json($new), $tid]);
        if (isset($b['company_name']) || isset($b['phone']) || isset($b['name'])) {
            $u = [];
            if (isset($b['name'])) {
                $u['name'] = V::str($b, 'name', 160);
            }
            if (array_key_exists('company_name', $b)) {
                $u['company_name'] = V::str($b, 'company_name', 200, false);
            }
            if (array_key_exists('phone', $b)) {
                $u['phone'] = V::str($b, 'phone', 40, false);
            }
            DB::update('tenants', $u, 'id=?', [$tid]);
        }
        Audit::log('data', 'settings_changed', ['tenant' => $tid]);
        Res::ok(['settings' => $new]);
    }

    private static function export(int $tid): never
    {
        Audit::log('data', 'tenant_data_export', ['tenant' => $tid]);
        Res::securityHeaders();
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="funbreak-veri-' . Util::today() . '.json"');
        echo json_encode(Fin::exportTenant($tid), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }

    // ---- kurlar ----
    private static function rateInt(string $s): int
    {
        if (!preg_match('/^(\d{1,10})(?:\.(\d{1,8}))?$/', $s, $m) || (float)$s <= 0) {
            throw new ApiError('validation', 'Kur pozitif bir sayı olmalı (en fazla 8 ondalık).', 422, 'rate');
        }
        return (int)$m[1] * 100000000 + (int)str_pad($m[2] ?? '', 8, '0');
    }

    private static function rateSet(Req $r, int $tid): never
    {
        $b = $r->body;
        $cur = (string)V::enum($b, 'currency', ['EUR', 'USD']);
        $date = (string)V::date($b + ['rate_date' => Util::today()], 'rate_date');
        $rate = trim((string)($b['rate'] ?? ''));
        self::rateInt($rate);
        DB::q('INSERT INTO fx_rates (tenant_id,currency,rate_date,rate,source) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE rate=VALUES(rate), source=VALUES(source)', [$tid, $cur, $date, $rate, 'manual']);
        Res::ok([], 201);
    }

    private static function rateFetch(int $tid): never
    {
        RateLimit::enforce('fxfetch:' . $tid, 10, 3600);
        $xml = null;
        if (function_exists('curl_init')) {
            $ch = curl_init('https://www.tcmb.gov.tr/kurlar/today.xml');
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS]);
            $xml = curl_exec($ch);
            curl_close($ch);
        }
        if (!$xml) {
            throw new ApiError('fetch_failed', 'TCMB kurları alınamadı. Kurları elle girebilirsiniz.', 502);
        }
        $x = @simplexml_load_string((string)$xml, 'SimpleXMLElement', LIBXML_NONET);
        if (!$x) {
            throw new ApiError('fetch_failed', 'TCMB yanıtı çözümlenemedi.', 502);
        }
        $date = Util::today();
        $n = 0;
        foreach ($x->Currency as $c) {
            $code = (string)$c['CurrencyCode'];
            if (in_array($code, ['USD', 'EUR'], true)) {
                $rate = trim((string)$c->ForexSelling);
                try {
                    self::rateInt($rate);
                } catch (ApiError) {
                    continue;
                }
                DB::q('INSERT INTO fx_rates (tenant_id,currency,rate_date,rate,source) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE rate=VALUES(rate), source=VALUES(source)', [$tid, $code, $date, $rate, 'tcmb']);
                $n++;
            }
        }
        Res::ok(['updated' => $n]);
    }

    /** Ortak para birimine çevrilmiş özet: kullanılan kur ve tarih açıkça döner. */
    private static function converted(Req $r, int $tid): never
    {
        $to = (string)V::enum($r->query, 'to', Money::currencies(), false, 'TRY');
        $bal = Fin::balancesByCurrency($tid);
        $rateOf = function (string $c) use ($tid): ?array {
            if ($c === 'TRY') {
                return ['rate' => '1', 'int' => 100000000, 'date' => null, 'source' => 'sabit'];
            }
            $x = DB::one('SELECT rate,rate_date,source FROM fx_rates WHERE tenant_id=? AND currency=? AND rate_date<=? ORDER BY rate_date DESC LIMIT 1', [$tid, $c, Util::today()]);
            return $x ? ['rate' => $x['rate'], 'int' => self::rateInt((string)$x['rate']), 'date' => $x['rate_date'], 'source' => $x['source']] : null;
        };
        $target = $rateOf($to);
        $rows = [];
        $total = 0;
        $missing = [];
        foreach ($bal as $cur => $minor) {
            $fr = $rateOf($cur);
            if (!$fr || !$target) {
                $missing[] = $cur;
                continue;
            }
            $conv = $cur === $to ? $minor : Money::mulDivBig($minor, $fr['int'], $target['int']);
            $total += $conv;
            $rows[] = ['currency' => $cur, 'amount_minor' => $minor, 'rate' => $fr['rate'], 'rate_date' => $fr['date'], 'rate_source' => $fr['source'], 'converted_minor' => $conv];
        }
        Res::ok(['to' => $to, 'rows' => $rows, 'total_minor' => $total, 'missing_rates' => $missing, 'note' => 'Bu özet bilgi amaçlıdır; kur ve tarih satırda belirtilmiştir.']);
    }
}
