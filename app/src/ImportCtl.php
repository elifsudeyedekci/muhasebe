<?php
declare(strict_types=1);

namespace FB;

/** Banka ekstresi içe aktarma ve mutabakat. Aynı ekstre ikinci kez yüklenirse mükerrer kayıt oluşmaz. */
final class ImportCtl
{
    public static function routes(Router $r): void
    {
        $t = ['auth' => 'tenant'];
        $r->add('POST', '/accounts/{id}/statement', fn($q, $p, $tid) => self::import($q, (int)$p['id'], $tid), $t);
        $r->add('GET', '/accounts/{id}/reconciliation', fn($q, $p, $tid) => self::summary((int)$p['id'], $tid), $t);
        $r->add('GET', '/statement-lines', fn($q, $p, $tid) => self::lines($q, $tid), $t);
        $r->add('POST', '/statement-lines/{id}/match', fn($q, $p, $tid) => self::match($q, (int)$p['id'], $tid), $t);
        $r->add('POST', '/statement-lines/{id}/create', fn($q, $p, $tid) => self::createFrom($q, (int)$p['id'], $tid), ['auth' => 'tenant', 'idem' => true]);
        $r->add('POST', '/statement-lines/{id}/ignore', fn($q, $p, $tid) => self::setStatus((int)$p['id'], $tid, 'ignored'), $t);
        $r->add('POST', '/statement-lines/{id}/unmatch', fn($q, $p, $tid) => self::unmatch((int)$p['id'], $tid), $t);
    }

    private static function import(Req $r, int $accId, int $tid): never
    {
        $acc = DB::one('SELECT * FROM accounts WHERE id=? AND tenant_id=?', [$accId, $tid]);
        if (!$acc) {
            throw new ApiError('not_found', 'Hesap bulunamadı.', 404);
        }
        $lines = $r->body['lines'] ?? null;
        if (!is_array($lines) || !$lines || count($lines) > 5000) {
            throw new ApiError('validation', '1-5000 arası satır gerekli.', 422, 'lines');
        }
        $seen = [];
        $added = 0;
        $dups = 0;
        DB::tx(function () use ($lines, $tid, $accId, &$seen, &$added, &$dups) {
            foreach ($lines as $l) {
                if (!is_array($l)) {
                    throw new ApiError('validation', 'Geçersiz satır.', 422, 'lines');
                }
                $date = (string)V::date($l, 'date');
                $amt = V::signedAmount($l, 'amount_minor');
                if ($amt === 0) {
                    continue;
                }
                $desc = V::str($l, 'description', 300, false);
                $norm = preg_replace('/\s+/', ' ', mb_strtolower((string)$desc)) ?? '';
                $base = $date . '|' . $amt . '|' . $norm;
                $seen[$base] = ($seen[$base] ?? 0) + 1; // aynı gün/tutar/açıklamalı gerçek tekrarlar sıra no ile ayrışır
                $hash = hash('sha256', $accId . '|' . $base . '|' . $seen[$base]);
                $n = DB::exec('INSERT IGNORE INTO statement_lines (tenant_id,account_id,line_date,description,amount_minor,line_hash,created_at) VALUES (?,?,?,?,?,?,?)', [$tid, $accId, $date, $desc, $amt, $hash, Util::now()]);
                $n === 1 ? $added++ : $dups++;
            }
            Audit::log('data', 'statement_imported', ['tenant' => $tid, 'entity' => 'account', 'id' => $accId, 'details' => ['added' => $added, 'duplicates' => $dups]]);
        });
        Res::ok(['added' => $added, 'duplicates' => $dups], 201);
    }

    private static function lineRow(int $id, int $tid): array
    {
        $l = DB::one('SELECT * FROM statement_lines WHERE id=? AND tenant_id=?', [$id, $tid]);
        if (!$l) {
            throw new ApiError('not_found', 'Ekstre satırı bulunamadı.', 404);
        }
        return $l;
    }

