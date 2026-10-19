<?php
declare(strict_types=1);

namespace FB;

/**
 * Ödeme / tahsilat ve fatura-kalem dağıtımı.
 * Para hareketi (transactions: payment|collection) TEK kayıttır; faturalarla eşleştirmesi payment_allocations tablosunda tutulur.
 * Dağıtılmayan kısım cari hesapta "eşleştirilmemiş / avans" olarak kalır ve sonradan dağıtılabilir.
 * Dağıtımı değiştirmek para hareketi oluşturmaz; kasa/banka bakiyesine dokunmaz.
 */
final class PayCtl
{
    public const METHODS = ['cash' => 'Nakit', 'transfer' => 'Havale / EFT', 'card' => 'Kart', 'cheque' => 'Çek / senet', 'other' => 'Diğer'];

    public static function routes(Router $r): void
    {
        $t = ['auth' => 'tenant'];
        $ti = ['auth' => 'tenant', 'idem' => true];
        $r->add('GET', '/payments', fn($q, $p, $tid) => self::list($q, $tid), $t);
        $r->add('POST', '/payments', fn($q, $p, $tid) => self::create($q, $tid), $ti);
        $r->add('GET', '/payments/{id}', fn($q, $p, $tid) => self::detail((int)$p['id'], $tid), $t);
        $r->add('POST', '/payments/{id}/allocate', fn($q, $p, $tid) => self::allocate($q, (int)$p['id'], $tid, false), $ti);
        $r->add('PUT', '/payments/{id}/allocations', fn($q, $p, $tid) => self::allocate($q, (int)$p['id'], $tid, true), $t);
        $r->add('PATCH', '/payments/{id}', fn($q, $p, $tid) => self::meta($q, (int)$p['id'], $tid), $t);
        $r->add('POST', '/allocations/{id}/reverse', fn($q, $p, $tid) => self::reverse($q, (int)$p['id'], $tid), $t);
        $r->add('GET', '/home', fn($q, $p, $tid) => self::home($q, $tid), $t);
        $r->add('GET', '/owner-account', fn($q, $p, $tid) => self::ownerAccount($q->query, $tid), $t);
        $r->add('GET', '/reports/contacts', fn($q, $p, $tid) => self::contactReport($q->query, $tid), $t);
        $r->add('GET', '/reports/accounts', fn($q, $p, $tid) => self::accountReport($q->query, $tid), $t);
    }

    // ---------- yardımcılar ----------
    public static function allocatedOf(int $tid, int $txnId): int
    {
        return (int)DB::val("SELECT COALESCE(SUM(amount_minor),0) FROM payment_allocations WHERE tenant_id=? AND txn_id=? AND status='active'", [$tid, $txnId]);
    }

