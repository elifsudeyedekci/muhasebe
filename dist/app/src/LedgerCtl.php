<?php
declare(strict_types=1);

namespace FB;

/** Gelir/gider hareketleri, alacak-borç kalemleri, transfer ve döviz, tekrarlayan şablonlar. */
final class LedgerCtl
{
    public static function routes(Router $r): void
    {
        $t = ['auth' => 'tenant'];
        $ti = ['auth' => 'tenant', 'idem' => true];
        $r->add('GET', '/transactions', fn($q, $p, $tid) => self::txnList($q, $tid), $t);
        $r->add('POST', '/transactions', fn($q, $p, $tid) => self::txnCreate($q, $tid, null), $ti);
        $r->add('POST', '/transactions/{id}/void', fn($q, $p, $tid) => self::txnVoid($q, (int)$p['id'], $tid), $t);
        $r->add('POST', '/transactions/{id}/replace', fn($q, $p, $tid) => self::txnReplace($q, (int)$p['id'], $tid), $ti);
        $r->add('POST', '/transfers', fn($q, $p, $tid) => self::transfer($q, $tid), $ti);
        $r->add('POST', '/fx', fn($q, $p, $tid) => self::fx($q, $tid), $ti);
        $r->add('GET', '/entries', fn($q, $p, $tid) => self::entryList($q, $tid), $t);
        $r->add('POST', '/entries', fn($q, $p, $tid) => self::entryCreate($q, $tid), $ti);
        $r->add('PATCH', '/entries/{id}', fn($q, $p, $tid) => self::entryUpdate($q, (int)$p['id'], $tid), $t);
        $r->add('POST', '/entries/{id}/settle', fn($q, $p, $tid) => self::settle($q, (int)$p['id'], $tid), $ti);
        $r->add('POST', '/entries/{id}/cancel', fn($q, $p, $tid) => self::entryCancel($q, (int)$p['id'], $tid), $t);
        $r->add('GET', '/recurring', fn($q, $p, $tid) => Res::ok(['templates' => DB::all('SELECT * FROM recurring_templates WHERE tenant_id=? ORDER BY active DESC, next_date', [$tid])]), $t);
        $r->add('POST', '/recurring', fn($q, $p, $tid) => self::recurringCreate($q, $tid), $ti);
        $r->add('PATCH', '/recurring/{id}', fn($q, $p, $tid) => self::recurringUpdate($q, (int)$p['id'], $tid), $t);
        $r->add('POST', '/recurring/run', fn($q, $p, $tid) => Res::ok(['generated' => self::runRecurring($tid)]), $t);
    }

    /** KDV: 'included' → tutar brüttür; 'excluded' → tutar net, KDV üstüne eklenir. Dönüş: [brüt, kdv]. */
    public static function vatCalc(array $b, string $amtKey = 'amount_minor'): array
    {
        $amt = (int)V::amount($b, $amtKey);
        $rate = (int)V::int($b, 'vat_rate_bp', 0, 10000, false, 0);
        $mode = (string)V::enum($b, 'vat_mode', ['included', 'excluded'], false, 'included');
        if ($rate === 0) {
            return [$amt, 0, 0];
        }
        if ($mode === 'excluded') {
            $vat = Money::mulDiv($amt, $rate, 10000);
            return [$amt + $vat, $vat, $rate];
        }
        return [$amt, Fin::vatOfGross($amt, $rate), $rate];
    }

    private static function category(?int $id, int $tid, string $kind): ?int
    {
        if ($id === null) {
            return null;
        }
        $c = DB::one('SELECT kind FROM categories WHERE id=? AND tenant_id=?', [$id, $tid]);
        if (!$c) {
            throw new ApiError('not_found', 'Kategori bulunamadı.', 404, 'category_id');
        }
        if ($c['kind'] !== $kind) {
            throw new ApiError('validation', 'Kategori türü işlemle uyuşmuyor.', 422, 'category_id');
        }
        return $id;
    }

