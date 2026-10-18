<?php
declare(strict_types=1);

namespace FB;

/** İşletme alanları, hesaplar, kategoriler, cariler, projeler. */
final class SetupCtl
{
    public static function routes(Router $r): void
    {
        $t = ['auth' => 'tenant'];
        $ti = ['auth' => 'tenant', 'idem' => true];
        $r->add('GET', '/meta', fn($q, $p, $tid) => self::meta($tid), $t);
        $r->add('GET', '/workspaces', fn($q, $p, $tid) => Res::ok(['workspaces' => self::workspaces($tid)]), $t);
        $r->add('POST', '/workspaces', fn($q, $p, $tid) => self::wsCreate($q, $tid), $ti);
        $r->add('PATCH', '/workspaces/{id}', fn($q, $p, $tid) => self::wsUpdate($q, (int)$p['id'], $tid), $t);
        $r->add('GET', '/accounts', fn($q, $p, $tid) => Res::ok(['accounts' => Fin::accounts($tid)]), $t);
        $r->add('POST', '/accounts', fn($q, $p, $tid) => self::accCreate($q, $tid), $ti);
        $r->add('PATCH', '/accounts/{id}', fn($q, $p, $tid) => self::accUpdate($q, (int)$p['id'], $tid), $t);
        $r->add('GET', '/accounts/{id}/ledger', fn($q, $p, $tid) => self::ledger($q, (int)$p['id'], $tid), $t);
        $r->add('POST', '/accounts/{id}/adjust', fn($q, $p, $tid) => self::adjust($q, (int)$p['id'], $tid), $ti);
        $r->add('GET', '/categories', fn($q, $p, $tid) => Res::ok(['categories' => DB::all('SELECT id,kind,name,archived FROM categories WHERE tenant_id=? ORDER BY kind,name', [$tid])]), $t);
        $r->add('POST', '/categories', fn($q, $p, $tid) => self::catCreate($q, $tid), $t);
        $r->add('PATCH', '/categories/{id}', fn($q, $p, $tid) => self::catUpdate($q, (int)$p['id'], $tid), $t);
        $r->add('GET', '/contacts', fn($q, $p, $tid) => self::contacts($q, $tid), $t);
        $r->add('POST', '/contacts', fn($q, $p, $tid) => self::contactSave($q, null, $tid), $ti);
        $r->add('PATCH', '/contacts/{id}', fn($q, $p, $tid) => self::contactSave($q, (int)$p['id'], $tid), $t);
        $r->add('GET', '/contacts/{id}/statement', fn($q, $p, $tid) => self::statement($q, (int)$p['id'], $tid), $t);
        $r->add('GET', '/projects', fn($q, $p, $tid) => self::projects($tid), $t);
        $r->add('POST', '/projects', fn($q, $p, $tid) => self::projectSave($q, null, $tid), $ti);
        $r->add('PATCH', '/projects/{id}', fn($q, $p, $tid) => self::projectSave($q, (int)$p['id'], $tid), $t);
        $r->add('GET', '/projects/{id}', fn($q, $p, $tid) => self::projectDetail((int)$p['id'], $tid), $t);
        $r->add('POST', '/projects/{id}/quotes', fn($q, $p, $tid) => self::quoteCreate($q, (int)$p['id'], $tid), $ti);
        $r->add('POST', '/projects/{id}/milestones', fn($q, $p, $tid) => self::milestoneCreate($q, (int)$p['id'], $tid), $ti);
    }