    public static function entryPaid(int $tid, int $entryId): int
    {
        return (int)DB::val("SELECT COALESCE(SUM(a.amount_minor),0) FROM payment_allocations a JOIN transactions t ON t.id=a.txn_id
                             WHERE a.tenant_id=? AND a.entry_id=? AND a.status='active' AND t.status='posted'", [$tid, $entryId]);
    }

    /**
     * Dağıtım satırlarını doğrular, kalemleri kilitler. Dönüş: [entry_id => ['entry' => satır, 'amount' => tutar]].
     * Kalan borçtan fazla dağıtım reddedilir (fazlası dağıtılmadan avans olarak kalır).
     */
    private static function checkAllocs(int $tid, string $side, string $currency, ?int $contact, mixed $in, int $maxTotal): array
    {
        if ($in === null || $in === []) {
            return [];
        }
        if (!is_array($in) || count($in) > 200) {
            throw new ApiError('validation', 'Geçersiz dağıtım listesi.', 422, 'allocations');
        }
        $want = [];
        foreach ($in as $a) {
            if (!is_array($a)) {
                throw new ApiError('validation', 'Geçersiz dağıtım satırı.', 422, 'allocations');
            }
            $eid = (int)V::id($a['entry_id'] ?? null, 'entry_id');
            $amt = (int)V::amount($a, 'amount_minor');
            if (isset($want[$eid])) {
                throw new ApiError('validation', 'Aynı kalem iki kez seçilemez.', 422, 'allocations');
            }
            $want[$eid] = $amt;
        }
        ksort($want);
        $out = [];
        $sum = 0;
        $needKind = $side === 'payment' ? 'payable' : 'receivable';
        foreach ($want as $eid => $amt) {
            $e = DB::one('SELECT * FROM entries WHERE id=? AND tenant_id=? FOR UPDATE', [$eid, $tid]);
            if (!$e) {
                throw new ApiError('not_found', 'Dağıtılacak kalem bulunamadı.', 404, 'allocations');
            }
            if ($e['status'] !== 'open') {
                throw new ApiError('invalid_state', 'İptal edilmiş kaleme dağıtım yapılamaz.', 409, 'allocations');
            }
            if ($e['kind'] !== $needKind) {
                throw new ApiError('direction_mismatch', $side === 'payment' ? 'Ödeme yalnızca borç (alış) kalemlerine dağıtılabilir.' : 'Tahsilat yalnızca alacak (satış) kalemlerine dağıtılabilir.', 422, 'allocations');
            }
            if ($e['currency'] !== $currency) {
                throw new ApiError('currency_mismatch', "Kalem para birimi {$e['currency']}; ödeme para birimi $currency.", 422, 'allocations');
            }
            if ($contact !== null && $e['contact_id'] !== null && (int)$e['contact_id'] !== $contact) {
                throw new ApiError('contact_mismatch', 'Seçilen kalem başka bir cariye ait.', 422, 'allocations');
            }
            $remaining = (int)$e['amount_minor'] - self::entryPaid($tid, $eid);
            if ($amt > $remaining) {
                throw new ApiError('overpayment', 'Dağıtılan tutar faturanın kalan tutarından büyük olamaz.', 422, 'allocations', ['entry_id' => $eid, 'remaining_minor' => $remaining]);
            }
            $sum += $amt;
            $out[$eid] = ['entry' => $e, 'amount' => $amt];
        }
        if ($sum > $maxTotal) {
            throw new ApiError('allocation_exceeds', 'Dağıtılan toplam, ödeme tutarını aşamaz.', 422, 'allocations', ['max_minor' => $maxTotal]);
        }
        return $out;
    }

    /**
     * Tek ödeme/tahsilat kaydı + isteğe bağlı dağıtım. Tek işlemde (transaction) çalışır.
     * $b: kind(payment|collection), account_id, amount_minor, date, contact_id?, allocations?, description?, reason?, reference?, method?, project_id?, for_workspace_id?
     * @return array{id:int,allocated_minor:int,unallocated_minor:int}
     */
    public static function record(int $tid, array $b): array
    {
        $side = (string)V::enum($b, 'kind', ['payment', 'collection']);
        $acc = (int)Fin::ownedId('accounts', V::id($b['account_id'] ?? null, 'account_id'), $tid, 'account_id', true);
        $amount = (int)V::amount($b, 'amount_minor');
        $date = (string)V::date($b + ['date' => Util::today()], 'date');
        $contactIn = Fin::ownedId('contacts', V::id($b['contact_id'] ?? null, 'contact_id', false), $tid, 'contact_id');
        $project = Fin::ownedId('projects', V::id($b['project_id'] ?? null, 'project_id', false), $tid, 'project_id');
        $method = V::enum($b, 'method', array_keys(self::METHODS), false);
        $reference = V::str($b, 'reference', 100, false);
        $reason = V::str($b, 'reason', 300, false);
        $desc = V::str($b, 'description', 300, false);
        $forIn = Fin::ownedId('workspaces', V::id($b['for_workspace_id'] ?? null, 'for_workspace_id', false), $tid, 'for_workspace_id');
        return DB::tx(function () use ($tid, $side, $acc, $amount, $date, $contactIn, $project, $method, $reference, $reason, $desc, $forIn, $b) {
            $a = DB::one('SELECT a.*, w.kind AS wkind FROM accounts a JOIN workspaces w ON w.id=a.workspace_id WHERE a.id=? AND a.tenant_id=?', [$acc, $tid]);
            $currency = $a['currency'];
            $contact = $contactIn;
            // önce dağıtım kalemlerinin cari tutarlılığı için cariyi kalemlerden türet
            $allocs = self::checkAllocs($tid, $side, $currency, $contact, $b['allocations'] ?? null, $amount);
            if ($contact === null && $allocs) {
                $cs = array_unique(array_map(fn($x) => $x['entry']['contact_id'], $allocs));
                if (count($cs) === 1 && $cs[0] !== null) {
                    $contact = (int)$cs[0];
                }
            }
            if ($contact === null && !$allocs && !empty($b['require_contact'])) {
                throw new ApiError('validation', 'Cari hesap seçin.', 422, 'contact_id');
            }
            // şahsi hesaptan işletme adına ödeme: işletme bankasına dokunmaz, ortak cari hesabını besler
            $forWs = null;
            if ($a['wkind'] === 'personal') {
                if ($forIn !== null) {
                    if (DB::val('SELECT kind FROM workspaces WHERE id=?', [$forIn]) !== 'business') {
                        throw new ApiError('validation', 'Hedef alan bir işletme olmalı.', 422, 'for_workspace_id');
                    }
                    $forWs = $forIn;
                } else {
                    $bw = [];
                    foreach ($allocs as $x) {
                        if (DB::val('SELECT kind FROM workspaces WHERE id=?', [$x['entry']['workspace_id']]) === 'business') {
                            $bw[(int)$x['entry']['workspace_id']] = true;
                        }
                    }
                    if (count($bw) > 1) {
                        throw new ApiError('validation', 'Şahsi hesaptan yapılan ödeme tek bir işletmeye dağıtılmalı.', 422, 'for_workspace_id');
                    }
                    $forWs = $bw ? (int)array_key_first($bw) : null;
                }
            }
            if ($reference !== null && empty($b['force']) && DB::val("SELECT id FROM transactions WHERE tenant_id=? AND account_id=? AND reference=? AND amount_minor=? AND status='posted' AND kind IN ('payment','collection') LIMIT 1", [$tid, $acc, $reference, $amount])) {
                throw new ApiError('duplicate_payment', 'Bu hesapta aynı referans ve tutarla bir kayıt zaten var (mükerrer olabilir).', 409, 'reference');
            }
            $isRec = $side === 'collection';
            $first = $allocs ? reset($allocs)['entry'] : null;
            $txn = Fin::post($tid, [
                'account_id' => $acc, 'direction' => $isRec ? 'in' : 'out', 'kind' => $side, 'amount_minor' => $amount, 'txn_date' => $date,
                'description' => $desc ?? (($isRec ? 'Tahsilat' : 'Ödeme') . ($first ? ': ' . $first['title'] : '')),
                'contact_id' => $contact, 'project_id' => $project ?? ($first['project_id'] ?? null),
                'reason' => $reason, 'reference' => $reference, 'method' => $method, 'for_workspace_id' => $forWs,
            ]);
            $alloc = 0;
            foreach ($allocs as $eid => $x) {
                DB::insert('payment_allocations', ['tenant_id' => $tid, 'txn_id' => $txn, 'entry_id' => $eid, 'amount_minor' => $x['amount'], 'status' => 'active', 'created_by' => Auth::$user['id'] ?? null, 'created_at' => Util::now()]);
                $alloc += $x['amount'];
            }
            Audit::log('data', $isRec ? 'collection_created' : 'payment_created', ['tenant' => $tid, 'entity' => 'transaction', 'id' => $txn, 'details' => [
                'amount_minor' => $amount, 'contact_id' => $contact, 'allocations' => array_map(fn($x) => $x['amount'], $allocs), 'unallocated_minor' => $amount - $alloc, 'for_workspace_id' => $forWs,
            ]]);
            return ['id' => $txn, 'allocated_minor' => $alloc, 'unallocated_minor' => $amount - $alloc];
        });
    }

    private static function create(Req $r, int $tid): never
    {
        $b = $r->body;
        $allocs = $b['allocations'] ?? [];
        if (empty($b['contact_id']) && !$allocs) {
            throw new ApiError('validation', 'Cari hesap seçin.', 422, 'contact_id');
        }
        $res = self::record($tid, $b);
        Res::ok($res, 201);
    }

    /** Dağıtılmamış tutarı kalemlere dağıtır; $replace ise mevcut aktif dağıtımları önce geri alır (tek işlemde). */
    private static function allocate(Req $r, int $id, int $tid, bool $replace): never
    {
        $res = DB::tx(function () use ($r, $id, $tid, $replace) {
            $t = DB::one('SELECT * FROM transactions WHERE id=? AND tenant_id=? FOR UPDATE', [$id, $tid]);
            if (!$t || !in_array($t['kind'], ['payment', 'collection'], true)) {
                throw new ApiError('not_found', 'Ödeme/tahsilat bulunamadı.', 404);
            }
            if ($t['status'] !== 'posted') {
                throw new ApiError('invalid_state', 'İptal edilmiş ödemeye dağıtım yapılamaz.', 409);
            }
            if ($replace) {
                DB::exec("UPDATE payment_allocations SET status='reversed', reversed_at=?, reversed_by=? WHERE tenant_id=? AND txn_id=? AND status='active'", [Util::now(), Auth::$user['id'] ?? null, $tid, $id]);
            }
            $free = (int)$t['amount_minor'] - self::allocatedOf($tid, $id);
            $contact = $t['contact_id'] !== null ? (int)$t['contact_id'] : null;
            $allocs = self::checkAllocs($tid, $t['kind'], $t['currency'], $contact, $r->body['allocations'] ?? null, $free);
            if (!$allocs && !$replace) {
                throw new ApiError('validation', 'En az bir dağıtım satırı gerekli.', 422, 'allocations');
            }
            $n = 0;
            foreach ($allocs as $eid => $x) {
                DB::insert('payment_allocations', ['tenant_id' => $tid, 'txn_id' => $id, 'entry_id' => $eid, 'amount_minor' => $x['amount'], 'status' => 'active', 'note' => V::str($r->body, 'note', 300, false), 'created_by' => Auth::$user['id'] ?? null, 'created_at' => Util::now()]);
                $n += $x['amount'];
            }
            if ($contact === null && $allocs) {
                $cs = array_unique(array_map(fn($x) => $x['entry']['contact_id'], $allocs));
                if (count($cs) === 1 && $cs[0] !== null) {
                    DB::exec('UPDATE transactions SET contact_id=? WHERE id=? AND tenant_id=?', [(int)$cs[0], $id, $tid]);
                }
            }
            $al = self::allocatedOf($tid, $id);
            Audit::log('data', $replace ? 'allocations_replaced' : 'payment_allocated', ['tenant' => $tid, 'entity' => 'transaction', 'id' => $id, 'details' => ['new_minor' => $n, 'allocated_minor' => $al, 'unallocated_minor' => (int)$t['amount_minor'] - $al]]);
            return ['allocated_minor' => $al, 'unallocated_minor' => (int)$t['amount_minor'] - $al];
        });
        Res::ok($res);
    }

    private static function reverse(Req $r, int $id, int $tid): never
    {
        DB::tx(function () use ($id, $tid) {
            $a = DB::one('SELECT * FROM payment_allocations WHERE id=? AND tenant_id=? FOR UPDATE', [$id, $tid]);
            if (!$a) {
                throw new ApiError('not_found', 'Dağıtım bulunamadı.', 404);
            }
            if ($a['status'] !== 'active') {
                throw new ApiError('already_reversed', 'Dağıtım zaten geri alınmış.', 409);
            }
            DB::exec("UPDATE payment_allocations SET status='reversed', reversed_at=?, reversed_by=? WHERE id=?", [Util::now(), Auth::$user['id'] ?? null, $id]);
            Audit::log('data', 'allocation_reversed', ['tenant' => $tid, 'entity' => 'transaction', 'id' => (int)$a['txn_id'], 'details' => ['entry_id' => (int)$a['entry_id'], 'amount_minor' => (int)$a['amount_minor']]]);
        });
        Res::ok();
    }

    /** Açıklama/referans/yöntem gibi tutarı etkilemeyen alanlar sonradan düzenlenebilir (hareket silinmez/değişmez). */
    private static function meta(Req $r, int $id, int $tid): never
    {
        $t = DB::one('SELECT * FROM transactions WHERE id=? AND tenant_id=?', [$id, $tid]);
        if (!$t) {
            throw new ApiError('not_found', 'İşlem bulunamadı.', 404);
        }
        $b = $r->body;
        $u = [];
        foreach (['description' => 300, 'reason' => 300, 'reference' => 100] as $k => $m) {
            if (array_key_exists($k, $b)) {
                $u[$k] = V::str($b, $k, $m, false);
            }
        }
        if (array_key_exists('method', $b)) {
            $u['method'] = V::enum($b, 'method', array_keys(self::METHODS), false);
        }
        if (array_key_exists('project_id', $b)) {
            $u['project_id'] = Fin::ownedId('projects', V::id($b['project_id'], 'project_id', false), $tid, 'project_id');
        }
        if ($u) {
            DB::update('transactions', $u, 'id=? AND tenant_id=?', [$id, $tid]);
            Audit::log('data', 'transaction_meta_updated', ['tenant' => $tid, 'entity' => 'transaction', 'id' => $id, 'details' => ['before' => array_intersect_key($t, $u), 'after' => $u]]);
        }
        Res::ok();
    }

    // ---------- okuma ----------
    private static function rows(array $q, int $tid, int $limit, int $offset): array
    {
        [$w, $p] = LedgerCtl::filters($q + ['status' => 'posted'], $tid);
        $w .= " AND t.kind IN ('payment','collection')";
        if (($q['alloc'] ?? '') === 'unallocated') {
            $w .= ' AND t.amount_minor > COALESCE((SELECT SUM(pa.amount_minor) FROM payment_allocations pa WHERE pa.txn_id=t.id AND pa.status=\'active\'),0)';
        } elseif (($q['alloc'] ?? '') === 'allocated') {
            $w .= ' AND EXISTS (SELECT 1 FROM payment_allocations pa WHERE pa.txn_id=t.id AND pa.status=\'active\')';
        }
        $rows = DB::all("SELECT t.id,t.txn_date,t.kind,t.direction,t.amount_minor,t.currency,t.status,t.description,t.reason,t.reference,t.method,t.account_id,a.name AS account_name,w.kind AS account_ws_kind,
                         t.contact_id,ct.name AS contact_name,t.project_id,t.for_workspace_id,t.created_by,u.display_name AS created_by_name,t.void_reason,
                         COALESCE((SELECT SUM(pa.amount_minor) FROM payment_allocations pa WHERE pa.txn_id=t.id AND pa.status='active'),0) AS allocated_minor,
                         (SELECT COUNT(*) FROM documents d WHERE d.tenant_id=t.tenant_id AND d.entity='transaction' AND d.entity_id=t.id AND d.deleted_at IS NULL) AS doc_count
                         FROM transactions t JOIN accounts a ON a.id=t.account_id JOIN workspaces w ON w.id=a.workspace_id LEFT JOIN contacts ct ON ct.id=t.contact_id LEFT JOIN users u ON u.id=t.created_by
                         WHERE $w ORDER BY t.txn_date DESC, t.id DESC LIMIT $limit OFFSET $offset", $p);
        $count = (int)DB::val("SELECT COUNT(*) FROM transactions t WHERE $w", $p);
        foreach ($rows as &$x) {
            foreach (['id', 'amount_minor', 'allocated_minor', 'account_id', 'doc_count'] as $k) {
                $x[$k] = (int)$x[$k];
            }
            $x['unallocated_minor'] = $x['amount_minor'] - $x['allocated_minor'];
            $x['allocation_state'] = $x['allocated_minor'] <= 0 ? 'unallocated' : ($x['unallocated_minor'] > 0 ? 'partial' : 'full');
            $x['personal_source'] = $x['account_ws_kind'] === 'personal';
        }
        return [$rows, $count];
    }

    /** Ödeme/tahsilat listesi ve raporu: her ödeme TEK satırdır (dağıtım satırları ayrı ödeme sayılmaz). */
    private static function list(Req $r, int $tid): never
    {
        $limit = min(500, max(1, (int)($r->query['limit'] ?? 100)));
        $off = max(0, (int)($r->query['offset'] ?? 0));
        [$rows, $count] = self::rows($r->query, $tid, $limit, $off);
        $sums = [];
        foreach ($rows as $x) {
            $c = $x['currency'];
            $sums[$c][$x['kind']]['total_minor'] = ($sums[$c][$x['kind']]['total_minor'] ?? 0) + $x['amount_minor'];
            $sums[$c][$x['kind']]['allocated_minor'] = ($sums[$c][$x['kind']]['allocated_minor'] ?? 0) + $x['allocated_minor'];
            $sums[$c][$x['kind']]['unallocated_minor'] = ($sums[$c][$x['kind']]['unallocated_minor'] ?? 0) + $x['unallocated_minor'];
        }
        Res::ok(['payments' => $rows, 'count' => $count, 'sums' => $sums, 'sums_note' => 'Toplamlar yalnızca bu sayfadaki satırları kapsar.']);
    }

    private static function detail(int $id, int $tid): never
    {
        $t = DB::one("SELECT t.*, a.name AS account_name, w.kind AS account_ws_kind, w.name AS account_ws_name, ct.name AS contact_name, u.display_name AS created_by_name, fw.name AS for_workspace_name
                      FROM transactions t JOIN accounts a ON a.id=t.account_id JOIN workspaces w ON w.id=a.workspace_id LEFT JOIN contacts ct ON ct.id=t.contact_id
                      LEFT JOIN users u ON u.id=t.created_by LEFT JOIN workspaces fw ON fw.id=t.for_workspace_id WHERE t.id=? AND t.tenant_id=?", [$id, $tid]);
        if (!$t) {
            throw new ApiError('not_found', 'İşlem bulunamadı.', 404);
        }
        $al = DB::all("SELECT pa.id,pa.entry_id,pa.amount_minor,pa.status,pa.created_at,pa.reversed_at,e.title,e.due_date,e.invoice_id,e.currency,i.number AS invoice_number
                       FROM payment_allocations pa JOIN entries e ON e.id=pa.entry_id LEFT JOIN invoices i ON i.id=e.invoice_id
                       WHERE pa.tenant_id=? AND pa.txn_id=? ORDER BY pa.id", [$tid, $id]);
        $active = 0;
        foreach ($al as &$x) {
            $x['id'] = (int)$x['id'];
            $x['amount_minor'] = (int)$x['amount_minor'];
            if ($x['status'] === 'active') {
                $active += $x['amount_minor'];
            }
        }
        $docs = DB::all("SELECT id,orig_name,mime,size_bytes,created_at FROM documents WHERE tenant_id=? AND entity='transaction' AND entity_id=? AND deleted_at IS NULL", [$tid, $id]);
        $t['allocated_minor'] = $active;
        $t['unallocated_minor'] = (int)$t['amount_minor'] - $active;
        Res::ok(['payment' => $t, 'allocations' => $al, 'documents' => $docs]);
    }

    // ---------- ortak cari (işletme sahibi ↔ işletme) ----------
    /**
     * Bakiye > 0: işletme, işletme sahibine borçlu. Hareketler:
     *  + işletmeye para koyma (owner_in)   + şahsi hesaptan işletme adına ödeme   − şahsi hesaba giren işletme tahsilatı   − işletmeden para çekme/geri ödeme (owner_out)
     * İşletme banka bakiyesi yalnızca işletme hesabındaki gerçek hareketlerle değişir.
     */
    public static function ownerMovements(int $tid, array $q = []): array
    {
        $w = ['t.tenant_id=?', "t.status='posted'"];
        $p = [$tid];
        if (!empty($q['workspace_id'])) {
            $w[] = 'COALESCE(t.for_workspace_id, a.workspace_id)=?';
            $p[] = (int)$q['workspace_id'];
        }
        if (!empty($q['from'])) {
            $w[] = 't.txn_date>=?';
            $p[] = V::date($q, 'from');
        }
        if (!empty($q['to'])) {
            $w[] = 't.txn_date<=?';
            $p[] = V::date($q, 'to');
        }
        $rows = DB::all("SELECT t.id,t.txn_date,t.kind,t.direction,t.amount_minor,t.currency,t.description,t.reason,t.reference,t.contact_id,ct.name AS contact_name,
                                COALESCE(t.for_workspace_id, a.workspace_id) AS ws_id, a.name AS account_name, w.kind AS acc_kind,
                                COALESCE((SELECT SUM(pa.amount_minor) FROM payment_allocations pa WHERE pa.txn_id=t.id AND pa.status='active'),0) AS allocated_minor
                         FROM transactions t JOIN accounts a ON a.id=t.account_id JOIN workspaces w ON w.id=a.workspace_id LEFT JOIN contacts ct ON ct.id=t.contact_id
                         WHERE " . implode(' AND ', $w) . " AND (
                              (t.kind IN ('owner_in','owner_out') AND w.kind='business')
                              OR (t.for_workspace_id IS NOT NULL AND w.kind='personal' AND t.kind IN ('payment','collection','expense','income'))
                         ) ORDER BY t.txn_date, t.id", $p);
        $out = [];
        foreach ($rows as $r) {
            $amt = (int)$r['amount_minor'];
            if ($r['kind'] === 'owner_in') {
                $sign = 1;
                $type = 'Sahipten işletmeye para';
            } elseif ($r['kind'] === 'owner_out') {
                $sign = -1;
                $type = 'İşletmeden sahibe para';
            } elseif ($r['direction'] === 'out') {
                $sign = 1;
                $type = 'Şahsi hesaptan işletme adına ödeme';
            } else {
                $sign = -1;
                $type = 'İşletme tahsilatı şahsi hesaba girdi';
            }
            $out[] = ['txn_id' => (int)$r['id'], 'date' => $r['txn_date'], 'type' => $type, 'kind' => $r['kind'], 'currency' => $r['currency'], 'workspace_id' => (int)$r['ws_id'], 'account_name' => $r['account_name'],
                'signed_minor' => $sign * $amt, 'description' => $r['description'], 'reason' => $r['reason'], 'reference' => $r['reference'], 'contact_id' => $r['contact_id'] !== null ? (int)$r['contact_id'] : null, 'contact_name' => $r['contact_name'],
                'invoice_linked' => (int)$r['allocated_minor'] > 0];
        }
        return $out;
    }

    private static function ownerAccount(array $q, int $tid): never
    {
        $rows = self::ownerMovements($tid, $q);
        $bal = [];
        foreach ($rows as &$r) {
            $k = $r['workspace_id'] . '|' . $r['currency'];
            $bal[$k] = ($bal[$k] ?? 0) + $r['signed_minor'];
            $r['balance_minor'] = $bal[$k];
        }
        $names = array_column(DB::all('SELECT id,name FROM workspaces WHERE tenant_id=?', [$tid]), 'name', 'id');
        $sum = [];
        foreach ($bal as $k => $v) {
            [$ws, $cur] = explode('|', $k);
            $sum[] = ['workspace_id' => (int)$ws, 'workspace_name' => $names[(int)$ws] ?? '', 'currency' => $cur, 'balance_minor' => $v];
        }
        Res::ok(['rows' => array_reverse($rows), 'balances' => $sum, 'note' => 'Pozitif: işletme, işletme sahibine borçlu. Negatif: sahip işletmeye borçlu. Bu bakiye işletme kasa/banka bakiyesine dahil değildir.']);
    }

    // ---------- raporlar ----------
    /** Cari hesap raporu: fatura toplamı, ödenen (dağıtılmış), eşleştirilmemiş/avans ve kalan — para birimi ayrı. */
    private static function contactReport(array $q, int $tid): never
    {
        $kind = ($q['kind'] ?? '') === 'supplier' ? 'supplier' : (($q['kind'] ?? '') === 'customer' ? 'customer' : null);
        $from = V::date($q, 'from', false);
        $to = V::date($q, 'to', false);
        $ek = $kind === 'supplier' ? 'payable' : ($kind === 'customer' ? 'receivable' : null);
        $w = "e.tenant_id=? AND e.status='open' AND e.contact_id IS NOT NULL" . ($ek ? ' AND e.kind=?' : '');
        $p = $ek ? [$tid, $ek] : [$tid];
        if ($from) {
            $w .= ' AND COALESCE((SELECT i.issue_date FROM invoices i WHERE i.id=e.invoice_id), DATE(e.created_at))>=?';
            $p[] = $from;
        }
        if ($to) {
            $w .= ' AND COALESCE((SELECT i.issue_date FROM invoices i WHERE i.id=e.invoice_id), DATE(e.created_at))<=?';
            $p[] = $to;
        }
        $rows = DB::all("SELECT e.contact_id, e.currency, e.kind, SUM(e.amount_minor) billed, SUM(COALESCE(x.paid,0)) paid
                         FROM entries e LEFT JOIN " . Fin::PAID_SUB . " x ON x.entry_id=e.id WHERE $w GROUP BY e.contact_id, e.currency, e.kind", [$tid, ...$p]);
        $pw = "t.tenant_id=? AND t.status='posted' AND t.kind IN ('payment','collection') AND t.contact_id IS NOT NULL" . ($from ? ' AND t.txn_date>=?' : '') . ($to ? ' AND t.txn_date<=?' : '');
        $pp = [$tid];
        $from && $pp[] = $from;
        $to && $pp[] = $to;
        $un = DB::all("SELECT t.contact_id, t.currency, t.kind, SUM(t.amount_minor - COALESCE((SELECT SUM(pa.amount_minor) FROM payment_allocations pa WHERE pa.txn_id=t.id AND pa.status='active'),0)) unalloc, SUM(t.amount_minor) total
                       FROM transactions t WHERE $pw GROUP BY t.contact_id, t.currency, t.kind", $pp);
        $names = array_column(DB::all('SELECT id,name FROM contacts WHERE tenant_id=?', [$tid]), 'name', 'id');
        $out = [];
        foreach ($rows as $r) {
            $k = $r['contact_id'] . '|' . $r['currency'];
            $out[$k] ??= ['contact_id' => (int)$r['contact_id'], 'contact' => $names[(int)$r['contact_id']] ?? '—', 'currency' => $r['currency'], 'receivable_billed_minor' => 0, 'receivable_paid_minor' => 0, 'payable_billed_minor' => 0, 'payable_paid_minor' => 0, 'advance_in_minor' => 0, 'advance_out_minor' => 0, 'payments_total_minor' => 0, 'collections_total_minor' => 0];
            $out[$k][$r['kind'] . '_billed_minor'] += (int)$r['billed'];
            $out[$k][$r['kind'] . '_paid_minor'] += (int)$r['paid'];
        }
        foreach ($un as $r) {
            if ($ek && ($r['kind'] === 'payment') !== ($ek === 'payable')) {
                continue;
            }
            $k = $r['contact_id'] . '|' . $r['currency'];
            $out[$k] ??= ['contact_id' => (int)$r['contact_id'], 'contact' => $names[(int)$r['contact_id']] ?? '—', 'currency' => $r['currency'], 'receivable_billed_minor' => 0, 'receivable_paid_minor' => 0, 'payable_billed_minor' => 0, 'payable_paid_minor' => 0, 'advance_in_minor' => 0, 'advance_out_minor' => 0, 'payments_total_minor' => 0, 'collections_total_minor' => 0];
            if ($r['kind'] === 'payment') {
                $out[$k]['advance_out_minor'] += (int)$r['unalloc'];
                $out[$k]['payments_total_minor'] += (int)$r['total'];
            } else {
                $out[$k]['advance_in_minor'] += (int)$r['unalloc'];
                $out[$k]['collections_total_minor'] += (int)$r['total'];
            }
        }
        foreach ($out as &$o) {
            $o['receivable_remaining_minor'] = $o['receivable_billed_minor'] - $o['receivable_paid_minor'];
            $o['payable_remaining_minor'] = $o['payable_billed_minor'] - $o['payable_paid_minor'];
            // pozitif: cari bize borçlu; negatif: biz cariye borçluyuz (avanslar düşülerek)
            $o['net_minor'] = $o['receivable_remaining_minor'] - $o['advance_in_minor'] - $o['payable_remaining_minor'] + $o['advance_out_minor'];
        }
        $rows = array_values($out);
        usort($rows, fn($a, $b) => abs($b['net_minor']) <=> abs($a['net_minor']));
        Res::ok(['rows' => $rows, 'note' => 'Pozitif net: cari size borçlu; negatif net: siz cariye borçlusunuz. Avanslar (eşleştirilmemiş ödemeler) net bakiyeden düşülür.']);
    }

    /** Kasa/banka raporu: dönem açılışı, girişler, çıkışlar, transferler, kapanış. */
    private static function accountReport(array $q, int $tid): never
    {
        $today = Util::today();
        $from = V::date($q, 'from', false) ?? substr($today, 0, 8) . '01';
        $to = V::date($q, 'to', false) ?? $today;
        $accs = Fin::accounts($tid);
        $rows = [];
        foreach ($accs as $a) {
            $m = DB::one("SELECT
                COALESCE(SUM(CASE WHEN txn_date<? THEN IF(direction='in',amount_minor,-amount_minor) END),0) before_minor,
                COALESCE(SUM(CASE WHEN txn_date BETWEEN ? AND ? AND direction='in' AND kind NOT IN ('transfer','fx','owner_in','owner_out') THEN amount_minor END),0) in_minor,
                COALESCE(SUM(CASE WHEN txn_date BETWEEN ? AND ? AND direction='out' AND kind NOT IN ('transfer','fx','owner_in','owner_out') THEN amount_minor END),0) out_minor,
                COALESCE(SUM(CASE WHEN txn_date BETWEEN ? AND ? AND direction='in' AND kind IN ('transfer','fx','owner_in','owner_out') THEN amount_minor END),0) transfer_in_minor,
                COALESCE(SUM(CASE WHEN txn_date BETWEEN ? AND ? AND direction='out' AND kind IN ('transfer','fx','owner_in','owner_out') THEN amount_minor END),0) transfer_out_minor
                FROM transactions WHERE tenant_id=? AND account_id=? AND status='posted'", [$from, $from, $to, $from, $to, $from, $to, $from, $to, $tid, $a['id']]);
            $open = $a['opening_date'] <= $to ? $a['opening_minor'] : 0;
            $opening = ($a['opening_date'] < $from ? $a['opening_minor'] : 0) + (int)$m['before_minor'];
            $rows[] = ['account_id' => $a['id'], 'name' => $a['name'], 'currency' => $a['currency'], 'kind' => $a['kind'], 'workspace_kind' => $a['workspace_kind'], 'archived' => $a['archived'],
                'opening_minor' => $opening, 'in_minor' => (int)$m['in_minor'], 'out_minor' => (int)$m['out_minor'], 'transfer_in_minor' => (int)$m['transfer_in_minor'], 'transfer_out_minor' => (int)$m['transfer_out_minor'],
                'closing_minor' => $opening + (int)$m['in_minor'] - (int)$m['out_minor'] + (int)$m['transfer_in_minor'] - (int)$m['transfer_out_minor'] + ($a['opening_date'] >= $from && $a['opening_date'] <= $to ? $open : 0)];
        }
        Res::ok(['from' => $from, 'to' => $to, 'rows' => $rows]);
    }

    // ---------- ana sayfa ----------
    /**
     * Ana sayfa özeti. Yalnızca İŞLETME alanları: şahsi hesaplar kasa/banka toplamına, şahsi faturalar borca karışmaz.
     * Alacak/borç = açık kalemlerin KALANI (aktif dağıtımlar düşülmüş; her fatura tek kalem → tek sayım). Avanslar ayrı gösterilir.
     * Bekleyen listesi anahtar tarihi: planlanan tarih varsa o, yoksa vade; ikisi de yoksa “tarih girilmemiş” (gecikmiş sayılmaz).
     */
    private static function home(Req $r, int $tid): never
    {
        $today = Util::today();
        $accs = array_values(array_filter(Fin::accounts($tid, false), fn($a) => $a['workspace_kind'] === 'business'));
        $cash = [];
        foreach ($accs as $a) {
            $cash[$a['currency']] = ($cash[$a['currency']] ?? 0) + $a['balance_minor'];
        }
        $rows = DB::all("SELECT e.id,e.kind,e.title,e.currency,e.amount_minor,e.due_date,e.planned_date,e.invoice_id,e.source,e.contact_id,c.name AS contact_name,i.number AS invoice_number,
                                COALESCE(x.paid,0) AS paid_minor
                         FROM entries e JOIN workspaces w ON w.id=e.workspace_id AND w.kind='business'
                         LEFT JOIN contacts c ON c.id=e.contact_id LEFT JOIN invoices i ON i.id=e.invoice_id
                         LEFT JOIN " . Fin::PAID_SUB . " x ON x.entry_id=e.id
                         WHERE e.tenant_id=? AND e.status='open' AND e.source<>'personal_bill'", [$tid, $tid]);
        $tot = ['receivable' => [], 'payable' => []];
        $lists = ['receivable' => [], 'payable' => []];
        $todayCnt = ['receivable' => 0, 'payable' => 0];
        $overdueCnt = ['receivable' => 0, 'payable' => 0];
        $todaySum = ['receivable' => [], 'payable' => []];
        foreach ($rows as $e) {
            $rem = (int)$e['amount_minor'] - (int)$e['paid_minor'];
            if ($rem <= 0) {
                continue;
            }
            $k = $e['kind'];
            $tot[$k][$e['currency']] = ($tot[$k][$e['currency']] ?? 0) + $rem;
            $key = $e['planned_date'] ?? $e['due_date'];
            $state = $key === null ? 'undated' : ($key < $today ? 'overdue' : ($key === $today ? 'today' : 'upcoming'));
            if ($state === 'today') {
                $todayCnt[$k]++;
                $todaySum[$k][$e['currency']] = ($todaySum[$k][$e['currency']] ?? 0) + $rem;
            } elseif ($state === 'overdue') {
                $overdueCnt[$k]++;
            }
            $lists[$k][] = ['id' => (int)$e['id'], 'party' => $e['contact_name'] ?: '(Cari yok)', 'contact_id' => $e['contact_id'] !== null ? (int)$e['contact_id'] : null, 'title' => $e['title'],
                'invoice_id' => $e['invoice_id'] !== null ? (int)$e['invoice_id'] : null, 'invoice_number' => $e['invoice_number'], 'currency' => $e['currency'], 'amount_minor' => (int)$e['amount_minor'],
                'paid_minor' => (int)$e['paid_minor'], 'remaining_minor' => $rem, 'due_date' => $e['due_date'], 'planned_date' => $e['planned_date'], 'key_date' => $key, 'state' => $state,
                'days' => $key === null ? null : (int)((strtotime($key) - strtotime($today)) / 86400), 'partial' => (int)$e['paid_minor'] > 0];
        }
        $ord = ['overdue' => 0, 'today' => 1, 'upcoming' => 2, 'undated' => 3];
        $out = [];
        foreach ($lists as $k => $l) {
            usort($l, fn($a, $b) => [$ord[$a['state']], $a['key_date'] ?? '9999', $a['id']] <=> [$ord[$b['state']], $b['key_date'] ?? '9999', $b['id']]);
            $out[$k] = ['count' => count($l), 'items' => array_slice($l, 0, 8)];
        }
        // eşleştirilmemiş avanslar (ayrı kavram; fatura borcu/alacağı değildir)
        $adv = ['in' => [], 'out' => []];
        foreach (DB::all("SELECT t.currency, t.kind, SUM(t.amount_minor - COALESCE((SELECT SUM(pa.amount_minor) FROM payment_allocations pa WHERE pa.txn_id=t.id AND pa.status='active'),0)) un
                          FROM transactions t JOIN accounts a ON a.id=t.account_id JOIN workspaces w ON w.id=a.workspace_id AND w.kind='business'
                          WHERE t.tenant_id=? AND t.status='posted' AND t.kind IN ('payment','collection') AND t.contact_id IS NOT NULL GROUP BY t.currency, t.kind", [$tid]) as $a) {
            if ((int)$a['un'] > 0) {
                $adv[$a['kind'] === 'collection' ? 'in' : 'out'][$a['currency']] = (int)$a['un'];
            }
        }
        $recent = DB::all("SELECT t.id,t.txn_date,t.kind,t.direction,t.amount_minor,t.currency,t.description,t.reason,a.name AS account_name,w.kind AS wkind,ct.name AS contact_name
                           FROM transactions t JOIN accounts a ON a.id=t.account_id JOIN workspaces w ON w.id=a.workspace_id LEFT JOIN contacts ct ON ct.id=t.contact_id
                           WHERE t.tenant_id=? AND t.status='posted' AND t.kind NOT IN ('adjustment') ORDER BY t.txn_date DESC, t.id DESC LIMIT 8", [$tid]);
        foreach ($recent as &$x) {
            $x['id'] = (int)$x['id'];
            $x['amount_minor'] = (int)$x['amount_minor'];
        }
        Res::ok(['today' => $today, 'cash' => ['totals' => $cash, 'accounts' => array_map(fn($a) => ['id' => $a['id'], 'name' => $a['name'], 'kind' => $a['kind'], 'currency' => $a['currency'], 'balance_minor' => $a['balance_minor'], 'workspace_name' => $a['workspace_name']], $accs)],
            'receivable' => ['total' => $tot['receivable']] + $out['receivable'], 'payable' => ['total' => $tot['payable']] + $out['payable'],
            'today_summary' => ['receivable_count' => $todayCnt['receivable'], 'payable_count' => $todayCnt['payable'], 'receivable_sum' => $todaySum['receivable'], 'payable_sum' => $todaySum['payable'], 'overdue_receivable' => $overdueCnt['receivable'], 'overdue_payable' => $overdueCnt['payable']],
            'advances' => $adv, 'recent' => $recent, 'has_accounts' => count($accs) > 0]);
    }
}
