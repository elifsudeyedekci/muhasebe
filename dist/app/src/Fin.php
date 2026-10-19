<?php
declare(strict_types=1);

namespace FB;

/** Paylaşılan finans hesapları. Bakiyeler her zaman defter (transactions) kayıtlarından hesaplanır. */
final class Fin
{
    /** Kalem başına ödenen tutar (aktif dağıtımlar, geçerli para hareketleri). Tek tenant yer tutucusu (?) içerir; takma ad çağıranda verilir. */
    public const PAID_SUB = "(SELECT a.entry_id, SUM(a.amount_minor) paid FROM payment_allocations a JOIN transactions pt ON pt.id=a.txn_id WHERE a.tenant_id=? AND a.status='active' AND pt.status='posted' GROUP BY a.entry_id)";

    public const DEFAULT_CATEGORIES = [
        'income' => ['Satış Geliri', 'Hizmet Geliri', 'Kira Geliri', 'Faiz Geliri', 'Diğer Gelirler'],
        'expense' => ['Yakıt', 'Ulaşım', 'Yemek', 'Malzeme', 'İşçilik', 'Taşeron', 'Kira', 'Abonelik', 'Banka Masrafı', 'Vergi', 'Sigorta', 'Diğer Giderler'],
    ];

    public static function seedCategories(int $tid): void
    {
        foreach (self::DEFAULT_CATEGORIES as $kind => $names) {
            foreach ($names as $n) {
                DB::exec('INSERT IGNORE INTO categories (tenant_id,kind,name) VALUES (?,?,?)', [$tid, $kind, $n]);
            }
        }
    }

    /** Hesap bakiyesi = açılış + posted hareketler. Açılış bakiyesi gelir değildir. */
    public static function accounts(int $tid, bool $withArchived = true): array
    {
        $sql = "SELECT a.*, w.name AS workspace_name, w.kind AS workspace_kind,
                a.opening_minor + COALESCE(SUM(CASE WHEN t.direction='in' THEN t.amount_minor ELSE -t.amount_minor END),0) AS balance_minor
                FROM accounts a JOIN workspaces w ON w.id=a.workspace_id
                LEFT JOIN transactions t ON t.account_id=a.id AND t.status='posted' AND t.tenant_id=a.tenant_id
                WHERE a.tenant_id=?" . ($withArchived ? '' : ' AND a.archived=0') . ' GROUP BY a.id ORDER BY a.archived, a.id';
        $rows = DB::all($sql, [$tid]);
        foreach ($rows as &$r) {
            foreach (['id', 'workspace_id', 'opening_minor', 'balance_minor', 'is_default', 'archived'] as $k) {
                $r[$k] = (int)$r[$k];
            }
            $r['project_id'] = $r['project_id'] !== null ? (int)$r['project_id'] : null;
        }
        return $rows;
    }

    public static function balancesByCurrency(int $tid, ?int $workspaceId = null): array
    {
        $out = [];
        foreach (self::accounts($tid, false) as $a) {
            if ($workspaceId !== null && $a['workspace_id'] !== $workspaceId) {
                continue;
            }
            $out[$a['currency']] = ($out[$a['currency']] ?? 0) + $a['balance_minor'];
        }
        return $out;
    }

    /** Açık alacak/borç kalemleri ve kalan tutarları. */
    public static function entriesOpen(int $tid, array $f = []): array
    {
        $w = ['e.tenant_id=?', "e.status='open'"];
        $p = [$tid];
        if (!empty($f['kind'])) {
            $w[] = 'e.kind=?';
            $p[] = $f['kind'];
        }
        if (!empty($f['workspace_id'])) {
            $w[] = 'e.workspace_id=?';
            $p[] = $f['workspace_id'];
        }
        $sql = "SELECT e.*, COALESCE(x.paid,0) AS paid_minor, e.amount_minor - COALESCE(x.paid,0) AS remaining_minor
                FROM entries e LEFT JOIN (SELECT a.entry_id, SUM(a.amount_minor) paid FROM payment_allocations a JOIN transactions pt ON pt.id=a.txn_id WHERE a.tenant_id=? AND a.status='active' AND pt.status='posted' GROUP BY a.entry_id) x ON x.entry_id=e.id
                WHERE " . implode(' AND ', $w);
        return DB::all($sql, [$tid, ...$p]);
    }