    private static function meta(int $tid): never
    {
        $t = DB::one('SELECT id,name,company_name,settings FROM tenants WHERE id=?', [$tid]);
        Res::ok([
            'tenant' => ['id' => (int)$t['id'], 'name' => $t['name'], 'company_name' => $t['company_name']],
            'settings' => json_decode((string)$t['settings'], true) ?: new \stdClass(),
            'workspaces' => self::workspaces($tid),
            'accounts' => Fin::accounts($tid),
            'categories' => DB::all('SELECT id,kind,name,archived FROM categories WHERE tenant_id=? ORDER BY kind,name', [$tid]),
            'contacts' => DB::all('SELECT id,kind,name,archived FROM contacts WHERE tenant_id=? ORDER BY name', [$tid]),
            'projects' => DB::all('SELECT id,name,status,workspace_id,contact_id,currency,archived FROM projects WHERE tenant_id=? ORDER BY name', [$tid]),
            'cards' => DB::all('SELECT id,name,currency,workspace_id,archived FROM cards WHERE tenant_id=? ORDER BY name', [$tid]),
            'items' => DB::all('SELECT id,kind,code,name,unit,sale_price_minor,vat_rate_bp,currency,archived FROM stock_items WHERE tenant_id=? ORDER BY name', [$tid]),
            'warehouses' => DB::all('SELECT id,name,archived FROM warehouses WHERE tenant_id=? ORDER BY name', [$tid]),
        ]);
    }

    // ---- işletme alanları ----
    private static function workspaces(int $tid): array
    {
        $rows = DB::all('SELECT id,name,kind,legal_name,tax_no,color,archived FROM workspaces WHERE tenant_id=? ORDER BY archived,id', [$tid]);
        foreach ($rows as &$r) {
            $r['id'] = (int)$r['id'];
            $r['archived'] = (int)$r['archived'];
        }
        return $rows;
    }

    private static function wsCreate(Req $r, int $tid): never
    {
        $b = $r->body;
        $name = (string)V::str($b, 'name', 120);
        if (DB::val('SELECT id FROM workspaces WHERE tenant_id=? AND name=?', [$tid, $name])) {
            throw new ApiError('duplicate', 'Bu isimde bir alan zaten var.', 409, 'name');
        }
        $id = DB::insert('workspaces', [
            'tenant_id' => $tid, 'name' => $name, 'kind' => V::enum($b, 'kind', ['business', 'personal'], false, 'business'),
            'legal_name' => V::str($b, 'legal_name', 200, false), 'tax_no' => V::str($b, 'tax_no', 30, false),
            'color' => self::color($b), 'created_at' => Util::now(),
        ]);
        Audit::log('data', 'workspace_created', ['tenant' => $tid, 'entity' => 'workspace', 'id' => $id]);
        Res::ok(['id' => $id], 201);
    }

    private static function color(array $b): ?string
    {
        $c = V::str($b, 'color', 9, false);
        if ($c !== null && !preg_match('/^#[0-9a-fA-F]{6}$/', $c)) {
            throw new ApiError('validation', 'Renk #RRGGBB biçiminde olmalı.', 422, 'color');
        }
        return $c;
    }

    private static function wsUpdate(Req $r, int $id, int $tid): never
    {
        $w = DB::one('SELECT * FROM workspaces WHERE id=? AND tenant_id=?', [$id, $tid]);
        if (!$w) {
            throw new ApiError('not_found', 'Alan bulunamadı.', 404);
        }
        $b = $r->body;
        $u = [];
        if (isset($b['name'])) {
            $u['name'] = V::str($b, 'name', 120);
            if (DB::val('SELECT id FROM workspaces WHERE tenant_id=? AND name=? AND id<>?', [$tid, $u['name'], $id])) {
                throw new ApiError('duplicate', 'Bu isimde bir alan zaten var.', 409, 'name');
            }
        }
        foreach (['legal_name' => 200, 'tax_no' => 30] as $k => $m) {
            if (array_key_exists($k, $b)) {
                $u[$k] = V::str($b, $k, $m, false);
            }
        }
        if (array_key_exists('color', $b)) {
            $u['color'] = self::color($b);
        }
        if (isset($b['archived'])) {
            $u['archived'] = V::bool($b, 'archived');
            if ($u['archived'] && DB::val('SELECT id FROM accounts WHERE tenant_id=? AND workspace_id=? AND archived=0 LIMIT 1', [$tid, $id])) {
                throw new ApiError('in_use', 'Önce bu alandaki hesapları arşivleyin.', 409);
            }
        }
        if ($u) {
            DB::update('workspaces', $u, 'id=? AND tenant_id=?', [$id, $tid]);
            Audit::log('data', 'workspace_updated', ['tenant' => $tid, 'entity' => 'workspace', 'id' => $id, 'details' => ['before' => array_intersect_key($w, $u), 'after' => $u]]);
        }
        Res::ok();
    }

