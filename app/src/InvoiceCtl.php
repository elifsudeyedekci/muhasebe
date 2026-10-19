<?php
declare(strict_types=1);

namespace FB;

/**
 * Satış / alış faturaları. Fatura kesmek tahsilat değildir: yalnızca alacak/borç kalemi doğurur.
 * Ödeme (payment_allocations), stok/teslimat (invoice_lines.fulfilled_milli + stock_movements) ve fatura birbirinden ayrı izlenir.
 */
final class InvoiceCtl
{
    public static function routes(Router $r): void
    {
        $t = ['auth' => 'tenant'];
        $ti = ['auth' => 'tenant', 'idem' => true];
        $r->add('GET', '/invoices', fn($q, $p, $tid) => self::list($q, $tid), $t);
        $r->add('POST', '/invoices', fn($q, $p, $tid) => self::create($q, $tid), $ti);
        $r->add('GET', '/invoices/{id}', fn($q, $p, $tid) => self::detail((int)$p['id'], $tid), $t);
        $r->add('PATCH', '/invoices/{id}', fn($q, $p, $tid) => self::update($q, (int)$p['id'], $tid), $t);
        $r->add('DELETE', '/invoices/{id}', fn($q, $p, $tid) => self::delete((int)$p['id'], $tid), $t);
        $r->add('POST', '/invoices/{id}/issue', fn($q, $p, $tid) => self::issuePlanned($q, (int)$p['id'], $tid), $ti);
        $r->add('POST', '/invoices/{id}/deliver', fn($q, $p, $tid) => self::deliver($q, (int)$p['id'], $tid), $ti);
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

    /**
     * Satırları doğrular/hesaplar; ürün çözümler (mevcut kart, yeni kart veya serbest/hizmet satırı).
     * Stok takibi: yalnızca ürün (product) kartı + depo olan satırlar. Hizmet ve serbest satırlar stok ürünü gibi işlenmez.
     */
    private static function parseLines(int $tid, mixed $lines, bool $invoiceAddStock): array
    {
        if (!is_array($lines) || count($lines) < 1 || count($lines) > 200) {
            throw new ApiError('validation', 'En az bir fatura satırı gerekli.', 422, 'lines');
        }
        $out = [];
        foreach (array_values($lines) as $i => $l) {
            if (!is_array($l)) {
                throw new ApiError('validation', 'Geçersiz satır.', 422, 'lines');
            }
            $new = isset($l['new_item']) && is_array($l['new_item']) ? $l['new_item'] : null;
            if (($l['description'] ?? '') === '' && $new && !empty($new['name'])) {
                $l['description'] = $new['name'];
            }
            $c = self::line($l, $i + 1);
            $itemId = Fin::ownedId('stock_items', V::id($l['item_id'] ?? null, 'item_id', false), $tid, 'item_id');
            $created = false;
            if ($itemId === null && $new !== null) {
                [$itemId, $created] = StockCtl::resolveItem($tid, $new + ['name' => $c['description'], 'unit' => $c['unit']]);
            }
            $kind = $itemId !== null ? (string)DB::val('SELECT kind FROM stock_items WHERE id=?', [$itemId]) : null;
            $whIn = Fin::ownedId('warehouses', V::id($l['warehouse_id'] ?? null, 'warehouse_id', false), $tid, 'warehouse_id');
            $stock = false;
            if ($itemId !== null) {
                if (array_key_exists('stock', $l)) {
                    $stock = (bool)V::bool($l, 'stock');
                } elseif ($whIn !== null) {
                    $stock = true;
                } else {
                    $stock = $invoiceAddStock && $kind === 'product';
                }
                if ($stock && $kind !== 'product') {
                    if (array_key_exists('stock', $l) && $l['stock']) {
                        throw new ApiError('validation', 'Hizmet kalemi stoğa eklenemez.', 422, 'lines');
                    }
                    $stock = false;
                }
            }
            $c['id'] = V::id($l['id'] ?? null, 'id', false);
            $c['item_id'] = $itemId;
            $c['warehouse_id'] = $stock ? ($whIn ?? StockCtl::defaultWarehouse($tid)) : null;
            $c['item_created'] = $created;
            $out[] = $c;
        }
        return $out;
    }

    private static function dueFrom(array $b, string $issue): ?string
    {
        $due = V::date($b, 'due_date', false);
        if ($due === null && isset($b['due_days']) && $b['due_days'] !== '') {
            $due = Util::addDays($issue, (int)V::int($b, 'due_days', 0, 3650));
        }
        if ($due !== null && $due < $issue) {
            throw new ApiError('validation', 'Vade tarihi fatura tarihinden önce olamaz.', 422, 'due_date');
        }
        return $due; // boş = vadesiz (sessizce gün eklenmez)
    }

    // ---------- liste ----------
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
            $w[] = '(i.number LIKE ? OR c.name LIKE ? OR EXISTS (SELECT 1 FROM invoice_lines ql WHERE ql.invoice_id=i.id AND ql.description LIKE ?))';
            $l = '%' . str_replace(['%', '_'], ['\\%', '\\_'], (string)$q['q']) . '%';
            array_push($p, $l, $l, $l);
        }
        $rows = DB::all("SELECT i.id,i.kind,i.number,i.issue_date,i.due_date,i.currency,i.status,i.subtotal_minor,i.vat_minor,i.total_minor,i.contact_id,c.name AS contact_name,i.project_id,i.workspace_id,i.defer_delivery,
                         e.id AS entry_id, COALESCE(x.paid,0) AS paid_minor
                         FROM invoices i JOIN contacts c ON c.id=i.contact_id LEFT JOIN entries e ON e.invoice_id=i.id AND e.status='open'
                         LEFT JOIN " . Fin::PAID_SUB . " x ON x.entry_id=e.id
                         WHERE " . implode(' AND ', $w) . ' ORDER BY i.issue_date DESC, i.id DESC LIMIT 1000', [$tid, ...$p]);
        $today = Util::today();
        $ds = self::deliveryStates($tid, array_map(fn($x) => (int)$x['id'], $rows));
        foreach ($rows as &$x) {
            foreach (['id', 'subtotal_minor', 'vat_minor', 'total_minor', 'paid_minor', 'contact_id', 'workspace_id'] as $k) {
                $x[$k] = (int)$x[$k];
            }
            $x['remaining_minor'] = $x['status'] === 'issued' ? $x['total_minor'] - $x['paid_minor'] : 0;
            // vadesiz fatura (due_date NULL) hiçbir zaman gecikmiş sayılmaz
            $x['payment_state'] = $x['status'] !== 'issued' ? $x['status'] : ($x['remaining_minor'] <= 0 ? 'paid' : ($x['paid_minor'] > 0 ? 'partial' : (($x['due_date'] !== null && $x['due_date'] < $today) ? 'overdue' : 'unpaid')));
            $x['delivery'] = $ds[$x['id']] ?? ['state' => 'none', 'tracked_milli' => 0, 'fulfilled_milli' => 0, 'pending_milli' => 0];
            $x['delivery_state'] = $x['delivery']['state'];
        }
        Res::ok(['invoices' => $rows]);
    }

