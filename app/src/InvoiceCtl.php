<?php
declare(strict_types=1);

namespace FB;

/** Satış / alış faturaları. Fatura kesmek tahsilat değildir: yalnızca alacak/borç kalemi doğurur. */
final class InvoiceCtl
{
    public static function routes(Router $r): void
    {
        $t = ['auth' => 'tenant'];
        $ti = ['auth' => 'tenant', 'idem' => true];
        $r->add('GET', '/invoices', fn($q, $p, $tid) => self::list($q, $tid), $t);
        $r->add('POST', '/invoices', fn($q, $p, $tid) => self::create($q, $tid), $ti);
        $r->add('GET', '/invoices/{id}', fn($q, $p, $tid) => self::detail((int)$p['id'], $tid), $t);
        $r->add('POST', '/invoices/{id}/issue', fn($q, $p, $tid) => self::issuePlanned($q, (int)$p['id'], $tid), $ti);
        $r->add('POST', '/invoices/{id}/cancel', fn($q, $p, $tid) => self::cancel($q, (int)$p['id'], $tid), $t);
    }

    public static function line(array $l, int $i): array
    {
        $qty = (int)V::int($l, 'qty_milli', 1, 1000000000);
        $price = (int)V::int($l, 'unit_price_minor', 0, 1000000000);
        $disc = (int)V::int($l, 'discount_bp', 0, 10000, false, 0);
        $vatBp = (int)V::int($l, 'vat_rate_bp', 0, 10000, false, 0);
        $gross = Money::mulDiv($qty, $price, 1000);
        $net = $gross - Money::mulDiv($gross, $disc, 10000);
        $vat = Money::mulDiv($net, $vatBp, 10000);
        return [
            'seq' => $i, 'description' => V::str($l, 'description', 300), 'qty_milli' => $qty, 'unit' => V::str($l, 'unit', 20, false) ?? 'adet',
            'unit_price_minor' => $price, 'discount_bp' => $disc, 'vat_rate_bp' => $vatBp, 'net_minor' => $net, 'vat_minor' => $vat, 'total_minor' => $net + $vat,
        ];
    }