    private static function lines(Req $r, int $tid): never
    {
        $w = ['s.tenant_id=?'];
        $p = [$tid];
        if (!empty($r->query['account_id'])) {
            $w[] = 's.account_id=?';
            $p[] = (int)$r->query['account_id'];
        }
        if (in_array($r->query['status'] ?? '', ['unmatched', 'matched', 'ignored'], true)) {
            $w[] = 's.status=?';
            $p[] = $r->query['status'];
        }
        $rows = DB::all('SELECT s.* FROM statement_lines s WHERE ' . implode(' AND ', $w) . ' ORDER BY s.line_date DESC, s.id DESC LIMIT 500', $p);
        foreach ($rows as &$l) {
            $l['id'] = (int)$l['id'];
            $l['amount_minor'] = (int)$l['amount_minor'];
            $l['candidates'] = [];
            if ($l['status'] === 'unmatched') {
                $dir = $l['amount_minor'] > 0 ? 'in' : 'out';
                $l['candidates'] = DB::all("SELECT t.id,t.txn_date,t.kind,t.description,t.amount_minor FROM transactions t WHERE t.tenant_id=? AND t.account_id=? AND t.status='posted' AND t.statement_line_id IS NULL
                    AND t.direction=? AND t.amount_minor=? AND t.txn_date BETWEEN ? AND ? ORDER BY ABS(DATEDIFF(t.txn_date,?)) LIMIT 5",
                    [$tid, $l['account_id'], $dir, abs($l['amount_minor']), Util::addDays($l['line_date'], -5), Util::addDays($l['line_date'], 5), $l['line_date']]);
            }
        }
        Res::ok(['lines' => $rows]);
    }

    private static function match(Req $r, int $id, int $tid): never
    {
        $l = self::lineRow($id, $tid);
        $txId = (int)V::id($r->body['transaction_id'] ?? null, 'transaction_id');
        DB::tx(function () use ($l, $id, $tid, $txId) {
            $line = DB::one('SELECT * FROM statement_lines WHERE id=? FOR UPDATE', [$id]);
            if ($line['status'] === 'matched') {
                throw new ApiError('already_matched', 'Satır zaten eşleşmiş.', 409);
            }
            $t = DB::one("SELECT * FROM transactions WHERE id=? AND tenant_id=? AND status='posted' FOR UPDATE", [$txId, $tid]);
            if (!$t || (int)$t['account_id'] !== (int)$l['account_id']) {
                throw new ApiError('not_found', 'Aynı hesapta aktif işlem bulunamadı.', 404, 'transaction_id');
            }
            $signed = $t['direction'] === 'in' ? (int)$t['amount_minor'] : -(int)$t['amount_minor'];
            if ($signed !== (int)$line['amount_minor']) {
                throw new ApiError('amount_mismatch', 'Tutar veya yön ekstre satırıyla uyuşmuyor.', 422);
            }
            if ($t['statement_line_id'] !== null) {
                throw new ApiError('already_matched', 'İşlem başka bir ekstre satırıyla eşleşmiş.', 409);
            }
            DB::exec("UPDATE statement_lines SET status='matched', matched_txn_id=? WHERE id=?", [$txId, $id]);
            DB::exec('UPDATE transactions SET statement_line_id=? WHERE id=?', [$id, $txId]);
            Audit::log('data', 'statement_matched', ['tenant' => $tid, 'entity' => 'statement_line', 'id' => $id, 'details' => ['txn' => $txId]]);
        });
        Res::ok();
    }

