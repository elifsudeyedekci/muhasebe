<?php
declare(strict_types=1);

namespace FB;

/** Stok, depo ve malzeme hareketleri (ağırlıklı ortalama maliyet). */
final class StockCtl
{
    public static function routes(Router $r): void
    {
        $t = ['auth' => 'tenant'];
        $ti = ['auth' => 'tenant', 'idem' => true];
        $r->add('GET', '/stock/items', fn($q, $p, $tid) => self::items($tid), $t);
        $r->add('POST', '/stock/items', fn($q, $p, $tid) => self::itemSave($q, null, $tid), $ti);
        $r->add('PATCH', '/stock/items/{id}', fn($q, $p, $tid) => self::itemSave($q, (int)$p['id'], $tid), $t);
        $r->add('POST', '/stock/warehouses', fn($q, $p, $tid) => self::whCreate($q, $tid), $t);
        $r->add('PATCH', '/stock/warehouses/{id}', fn($q, $p, $tid) => self::whUpdate($q, (int)$p['id'], $tid), $t);
        $r->add('GET', '/stock/movements', fn($q, $p, $tid) => self::movements($q, $tid), $t);
        $r->add('POST', '/stock/movements', fn($q, $p, $tid) => self::moveCreate($q, $tid), $ti);
        $r->add('POST', '/stock/movements/{id}/void', fn($q, $p, $tid) => self::moveVoid((int)$p['id'], $tid), $t);
        $r->add('GET', '/stock/match', fn($q, $p, $tid) => self::match($q, $tid), $t);
        $r->add('DELETE', '/stock/items/{id}', fn($q, $p, $tid) => self::itemDelete((int)$p['id'], $tid), $t);
        $r->add('DELETE', '/stock/warehouses/{id}', fn($q, $p, $tid) => self::whDelete((int)$p['id'], $tid), $t);
        $r->add('GET', '/reports/stock', fn($q, $p, $tid) => self::report($q, $tid), $t);
    }

    /** item_id => [qty_milli, value_minor, avg_cost_minor, wh => [id => qty]] */
    public static function levels(int $tid, ?int $itemId = null): array
    {
        $rows = DB::all("SELECT * FROM stock_movements WHERE tenant_id=? AND status='posted'" . ($itemId ? ' AND item_id=' . (int)$itemId : '') . ' ORDER BY move_date, id', [$tid]);
        $st = [];
        foreach ($rows as $m) {
            $i = (int)$m['item_id'];
            $st[$i] ??= ['qty_milli' => 0, 'value_minor' => 0, 'avg_cost_minor' => 0, 'wh' => []];
            $q = (int)$m['qty_milli'];
            $w = (int)$m['warehouse_id'];
            if (in_array($m['kind'], ['in', 'return_in'], true)) {
                $st[$i]['qty_milli'] += $q;
                $st[$i]['value_minor'] += Money::mulDiv($q, (int)$m['unit_cost_minor'], 1000);
                $st[$i]['wh'][$w] = ($st[$i]['wh'][$w] ?? 0) + $q;
            } else {
                $Q = $st[$i]['qty_milli'];
                $cost = $Q > 0 ? Money::mulDiv($st[$i]['value_minor'], min($q, $Q), $Q) : 0;
                $st[$i]['qty_milli'] -= $q;
                $st[$i]['value_minor'] = $st[$i]['qty_milli'] > 0 ? $st[$i]['value_minor'] - $cost : 0;
                $st[$i]['wh'][$w] = ($st[$i]['wh'][$w] ?? 0) - $q;
            }
            $st[$i]['avg_cost_minor'] = $st[$i]['qty_milli'] > 0 ? Money::mulDiv($st[$i]['value_minor'], 1000, $st[$i]['qty_milli']) : 0;
        }
        return $st;
    }

    private static function items(int $tid): never
    {
        $lv = self::levels($tid);
        $rows = DB::all('SELECT * FROM stock_items WHERE tenant_id=? ORDER BY archived, name', [$tid]);
        $back = self::backorders($tid);
        foreach ($rows as &$x) {
            $x['id'] = (int)$x['id'];
            $l = $lv[$x['id']] ?? ['qty_milli' => 0, 'value_minor' => 0, 'avg_cost_minor' => 0, 'wh' => []];
            $x['qty_milli'] = $l['qty_milli'];
            $x['value_minor'] = $l['value_minor'];
            $x['avg_cost_minor'] = $l['avg_cost_minor'];
            $x['by_warehouse'] = (object)$l['wh'];
            $x['low'] = $x['kind'] === 'product' && $l['qty_milli'] <= (int)$x['min_qty_milli'];
            $x['negative'] = $x['kind'] === 'product' && $l['qty_milli'] < 0;
            $x['backorder_milli'] = $back[$x['id']] ?? 0;
        }
        Res::ok(['items' => $rows, 'warehouses' => DB::all('SELECT * FROM warehouses WHERE tenant_id=? ORDER BY name', [$tid])]);
    }