    public static function obligations(int $tid): array
    {
        $today = Util::today();
        $out = [];
        foreach (self::entriesOpen($tid) as $e) {
            $rem = (int)$e['remaining_minor'];
            if ($rem <= 0) {
                continue;
            }
            $c = $e['currency'];
            $out[$c] ??= ['receivable' => 0, 'payable' => 0, 'overdue_receivable' => 0, 'overdue_payable' => 0, 'due7_payable' => 0, 'due7_receivable' => 0];
            $out[$c][$e['kind']] += $rem;
            if ($e['due_date'] === null) {
                // vadesiz kalem: gecikmiş veya yaklaşan sayılmaz
            } elseif ($e['due_date'] < $today) {
                $out[$c]['overdue_' . $e['kind']] += $rem;
            } elseif ($e['due_date'] <= Util::addDays($today, 7)) {
                $out[$c]['due7_' . $e['kind']] += $rem;
            }
        }
        return $out;
    }

    /** Hesap hareketi ekler (doğrulama + tarih denetimi). */
    public static function post(int $tid, array $t): int
    {
        $acc = DB::one('SELECT * FROM accounts WHERE id=? AND tenant_id=?', [$t['account_id'], $tid]);
        if (!$acc) {
            throw new ApiError('not_found', 'Hesap bulunamadı.', 404, 'account_id');
        }
        if ((int)$acc['archived'] === 1) {
            throw new ApiError('archived', 'Arşivlenmiş hesapta işlem yapılamaz.', 422, 'account_id');
        }
        if ($t['txn_date'] < $acc['opening_date']) {
            throw new ApiError('before_opening', 'İşlem tarihi hesabın açılış tarihinden önce olamaz.', 422, 'txn_date');
        }
        if ($t['txn_date'] > Util::addDays(Util::today(), 1)) {
            throw new ApiError('future_date', 'Gerçekleşen işlem ileri tarihli olamaz; ileri tarihli ödemeler için ödeme planı kullanın.', 422, 'txn_date');
        }
        $t += [
            'tenant_id' => $tid, 'workspace_id' => $acc['workspace_id'], 'currency' => $acc['currency'],
            'status' => 'posted', 'created_by' => Auth::$user['id'] ?? null, 'created_at' => Util::now(),
        ];
        if ((int)$t['amount_minor'] <= 0) {
            throw new ApiError('validation', 'Tutar sıfırdan büyük olmalı.', 422, 'amount_minor');
        }
        return DB::insert('transactions', $t);
    }

    public static function ownedId(string $table, ?int $id, int $tid, string $field, bool $req = false): ?int
    {
        if ($id === null) {
            if ($req) {
                throw new ApiError('validation', 'Bu alan zorunludur.', 422, $field);
            }
            return null;
        }
        if (!DB::val("SELECT id FROM `$table` WHERE id=? AND tenant_id=?", [$id, $tid])) {
            throw new ApiError('not_found', 'Kayıt bulunamadı.', 404, $field);
        }
        return $id;
    }

    /** KDV dahil brüt tutardan KDV payını çıkarır. */
    public static function vatOfGross(int $gross, int $rateBp): int
    {
        return $rateBp <= 0 ? 0 : $gross - Money::mulDiv($gross, 10000, 10000 + $rateBp);
    }