    // ---- hesaplar ----
    public static function ibanOk(string $iban): bool
    {
        if (!preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]{11,30}$/', $iban)) {
            return false;
        }
        $s = substr($iban, 4) . substr($iban, 0, 4);
        $n = '';
        foreach (str_split($s) as $c) {
            $n .= ctype_alpha($c) ? (string)(ord($c) - 55) : $c;
        }
        $rem = 0;
        foreach (str_split($n, 7) as $chunk) {
            $rem = (int)($rem . $chunk) % 97;
        }
        return $rem === 1;
    }

    private static function iban(array $b): ?string
    {
        $v = V::str($b, 'iban', 40, false);
        if ($v === null) {
            return null;
        }
        $v = strtoupper(preg_replace('/\s+/', '', $v) ?? '');
        if (!self::ibanOk($v)) {
            throw new ApiError('validation', 'IBAN geçersiz.', 422, 'iban');
        }
        return $v;
    }

    private static function accCreate(Req $r, int $tid): never
    {
        $b = $r->body;
        $ws = Fin::ownedId('workspaces', V::id($b['workspace_id'] ?? null, 'workspace_id'), $tid, 'workspace_id', true);
        $w = DB::one('SELECT archived FROM workspaces WHERE id=?', [$ws]);
        if ((int)$w['archived'] === 1) {
            throw new ApiError('archived', 'Arşivlenmiş alanda hesap açılamaz.', 422, 'workspace_id');
        }
        $id = DB::insert('accounts', [
            'tenant_id' => $tid, 'workspace_id' => $ws, 'name' => V::str($b, 'name', 120), 'kind' => V::enum($b, 'kind', ['bank', 'cash'], false, 'bank'),
            'bank_name' => V::str($b, 'bank_name', 120, false), 'currency' => V::cur($b), 'iban' => self::iban($b),
            'opening_minor' => V::signedAmount($b + ['opening_minor' => 0], 'opening_minor'),
            'opening_date' => V::date($b + ['opening_date' => Util::today()], 'opening_date'),
            'project_id' => Fin::ownedId('projects', V::id($b['project_id'] ?? null, 'project_id', false), $tid, 'project_id'),
            'created_at' => Util::now(),
        ]);
        Audit::log('data', 'account_created', ['tenant' => $tid, 'entity' => 'account', 'id' => $id, 'details' => ['opening_minor' => $b['opening_minor'] ?? 0]]);
        Res::ok(['id' => $id], 201);
    }

    private static function accUpdate(Req $r, int $id, int $tid): never
    {
        $a = DB::one('SELECT * FROM accounts WHERE id=? AND tenant_id=?', [$id, $tid]);
        if (!$a) {
            throw new ApiError('not_found', 'Hesap bulunamadı.', 404);
        }
        $b = $r->body;
        $u = [];
        foreach (['name' => 120, 'bank_name' => 120] as $k => $m) {
            if (array_key_exists($k, $b)) {
                $u[$k] = V::str($b, $k, $m, $k === 'name');
            }
        }
        if (array_key_exists('iban', $b)) {
            $u['iban'] = self::iban($b);
        }
        if (array_key_exists('project_id', $b)) {
            $u['project_id'] = Fin::ownedId('projects', V::id($b['project_id'], 'project_id', false), $tid, 'project_id');
        }
        if (isset($b['is_default'])) {
            $u['is_default'] = V::bool($b, 'is_default');
        }
        $hasTx = (int)DB::val('SELECT COUNT(*) FROM transactions WHERE account_id=? AND tenant_id=?', [$id, $tid]) > 0;
        if (isset($b['opening_minor']) || isset($b['opening_date'])) {
            if ($hasTx) {
                throw new ApiError('has_transactions', 'Hareketi olan hesabın açılış bakiyesi değiştirilemez; bakiye düzeltme kaydı kullanın.', 409);
            }
            if (isset($b['opening_minor'])) {
                $u['opening_minor'] = V::signedAmount($b, 'opening_minor');
            }
            if (isset($b['opening_date'])) {
                $u['opening_date'] = V::date($b, 'opening_date');
            }
        }
        if (isset($b['archived'])) {
            $arch = V::bool($b, 'archived');
            if ($arch) {
                $bal = array_values(array_filter(Fin::accounts($tid), fn($x) => $x['id'] === $id))[0]['balance_minor'];
                if ($bal !== 0) {
                    throw new ApiError('nonzero_balance', 'Bakiyesi sıfır olmayan hesap arşivlenemez.', 409);
                }
            }
            $u['archived'] = $arch;
        }
        if ($u) {
            DB::update('accounts', $u, 'id=? AND tenant_id=?', [$id, $tid]);
            Audit::log('data', 'account_updated', ['tenant' => $tid, 'entity' => 'account', 'id' => $id, 'details' => ['before' => array_intersect_key($a, $u), 'after' => $u]]);
        }
        Res::ok();
    }

    private static function ledger(Req $r, int $id, int $tid): never
    {
        $a = DB::one('SELECT * FROM accounts WHERE id=? AND tenant_id=?', [$id, $tid]);
        if (!$a) {
            throw new ApiError('not_found', 'Hesap bulunamadı.', 404);
        }
        $rows = DB::all('SELECT t.id,t.txn_date,t.direction,t.kind,t.amount_minor,t.description,t.status,t.void_reason,t.transfer_group,t.category_id,t.contact_id,t.entry_id FROM transactions t WHERE t.tenant_id=? AND t.account_id=? ORDER BY t.txn_date, t.id', [$tid, $id]);
        $bal = (int)$a['opening_minor'];
        $out = [];
        foreach ($rows as $t) {
            $signed = $t['direction'] === 'in' ? (int)$t['amount_minor'] : -(int)$t['amount_minor'];
            if ($t['status'] === 'posted') {
                $bal += $signed;
            }
            $out[] = ['id' => (int)$t['id'], 'date' => $t['txn_date'], 'kind' => $t['kind'], 'signed_minor' => $signed, 'description' => $t['description'], 'status' => $t['status'], 'void_reason' => $t['void_reason'], 'transfer' => $t['transfer_group'] !== null, 'balance_minor' => $bal];
        }
        $from = V::date($r->query, 'from', false);
        $to = V::date($r->query, 'to', false);
        $out = array_values(array_filter($out, fn($x) => (!$from || $x['date'] >= $from) && (!$to || $x['date'] <= $to)));
        Res::ok(['account' => ['id' => $id, 'name' => $a['name'], 'currency' => $a['currency'], 'opening_minor' => (int)$a['opening_minor'], 'opening_date' => $a['opening_date']], 'rows' => array_reverse($out), 'balance_minor' => $bal]);
    }

    /** Bakiye düzeltme: gelir/gider sayılmayan 'adjustment' hareketi. */
    private static function adjust(Req $r, int $id, int $tid): never
    {
        $b = $r->body;
        $delta = V::signedAmount($b, 'delta_minor');
        if ($delta === 0) {
            throw new ApiError('validation', 'Düzeltme tutarı sıfır olamaz.', 422, 'delta_minor');
        }
        $reason = (string)V::str($b, 'reason', 300);
        $date = (string)V::date($b + ['date' => Util::today()], 'date');
        $txn = DB::tx(function () use ($tid, $id, $delta, $date, $reason) {
            $tx = Fin::post($tid, ['account_id' => $id, 'direction' => $delta > 0 ? 'in' : 'out', 'kind' => 'adjustment', 'amount_minor' => abs($delta), 'txn_date' => $date, 'description' => 'Bakiye düzeltme: ' . $reason]);
            Audit::log('data', 'balance_adjusted', ['tenant' => $tid, 'entity' => 'account', 'id' => $id, 'details' => ['delta_minor' => $delta, 'reason' => $reason]]);
            return $tx;
        });
        Res::ok(['id' => $txn], 201);
    }

    // ---- kategoriler ----
    private static function catCreate(Req $r, int $tid): never
    {
        $b = $r->body;
        $kind = (string)V::enum($b, 'kind', ['income', 'expense']);
        $name = (string)V::str($b, 'name', 100);
        if (DB::val('SELECT id FROM categories WHERE tenant_id=? AND kind=? AND name=?', [$tid, $kind, $name])) {
            throw new ApiError('duplicate', 'Bu kategori zaten var.', 409, 'name');
        }
        Res::ok(['id' => DB::insert('categories', ['tenant_id' => $tid, 'kind' => $kind, 'name' => $name])], 201);
    }

    private static function catUpdate(Req $r, int $id, int $tid): never
    {
        if (!DB::val('SELECT id FROM categories WHERE id=? AND tenant_id=?', [$id, $tid])) {
            throw new ApiError('not_found', 'Kategori bulunamadı.', 404);
        }
        $u = [];
        if (isset($r->body['name'])) {
            $u['name'] = V::str($r->body, 'name', 100);
        }
        if (isset($r->body['archived'])) {
            $u['archived'] = V::bool($r->body, 'archived');
        }
        if ($u) {
            try {
                DB::update('categories', $u, 'id=? AND tenant_id=?', [$id, $tid]);
            } catch (\PDOException) {
                throw new ApiError('duplicate', 'Bu kategori zaten var.', 409, 'name');
            }
        }
        Res::ok();
    }

    // ---- cariler ----
    private static function contacts(Req $r, int $tid): never
    {
        $w = ['c.tenant_id=?'];
        $p = [$tid];
        if (!empty($r->query['kind']) && in_array($r->query['kind'], ['customer', 'supplier'], true)) {
            $w[] = "(c.kind=? OR c.kind='both')";
            $p[] = $r->query['kind'];
        }
        if (!empty($r->query['q'])) {
            $w[] = '(c.name LIKE ? OR c.tax_no LIKE ?)';
            $l = '%' . str_replace(['%', '_'], ['\\%', '\\_'], (string)$r->query['q']) . '%';
            array_push($p, $l, $l);
        }
        $rows = DB::all('SELECT c.* FROM contacts c WHERE ' . implode(' AND ', $w) . ' ORDER BY c.archived, c.name LIMIT 1000', $p);
        $bal = [];
        foreach (Fin::entriesOpen($tid) as $e) {
            if ($e['contact_id'] && (int)$e['remaining_minor'] > 0) {
                $bal[(int)$e['contact_id']][$e['currency']][$e['kind']] = ($bal[(int)$e['contact_id']][$e['currency']][$e['kind']] ?? 0) + (int)$e['remaining_minor'];
            }
        }
        foreach ($rows as &$c) {
            $c['id'] = (int)$c['id'];
            $c['balances'] = $bal[$c['id']] ?? new \stdClass();
        }
        Res::ok(['contacts' => $rows]);
    }

    private static function contactSave(Req $r, ?int $id, int $tid): never
    {
        $b = $r->body;
        $d = [
            'kind' => V::enum($b, 'kind', ['customer', 'supplier', 'both'], false, 'customer'),
            'name' => V::str($b, 'name', 200), 'tax_no' => V::str($b, 'tax_no', 30, false), 'tax_office' => V::str($b, 'tax_office', 100, false),
            'email' => V::str($b, 'email', 190, false), 'phone' => V::str($b, 'phone', 40, false), 'address' => V::str($b, 'address', 400, false), 'note' => V::str($b, 'note', 2000, false),
        ];
        if ($d['email'] !== null && !filter_var($d['email'], FILTER_VALIDATE_EMAIL)) {
            throw new ApiError('validation', 'Geçerli bir e-posta girin.', 422, 'email');
        }
        if ($id === null) {
            $d += ['tenant_id' => $tid, 'created_at' => Util::now()];
            $id = DB::insert('contacts', $d);
            Audit::log('data', 'contact_created', ['tenant' => $tid, 'entity' => 'contact', 'id' => $id]);
            Res::ok(['id' => $id], 201);
        }
        $old = DB::one('SELECT * FROM contacts WHERE id=? AND tenant_id=?', [$id, $tid]);
        if (!$old) {
            throw new ApiError('not_found', 'Cari bulunamadı.', 404);
        }
        $b2 = array_intersect_key($d, $b) + (isset($b['name']) ? [] : []);
        if (!isset($b['name'])) {
            unset($d['name']);
        }
        $upd = array_intersect_key($d, $b);
        if (isset($b['archived'])) {
            $upd['archived'] = V::bool($b, 'archived');
        }
        if ($upd) {
            DB::update('contacts', $upd, 'id=? AND tenant_id=?', [$id, $tid]);
            Audit::log('data', 'contact_updated', ['tenant' => $tid, 'entity' => 'contact', 'id' => $id, 'details' => ['before' => array_intersect_key($old, $upd), 'after' => $upd]]);
        }
        Res::ok();
    }

    /** Cari ekstre: alacak/borç doğuşları ve tahsilat/ödemeler; işletme ve para birimi bazında. */
    private static function statement(Req $r, int $id, int $tid): never
    {
        $c = DB::one('SELECT * FROM contacts WHERE id=? AND tenant_id=?', [$id, $tid]);
        if (!$c) {
            throw new ApiError('not_found', 'Cari bulunamadı.', 404);
        }
        $ws = V::id($r->query['workspace_id'] ?? null, 'workspace_id', false);
        $from = V::date($r->query, 'from', false);
        $to = V::date($r->query, 'to', false);
        $w = 'e.tenant_id=? AND e.contact_id=? AND e.status=\'open\'' . ($ws ? ' AND e.workspace_id=' . (int)$ws : '');
        $es = DB::all("SELECT e.id,e.kind,e.title,e.currency,e.amount_minor,e.due_date,e.workspace_id, COALESCE(i.issue_date, DATE(e.created_at)) AS ev_date, i.number
                       FROM entries e LEFT JOIN invoices i ON i.id=e.invoice_id WHERE $w", [$tid, $id]);
        $lines = [];
        foreach ($es as $e) {
            $sign = $e['kind'] === 'receivable' ? 1 : -1; // + : cari bize borçlu
            $lines[] = ['date' => $e['ev_date'], 'sort' => 0, 'currency' => $e['currency'], 'workspace_id' => (int)$e['workspace_id'], 'type' => $e['kind'] === 'receivable' ? 'Alacak' : 'Borç', 'text' => $e['title'] . ($e['number'] ? ' (' . $e['number'] . ')' : ''), 'signed_minor' => $sign * (int)$e['amount_minor'], 'due_date' => $e['due_date']];
        }
        $ts = DB::all("SELECT t.txn_date, t.kind, t.currency, t.workspace_id, t.amount_minor, t.description, e.kind AS ekind FROM transactions t JOIN entries e ON e.id=t.entry_id
                       WHERE t.tenant_id=? AND e.contact_id=? AND t.status='posted'" . ($ws ? ' AND t.workspace_id=' . (int)$ws : ''), [$tid, $id]);
        foreach ($ts as $t) {
            $sign = $t['ekind'] === 'receivable' ? -1 : 1; // tahsilat bakiyeyi azaltır
            $lines[] = ['date' => $t['txn_date'], 'sort' => 1, 'currency' => $t['currency'], 'workspace_id' => (int)$t['workspace_id'], 'type' => $t['ekind'] === 'receivable' ? 'Tahsilat' : 'Ödeme', 'text' => (string)$t['description'], 'signed_minor' => $sign * (int)$t['amount_minor'], 'due_date' => null];
        }
        usort($lines, fn($a, $b) => [$a['date'], $a['sort']] <=> [$b['date'], $b['sort']]);
        $groups = [];
        foreach ($lines as $l) {
            $key = $l['workspace_id'] . '|' . $l['currency'];
            $groups[$key]['workspace_id'] = $l['workspace_id'];
            $groups[$key]['currency'] = $l['currency'];
            $groups[$key]['all'][] = $l;
        }
        $out = [];
        foreach ($groups as $g) {
            $bal = 0;
            $opening = 0;
            $rows = [];
            foreach ($g['all'] as $l) {
                if ($from && $l['date'] < $from) {
                    $opening += $l['signed_minor'];
                    $bal += $l['signed_minor'];
                    continue;
                }
                if ($to && $l['date'] > $to) {
                    continue;
                }
                $bal += $l['signed_minor'];
                $l['balance_minor'] = $bal;
                $rows[] = $l;
            }
            $out[] = ['workspace_id' => $g['workspace_id'], 'currency' => $g['currency'], 'opening_minor' => $opening, 'rows' => $rows, 'closing_minor' => $bal];
        }
        Res::ok(['contact' => ['id' => $id, 'name' => $c['name'], 'tax_no' => $c['tax_no']], 'groups' => $out, 'note' => 'Pozitif bakiye: cari size borçlu; negatif: siz cariye borçlusunuz.']);
    }

    // ---- projeler ----
    private static function projects(int $tid): never
    {
        $rows = DB::all('SELECT p.*, c.name AS contact_name FROM projects p LEFT JOIN contacts c ON c.id=p.contact_id WHERE p.tenant_id=? ORDER BY p.archived, p.id DESC', [$tid]);
        foreach ($rows as &$p) {
            $p['id'] = (int)$p['id'];
            $f = Pl::projectFigures($tid, $p['id']);
            $p['revenue_minor'] = $f['revenue_minor'];
            $p['cost_minor'] = $f['cost_minor'];
            $p['profit_minor'] = $f['profit_minor'];
        }
        Res::ok(['projects' => $rows]);
    }

    private static function projectSave(Req $r, ?int $id, int $tid): never
    {
        $b = $r->body;
        $d = [
            'name' => V::str($b, 'name', 160), 'currency' => V::cur($b),
            'contract_minor' => V::signedAmount($b + ['contract_minor' => 0], 'contract_minor'),
            'est_cost_minor' => V::signedAmount($b + ['est_cost_minor' => 0], 'est_cost_minor'),
            'status' => V::enum($b, 'status', ['planned', 'active', 'done', 'cancelled'], false, 'active'),
            'start_date' => V::date($b, 'start_date', false), 'end_date' => V::date($b, 'end_date', false), 'note' => V::str($b, 'note', 2000, false),
            'workspace_id' => Fin::ownedId('workspaces', V::id($b['workspace_id'] ?? null, 'workspace_id', false), $tid, 'workspace_id'),
            'contact_id' => Fin::ownedId('contacts', V::id($b['contact_id'] ?? null, 'contact_id', false), $tid, 'contact_id'),
        ];
        if ($d['contract_minor'] < 0 || $d['est_cost_minor'] < 0) {
            throw new ApiError('validation', 'Tutarlar negatif olamaz.', 422);
        }
        if ($id === null) {
            $id = DB::insert('projects', $d + ['tenant_id' => $tid, 'created_at' => Util::now()]);
            Audit::log('data', 'project_created', ['tenant' => $tid, 'entity' => 'project', 'id' => $id]);
            Res::ok(['id' => $id], 201);
        }
        $old = DB::one('SELECT * FROM projects WHERE id=? AND tenant_id=?', [$id, $tid]);
        if (!$old) {
            throw new ApiError('not_found', 'Proje bulunamadı.', 404);
        }
        if (!isset($b['name'])) {
            unset($d['name']);
        }
        $upd = array_intersect_key($d, $b);
        if (isset($b['archived'])) {
            $upd['archived'] = V::bool($b, 'archived');
        }
        if ($upd) {
            DB::update('projects', $upd, 'id=? AND tenant_id=?', [$id, $tid]);
            Audit::log('data', 'project_updated', ['tenant' => $tid, 'entity' => 'project', 'id' => $id, 'details' => ['before' => array_intersect_key($old, $upd), 'after' => $upd]]);
        }
        Res::ok();
    }

    private static function projectDetail(int $id, int $tid): never
    {
        $p = DB::one('SELECT p.*, c.name AS contact_name FROM projects p LEFT JOIN contacts c ON c.id=p.contact_id WHERE p.id=? AND p.tenant_id=?', [$id, $tid]);
        if (!$p) {
            throw new ApiError('not_found', 'Proje bulunamadı.', 404);
        }
        $ms = DB::all('SELECT m.*, COALESCE(x.paid,0) paid_minor FROM project_milestones m LEFT JOIN (SELECT entry_id, SUM(amount_minor) paid FROM transactions WHERE tenant_id=? AND status=\'posted\' GROUP BY entry_id) x ON x.entry_id=m.entry_id WHERE m.tenant_id=? AND m.project_id=? ORDER BY m.due_date', [$tid, $tid, $id]);
        $qs = DB::all('SELECT * FROM project_quotes WHERE tenant_id=? AND project_id=? ORDER BY quote_date DESC', [$tid, $id]);
        Res::ok(['project' => $p, 'figures' => Pl::projectFigures($tid, $id), 'milestones' => $ms, 'quotes' => $qs]);
    }

    private static function quoteCreate(Req $r, int $pid, int $tid): never
    {
        Fin::ownedId('projects', $pid, $tid, 'id', true);
        $b = $r->body;
        $id = DB::insert('project_quotes', [
            'tenant_id' => $tid, 'project_id' => $pid, 'kind' => V::enum($b, 'kind', ['quote', 'order'], false, 'quote'), 'number' => V::str($b, 'number', 60, false),
            'quote_date' => V::date($b + ['quote_date' => Util::today()], 'quote_date'), 'amount_minor' => V::amount($b, 'amount_minor'),
            'status' => V::enum($b, 'status', ['draft', 'sent', 'accepted', 'rejected'], false, 'draft'), 'note' => V::str($b, 'note', 400, false), 'created_at' => Util::now(),
        ]);
        Res::ok(['id' => $id], 201);
    }

    /** Hakediş / ara ödeme planı: alacak kalemi oluşturur (proje geliri olarak tahakkuk eder). */
    private static function milestoneCreate(Req $r, int $pid, int $tid): never
    {
        $p = DB::one('SELECT * FROM projects WHERE id=? AND tenant_id=?', [$pid, $tid]);
        if (!$p) {
            throw new ApiError('not_found', 'Proje bulunamadı.', 404);
        }
        $b = $r->body;
        $title = (string)V::str($b, 'title', 200);
        $amount = (int)V::amount($b, 'amount_minor');
        $due = (string)V::date($b, 'due_date');
        $ws = (int)($p['workspace_id'] ?? DB::val('SELECT id FROM workspaces WHERE tenant_id=? AND kind=\'business\' AND archived=0 ORDER BY id LIMIT 1', [$tid]));
        if (!$ws) {
            throw new ApiError('validation', 'Önce bir işletme alanı oluşturun.', 422);
        }
        $id = DB::tx(function () use ($tid, $pid, $p, $title, $amount, $due, $ws) {
            $eid = DB::insert('entries', [
                'tenant_id' => $tid, 'workspace_id' => $ws, 'kind' => 'receivable', 'source' => 'milestone', 'contact_id' => $p['contact_id'], 'project_id' => $pid,
                'title' => $p['name'] . ' — ' . $title, 'currency' => $p['currency'], 'amount_minor' => $amount, 'due_date' => $due, 'original_due_date' => $due,
                'affects_pl' => 1, 'created_at' => Util::now(),
            ]);
            return DB::insert('project_milestones', ['tenant_id' => $tid, 'project_id' => $pid, 'title' => $title, 'due_date' => $due, 'amount_minor' => $amount, 'entry_id' => $eid, 'created_at' => Util::now()]);
        });
        Res::ok(['id' => $id], 201);
    }
}