    private static function itemSave(Req $r, ?int $id, int $tid): never
    {
        $b = $r->body;
        $d = [
            'kind' => V::enum($b, 'kind', ['product', 'service'], false, 'product'), 'code' => V::str($b, 'code', 60, false), 'name' => V::str($b, 'name', 200),
            'unit' => V::str($b, 'unit', 20, false) ?? 'adet', 'barcode' => V::str($b, 'barcode', 60, false), 'currency' => V::cur($b),
            'sale_price_minor' => V::amount($b + ['sale_price_minor' => 0], 'sale_price_minor', false, true), 'vat_rate_bp' => V::int($b, 'vat_rate_bp', 0, 10000, false, 2000),
            'min_qty_milli' => V::int($b, 'min_qty_milli', 0, 1000000000, false, 0),
        ];
        try {
            if ($id === null) {
                $nid = DB::insert('stock_items', $d + ['tenant_id' => $tid, 'created_at' => Util::now()]);
                Res::ok(['id' => $nid], 201);
            }
            if (!DB::val('SELECT id FROM stock_items WHERE id=? AND tenant_id=?', [$id, $tid])) {
                throw new ApiError('not_found', 'Ürün bulunamadı.', 404);
            }
            if (!isset($b['name'])) {
                unset($d['name']);
            }
            $u = array_intersect_key($d, $b);
            if (isset($b['archived'])) {
                $u['archived'] = V::bool($b, 'archived');
            }
            if ($u) {
                DB::update('stock_items', $u, 'id=? AND tenant_id=?', [$id, $tid]);
            }
        } catch (\PDOException $e) {
            if ($e->getCode() === '23000') {
                throw new ApiError('duplicate', 'Bu ürün kodu zaten kullanılıyor.', 409, 'code');
            }
            throw $e;
        }
        Res::ok();
    }

    private static function whCreate(Req $r, int $tid): never
    {
        $n = (string)V::str($r->body, 'name', 120);
        try {
            Res::ok(['id' => DB::insert('warehouses', ['tenant_id' => $tid, 'name' => $n])], 201);
        } catch (\PDOException $e) {
            throw new ApiError('duplicate', 'Bu isimde depo zaten var.', 409, 'name');
        }
    }

    private static function whUpdate(Req $r, int $id, int $tid): never
    {
        if (!DB::val('SELECT id FROM warehouses WHERE id=? AND tenant_id=?', [$id, $tid])) {
            throw new ApiError('not_found', 'Depo bulunamadı.', 404);
        }
        $u = [];
        if (isset($r->body['name'])) {
            $u['name'] = V::str($r->body, 'name', 120);
        }
        if (isset($r->body['archived'])) {
            $u['archived'] = V::bool($r->body, 'archived');
        }
        if ($u) {
            DB::update('warehouses', $u, 'id=? AND tenant_id=?', [$id, $tid]);
        }
        Res::ok();
    }

    private static function movements(Req $r, int $tid): never
    {
        $w = ['m.tenant_id=?'];
        $p = [$tid];
        foreach (['item_id', 'warehouse_id', 'project_id'] as $k) {
            if (!empty($r->query[$k])) {
                $w[] = "m.$k=?";
                $p[] = (int)$r->query[$k];
            }
        }
        $rows = DB::all('SELECT m.*, i.name AS item_name, i.unit, wh.name AS warehouse_name, pr.name AS project_name, inv.number AS invoice_number FROM stock_movements m JOIN stock_items i ON i.id=m.item_id JOIN warehouses wh ON wh.id=m.warehouse_id LEFT JOIN projects pr ON pr.id=m.project_id LEFT JOIN invoices inv ON inv.id=m.invoice_id WHERE ' . implode(' AND ', $w) . ' ORDER BY m.move_date DESC, m.id DESC LIMIT 500', $p);
        Res::ok(['movements' => $rows]);
    }