    // ---------- teslimat durumu ----------
    /** @return array<int,array{state:string,tracked_milli:int,fulfilled_milli:int,pending_milli:int}> */
    public static function deliveryStates(int $tid, array $ids): array
    {
        if (!$ids) {
            return [];
        }
        $in = implode(',', array_map('intval', $ids));
        $rows = DB::all("SELECT l.invoice_id, l.item_id, l.warehouse_id, l.qty_milli, l.fulfilled_milli, i.status, i.kind FROM invoice_lines l JOIN invoices i ON i.id=l.invoice_id
                         JOIN stock_items s ON s.id=l.item_id AND s.kind='product' WHERE l.tenant_id=? AND l.invoice_id IN ($in) AND l.warehouse_id IS NOT NULL", [$tid]);
        $agg = [];
        foreach ($rows as $x) {
            $id = (int)$x['invoice_id'];
            $agg[$id] ??= ['status' => $x['status'], 'kind' => $x['kind'], 'tracked' => 0, 'ful' => 0, 'pend' => [], 'lines' => 0];
            $agg[$id]['tracked'] += (int)$x['qty_milli'];
            $agg[$id]['ful'] += (int)$x['fulfilled_milli'];
            $p = (int)$x['qty_milli'] - (int)$x['fulfilled_milli'];
            if ($p > 0) {
                $k = $x['item_id'] . '|' . $x['warehouse_id'];
                $agg[$id]['pend'][$k] = ($agg[$id]['pend'][$k] ?? 0) + $p;
            }
        }
        $lv = null;
        $out = [];
        foreach ($ids as $id) {
            $id = (int)$id;
            $a = $agg[$id] ?? null;
            if (!$a) {
                $out[$id] = ['state' => 'none', 'tracked_milli' => 0, 'fulfilled_milli' => 0, 'pending_milli' => 0];
                continue;
            }
            $pending = $a['tracked'] - $a['ful'];
            if ($a['status'] === 'cancelled') {
                $state = 'cancelled';
            } elseif ($a['status'] === 'planned') {
                $state = 'none';
            } elseif ($pending <= 0) {
                $state = 'delivered';
            } elseif ($a['ful'] > 0) {
                $state = 'partial';
            } else {
                $state = 'awaiting_supply';
                if ($a['kind'] === 'sales') {
                    $lv ??= StockCtl::levels($tid);
                    $ready = true;
                    foreach ($a['pend'] as $k => $need) {
                        [$it, $wh] = array_map('intval', explode('|', $k));
                        if (($lv[$it]['wh'][$wh] ?? 0) < $need) {
                            $ready = false;
                        }
                    }
                    $state = $ready ? 'ready' : 'awaiting_supply';
                }
            }
            $out[$id] = ['state' => $state, 'tracked_milli' => $a['tracked'], 'fulfilled_milli' => $a['ful'], 'pending_milli' => $pending];
        }
        return $out;
    }

    // ---------- stok ----------
    /**
     * Satırların fulfilled_milli hedefini hesaplar ve stok hareketlerini FARK kadar işler (aynı faturayı tekrar kaydetmek yeni hareket üretmez).
     * @param array<int,array{qty:int,f:int}> $prev önceki satır durumları (satır id => qty/fulfilled); yeni satırlar yoktur
     * @param array<int,int> $removedF silinen satırların önceki fulfilled değerleri (anahtar: "item|wh" → miktar)
     */
    private static function settleStock(int $tid, array $inv, array $prev, array $removed, bool $defer, string $date, array &$warnings): void
    {
        $sales = $inv['kind'] === 'sales';
        $neg = StockCtl::allowNegative($tid);
        $lines = DB::all("SELECT l.*, s.name AS item_name FROM invoice_lines l JOIN stock_items s ON s.id=l.item_id AND s.kind='product' WHERE l.invoice_id=? AND l.tenant_id=? AND l.warehouse_id IS NOT NULL ORDER BY l.seq", [$inv['id'], $tid]);
        $lv = StockCtl::levels($tid);
        // kullanılabilir stok: mevcut seviye + bu faturanın serbest bıraktığı (kısalan/silinen) miktar
        $avail = [];
        $keep = [];
        foreach ($lines as $l) {
            $k = $l['item_id'] . '|' . $l['warehouse_id'];
            $pv = $prev[(int)$l['id']] ?? ['qty' => 0, 'f' => 0];
            if (isset($prev[(int)$l['id']]) && empty($prev[(int)$l['id']]['tracked'])) {
                $pv = ['qty' => 0, 'f' => 0];
            }
            $keep[(int)$l['id']] = min($pv['f'], (int)$l['qty_milli']);
            $avail[$k] ??= (int)($lv[(int)$l['item_id']]['wh'][(int)$l['warehouse_id']] ?? 0);
            $avail[$k] += $pv['f'] - $keep[(int)$l['id']];
        }
        foreach ($removed as $k => $q) {
            [$it, $wh] = array_map('intval', explode('|', $k));
            $avail[$k] ??= (int)($lv[$it]['wh'][$wh] ?? 0);
            $avail[$k] += $q;
        }
        foreach ($lines as $l) {
            $id = (int)$l['id'];
            $k = $l['item_id'] . '|' . $l['warehouse_id'];
            $qty = (int)$l['qty_milli'];
            $pv = $prev[$id] ?? null;
            if ($pv !== null && empty($pv['tracked'])) {
                $pv = null;
            }
            $f = $keep[$id];
            // yeni satır, ya da önceden tamamen işlenmiş satırın artan kısmı otomatik işlenir (ertelenmediyse)
            $auto = !$defer && ($pv === null || ($pv['f'] >= $pv['qty'] && $qty > $pv['qty']));
            if ($auto) {
                $want = $qty - $f;
                if ($sales && !$neg) {
                    $can = max(0, min($want, $avail[$k]));
                    $f += $can;
                    $avail[$k] -= $can;
                } else {
                    $f += $want;
                }
            }
            if ($f < $qty && $sales) {
                $warnings[] = "{$l['item_name']}: " . Money::fmtQty($qty - $f) . " {$l['unit']} karşılanmadı (stokta yok / teslim bekliyor)";
            }
            if ($f !== (int)$l['fulfilled_milli']) {
                DB::exec('UPDATE invoice_lines SET fulfilled_milli=? WHERE id=?', [$f, $id]);
            }
        }
        self::syncMovements($tid, $inv, $date, $neg);
    }

    /** Fatura için gereken net stok etkisi ile mevcut posted hareketlerin farkını işler. */
    private static function syncMovements(int $tid, array $inv, string $date, bool $neg): void
    {
        $sales = $inv['kind'] === 'sales';
        $want = [];
        $cost = [];
        foreach (DB::all("SELECT l.item_id, l.warehouse_id, l.qty_milli, l.fulfilled_milli, l.net_minor FROM invoice_lines l JOIN stock_items s ON s.id=l.item_id AND s.kind='product' WHERE l.invoice_id=? AND l.tenant_id=? AND l.warehouse_id IS NOT NULL", [$inv['id'], $tid]) as $l) {
            $k = $l['item_id'] . '|' . $l['warehouse_id'];
            $want[$k] = ($want[$k] ?? 0) + (int)$l['fulfilled_milli'];
            $cost[$k]['net'] = ($cost[$k]['net'] ?? 0) + (int)$l['net_minor'];
            $cost[$k]['qty'] = ($cost[$k]['qty'] ?? 0) + (int)$l['qty_milli'];
        }
        $cur = [];
        foreach (DB::all("SELECT item_id, warehouse_id, SUM(IF(kind IN ('in','return_in'), qty_milli, -qty_milli)) eff FROM stock_movements WHERE tenant_id=? AND invoice_id=? AND status='posted' GROUP BY item_id, warehouse_id", [$tid, $inv['id']]) as $m) {
            $cur[$m['item_id'] . '|' . $m['warehouse_id']] = (int)$m['eff'];
        }
        foreach (array_unique(array_merge(array_keys($want), array_keys($cur))) as $k) {
            [$item, $wh] = array_map('intval', explode('|', $k));
            $desired = ($sales ? -1 : 1) * ($want[$k] ?? 0);
            $diff = $desired - ($cur[$k] ?? 0);
            if ($diff === 0) {
                continue;
            }
            $lv = StockCtl::levels($tid, $item)[$item] ?? ['qty_milli' => 0, 'avg_cost_minor' => 0];
            $abs = abs($diff);
            if ($sales) {
                if ($diff < 0) {
                    $kind = 'out';
                    $uc = (int)$lv['avg_cost_minor'];
                } else {
                    $kind = 'return_in';
                    $row = DB::one("SELECT SUM(qty_milli * unit_cost_minor) v, SUM(qty_milli) q FROM stock_movements WHERE tenant_id=? AND invoice_id=? AND item_id=? AND kind='out' AND status='posted'", [$tid, $inv['id'], $item]);
                    $uc = $row && (int)$row['q'] > 0 ? Money::mulDiv((int)$row['v'], 1, (int)$row['q']) : (int)$lv['avg_cost_minor'];
                }
            } elseif ($diff > 0) {
                $kind = 'in';
                $uc = isset($cost[$k]) && $cost[$k]['qty'] > 0 ? Money::mulDiv($cost[$k]['net'], 1000, $cost[$k]['qty']) : (int)$lv['avg_cost_minor'];
            } else {
                $kind = 'return_out';
                $uc = (int)$lv['avg_cost_minor'];
                if (!$neg && (int)$lv['qty_milli'] - $abs < 0) {
                    throw new ApiError('stock_used', 'Bu faturadan stoğa giren ürün başka işlemlerde kullanılmış; miktar azaltılamaz/fatura iptal edilemez (stok eksiye düşerdi).', 409);
                }
            }
            DB::insert('stock_movements', ['tenant_id' => $tid, 'item_id' => $item, 'warehouse_id' => $wh, 'kind' => $kind, 'qty_milli' => $abs, 'unit_cost_minor' => $uc, 'move_date' => $date,
                'project_id' => $sales ? $inv['project_id'] : null, 'invoice_id' => $inv['id'], 'note' => ($sales ? 'Satış faturası' : 'Alış faturası') . ($kind === 'return_in' || $kind === 'return_out' ? ' (düzeltme)' : ''), 'created_at' => Util::now()]);
        }
    }

    // ---------- oluştur ----------
    private static function create(Req $r, int $tid): never
    {
        $b = $r->body;
        $kind = (string)V::enum($b, 'kind', ['sales', 'purchase']);
        $ws = Fin::ownedId('workspaces', V::id($b['workspace_id'] ?? null, 'workspace_id'), $tid, 'workspace_id', true);
        $contact = Fin::ownedId('contacts', V::id($b['contact_id'] ?? null, 'contact_id'), $tid, 'contact_id', true);
        $project = Fin::ownedId('projects', V::id($b['project_id'] ?? null, 'project_id', false), $tid, 'project_id');
        $status = (string)V::enum($b, 'status', ['planned', 'issued'], false, 'issued');
        $issue = (string)V::date($b + ['issue_date' => Util::today()], 'issue_date');
        $due = self::dueFrom($b, $issue);
        $number = V::str($b, 'number', 60, false);
        if ($status === 'issued' && $kind === 'purchase' && $number === null) {
            throw new ApiError('validation', 'Alış faturasında fatura numarası zorunludur.', 422, 'number');
        }
        $cur = V::cur($b);
        $defer = (int)V::bool($b, 'defer_delivery');
        $addStock = (bool)V::bool($b, 'add_to_stock');
        $res = DB::tx(function () use ($tid, $b, $kind, $ws, $contact, $project, $status, $issue, $due, $number, $cur, $defer, $addStock) {
            $calc = self::parseLines($tid, $b['lines'] ?? null, $addStock);
            $sub = array_sum(array_column($calc, 'net_minor'));
            $vat = array_sum(array_column($calc, 'vat_minor'));
            if ($sub + $vat > Money::MAX) {
                throw new ApiError('amount_too_large', 'Fatura tutarı çok büyük.', 422);
            }
            $dedupe = $number !== null ? Util::sha($kind . '|' . $contact . '|' . mb_strtolower($number)) : null;
            try {
                $id = DB::insert('invoices', [
                    'tenant_id' => $tid, 'workspace_id' => $ws, 'kind' => $kind, 'number' => $number, 'contact_id' => $contact, 'project_id' => $project,
                    'issue_date' => $issue, 'due_date' => $due, 'currency' => $cur, 'status' => $status, 'subtotal_minor' => $sub, 'vat_minor' => $vat, 'total_minor' => $sub + $vat,
                    'note' => V::str($b, 'note', 2000, false), 'created_by' => Auth::$user['id'], 'created_at' => Util::now(), 'dedupe_key' => $dedupe, 'defer_delivery' => $defer,
                ]);
            } catch (\PDOException $e) {
                if ($e->getCode() === '23000') {
                    throw new ApiError('duplicate_invoice', 'Bu cari için aynı numaralı fatura zaten kayıtlı.', 409, 'number');
                }
                throw $e;
            }
            $created = 0;
            foreach ($calc as $c) {
                $created += $c['item_created'] ? 1 : 0;
                unset($c['id'], $c['item_created']);
                DB::insert('invoice_lines', $c + ['tenant_id' => $tid, 'invoice_id' => $id]);
            }
            $warnings = [];
            if ($status === 'issued') {
                $warnings = self::materialize($tid, $id);
            }
            Audit::log('data', 'invoice_created', ['tenant' => $tid, 'entity' => 'invoice', 'id' => $id, 'details' => ['kind' => $kind, 'status' => $status, 'total_minor' => $sub + $vat, 'due_date' => $due, 'new_items' => $created]]);
            return ['id' => $id, 'warnings' => $warnings, 'new_items' => $created];
        });
        Res::ok($res, 201);
    }

    /** Kesilmiş faturanın tek alacak/borç kalemini ve stok hareketlerini oluşturur (idempotent: tekil anahtar). */
    private static function materialize(int $tid, int $invId): array
    {
        $inv = DB::one('SELECT * FROM invoices WHERE id=? AND tenant_id=?', [$invId, $tid]);
        $isSales = $inv['kind'] === 'sales';
        DB::insert('entries', [
            'tenant_id' => $tid, 'workspace_id' => $inv['workspace_id'], 'kind' => $isSales ? 'receivable' : 'payable', 'source' => 'invoice', 'contact_id' => $inv['contact_id'],
            'project_id' => $inv['project_id'], 'invoice_id' => $invId, 'invoice_key' => (string)$invId, 'title' => self::entryTitle($tid, $inv),
            'currency' => $inv['currency'], 'amount_minor' => $inv['total_minor'], 'due_date' => $inv['due_date'], 'original_due_date' => $inv['due_date'],
            'affects_pl' => 0, 'created_at' => Util::now(),
        ]);
        $warnings = [];
        self::settleStock($tid, $inv, [], [], (bool)$inv['defer_delivery'], $inv['issue_date'], $warnings);
        return $warnings;
    }

    private static function entryTitle(int $tid, array $inv): string
    {
        $name = (string)DB::val('SELECT name FROM contacts WHERE id=?', [$inv['contact_id']]);
        return ($inv['kind'] === 'sales' ? 'Satış faturası ' : 'Alış faturası ') . ($inv['number'] ?? '#' . $inv['id']) . ' — ' . $name;
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

    // ---------- düzenle ----------
    private static function update(Req $r, int $id, int $tid): never
    {
        $b = $r->body;
        $res = DB::tx(function () use ($b, $id, $tid) {
            $inv = DB::one('SELECT * FROM invoices WHERE id=? AND tenant_id=? FOR UPDATE', [$id, $tid]);
            if (!$inv) {
                throw new ApiError('not_found', 'Fatura bulunamadı.', 404);
            }
            if ($inv['status'] === 'cancelled') {
                throw new ApiError('invalid_state', 'İptal edilmiş fatura düzenlenemez.', 409);
            }
            $entry = $inv['status'] === 'issued' ? DB::one('SELECT * FROM entries WHERE invoice_id=? AND tenant_id=? FOR UPDATE', [$id, $tid]) : null;
            $paid = $entry ? PayCtl::entryPaid($tid, (int)$entry['id']) : 0;
            $hasAlloc = $entry ? LedgerCtl::allocCount($tid, (int)$entry['id']) > 0 : false;
            $issue = array_key_exists('issue_date', $b) ? (string)V::date($b, 'issue_date') : $inv['issue_date'];
            $due = array_key_exists('due_date', $b) || isset($b['due_days']) ? self::dueFrom($b, $issue) : $inv['due_date'];
            if ($due !== null && $due < $issue) {
                throw new ApiError('validation', 'Vade tarihi fatura tarihinden önce olamaz.', 422, 'due_date');
            }
            $u = ['issue_date' => $issue, 'due_date' => $due];
            if (array_key_exists('note', $b)) {
                $u['note'] = V::str($b, 'note', 2000, false);
            }
            if (array_key_exists('project_id', $b)) {
                $u['project_id'] = Fin::ownedId('projects', V::id($b['project_id'], 'project_id', false), $tid, 'project_id');
            }
            if (array_key_exists('defer_delivery', $b)) {
                $u['defer_delivery'] = V::bool($b, 'defer_delivery');
            }
            foreach (['contact_id' => 'contacts', 'workspace_id' => 'workspaces'] as $k => $tb) {
                if (isset($b[$k]) && (int)$b[$k] !== (int)$inv[$k]) {
                    if ($hasAlloc) {
                        throw new ApiError('has_payments', 'Ödeme/tahsilat dağıtımı olan faturanın carisi veya alanı değiştirilemez; önce dağıtımları geri alın.', 409, $k);
                    }
                    $u[$k] = Fin::ownedId($tb, V::id($b[$k], $k), $tid, $k, true);
                }
            }
            if (isset($b['currency']) && $b['currency'] !== $inv['currency']) {
                if ($hasAlloc) {
                    throw new ApiError('has_payments', 'Ödeme dağıtımı olan faturanın para birimi değiştirilemez.', 409, 'currency');
                }
                $u['currency'] = V::cur($b);
            }
            if (array_key_exists('number', $b)) {
                $u['number'] = V::str($b, 'number', 60, false);
                if ($inv['status'] === 'issued' && $inv['kind'] === 'purchase' && $u['number'] === null) {
                    throw new ApiError('validation', 'Alış faturasında fatura numarası zorunludur.', 422, 'number');
                }
            }
            $newNumber = array_key_exists('number', $u) ? $u['number'] : $inv['number'];
            $newContact = $u['contact_id'] ?? (int)$inv['contact_id'];
            $u['dedupe_key'] = $newNumber !== null ? Util::sha($inv['kind'] . '|' . $newContact . '|' . mb_strtolower((string)$newNumber)) : null;

            // satırlar
            $prev = [];
            $prevLines = DB::all('SELECT * FROM invoice_lines WHERE invoice_id=? AND tenant_id=?', [$id, $tid]);
            foreach ($prevLines as $pl) {
                $prev[(int)$pl['id']] = ['qty' => (int)$pl['qty_milli'], 'f' => (int)$pl['fulfilled_milli'], 'item' => $pl['item_id'], 'wh' => $pl['warehouse_id'], 'tracked' => $pl['item_id'] !== null && $pl['warehouse_id'] !== null];
            }
            $before = ['total_minor' => (int)$inv['total_minor'], 'due_date' => $inv['due_date'], 'lines' => count($prevLines)];
            $removed = [];
            $newLineCount = count($prevLines);
            if (isset($b['lines'])) {
                $calc = self::parseLines($tid, $b['lines'], (bool)V::bool($b, 'add_to_stock'));
                $keepIds = [];
                $sub = array_sum(array_column($calc, 'net_minor'));
                $vat = array_sum(array_column($calc, 'vat_minor'));
                if ($sub + $vat > Money::MAX) {
                    throw new ApiError('amount_too_large', 'Fatura tutarı çok büyük.', 422);
                }
                if ($entry && $sub + $vat < $paid) {
                    throw new ApiError('total_below_paid', 'Fatura toplamı, o ana kadar ödenen/tahsil edilen tutardan küçük olamaz. Önce ödeme dağıtımını geri alın.', 422, 'lines', ['paid_minor' => $paid]);
                }
                $newItems = 0;
                foreach ($calc as $c) {
                    $newItems += $c['item_created'] ? 1 : 0;
                    $lid = $c['id'];
                    unset($c['id'], $c['item_created']);
                    if ($lid !== null && isset($prev[(int)$lid])) {
                        $keepIds[] = (int)$lid;
                        $pv0 = $prev[(int)$lid];
                        if (($pv0['item'] !== null ? (int)$pv0['item'] : null) !== $c['item_id'] || ($pv0['wh'] !== null ? (int)$pv0['wh'] : null) !== $c['warehouse_id']) {
                            if ($pv0['item'] !== null && $pv0['wh'] !== null && $pv0['f'] > 0) {
                                $ok = $pv0['item'] . '|' . $pv0['wh'];
                                $removed[$ok] = ($removed[$ok] ?? 0) + $pv0['f'];
                            }
                            unset($prev[(int)$lid]);
                            $c['fulfilled_milli'] = 0;
                        }
                        DB::update('invoice_lines', $c, 'id=? AND tenant_id=?', [(int)$lid, $tid]);
                    } else {
                        $nid = DB::insert('invoice_lines', $c + ['tenant_id' => $tid, 'invoice_id' => $id]);
                        $keepIds[] = $nid;
                    }
                }
                foreach ($prev as $lid => $pv) {
                    if (!in_array($lid, $keepIds, true)) {
                        if ($pv['item'] !== null && $pv['wh'] !== null && $pv['f'] > 0) {
                            $k = $pv['item'] . '|' . $pv['wh'];
                            $removed[$k] = ($removed[$k] ?? 0) + $pv['f'];
                        }
                        DB::exec('DELETE FROM invoice_lines WHERE id=? AND tenant_id=?', [$lid, $tid]);
                    }
                }
                $u['subtotal_minor'] = $sub;
                $u['vat_minor'] = $vat;
                $u['total_minor'] = $sub + $vat;
                $newLineCount = count($calc);
            }
            try {
                DB::update('invoices', $u, 'id=? AND tenant_id=?', [$id, $tid]);
            } catch (PDOException $e) {
                if ($e->getCode() === '23000') {
                    throw new ApiError('duplicate_invoice', 'Bu cari için aynı numaralı fatura zaten kayıtlı.', 409, 'number');
                }
                throw $e;
            }
            $inv2 = DB::one('SELECT * FROM invoices WHERE id=?', [$id]);
            $warnings = [];
            if ($entry) {
                DB::update('entries', [
                    'amount_minor' => $inv2['total_minor'], 'due_date' => $inv2['due_date'], 'contact_id' => $inv2['contact_id'], 'workspace_id' => $inv2['workspace_id'], 'project_id' => $inv2['project_id'],
                    'currency' => $inv2['currency'], 'title' => self::entryTitle($tid, $inv2),
                ] + ($entry['original_due_date'] === null && $inv2['due_date'] !== null ? ['original_due_date' => $inv2['due_date']] : []), 'id=? AND tenant_id=?', [(int)$entry['id'], $tid]);
                self::settleStock($tid, $inv2, $prev, $removed, (bool)$inv2['defer_delivery'], Util::today(), $warnings);
            }
            Audit::log('data', 'invoice_updated', ['tenant' => $tid, 'entity' => 'invoice', 'id' => $id, 'details' => ['before' => $before, 'after' => ['total_minor' => (int)$inv2['total_minor'], 'due_date' => $inv2['due_date'], 'lines' => $newLineCount]]]);
            return ['warnings' => $warnings];
        });
        Res::ok($res);
    }

    // ---------- teslimat ----------
    private static function deliver(Req $r, int $id, int $tid): never
    {
        $b = $r->body;
        $date = (string)V::date($b + ['date' => Util::today()], 'date');
        $in = $b['lines'] ?? null;
        if (!is_array($in) || !$in) {
            throw new ApiError('validation', 'Teslim edilecek satır seçin.', 422, 'lines');
        }
        $res = DB::tx(function () use ($in, $id, $tid, $date) {
            $inv = DB::one('SELECT * FROM invoices WHERE id=? AND tenant_id=? FOR UPDATE', [$id, $tid]);
            if (!$inv) {
                throw new ApiError('not_found', 'Fatura bulunamadı.', 404);
            }
            if ($inv['status'] !== 'issued') {
                throw new ApiError('invalid_state', 'Yalnızca kesilmiş faturada teslimat işlenir.', 409);
            }
            $neg = StockCtl::allowNegative($tid);
            $sales = $inv['kind'] === 'sales';
            $lv = StockCtl::levels($tid);
            $avail = [];
            $seen = [];
            foreach ($in as $x) {
                $lid = (int)V::id($x['line_id'] ?? null, 'line_id');
                $q = (int)V::int($x, 'qty_milli', 1, 1000000000);
                if (isset($seen[$lid])) {
                    throw new ApiError('validation', 'Aynı satır iki kez seçilemez.', 422, 'lines');
                }
                $seen[$lid] = true;
                $l = DB::one("SELECT l.*, s.name AS item_name FROM invoice_lines l JOIN stock_items s ON s.id=l.item_id AND s.kind='product' WHERE l.id=? AND l.invoice_id=? AND l.tenant_id=? AND l.warehouse_id IS NOT NULL FOR UPDATE", [$lid, $id, $tid]);
                if (!$l) {
                    throw new ApiError('not_found', 'Stok takipli fatura satırı bulunamadı.', 404, 'lines');
                }
                $pending = (int)$l['qty_milli'] - (int)$l['fulfilled_milli'];
                if ($q > $pending) {
                    throw new ApiError('over_delivery', "{$l['item_name']}: bekleyen miktar " . Money::fmtQty($pending) . '; fazlası teslim edilemez.', 422, 'lines', ['pending_milli' => $pending]);
                }
                if ($sales && !$neg) {
                    $k = $l['item_id'] . '|' . $l['warehouse_id'];
                    $avail[$k] ??= (int)($lv[(int)$l['item_id']]['wh'][(int)$l['warehouse_id']] ?? 0);
                    if ($q > $avail[$k]) {
                        throw new ApiError('insufficient_stock', "{$l['item_name']}: depoda yeterli stok yok (mevcut: " . Money::fmtQty(max(0, $avail[$k])) . '). Önce stok girişi yapın.', 409, 'lines', ['available_milli' => max(0, $avail[$k])]);
                    }
                    $avail[$k] -= $q;
                }
                DB::exec('UPDATE invoice_lines SET fulfilled_milli = fulfilled_milli + ? WHERE id=?', [$q, $lid]);
            }
            self::syncMovements($tid, $inv, $date, $neg);
            Audit::log('data', 'invoice_delivery', ['tenant' => $tid, 'entity' => 'invoice', 'id' => $id, 'details' => ['lines' => count($in), 'date' => $date]]);
            return ['delivery' => self::deliveryStates($tid, [$id])[$id]];
        });
        Res::ok($res);
    }

    // ---------- iptal / silme ----------
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
                $eid = (int)DB::val('SELECT id FROM entries WHERE invoice_id=? AND tenant_id=?', [$id, $tid]);
                if ($eid && PayCtl::entryPaid($tid, $eid) > 0) {
                    throw new ApiError('has_payments', 'Tahsilat/ödemesi olan fatura iptal edilemez; önce ödeme dağıtımını geri alın veya ilgili hareketleri iptal edin.', 409);
                }
                self::guardStockRemoval($tid, $inv);
                DB::exec('UPDATE entries SET status=\'cancelled\', cancelled_at=? WHERE invoice_id=? AND tenant_id=?', [Util::now(), $id, $tid]);
                DB::exec('UPDATE stock_movements SET status=\'voided\' WHERE invoice_id=? AND tenant_id=?', [$id, $tid]);
            }
            DB::exec('UPDATE invoices SET status=\'cancelled\', cancel_reason=?, cancelled_at=? WHERE id=? AND tenant_id=?', [$reason, Util::now(), $id, $tid]);
            Audit::log('data', 'invoice_cancelled', ['tenant' => $tid, 'entity' => 'invoice', 'id' => $id, 'details' => ['reason' => $reason, 'total_minor' => (int)$inv['total_minor'], 'was' => $inv['status']]]);
        });
        Res::ok();
    }

    /** Alış faturasından stoğa girmiş ürün başka işlemlerde tüketildiyse faturanın stok etkisi geri alınamaz. */
    private static function guardStockRemoval(int $tid, array $inv): void
    {
        if ($inv['kind'] !== 'purchase' || StockCtl::allowNegative($tid)) {
            return;
        }
        foreach (DB::all("SELECT item_id, SUM(IF(kind IN ('in','return_in'),qty_milli,-qty_milli)) eff FROM stock_movements WHERE tenant_id=? AND invoice_id=? AND status='posted' GROUP BY item_id", [$tid, $inv['id']]) as $m) {
            $have = StockCtl::levels($tid, (int)$m['item_id'])[(int)$m['item_id']]['qty_milli'] ?? 0;
            if ((int)$m['eff'] > 0 && $have - (int)$m['eff'] < 0) {
                throw new ApiError('stock_used', 'Bu faturayla stoğa giren ürün başka işlemlerde kullanılmış; fatura iptal/silinemez (stok eksiye düşerdi).', 409);
            }
        }
    }

    /** Silme: yalnızca hiç ödeme dağıtımı olmayan fatura. Aksi halde iptal veya dağıtımı geri alma yolu gösterilir. */
    private static function delete(int $id, int $tid): never
    {
        DB::tx(function () use ($id, $tid) {
            $inv = DB::one('SELECT * FROM invoices WHERE id=? AND tenant_id=? FOR UPDATE', [$id, $tid]);
            if (!$inv) {
                throw new ApiError('not_found', 'Fatura bulunamadı.', 404);
            }
            $e = DB::one('SELECT id FROM entries WHERE invoice_id=? AND tenant_id=?', [$id, $tid]);
            if ($e && LedgerCtl::allocCount($tid, (int)$e['id']) > 0) {
                throw new ApiError('has_payments', 'Ödeme/tahsilat geçmişi olan fatura silinemez. Önce dağıtımları geri alın ya da faturayı “İptal et” (geçmiş korunur).', 409);
            }
            if ($inv['status'] === 'issued') {
                self::guardStockRemoval($tid, $inv);
            }
            DB::exec('DELETE FROM stock_movements WHERE invoice_id=? AND tenant_id=?', [$id, $tid]);
            DB::exec('DELETE FROM entries WHERE invoice_id=? AND tenant_id=?', [$id, $tid]);
            DB::exec('DELETE FROM invoice_lines WHERE invoice_id=? AND tenant_id=?', [$id, $tid]);
            DB::exec("UPDATE documents SET deleted_at=? WHERE tenant_id=? AND entity='invoice' AND entity_id=? AND deleted_at IS NULL", [Util::now(), $tid, $id]);
            DB::exec('DELETE FROM invoices WHERE id=? AND tenant_id=?', [$id, $tid]);
            Audit::log('data', 'invoice_deleted', ['tenant' => $tid, 'entity' => 'invoice', 'id' => $id, 'details' => ['snapshot' => array_intersect_key($inv, array_flip(['kind', 'number', 'status', 'issue_date', 'due_date', 'total_minor', 'currency', 'contact_id']))]]);
        });
        Res::ok();
    }

    // ---------- ayrıntı ----------
    private static function detail(int $id, int $tid): never
    {
        $inv = DB::one('SELECT i.*, c.name AS contact_name FROM invoices i JOIN contacts c ON c.id=i.contact_id WHERE i.id=? AND i.tenant_id=?', [$id, $tid]);
        if (!$inv) {
            throw new ApiError('not_found', 'Fatura bulunamadı.', 404);
        }
        $lines = DB::all('SELECT l.*, s.name AS item_name, s.kind AS item_kind FROM invoice_lines l LEFT JOIN stock_items s ON s.id=l.item_id WHERE l.invoice_id=? AND l.tenant_id=? ORDER BY l.seq', [$id, $tid]);
        foreach ($lines as &$l) {
            $l['tracked'] = $l['item_kind'] === 'product' && $l['warehouse_id'] !== null;
            $l['pending_milli'] = $l['tracked'] ? (int)$l['qty_milli'] - (int)$l['fulfilled_milli'] : 0;
        }
        $entry = DB::one('SELECT * FROM entries WHERE invoice_id=? AND tenant_id=?', [$id, $tid]);
        $pays = [];
        $paid = 0;
        if ($entry) {
            foreach (DB::all("SELECT pa.id AS alloc_id, pa.status AS alloc_status, pa.amount_minor, t.id, t.txn_date, t.status, t.account_id, t.kind, t.reference, t.description
                              FROM payment_allocations pa JOIN transactions t ON t.id=pa.txn_id WHERE pa.entry_id=? AND pa.tenant_id=? ORDER BY t.txn_date, pa.id", [$entry['id'], $tid]) as $p) {
                $live = $p['alloc_status'] === 'active' && $p['status'] === 'posted';
                $pays[] = ['id' => (int)$p['id'], 'alloc_id' => (int)$p['alloc_id'], 'txn_date' => $p['txn_date'], 'amount_minor' => (int)$p['amount_minor'], 'status' => $live ? 'posted' : ($p['alloc_status'] === 'reversed' ? 'reversed' : 'voided'), 'account_id' => (int)$p['account_id'], 'kind' => $p['kind'], 'reference' => $p['reference'], 'description' => $p['description']];
                $paid += $live ? (int)$p['amount_minor'] : 0;
            }
        }
        $docs = DB::all("SELECT id,orig_name,mime,size_bytes,created_at FROM documents WHERE tenant_id=? AND entity='invoice' AND entity_id=? AND deleted_at IS NULL", [$tid, $id]);
        $moves = DB::all('SELECT m.id,m.kind,m.qty_milli,m.unit_cost_minor,m.move_date,m.status,m.note,i.name AS item_name FROM stock_movements m JOIN stock_items i ON i.id=m.item_id WHERE m.tenant_id=? AND m.invoice_id=? ORDER BY m.id', [$tid, $id]);
        Res::ok(['invoice' => $inv, 'lines' => $lines, 'entry' => $entry, 'payments' => $pays, 'paid_minor' => $paid, 'remaining_minor' => $inv['status'] === 'issued' ? (int)$inv['total_minor'] - $paid : 0,
            'delivery' => self::deliveryStates($tid, [$id])[$id], 'stock_movements' => $moves, 'documents' => $docs]);
    }
}