    private static function list(Req $r, int $tid): never
    {
        $q = $r->query;
        $w = ['i.tenant_id=?'];
        $p = [$tid];
        if (in_array($q['kind'] ?? '', ['sales', 'purchase'], true)) {
            $w[] = 'i.kind=?';
            $p[] = $q['kind'];
        }
        if (in_array($q['status'] ?? '', ['planned', 'issued', 'cancelled'], true)) {
            $w[] = 'i.status=?';
            $p[] = $q['status'];
        }
        foreach (['contact_id', 'project_id', 'workspace_id'] as $k) {
            if (!empty($q[$k])) {
                $w[] = "i.$k=?";
                $p[] = (int)$q[$k];
            }
        }
        if (!empty($q['scope']) && in_array($q['scope'], ['business', 'personal'], true) && empty($q['workspace_id'])) {
            $w[] = 'i.workspace_id IN (SELECT id FROM workspaces WHERE tenant_id=? AND kind=?)';
            array_push($p, $tid, $q['scope']);
        }
        if (!empty($q['from'])) {
            $w[] = 'i.issue_date>=?';
            $p[] = V::date($q, 'from');
        }
        if (!empty($q['to'])) {
            $w[] = 'i.issue_date<=?';
            $p[] = V::date($q, 'to');
        }
        if (!empty($q['q'])) {
            $w[] = '(i.number LIKE ? OR c.name LIKE ?)';
            $l = '%' . str_replace(['%', '_'], ['\\%', '\\_'], (string)$q['q']) . '%';
            array_push($p, $l, $l);
        }
        $rows = DB::all("SELECT i.id,i.kind,i.number,i.issue_date,i.due_date,i.currency,i.status,i.subtotal_minor,i.vat_minor,i.total_minor,i.contact_id,c.name AS contact_name,i.project_id,i.workspace_id,
                         e.id AS entry_id, COALESCE(x.paid,0) AS paid_minor
                         FROM invoices i JOIN contacts c ON c.id=i.contact_id LEFT JOIN entries e ON e.invoice_id=i.id AND e.status='open'
                         LEFT JOIN (SELECT entry_id, SUM(amount_minor) paid FROM transactions WHERE tenant_id=? AND status='posted' AND entry_id IS NOT NULL GROUP BY entry_id) x ON x.entry_id=e.id
                         WHERE " . implode(' AND ', $w) . ' ORDER BY i.issue_date DESC, i.id DESC LIMIT 1000', [$tid, ...$p]);
        $today = Util::today();
        foreach ($rows as &$x) {
            foreach (['id', 'subtotal_minor', 'vat_minor', 'total_minor', 'paid_minor', 'contact_id', 'workspace_id'] as $k) {
                $x[$k] = (int)$x[$k];
            }
            $x['remaining_minor'] = $x['status'] === 'issued' ? $x['total_minor'] - $x['paid_minor'] : 0;
            $x['payment_state'] = $x['status'] !== 'issued' ? $x['status'] : ($x['remaining_minor'] <= 0 ? 'paid' : ($x['paid_minor'] > 0 ? 'partial' : (($x['due_date'] ?? $x['issue_date']) < $today ? 'overdue' : 'unpaid')));
        }
        Res::ok(['invoices' => $rows]);
    }

    private static function create(Req $r, int $tid): never
    {
        $b = $r->body;
        $kind = (string)V::enum($b, 'kind', ['sales', 'purchase']);
        $ws = Fin::ownedId('workspaces', V::id($b['workspace_id'] ?? null, 'workspace_id'), $tid, 'workspace_id', true);
        $contact = Fin::ownedId('contacts', V::id($b['contact_id'] ?? null, 'contact_id'), $tid, 'contact_id', true);
        $project = Fin::ownedId('projects', V::id($b['project_id'] ?? null, 'project_id', false), $tid, 'project_id');
        $status = (string)V::enum($b, 'status', ['planned', 'issued'], false, 'issued');
        $issue = (string)V::date($b + ['issue_date' => Util::today()], 'issue_date');
        $due = V::date($b, 'due_date', false);
        if ($due !== null && $due < $issue) {
            throw new ApiError('validation', 'Vade tarihi fatura tarihinden önce olamaz.', 422, 'due_date');
        }
        $number = V::str($b, 'number', 60, false);
        if ($status === 'issued' && $kind === 'purchase' && $number === null) {
            throw new ApiError('validation', 'Alış faturasında fatura numarası zorunludur.', 422, 'number');
        }
        $lines = $b['lines'] ?? null;
        if (!is_array($lines) || count($lines) < 1 || count($lines) > 200) {
            throw new ApiError('validation', 'En az bir fatura satırı gerekli.', 422, 'lines');
        }
        $cur = V::cur($b);
        $res = DB::tx(function () use ($tid, $b, $kind, $ws, $contact, $project, $status, $issue, $due, $number, $lines, $cur) {
            $calc = [];
            $sub = 0;
            $vat = 0;
            foreach (array_values($lines) as $i => $l) {
                if (!is_array($l)) {
                    throw new ApiError('validation', 'Geçersiz satır.', 422, 'lines');
                }
                $c = self::line($l, $i + 1);
                $c['item_id'] = Fin::ownedId('stock_items', V::id($l['item_id'] ?? null, 'item_id', false), $tid, 'item_id');
                $c['warehouse_id'] = Fin::ownedId('warehouses', V::id($l['warehouse_id'] ?? null, 'warehouse_id', false), $tid, 'warehouse_id');
                $sub += $c['net_minor'];
                $vat += $c['vat_minor'];
                $calc[] = $c;
            }
            if ($sub + $vat > Money::MAX) {
                throw new ApiError('amount_too_large', 'Fatura tutarı çok büyük.', 422);
            }
            $dedupe = $number !== null ? Util::sha($kind . '|' . $contact . '|' . mb_strtolower($number)) : null;
            try {
                $id = DB::insert('invoices', [
                    'tenant_id' => $tid, 'workspace_id' => $ws, 'kind' => $kind, 'number' => $number, 'contact_id' => $contact, 'project_id' => $project,
                    'issue_date' => $issue, 'due_date' => $due, 'currency' => $cur, 'status' => $status, 'subtotal_minor' => $sub, 'vat_minor' => $vat, 'total_minor' => $sub + $vat,
                    'note' => V::str($b, 'note', 2000, false), 'created_by' => Auth::$user['id'], 'created_at' => Util::now(), 'dedupe_key' => $dedupe,
                ]);
            } catch (\PDOException $e) {
                if ($e->getCode() === '23000') {
                    throw new ApiError('duplicate_invoice', 'Bu cari için aynı numaralı fatura zaten kayıtlı.', 409, 'number');
                }
                throw $e;
            }
            foreach ($calc as $c) {
                DB::insert('invoice_lines', $c + ['tenant_id' => $tid, 'invoice_id' => $id]);
            }
            $warnings = [];
            if ($status === 'issued') {
                $warnings = self::materialize($tid, $id);
            }
            Audit::log('data', 'invoice_created', ['tenant' => $tid, 'entity' => 'invoice', 'id' => $id, 'details' => ['kind' => $kind, 'status' => $status, 'total_minor' => $sub + $vat]]);
            return ['id' => $id, 'warnings' => $warnings];
        });
        Res::ok($res, 201);
    }

    /** Kesilmiş faturanın tek alacak/borç kalemini ve stok hareketlerini oluşturur (idempotent: tekil anahtar). */
    private static function materialize(int $tid, int $invId): array
    {
        $inv = DB::one('SELECT * FROM invoices WHERE id=? AND tenant_id=?', [$invId, $tid]);
        $isSales = $inv['kind'] === 'sales';
        $contactName = (string)DB::val('SELECT name FROM contacts WHERE id=?', [$inv['contact_id']]);
        DB::insert('entries', [
            'tenant_id' => $tid, 'workspace_id' => $inv['workspace_id'], 'kind' => $isSales ? 'receivable' : 'payable', 'source' => 'invoice', 'contact_id' => $inv['contact_id'],
            'project_id' => $inv['project_id'], 'invoice_id' => $invId, 'invoice_key' => (string)$invId,
            'title' => ($isSales ? 'Satış faturası ' : 'Alış faturası ') . ($inv['number'] ?? '#' . $invId) . ' — ' . $contactName,
            'currency' => $inv['currency'], 'amount_minor' => $inv['total_minor'], 'due_date' => $inv['due_date'] ?? $inv['issue_date'], 'original_due_date' => $inv['due_date'] ?? $inv['issue_date'],
            'affects_pl' => 0, 'created_at' => Util::now(),
        ]);
        $warnings = [];
        foreach (DB::all('SELECT l.*, s.kind AS item_kind, s.name AS item_name FROM invoice_lines l LEFT JOIN stock_items s ON s.id=l.item_id WHERE l.invoice_id=? AND l.tenant_id=?', [$invId, $tid]) as $l) {
            if ($l['item_id'] === null || $l['item_kind'] !== 'product' || $l['warehouse_id'] === null) {
                continue;
            }
            if ($isSales) {
                $lv = StockCtl::levels($tid, (int)$l['item_id']);
                $avg = $lv[(int)$l['item_id']]['avg_cost_minor'] ?? 0;
                $have = $lv[(int)$l['item_id']]['qty_milli'] ?? 0;
                DB::insert('stock_movements', ['tenant_id' => $tid, 'item_id' => $l['item_id'], 'warehouse_id' => $l['warehouse_id'], 'kind' => 'out', 'qty_milli' => $l['qty_milli'], 'unit_cost_minor' => $avg, 'move_date' => $inv['issue_date'], 'project_id' => $inv['project_id'], 'invoice_id' => $invId, 'note' => 'Satış faturası', 'created_at' => Util::now()]);
                if ($have < (int)$l['qty_milli']) {
                    $warnings[] = "Negatif stok: {$l['item_name']}";
                }
            } else {
                $unit = Money::mulDiv((int)$l['net_minor'], 1000, (int)$l['qty_milli']);
                DB::insert('stock_movements', ['tenant_id' => $tid, 'item_id' => $l['item_id'], 'warehouse_id' => $l['warehouse_id'], 'kind' => 'in', 'qty_milli' => $l['qty_milli'], 'unit_cost_minor' => $unit, 'move_date' => $inv['issue_date'], 'project_id' => null, 'invoice_id' => $invId, 'note' => 'Alış faturası', 'created_at' => Util::now()]);
            }
        }
        return $warnings;
    }

    private static function issuePlanned(Req $r, int $id, int $tid): never
    {
        $res = DB::tx(function () use ($r, $id, $tid) {
            $inv = DB::one('SELECT * FROM invoices WHERE id=? AND tenant_id=? FOR UPDATE', [$id, $tid]);
            if (!$inv) {
                throw new ApiError('not_found', 'Fatura bulunamadı.', 404);
            }
            if ($inv['status'] !== 'planned') {
                throw new ApiError('invalid_state', 'Yalnızca planlanan fatura kesilebilir.', 409);
            }
            $number = V::str($r->body, 'number', 60, false) ?? $inv['number'];
            if ($number === null) {
                throw new ApiError('validation', 'Fatura numarası girin.', 422, 'number');
            }
            $issue = (string)V::date($r->body + ['issue_date' => Util::today()], 'issue_date');
            try {
                DB::update('invoices', ['status' => 'issued', 'number' => $number, 'issue_date' => $issue, 'due_date' => $inv['due_date'] !== null && $inv['due_date'] < $issue ? $issue : $inv['due_date'], 'dedupe_key' => Util::sha($inv['kind'] . '|' . $inv['contact_id'] . '|' . mb_strtolower($number))], 'id=? AND tenant_id=?', [$id, $tid]);
            } catch (\PDOException $e) {
                if ($e->getCode() === '23000') {
                    throw new ApiError('duplicate_invoice', 'Bu cari için aynı numaralı fatura zaten kayıtlı.', 409, 'number');
                }
                throw $e;
            }
            $w = self::materialize($tid, $id);
            Audit::log('data', 'invoice_issued', ['tenant' => $tid, 'entity' => 'invoice', 'id' => $id]);
            return ['warnings' => $w];
        });
        Res::ok($res);
    }

    private static function cancel(Req $r, int $id, int $tid): never
    {
        $reason = (string)V::str($r->body, 'reason', 300);
        DB::tx(function () use ($id, $tid, $reason) {
            $inv = DB::one('SELECT * FROM invoices WHERE id=? AND tenant_id=? FOR UPDATE', [$id, $tid]);
            if (!$inv) {
                throw new ApiError('not_found', 'Fatura bulunamadı.', 404);
            }
            if ($inv['status'] === 'cancelled') {
                throw new ApiError('already_cancelled', 'Fatura zaten iptal edilmiş.', 409);
            }
            if ($inv['status'] === 'issued') {
                $paid = (int)DB::val('SELECT COALESCE(SUM(t.amount_minor),0) FROM transactions t JOIN entries e ON e.id=t.entry_id WHERE e.invoice_id=? AND t.status=\'posted\' AND t.tenant_id=?', [$id, $tid]);
                if ($paid > 0) {
                    throw new ApiError('has_payments', 'Tahsilat/ödemesi olan fatura iptal edilemez; önce ilgili hareketleri iptal edin.', 409);
                }
                DB::exec('UPDATE entries SET status=\'cancelled\', cancelled_at=? WHERE invoice_id=? AND tenant_id=?', [Util::now(), $id, $tid]);
                DB::exec('UPDATE stock_movements SET status=\'voided\' WHERE invoice_id=? AND tenant_id=?', [$id, $tid]);
            }
            DB::exec('UPDATE invoices SET status=\'cancelled\', cancel_reason=?, cancelled_at=? WHERE id=? AND tenant_id=?', [$reason, Util::now(), $id, $tid]);
            Audit::log('data', 'invoice_cancelled', ['tenant' => $tid, 'entity' => 'invoice', 'id' => $id, 'details' => ['reason' => $reason, 'total_minor' => (int)$inv['total_minor'], 'was' => $inv['status']]]);
        });
        Res::ok();
    }

    private static function detail(int $id, int $tid): never
    {
        $inv = DB::one('SELECT i.*, c.name AS contact_name FROM invoices i JOIN contacts c ON c.id=i.contact_id WHERE i.id=? AND i.tenant_id=?', [$id, $tid]);
        if (!$inv) {
            throw new ApiError('not_found', 'Fatura bulunamadı.', 404);
        }
        $lines = DB::all('SELECT * FROM invoice_lines WHERE invoice_id=? AND tenant_id=? ORDER BY seq', [$id, $tid]);
        $entry = DB::one('SELECT * FROM entries WHERE invoice_id=? AND tenant_id=?', [$id, $tid]);
        $pays = $entry ? DB::all("SELECT id,txn_date,amount_minor,status,account_id FROM transactions WHERE entry_id=? AND tenant_id=? ORDER BY txn_date,id", [$entry['id'], $tid]) : [];
        $docs = DB::all("SELECT id,orig_name,mime,size_bytes,created_at FROM documents WHERE tenant_id=? AND entity='invoice' AND entity_id=? AND deleted_at IS NULL", [$tid, $id]);
        Res::ok(['invoice' => $inv, 'lines' => $lines, 'entry' => $entry, 'payments' => $pays, 'documents' => $docs]);
    }
}