    private static function moveCreate(Req $r, int $tid): never
    {
        $b = $r->body;
        $item = Fin::ownedId('stock_items', V::id($b['item_id'] ?? null, 'item_id'), $tid, 'item_id', true);
        $wh = Fin::ownedId('warehouses', V::id($b['warehouse_id'] ?? null, 'warehouse_id'), $tid, 'warehouse_id', true);
        $kind = (string)V::enum($b, 'kind', ['in', 'out', 'return_in', 'return_out']);
        $it = DB::one('SELECT kind FROM stock_items WHERE id=?', [$item]);
        if ($it['kind'] !== 'product') {
            throw new ApiError('validation', 'Hizmetler için stok hareketi oluşturulamaz.', 422, 'item_id');
        }
        $qty = (int)V::int($b, 'qty_milli', 1, 1000000000);
        $date = (string)V::date($b + ['move_date' => Util::today()], 'move_date');
        $project = Fin::ownedId('projects', V::id($b['project_id'] ?? null, 'project_id', false), $tid, 'project_id');
        $res = DB::tx(function () use ($tid, $item, $wh, $kind, $qty, $date, $project, $b) {
            $lv = self::levels($tid, $item)[$item] ?? ['qty_milli' => 0, 'avg_cost_minor' => 0];
            if (in_array($kind, ['in', 'return_in'], true)) {
                $cost = (int)V::int($b + ['unit_cost_minor' => $kind === 'return_in' ? $lv['avg_cost_minor'] : null], 'unit_cost_minor', 0, 1000000000);
            } else {
                $cost = $lv['avg_cost_minor'];
            }
            $short = !in_array($kind, ['in', 'return_in'], true) && $lv['qty_milli'] < $qty;
            if ($short && !self::allowNegative($tid)) {
                throw new ApiError('insufficient_stock', 'Yeterli stok yok (mevcut: ' . Money::fmtQty((int)$lv['qty_milli']) . '). Stok eksiye düşürülemez; ayarlardan “negatif stoğa izin ver” açılabilir.', 409, 'qty_milli', ['available_milli' => (int)$lv['qty_milli']]);
            }
            $id = DB::insert('stock_movements', ['tenant_id' => $tid, 'item_id' => $item, 'warehouse_id' => $wh, 'kind' => $kind, 'qty_milli' => $qty, 'unit_cost_minor' => $cost, 'move_date' => $date, 'project_id' => $project, 'note' => V::str($b, 'note', 300, false), 'created_at' => Util::now()]);
            Audit::log('data', 'stock_movement', ['tenant' => $tid, 'entity' => 'stock_movement', 'id' => $id, 'details' => ['kind' => $kind, 'qty_milli' => $qty]]);
            return ['id' => $id, 'warning' => $short ? 'Negatif stok uyarısı: yeterli stok yok.' : null];
        });
        Res::ok($res, 201);
    }

    private static function moveVoid(int $id, int $tid): never
    {
        $m = DB::one('SELECT * FROM stock_movements WHERE id=? AND tenant_id=?', [$id, $tid]);
        if (!$m) {
            throw new ApiError('not_found', 'Hareket bulunamadı.', 404);
        }
        if ($m['invoice_id'] !== null) {
            throw new ApiError('invalid_state', 'Fatura kaynaklı stok hareketi faturayı iptal ederek kaldırılır.', 409);
        }
        if ($m['status'] === 'voided') {
            throw new ApiError('already_voided', 'Hareket zaten iptal edilmiş.', 409);
        }
        if (in_array($m['kind'], ['in', 'return_in'], true) && !self::allowNegative($tid)) {
            $have = self::levels($tid, (int)$m['item_id'])[(int)$m['item_id']]['qty_milli'] ?? 0;
            if ($have - (int)$m['qty_milli'] < 0) {
                throw new ApiError('stock_used', 'Bu giriş sonrasında stok başka işlemlerde kullanıldığı için iptal edilemez (stok eksiye düşerdi).', 409);
            }
        }
        DB::exec('UPDATE stock_movements SET status=\'voided\' WHERE id=? AND tenant_id=?', [$id, $tid]);
        Audit::log('data', 'stock_movement_voided', ['tenant' => $tid, 'entity' => 'stock_movement', 'id' => $id]);
        Res::ok();
    }