    private static function createFrom(Req $r, int $id, int $tid): never
    {
        $l = self::lineRow($id, $tid);
        $b = $r->body;
        $kind = $l['amount_minor'] > 0 ? 'income' : 'expense';
        $catId = V::id($b['category_id'] ?? null, 'category_id', false);
        if ($catId !== null) {
            $k = DB::val('SELECT kind FROM categories WHERE id=? AND tenant_id=?', [$catId, $tid]);
            if ($k !== $kind) {
                throw new ApiError('validation', 'Kategori türü hareketle uyuşmuyor.', 422, 'category_id');
            }
        }
        $rate = (int)V::int($b, 'vat_rate_bp', 0, 10000, false, 0);
        $res = DB::tx(function () use ($l, $id, $tid, $kind, $catId, $rate, $b) {
            $line = DB::one('SELECT * FROM statement_lines WHERE id=? FOR UPDATE', [$id]);
            if ($line['status'] !== 'unmatched') {
                throw new ApiError('already_matched', 'Satır zaten işlenmiş.', 409);
            }
            $amt = abs((int)$line['amount_minor']);
            $tx = Fin::post($tid, [
                'account_id' => $line['account_id'], 'direction' => $kind === 'income' ? 'in' : 'out', 'kind' => $kind, 'amount_minor' => $amt, 'txn_date' => $line['line_date'],
                'description' => V::str($b, 'description', 300, false) ?? $line['description'], 'category_id' => $catId, 'vat_rate_bp' => $rate, 'vat_minor' => Fin::vatOfGross($amt, $rate),
                'contact_id' => Fin::ownedId('contacts', V::id($b['contact_id'] ?? null, 'contact_id', false), $tid, 'contact_id'), 'statement_line_id' => $id,
            ]);
            DB::exec("UPDATE statement_lines SET status='matched', matched_txn_id=? WHERE id=?", [$tx, $id]);
            Audit::log('data', 'statement_line_posted', ['tenant' => $tid, 'entity' => 'statement_line', 'id' => $id, 'details' => ['txn' => $tx]]);
            return ['id' => $tx];
        });
        Res::ok($res, 201);
    }

    private static function setStatus(int $id, int $tid, string $st): never
    {
        $l = self::lineRow($id, $tid);
        if ($l['status'] === 'matched') {
            throw new ApiError('already_matched', 'Eşleşmiş satır için önce eşleşmeyi kaldırın.', 409);
        }
        DB::exec('UPDATE statement_lines SET status=? WHERE id=? AND tenant_id=?', [$st, $id, $tid]);
        Res::ok();
    }

    private static function unmatch(int $id, int $tid): never
    {
        $l = self::lineRow($id, $tid);
        DB::tx(function () use ($l, $id, $tid) {
            if ($l['matched_txn_id']) {
                DB::exec('UPDATE transactions SET statement_line_id=NULL WHERE id=? AND tenant_id=?', [$l['matched_txn_id'], $tid]);
            }
            DB::exec("UPDATE statement_lines SET status='unmatched', matched_txn_id=NULL WHERE id=? AND tenant_id=?", [$id, $tid]);
        });
        Res::ok();
    }

    private static function summary(int $accId, int $tid): never
    {
        $acc = DB::one('SELECT id,name,currency FROM accounts WHERE id=? AND tenant_id=?', [$accId, $tid]);
        if (!$acc) {
            throw new ApiError('not_found', 'Hesap bulunamadı.', 404);
        }
        $c = DB::all('SELECT status, COUNT(*) n, COALESCE(SUM(amount_minor),0) s FROM statement_lines WHERE tenant_id=? AND account_id=? GROUP BY status', [$tid, $accId]);
        $range = DB::one('SELECT MIN(line_date) a, MAX(line_date) b FROM statement_lines WHERE tenant_id=? AND account_id=?', [$tid, $accId]);
        $unrec = [];
        if ($range['a']) {
            $unrec = DB::all("SELECT id,txn_date,kind,description,direction,amount_minor FROM transactions WHERE tenant_id=? AND account_id=? AND status='posted' AND statement_line_id IS NULL AND txn_date BETWEEN ? AND ? ORDER BY txn_date", [$tid, $accId, $range['a'], $range['b']]);
        }
        Res::ok(['account' => $acc, 'counts' => $c, 'range' => $range, 'unreconciled_transactions' => $unrec]);
    }
}