    /** Hareketi iptal eder (silmez). Transfer ise iki bacak birlikte iptal edilir. */
    public static function voidTxn(int $tid, int $id, string $reason): array
    {
        return DB::tx(function () use ($tid, $id, $reason) {
            $t = DB::one('SELECT * FROM transactions WHERE id=? AND tenant_id=? FOR UPDATE', [$id, $tid]);
            if (!$t) {
                throw new ApiError('not_found', 'İşlem bulunamadı.', 404);
            }
            if ($t['status'] === 'voided') {
                throw new ApiError('already_voided', 'İşlem zaten iptal edilmiş.', 409);
            }
            $ids = [$id];
            if ($t['transfer_group']) {
                $ids = array_map(fn($r) => (int)$r['id'], DB::all('SELECT id FROM transactions WHERE tenant_id=? AND transfer_group=? AND status=\'posted\' FOR UPDATE', [$tid, $t['transfer_group']]));
            }
            foreach ($ids as $i) {
                DB::exec('UPDATE transactions SET status=\'voided\', void_reason=?, voided_at=?, voided_by=? WHERE id=? AND tenant_id=?', [$reason, Util::now(), Auth::$user['id'] ?? null, $i, $tid]);
            }
            if ($t['statement_line_id']) {
                DB::exec('UPDATE statement_lines SET status=\'unmatched\', matched_txn_id=NULL WHERE id=? AND tenant_id=?', [$t['statement_line_id'], $tid]);
            }
            Audit::log('data', 'transaction_voided', ['tenant' => $tid, 'entity' => 'transaction', 'id' => $id, 'details' => ['reason' => $reason, 'before' => ['kind' => $t['kind'], 'amount_minor' => (int)$t['amount_minor'], 'date' => $t['txn_date'], 'account_id' => (int)$t['account_id']], 'legs' => $ids]]);
            return $ids;
        });
    }

    /**
     * Başka kayıt tarafından kullanılmayan tanım/kart kaydını siler; kullanılıyorsa neden açıklanır ve arşivleme önerilir.
     * @param array<int,array{0:string,1:string}> $refs [tablo, sütun] çiftleri (tenant_id sütunu olan tablolar)
     */
    public static function deleteIfUnused(string $table, int $id, int $tid, string $label, array $refs): never
    {
        $row = DB::one("SELECT * FROM `$table` WHERE id=? AND tenant_id=?", [$id, $tid]);
        if (!$row) {
            throw new ApiError('not_found', "$label bulunamadı.", 404);
        }
        foreach ($refs as [$tb, $col]) {
            if (DB::val("SELECT 1 FROM `$tb` WHERE tenant_id=? AND `$col`=? LIMIT 1", [$tid, $id])) {
                throw new ApiError('in_use', "$label başka kayıtlarda kullanıldığı için silinemez; geçmiş korunur. Bunun yerine arşivleyin (yeni işlemlerde görünmez).", 409);
            }
        }
        DB::exec("DELETE FROM `$table` WHERE id=? AND tenant_id=?", [$id, $tid]);
        Audit::log('data', $table . '_deleted', ['tenant' => $tid, 'entity' => $table, 'id' => $id, 'details' => ['name' => $row['name'] ?? null]]);
        Res::ok();
    }

    /** Tüm müşteri verisini JSON dışa aktarım için toplar. */
    public static function exportTenant(int $tid): array
    {
        $tables = ['workspaces', 'accounts', 'categories', 'contacts', 'projects', 'project_quotes', 'project_milestones', 'entries', 'transactions', 'invoices', 'invoice_lines', 'stock_items', 'warehouses', 'stock_movements', 'recurring_templates', 'cards', 'card_purchases', 'card_installments', 'loans', 'loan_installments', 'statement_lines', 'budgets', 'fx_rates', 'payment_allocations'];
        $out = ['exported_at' => Util::now(), 'tenant_id' => $tid];
        foreach ($tables as $t) {
            $out[$t] = DB::all("SELECT * FROM `$t` WHERE tenant_id=? ORDER BY id", [$tid]);
        }
        $out['documents'] = DB::all('SELECT id,entity,entity_id,orig_name,mime,size_bytes,sha256,created_at FROM documents WHERE tenant_id=? AND deleted_at IS NULL', [$tid]);
        return $out;
    }
}