    // ---- fatura entegrasyonu: ürün eşleştirme, politika, bekleyen teslimat ----
    public static function norm(string $s): string
    {
        $s = mb_strtolower(str_replace(['İ', 'I'], ['i', 'ı'], trim($s)));
        return preg_replace('/\s+/u', ' ', $s) ?? $s;
    }

    public static function allowNegative(int $tid): bool
    {
        $set = json_decode((string)DB::val('SELECT settings FROM tenants WHERE id=?', [$tid]), true) ?: [];
        return !empty($set['stock']['allow_negative']);
    }

    /** Ürünü kod → barkod → birebir (normalize) ad ile bulur; yoksa oluşturur. Benzer adlar OTOMATİK birleştirilmez. @return array{0:int,1:bool} [id, yeni_mi] */
    public static function resolveItem(int $tid, array $spec): array
    {
        $name = (string)V::str($spec, 'name', 200);
        $kind = (string)V::enum($spec, 'kind', ['product', 'service'], false, 'product');
        $code = V::str($spec, 'code', 60, false);
        $barcode = V::str($spec, 'barcode', 60, false);
        $hit = null;
        if ($code !== null) {
            $hit = DB::one('SELECT id,kind,archived FROM stock_items WHERE tenant_id=? AND code=?', [$tid, $code]);
        }
        if (!$hit && $barcode !== null) {
            $hit = DB::one('SELECT id,kind,archived FROM stock_items WHERE tenant_id=? AND barcode=?', [$tid, $barcode]);
        }
        if (!$hit) {
            $n = self::norm($name);
            foreach (DB::all('SELECT id,kind,archived,name FROM stock_items WHERE tenant_id=? ORDER BY archived, id', [$tid]) as $x) {
                if (self::norm($x['name']) === $n && $x['kind'] === $kind) {
                    $hit = $x;
                    break;
                }
            }
        }
        if ($hit) {
            if ($hit['kind'] !== $kind) {
                throw new ApiError('item_kind_conflict', 'Bu kod/barkod başka türde (ürün/hizmet) bir karta ait.', 409, 'code');
            }
            if ((int)$hit['archived'] === 1) {
                DB::exec('UPDATE stock_items SET archived=0 WHERE id=? AND tenant_id=?', [$hit['id'], $tid]);
            }
            return [(int)$hit['id'], false];
        }
        $id = DB::insert('stock_items', [
            'tenant_id' => $tid, 'kind' => $kind, 'code' => $code, 'name' => $name, 'unit' => V::str($spec, 'unit', 20, false) ?? 'adet', 'barcode' => $barcode,
            'currency' => V::cur($spec), 'sale_price_minor' => (int)V::amount($spec + ['sale_price_minor' => 0], 'sale_price_minor', false, true),
            'vat_rate_bp' => (int)V::int($spec, 'vat_rate_bp', 0, 10000, false, 2000), 'created_at' => Util::now(),
        ]);
        Audit::log('data', 'stock_item_autocreated', ['tenant' => $tid, 'entity' => 'stock_item', 'id' => $id, 'details' => ['name' => $name, 'kind' => $kind]]);
        return [$id, true];
    }

    public static function defaultWarehouse(int $tid): int
    {
        $id = DB::val('SELECT id FROM warehouses WHERE tenant_id=? AND archived=0 ORDER BY id LIMIT 1', [$tid]);
        return $id ? (int)$id : DB::insert('warehouses', ['tenant_id' => $tid, 'name' => 'Ana Depo']);
    }

    /** Yazılan ada göre kesin eşleşme ve benzer kartlar (kullanıcı onayı için; otomatik birleştirme yok). */
    private static function match(Req $r, int $tid): never
    {
        $name = (string)V::str($r->query, 'name', 200, false);
        $code = V::str($r->query, 'code', 60, false);
        $n = self::norm($name);
        $fold = fn(string $s) => strtr($s, ['ı' => 'i', 'ğ' => 'g', 'ü' => 'u', 'ş' => 's', 'ö' => 'o', 'ç' => 'c']);
        $nf = $fold($n);
        $exact = null;
        $similar = [];
        foreach (DB::all('SELECT id,kind,code,name,unit,barcode,archived,vat_rate_bp,sale_price_minor FROM stock_items WHERE tenant_id=?', [$tid]) as $x) {
            $xn = self::norm($x['name']);
            $xf = $fold($xn);
            if (($code !== null && $x['code'] === $code) || ($n !== '' && $xn === $n)) {
                $exact = $x;
            } elseif ($nf !== '' && strlen($nf) >= 3 && (str_contains($xf, $nf) || str_contains($nf, $xf) || (strlen($nf) <= 60 && strlen($xf) <= 60 && levenshtein($nf, $xf) <= 2))) {
                $similar[] = $x;
            }
        }
        Res::ok(['exact' => $exact, 'similar' => array_slice($similar, 0, 8)]);
    }