    private static function accountBalance(int $tid, int $accId): int
    {
        return (int)DB::val("SELECT a.opening_minor + COALESCE(SUM(CASE WHEN t.direction='in' THEN t.amount_minor ELSE -t.amount_minor END),0)
                             FROM accounts a LEFT JOIN transactions t ON t.account_id=a.id AND t.status='posted' WHERE a.id=? AND a.tenant_id=?", [$accId, $tid]);
    }

    // ---- hareketler ----
    public static function filters(array $q, int $tid, string $alias = 't'): array
    {
        $w = ["$alias.tenant_id=?"];
        $p = [$tid];
        foreach (['account_id', 'category_id', 'contact_id', 'project_id', 'workspace_id'] as $k) {
            if (!empty($q[$k])) {
                $w[] = "$alias.$k=?";
                $p[] = (int)$q[$k];
            }
        }
        if (!empty($q['scope']) && in_array($q['scope'], ['business', 'personal'], true) && empty($q['workspace_id'])) {
            $w[] = "$alias.workspace_id IN (SELECT id FROM workspaces WHERE tenant_id=? AND kind=?)";
            array_push($p, $tid, $q['scope']);
        }
        if (!empty($q['kind']) && in_array($q['kind'], ['income', 'expense', 'collection', 'payment', 'transfer', 'fx', 'card_payment', 'loan_payment', 'loan_proceeds', 'adjustment'], true)) {
            $w[] = "$alias.kind=?";
            $p[] = $q['kind'];
        }
        if (!empty($q['currency']) && in_array($q['currency'], Money::currencies(), true)) {
            $w[] = "$alias.currency=?";
            $p[] = $q['currency'];
        }
        if (!empty($q['from'])) {
            $w[] = "$alias.txn_date>=?";
            $p[] = V::date($q, 'from');
        }
        if (!empty($q['to'])) {
            $w[] = "$alias.txn_date<=?";
            $p[] = V::date($q, 'to');
        }
        if (($q['doc'] ?? '') === 'with' || ($q['doc'] ?? '') === 'without') {
            $w[] = ($q['doc'] === 'with' ? 'EXISTS' : 'NOT EXISTS') . " (SELECT 1 FROM documents d WHERE d.tenant_id=$alias.tenant_id AND d.entity='transaction' AND d.entity_id=$alias.id AND d.deleted_at IS NULL)";
        }
        $w[] = "$alias.status=?";
        $p[] = ($q['status'] ?? 'posted') === 'voided' ? 'voided' : 'posted';
        if (!empty($q['q'])) {
            $w[] = "$alias.description LIKE ?";
            $p[] = '%' . str_replace(['%', '_'], ['\\%', '\\_'], (string)$q['q']) . '%';
        }
        return [implode(' AND ', $w), $p];
    }

    public static function txnList(Req $r, int $tid): never
    {
        [$w, $p] = self::filters($r->query, $tid);
        $limit = min(500, max(1, (int)($r->query['limit'] ?? 100)));
        $off = max(0, (int)($r->query['offset'] ?? 0));
        $rows = DB::all("SELECT t.id,t.txn_date,t.kind,t.direction,t.amount_minor,t.vat_minor,t.vat_rate_bp,t.currency,t.description,t.status,t.account_id,a.name AS account_name,t.workspace_id,
                         t.category_id,c.name AS category_name,t.contact_id,ct.name AS contact_name,t.project_id,t.entry_id,t.transfer_group,t.void_reason,t.replaces_id,
                         (SELECT COUNT(*) FROM documents d WHERE d.tenant_id=t.tenant_id AND d.entity='transaction' AND d.entity_id=t.id AND d.deleted_at IS NULL) AS doc_count
                         FROM transactions t JOIN accounts a ON a.id=t.account_id LEFT JOIN categories c ON c.id=t.category_id LEFT JOIN contacts ct ON ct.id=t.contact_id
                         WHERE $w ORDER BY t.txn_date DESC, t.id DESC LIMIT $limit OFFSET $off", $p);
        $tot = DB::all("SELECT t.currency, t.kind, t.direction, SUM(t.amount_minor) s FROM transactions t WHERE $w GROUP BY t.currency, t.kind, t.direction", $p);
        $sum = [];
        foreach ($tot as $x) {
            $sum[$x['currency']][$x['kind']] = ($sum[$x['currency']][$x['kind']] ?? 0) + (int)$x['s'];
        }
        $count = (int)DB::val("SELECT COUNT(*) FROM transactions t WHERE $w", $p);
        foreach ($rows as &$x) {
            foreach (['id', 'amount_minor', 'vat_minor', 'vat_rate_bp', 'account_id', 'workspace_id', 'doc_count'] as $k) {
                $x[$k] = (int)$x[$k];
            }
        }
        Res::ok(['transactions' => $rows, 'count' => $count, 'sums' => $sum]);
    }

    private static function txnCreate(Req $r, int $tid, ?int $replaces): never
    {
        [$id, $warning] = self::txnInsert($r, $tid, $replaces);
        Res::ok(['id' => $id, 'warning' => $warning], 201);
    }

    private static function txnInsert(Req $r, int $tid, ?int $replaces): array
    {
        $b = $r->body;
        $kind = (string)V::enum($b, 'kind', ['income', 'expense']);
        $acc = Fin::ownedId('accounts', V::id($b['account_id'] ?? null, 'account_id'), $tid, 'account_id', true);
        [$gross, $vat, $rate] = self::vatCalc($b);
        $id = null;
        $warning = null;
        DB::tx(function () use ($b, $tid, $kind, $acc, $gross, $vat, $rate, $replaces, &$id, &$warning) {
            $contact = Fin::ownedId('contacts', V::id($b['contact_id'] ?? null, 'contact_id', false), $tid, 'contact_id');
            $project = Fin::ownedId('projects', V::id($b['project_id'] ?? null, 'project_id', false), $tid, 'project_id');
            $id = Fin::post($tid, [
                'account_id' => $acc, 'direction' => $kind === 'income' ? 'in' : 'out', 'kind' => $kind, 'amount_minor' => $gross,
                'txn_date' => V::date($b + ['txn_date' => Util::today()], 'txn_date'), 'description' => V::str($b, 'description', 300, false),
                'category_id' => self::category(V::id($b['category_id'] ?? null, 'category_id', false), $tid, $kind),
                'contact_id' => $contact, 'project_id' => $project, 'vat_rate_bp' => $rate, 'vat_minor' => $vat, 'replaces_id' => $replaces,
            ]);
            if ($kind === 'expense' && self::accountBalance($tid, $acc) < 0) {
                $warning = 'Hesap bakiyesi negatife düştü.';
            }
            Audit::log('data', $replaces ? 'transaction_replaced' : 'transaction_created', ['tenant' => $tid, 'entity' => 'transaction', 'id' => $id, 'details' => ['kind' => $kind, 'amount_minor' => $gross, 'replaces' => $replaces]]);
        });
        return [$id, $warning];
    }

    private static function txnVoid(Req $r, int $id, int $tid): never
    {
        $reason = (string)V::str($r->body, 'reason', 300);
        $ids = Fin::voidTxn($tid, $id, $reason);
        Res::ok(['voided' => $ids]);
    }

    /** Düzenleme = eskiyi iptal et + yenisini ekle (geçmiş korunur, bağlantı replaces_id ile tutulur). */
    private static function txnReplace(Req $r, int $id, int $tid): never
    {
        $t = DB::one('SELECT * FROM transactions WHERE id=? AND tenant_id=?', [$id, $tid]);
        if (!$t) {
            throw new ApiError('not_found', 'İşlem bulunamadı.', 404);
        }
        if (!in_array($t['kind'], ['income', 'expense'], true) || $t['status'] !== 'posted') {
            throw new ApiError('invalid_state', 'Yalnızca aktif gelir/gider kayıtları düzenlenebilir.', 409);
        }
        $res = DB::tx(function () use ($r, $id, $tid) {
            Fin::voidTxn($tid, $id, 'Düzenleme ile yenisi oluşturuldu');
            return self::txnInsert($r, $tid, $id);
        });
        Res::ok(['id' => $res[0], 'warning' => $res[1]], 201);
    }

    // ---- transfer / döviz ----
    private static function transfer(Req $r, int $tid): never
    {
        $b = $r->body;
        $from = Fin::ownedId('accounts', V::id($b['from_account_id'] ?? null, 'from_account_id'), $tid, 'from_account_id', true);
        $to = Fin::ownedId('accounts', V::id($b['to_account_id'] ?? null, 'to_account_id'), $tid, 'to_account_id', true);
        if ($from === $to) {
            throw new ApiError('validation', 'Kaynak ve hedef hesap farklı olmalı.', 422, 'to_account_id');
        }
        $a = DB::one('SELECT currency FROM accounts WHERE id=?', [$from]);
        $c = DB::one('SELECT currency FROM accounts WHERE id=?', [$to]);
        if ($a['currency'] !== $c['currency']) {
            throw new ApiError('currency_mismatch', 'Farklı para birimli hesaplar arasında döviz işlemi kullanın.', 422, 'to_account_id');
        }
        $amt = (int)V::amount($b, 'amount_minor');
        $date = (string)V::date($b + ['date' => Util::today()], 'date');
        $desc = V::str($b, 'description', 300, false) ?? 'Transfer';
        $g = Util::rid();
        $ids = DB::tx(function () use ($tid, $from, $to, $amt, $date, $desc, $g) {
            $o = Fin::post($tid, ['account_id' => $from, 'direction' => 'out', 'kind' => 'transfer', 'amount_minor' => $amt, 'txn_date' => $date, 'description' => $desc, 'transfer_group' => $g]);
            $i = Fin::post($tid, ['account_id' => $to, 'direction' => 'in', 'kind' => 'transfer', 'amount_minor' => $amt, 'txn_date' => $date, 'description' => $desc, 'transfer_group' => $g]);
            Audit::log('data', 'transfer_created', ['tenant' => $tid, 'entity' => 'transfer', 'id' => $g, 'details' => ['from' => $from, 'to' => $to, 'amount_minor' => $amt]]);
            return [$o, $i];
        });
        Res::ok(['group' => $g, 'ids' => $ids], 201);
    }

    private static function fx(Req $r, int $tid): never
    {
        $b = $r->body;
        $from = Fin::ownedId('accounts', V::id($b['from_account_id'] ?? null, 'from_account_id'), $tid, 'from_account_id', true);
        $to = Fin::ownedId('accounts', V::id($b['to_account_id'] ?? null, 'to_account_id'), $tid, 'to_account_id', true);
        $a = DB::one('SELECT currency FROM accounts WHERE id=?', [$from]);
        $c = DB::one('SELECT currency FROM accounts WHERE id=?', [$to]);
        if ($a['currency'] === $c['currency']) {
            throw new ApiError('currency_mismatch', 'Döviz işleminde hesapların para birimi farklı olmalı.', 422, 'to_account_id');
        }
        $out = (int)V::amount($b, 'from_amount_minor');
        $in = (int)V::amount($b, 'to_amount_minor');
        $fee = (int)V::amount($b + ['fee_minor' => 0], 'fee_minor', false, true);
        $date = (string)V::date($b + ['date' => Util::today()], 'date');
        $g = Util::rid();
        $rate = number_format($in / $out, 8, '.', '');
        $ids = DB::tx(function () use ($tid, $from, $to, $out, $in, $fee, $date, $g, $rate, $a, $c) {
            $desc = "Döviz: {$a['currency']}→{$c['currency']} kur $rate";
            $o = Fin::post($tid, ['account_id' => $from, 'direction' => 'out', 'kind' => 'fx', 'amount_minor' => $out, 'txn_date' => $date, 'description' => $desc, 'transfer_group' => $g, 'fx_rate' => $rate]);
            $i = Fin::post($tid, ['account_id' => $to, 'direction' => 'in', 'kind' => 'fx', 'amount_minor' => $in, 'txn_date' => $date, 'description' => $desc, 'transfer_group' => $g, 'fx_rate' => $rate]);
            $f = null;
            if ($fee > 0) {
                $cat = DB::val("SELECT id FROM categories WHERE tenant_id=? AND kind='expense' AND name='Banka Masrafı'", [$tid]);
                $f = Fin::post($tid, ['account_id' => $from, 'direction' => 'out', 'kind' => 'expense', 'amount_minor' => $fee, 'txn_date' => $date, 'description' => 'Döviz işlem masrafı', 'category_id' => $cat ?: null]);
            }
            Audit::log('data', 'fx_created', ['tenant' => $tid, 'entity' => 'transfer', 'id' => $g, 'details' => ['out' => $out, 'in' => $in, 'rate' => $rate, 'fee' => $fee]]);
            return [$o, $i, $f];
        });
        Res::ok(['group' => $g, 'ids' => $ids, 'rate' => $rate], 201);
    }

    // ---- alacak / borç ----
    public static function entryList(Req $r, int $tid): never
    {
        $q = $r->query;
        $w = ['e.tenant_id=?'];
        $p = [$tid];
        if (in_array($q['kind'] ?? '', ['receivable', 'payable'], true)) {
            $w[] = 'e.kind=?';
            $p[] = $q['kind'];
        }
        if (!empty($q['source']) && in_array($q['source'], ['manual', 'invoice', 'personal_bill', 'recurring', 'milestone'], true)) {
            $w[] = 'e.source=?';
            $p[] = $q['source'];
        }
        foreach (['contact_id', 'project_id', 'workspace_id'] as $k) {
            if (!empty($q[$k])) {
                $w[] = "e.$k=?";
                $p[] = (int)$q[$k];
            }
        }
        if (!empty($q['scope']) && in_array($q['scope'], ['business', 'personal'], true) && empty($q['workspace_id'])) {
            $w[] = 'e.workspace_id IN (SELECT id FROM workspaces WHERE tenant_id=? AND kind=?)';
            array_push($p, $tid, $q['scope']);
        }
        if (!empty($q['currency']) && in_array($q['currency'], Money::currencies(), true)) {
            $w[] = 'e.currency=?';
            $p[] = $q['currency'];
        }
        $today = Util::today();
        $win = $q['window'] ?? '';
        if ($win === 'today') {
            $w[] = 'e.due_date=?';
            $p[] = $today;
        } elseif ($win === '7' || $win === '30') {
            $w[] = 'e.due_date BETWEEN ? AND ?';
            array_push($p, $today, Util::addDays($today, (int)$win));
        } elseif ($win === 'overdue') {
            $w[] = 'e.due_date < ?';
            $p[] = $today;
        }
        if (!empty($q['from'])) {
            $w[] = 'e.due_date>=?';
            $p[] = V::date($q, 'from');
        }
        if (!empty($q['to'])) {
            $w[] = 'e.due_date<=?';
            $p[] = V::date($q, 'to');
        }
        if (!empty($q['q'])) {
            $w[] = '(e.title LIKE ? OR e.provider LIKE ?)';
            $l = '%' . str_replace(['%', '_'], ['\\%', '\\_'], (string)$q['q']) . '%';
            array_push($p, $l, $l);
        }
        $status = $q['status'] ?? 'unpaid';
        $having = match ($status) {
            'paid' => 'HAVING remaining_minor <= 0 AND e.status = \'open\'',
            'all' => '',
            'cancelled' => '',
            default => 'HAVING remaining_minor > 0',
        };
        $w[] = $status === 'cancelled' ? "e.status='cancelled'" : "e.status='open'";
        $rows = DB::all("SELECT e.*, c.name AS contact_name, pr.name AS project_name, w.name AS workspace_name, w.kind AS workspace_kind, COALESCE(x.paid,0) AS paid_minor, e.amount_minor - COALESCE(x.paid,0) AS remaining_minor
                         FROM entries e LEFT JOIN contacts c ON c.id=e.contact_id LEFT JOIN projects pr ON pr.id=e.project_id JOIN workspaces w ON w.id=e.workspace_id
                         LEFT JOIN (SELECT entry_id, SUM(amount_minor) paid FROM transactions WHERE tenant_id=? AND status='posted' AND entry_id IS NOT NULL GROUP BY entry_id) x ON x.entry_id=e.id
                         WHERE " . implode(' AND ', $w) . " $having ORDER BY e.due_date, e.priority, e.id LIMIT 1000", [$tid, ...$p]);
        $sums = [];
        foreach ($rows as &$e) {
            foreach (['id', 'amount_minor', 'paid_minor', 'remaining_minor', 'workspace_id', 'priority', 'affects_pl'] as $k) {
                $e[$k] = (int)$e[$k];
            }
            $e['days_overdue'] = ($e['remaining_minor'] > 0 && $e['due_date'] < $today) ? (int)((strtotime($today) - strtotime($e['due_date'])) / 86400) : 0;
            $e['state'] = $e['status'] === 'cancelled' ? 'cancelled' : ($e['remaining_minor'] <= 0 ? 'paid' : ($e['days_overdue'] > 0 ? 'overdue' : ($e['paid_minor'] > 0 ? 'partial' : 'open')));
            $e['rescheduled'] = $e['due_date'] !== $e['original_due_date'];
            if ($e['remaining_minor'] > 0) {
                $sums[$e['currency']][$e['kind']] = ($sums[$e['currency']][$e['kind']] ?? 0) + $e['remaining_minor'];
            }
        }
        Res::ok(['entries' => $rows, 'sums' => $sums, 'today' => $today]);
    }

    private static function entryCreate(Req $r, int $tid): never
    {
        $b = $r->body;
        $kind = (string)V::enum($b, 'kind', ['receivable', 'payable']);
        $ws = Fin::ownedId('workspaces', V::id($b['workspace_id'] ?? null, 'workspace_id'), $tid, 'workspace_id', true);
        $wk = (string)DB::val('SELECT kind FROM workspaces WHERE id=?', [$ws]);
        $source = (string)V::enum($b, 'source', ['manual', 'personal_bill'], false, 'manual');
        if ($source === 'personal_bill') {
            if ($wk !== 'personal') {
                throw new ApiError('validation', 'Şahsi fatura yalnızca şahsi alanda oluşturulabilir.', 422, 'workspace_id');
            }
            $kind = 'payable';
        }
        $amount = (int)V::amount($b, 'amount_minor');
        $due = (string)V::date($b, 'due_date');
        $n = (int)V::int($b, 'installments', 1, 120, false, 1);
        $cur = V::cur($b);
        $contact = Fin::ownedId('contacts', V::id($b['contact_id'] ?? null, 'contact_id', false), $tid, 'contact_id');
        $project = Fin::ownedId('projects', V::id($b['project_id'] ?? null, 'project_id', false), $tid, 'project_id');
        $cat = self::category(V::id($b['category_id'] ?? null, 'category_id', false), $tid, $kind === 'receivable' ? 'income' : 'expense');
        $title = (string)V::str($b, 'title', 200);
        $affects = $source === 'personal_bill' ? 1 : V::bool($b, 'affects_pl');
        $group = $n > 1 ? Util::rid() : null;
        if ($amount < $n) {
            throw new ApiError('validation', 'Tutar taksit sayısından küçük olamaz.', 422, 'amount_minor');
        }
        $ids = DB::tx(function () use ($tid, $kind, $ws, $source, $amount, $due, $n, $cur, $contact, $project, $cat, $title, $affects, $group, $b) {
            $base = intdiv($amount, $n);
            $ids = [];
            for ($i = 1; $i <= $n; $i++) {
                $amt = $i === $n ? $amount - $base * ($n - 1) : $base;
                $d = Util::addMonths($due, $i - 1, (int)substr($due, 8, 2));
                $ids[] = DB::insert('entries', [
                    'tenant_id' => $tid, 'workspace_id' => $ws, 'kind' => $kind, 'source' => $source, 'contact_id' => $contact, 'project_id' => $project, 'category_id' => $cat,
                    'group_id' => $group, 'seq' => $n > 1 ? $i : null, 'seq_total' => $n > 1 ? $n : null,
                    'title' => $n > 1 ? "$title ($i/$n)" : $title, 'provider' => V::str($b, 'provider', 120, false), 'period' => V::str($b, 'period', 20, false),
                    'currency' => $cur, 'amount_minor' => $amt, 'due_date' => $d, 'original_due_date' => $d, 'priority' => (int)V::int($b, 'priority', 1, 3, false, 2),
                    'affects_pl' => $affects, 'note' => V::str($b, 'note', 2000, false), 'created_at' => Util::now(),
                ]);
            }
            Audit::log('data', 'entry_created', ['tenant' => $tid, 'entity' => 'entry', 'id' => $ids[0], 'details' => ['kind' => $kind, 'amount_minor' => $amount, 'installments' => $n]]);
            return $ids;
        });
        Res::ok(['ids' => $ids], 201);
    }

    private static function entryRow(int $id, int $tid, bool $lock = false): array
    {
        $e = DB::one('SELECT e.*, COALESCE((SELECT SUM(amount_minor) FROM transactions WHERE entry_id=e.id AND status=\'posted\'),0) AS paid_minor FROM entries e WHERE e.id=? AND e.tenant_id=?' . ($lock ? ' FOR UPDATE' : ''), [$id, $tid]);
        if (!$e) {
            throw new ApiError('not_found', 'Kalem bulunamadı.', 404);
        }
        return $e;
    }

    private static function entryUpdate(Req $r, int $id, int $tid): never
    {
        $e = self::entryRow($id, $tid);
        if ($e['status'] === 'cancelled') {
            throw new ApiError('invalid_state', 'İptal edilmiş kalem düzenlenemez.', 409);
        }
        $b = $r->body;
        $u = [];
        if (isset($b['title'])) {
            $u['title'] = V::str($b, 'title', 200);
        }
        if (array_key_exists('note', $b)) {
            $u['note'] = V::str($b, 'note', 2000, false);
        }
        if (isset($b['priority'])) {
            $u['priority'] = V::int($b, 'priority', 1, 3);
        }
        if (isset($b['due_date'])) {
            $u['due_date'] = V::date($b, 'due_date');
        }
        foreach (['contact_id' => 'contacts', 'project_id' => 'projects'] as $k => $tb) {
            if (array_key_exists($k, $b)) {
                $u[$k] = Fin::ownedId($tb, V::id($b[$k], $k, false), $tid, $k);
            }
        }
        if (isset($b['amount_minor'])) {
            if ($e['source'] === 'invoice') {
                throw new ApiError('invalid_state', 'Fatura kaleminin tutarı faturadan gelir.', 409);
            }
            $amt = (int)V::amount($b, 'amount_minor');
            if ($amt < (int)$e['paid_minor']) {
                throw new ApiError('validation', 'Tutar, o ana kadar ödenen tutardan küçük olamaz.', 422, 'amount_minor');
            }
            $u['amount_minor'] = $amt;
        }
        if ($u) {
            DB::update('entries', $u, 'id=? AND tenant_id=?', [$id, $tid]);
            Audit::log('data', isset($u['due_date']) ? 'entry_rescheduled' : 'entry_updated', ['tenant' => $tid, 'entity' => 'entry', 'id' => $id, 'details' => ['before' => array_intersect_key($e, $u), 'after' => $u]]);
        }
        Res::ok();
    }

    private static function entryCancel(Req $r, int $id, int $tid): never
    {
        $reason = (string)V::str($r->body, 'reason', 300);
        DB::tx(function () use ($id, $tid, $reason) {
            $e = self::entryRow($id, $tid, true);
            if ($e['source'] === 'invoice') {
                throw new ApiError('invalid_state', 'Fatura kalemi, faturayı iptal ederek kapatılır.', 409);
            }
            if ((int)$e['paid_minor'] > 0) {
                throw new ApiError('has_payments', 'Ödemesi olan kalem iptal edilemez; önce ödemeleri iptal edin.', 409);
            }
            DB::exec('UPDATE entries SET status=\'cancelled\', cancelled_at=?, note=CONCAT(COALESCE(note,\'\'), ?) WHERE id=? AND tenant_id=?', [Util::now(), "\n[İptal: $reason]", $id, $tid]);
            Audit::log('data', 'entry_cancelled', ['tenant' => $tid, 'entity' => 'entry', 'id' => $id, 'details' => ['reason' => $reason, 'amount_minor' => (int)$e['amount_minor']]]);
        });
        Res::ok();
    }

    /** Tahsilat/ödeme: yalnızca kalan tutarı azaltır; kalan kontrolü kilit altında yapılır. */
    private static function settle(Req $r, int $id, int $tid): never
    {
        $b = $r->body;
        $acc = Fin::ownedId('accounts', V::id($b['account_id'] ?? null, 'account_id'), $tid, 'account_id', true);
        $amt = (int)V::amount($b, 'amount_minor');
        $date = (string)V::date($b + ['date' => Util::today()], 'date');
        $res = DB::tx(function () use ($tid, $id, $acc, $amt, $date, $b) {
            $e = self::entryRow($id, $tid, true);
            if ($e['status'] !== 'open') {
                throw new ApiError('invalid_state', 'İptal edilmiş kalem için işlem yapılamaz.', 409);
            }
            $a = DB::one('SELECT currency FROM accounts WHERE id=? AND tenant_id=?', [$acc, $tid]);
            if ($a['currency'] !== $e['currency']) {
                throw new ApiError('currency_mismatch', "Kalem para birimi {$e['currency']}; aynı para biriminde bir hesap seçin.", 422, 'account_id');
            }
            $remaining = (int)$e['amount_minor'] - (int)$e['paid_minor'];
            if ($amt > $remaining) {
                throw new ApiError('overpayment', 'Tutar kalan tutardan büyük olamaz.', 422, 'amount_minor', ['remaining_minor' => $remaining]);
            }
            $isRec = $e['kind'] === 'receivable';
            $tx = Fin::post($tid, [
                'account_id' => $acc, 'direction' => $isRec ? 'in' : 'out', 'kind' => $isRec ? 'collection' : 'payment', 'amount_minor' => $amt, 'txn_date' => $date,
                'description' => V::str($b, 'description', 300, false) ?? (($isRec ? 'Tahsilat: ' : 'Ödeme: ') . $e['title']),
                'entry_id' => $id, 'contact_id' => $e['contact_id'], 'project_id' => $e['project_id'],
            ]);
            Audit::log('data', $isRec ? 'collection_created' : 'payment_created', ['tenant' => $tid, 'entity' => 'entry', 'id' => $id, 'details' => ['txn' => $tx, 'amount_minor' => $amt]]);
            return ['id' => $tx, 'remaining_minor' => $remaining - $amt];
        });
        Res::ok($res, 201);
    }

    // ---- tekrarlayan şablonlar (yalnızca PLAN üretir; banka bakiyesine dokunmaz) ----
    private static function recurringCreate(Req $r, int $tid): never
    {
        $b = $r->body;
        $next = (string)V::date($b, 'next_date');
        $id = DB::insert('recurring_templates', [
            'tenant_id' => $tid, 'workspace_id' => Fin::ownedId('workspaces', V::id($b['workspace_id'] ?? null, 'workspace_id'), $tid, 'workspace_id', true),
            'entry_kind' => V::enum($b, 'entry_kind', ['receivable', 'payable']), 'title' => V::str($b, 'title', 200), 'amount_minor' => V::amount($b, 'amount_minor'),
            'currency' => V::cur($b), 'frequency' => V::enum($b, 'frequency', ['weekly', 'monthly', 'yearly'], false, 'monthly'),
            'category_id' => V::id($b['category_id'] ?? null, 'category_id', false) ? Fin::ownedId('categories', V::id($b['category_id'], 'category_id'), $tid, 'category_id') : null,
            'contact_id' => Fin::ownedId('contacts', V::id($b['contact_id'] ?? null, 'contact_id', false), $tid, 'contact_id'),
            'next_date' => $next, 'anchor_day' => (int)substr($next, 8, 2), 'end_date' => V::date($b, 'end_date', false), 'created_at' => Util::now(),
        ]);
        self::runRecurring($tid);
        Res::ok(['id' => $id], 201);
    }

    private static function recurringUpdate(Req $r, int $id, int $tid): never
    {
        if (!DB::val('SELECT id FROM recurring_templates WHERE id=? AND tenant_id=?', [$id, $tid])) {
            throw new ApiError('not_found', 'Şablon bulunamadı.', 404);
        }
        $u = [];
        if (isset($r->body['active'])) {
            $u['active'] = V::bool($r->body, 'active');
        }
        if (isset($r->body['amount_minor'])) {
            $u['amount_minor'] = V::amount($r->body, 'amount_minor');
        }
        if (array_key_exists('end_date', $r->body)) {
            $u['end_date'] = V::date($r->body, 'end_date', false);
        }
        if ($u) {
            DB::update('recurring_templates', $u, 'id=? AND tenant_id=?', [$id, $tid]);
        }
        Res::ok();
    }

    /** Bugünden 60 gün ileriye kadar planlı alacak/borç kalemleri üretir; (şablon, vade) tekil olduğundan tekrar üretmez. */
    public static function runRecurring(int $tid): int
    {
        $horizon = Util::addDays(Util::today(), 60);
        $n = 0;
        DB::tx(function () use ($tid, $horizon, &$n) {
            foreach (DB::all('SELECT * FROM recurring_templates WHERE tenant_id=? AND active=1 AND next_date<=? FOR UPDATE', [$tid, $horizon]) as $t) {
                $d = $t['next_date'];
                $guard = 0;
                while ($d <= $horizon && ($t['end_date'] === null || $d <= $t['end_date']) && $guard++ < 400) {
                    $n += DB::exec('INSERT IGNORE INTO entries (tenant_id,workspace_id,kind,source,contact_id,category_id,recurring_id,title,currency,amount_minor,due_date,original_due_date,affects_pl,created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
                        [$tid, $t['workspace_id'], $t['entry_kind'], 'recurring', $t['contact_id'], $t['category_id'], $t['id'], $t['title'], $t['currency'], $t['amount_minor'], $d, $d, 1, Util::now()]);
                    $d = match ($t['frequency']) {
                        'weekly' => Util::addDays($d, 7),
                        'yearly' => Util::addMonths($d, 12, (int)$t['anchor_day']),
                        default => Util::addMonths($d, 1, (int)$t['anchor_day']),
                    };
                }
                DB::exec('UPDATE recurring_templates SET next_date=?, last_generated=? WHERE id=?', [$d, Util::today(), $t['id']]);
            }
        });
        return $n;
    }
}