    /** Satış faturalarında karşılanmamış (teslim edilmemiş) miktar: item_id => milli */
    public static function backorders(int $tid): array
    {
        $rows = DB::all("SELECT l.item_id, SUM(l.qty_milli - l.fulfilled_milli) pend FROM invoice_lines l JOIN invoices i ON i.id=l.invoice_id
                         WHERE l.tenant_id=? AND i.status='issued' AND i.kind='sales' AND l.item_id IS NOT NULL AND l.warehouse_id IS NOT NULL AND l.qty_milli > l.fulfilled_milli GROUP BY l.item_id", [$tid]);
        $out = [];
        foreach ($rows as $x) {
            $out[(int)$x['item_id']] = (int)$x['pend'];
        }
        return $out;
    }

    private static function itemDelete(int $id, int $tid): never
    {
        Fin::deleteIfUnused('stock_items', $id, $tid, 'Ürün', [['stock_movements', 'item_id'], ['invoice_lines', 'item_id']]);
    }

    private static function whDelete(int $id, int $tid): never
    {
        Fin::deleteIfUnused('warehouses', $id, $tid, 'Depo', [['stock_movements', 'warehouse_id'], ['invoice_lines', 'warehouse_id']]);
    }

    /** Stok raporu: mevcut miktar, dönem giriş/çıkışları (kaynak belgeye göre), karşılanmamış satış ve iptal edilen hareketler. */
    private static function report(Req $r, int $tid): never
    {
        $from = V::date($r->query, 'from', false);
        $to = V::date($r->query, 'to', false);
        $lv = self::levels($tid);
        $back = self::backorders($tid);
        $items = [];
        foreach (DB::all("SELECT id,kind,code,name,unit,min_qty_milli FROM stock_items WHERE tenant_id=? AND kind='product' ORDER BY name", [$tid]) as $x) {
            $l = $lv[(int)$x['id']] ?? ['qty_milli' => 0, 'value_minor' => 0, 'avg_cost_minor' => 0];
            $items[(int)$x['id']] = ['item_id' => (int)$x['id'], 'name' => $x['name'], 'code' => $x['code'], 'unit' => $x['unit'], 'qty_milli' => $l['qty_milli'], 'value_minor' => $l['value_minor'], 'avg_cost_minor' => $l['avg_cost_minor'],
                'backorder_milli' => $back[(int)$x['id']] ?? 0, 'in_milli' => 0, 'out_milli' => 0, 'voided_count' => 0, 'by_source' => new \stdClass()];
        }
        $w = 'm.tenant_id=?';
        $p = [$tid];
        if ($from) {
            $w .= ' AND m.move_date>=?';
            $p[] = $from;
        }
        if ($to) {
            $w .= ' AND m.move_date<=?';
            $p[] = $to;
        }
        $src = [];
        foreach (DB::all("SELECT m.item_id, m.kind, m.status, m.qty_milli, m.invoice_id, i.kind AS ikind FROM stock_movements m LEFT JOIN invoices i ON i.id=m.invoice_id WHERE $w", $p) as $m) {
            $id = (int)$m['item_id'];
            if (!isset($items[$id])) {
                continue;
            }
            if ($m['status'] === 'voided') {
                $items[$id]['voided_count']++;
                continue;
            }
            $inn = in_array($m['kind'], ['in', 'return_in'], true);
            $items[$id][$inn ? 'in_milli' : 'out_milli'] += (int)$m['qty_milli'];
            $s = $m['invoice_id'] === null ? 'manual' : ($m['ikind'] === 'sales' ? 'sales_invoice' : 'purchase_invoice');
            $src[$id][$s] = ($src[$id][$s] ?? 0) + (int)$m['qty_milli'] * ($inn ? 1 : -1);
        }
        foreach ($src as $id => $s) {
            $items[$id]['by_source'] = $s;
        }
        Res::ok(['items' => array_values($items), 'from' => $from, 'to' => $to, 'note' => 'Karşılanmamış satış miktarı fiziksel stoktan ayrıdır; stok eksiye düşürülmez (ayarlardan açılmadıkça).']);
    }
}
