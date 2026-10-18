<?php
declare(strict_types=1);

namespace FB;

/** Kredi kartları ve banka kredileri. */
final class CardCtl
{
    public static function routes(Router $r): void
    {
        $t = ['auth' => 'tenant'];
        $ti = ['auth' => 'tenant', 'idem' => true];
        $r->add('GET', '/cards', fn($q, $p, $tid) => self::cards($tid), $t);
        $r->add('POST', '/cards', fn($q, $p, $tid) => self::cardCreate($q, $tid), $ti);
        $r->add('PATCH', '/cards/{id}', fn($q, $p, $tid) => self::cardUpdate($q, (int)$p['id'], $tid), $t);
        $r->add('GET', '/cards/{id}', fn($q, $p, $tid) => self::cardDetail((int)$p['id'], $tid), $t);
        $r->add('POST', '/cards/{id}/purchases', fn($q, $p, $tid) => self::purchase($q, (int)$p['id'], $tid), $ti);
        $r->add('POST', '/cards/{id}/pay', fn($q, $p, $tid) => self::pay($q, (int)$p['id'], $tid), $ti);
        $r->add('POST', '/card-purchases/{id}/void', fn($q, $p, $tid) => self::voidPurchase((int)$p['id'], $tid), $t);
        $r->add('GET', '/loans', fn($q, $p, $tid) => self::loans($tid), $t);
        $r->add('POST', '/loans', fn($q, $p, $tid) => self::loanCreate($q, $tid), $ti);
        $r->add('GET', '/loans/{id}', fn($q, $p, $tid) => self::loanDetail((int)$p['id'], $tid), $t);
        $r->add('POST', '/loans/{id}/disburse', fn($q, $p, $tid) => self::disburse($q, (int)$p['id'], $tid), $ti);
        $r->add('POST', '/loans/{id}/pay', fn($q, $p, $tid) => self::loanPay($q, (int)$p['id'], $tid), $ti);
    }

    // ---------- kartlar ----------
    private static function day(array $b, string $k, int $def): int
    {
        return (int)V::int($b, $k, 1, 28, false, $def);
    }

    /** Kartın tüm ekstre dönemleri; ödemeler eskiden yeniye FIFO uygulanır. */
    public static function statements(int $tid, int $cardId, array $card): array
    {
        $rows = DB::all("SELECT ci.due_date, SUM(ci.amount_minor) amt FROM card_installments ci JOIN card_purchases cp ON cp.id=ci.purchase_id AND cp.status='posted'
                         WHERE ci.tenant_id=? AND ci.card_id=? GROUP BY ci.due_date ORDER BY ci.due_date", [$tid, $cardId]);
        $paidPool = (int)DB::val("SELECT COALESCE(SUM(amount_minor),0) FROM transactions WHERE tenant_id=? AND card_id=? AND kind='card_payment' AND status='posted'", [$tid, $cardId]);
        $today = Util::today();
        $out = [];
        foreach ($rows as $s) {
            $amt = (int)$s['amt'];
            $paid = min($amt, $paidPool);
            $paidPool -= $paid;
            $due = $s['due_date'];
            $sd = (int)$card['statement_day'];
            $dd = (int)$card['due_day'];
            $closing = $dd > $sd ? substr($due, 0, 8) . str_pad((string)$sd, 2, '0', STR_PAD_LEFT) : Util::addMonths($due, -1, $sd);
            $rem = $amt - $paid;
            $out[] = ['due_date' => $due, 'closing_date' => $closing, 'billed' => $closing <= $today, 'amount_minor' => $amt, 'paid_minor' => $paid, 'remaining_minor' => $rem,
                'state' => $rem <= 0 ? 'paid' : ($due < $today ? 'overdue' : ($closing <= $today ? 'due' : 'upcoming'))];
        }
        return ['statements' => $out, 'credit_minor' => $paidPool];
    }

    private static function cards(int $tid): never
    {
        $rows = DB::all('SELECT c.*, w.name AS workspace_name FROM cards c JOIN workspaces w ON w.id=c.workspace_id WHERE c.tenant_id=? ORDER BY c.archived, c.id', [$tid]);
        foreach ($rows as &$c) {
            $c['id'] = (int)$c['id'];
            $s = self::statements($tid, $c['id'], $c);
            $debt = array_sum(array_column($s['statements'], 'remaining_minor'));
            $c['debt_minor'] = $debt - $s['credit_minor'];
            $c['available_minor'] = (int)$c['limit_minor'] - $c['debt_minor'];
            $next = array_values(array_filter($s['statements'], fn($x) => $x['remaining_minor'] > 0));
            $c['next_due'] = $next[0] ?? null;
        }
        Res::ok(['cards' => $rows]);
    }

    private static function cardCreate(Req $r, int $tid): never
    {
        $b = $r->body;
        $id = DB::insert('cards', [
            'tenant_id' => $tid, 'workspace_id' => Fin::ownedId('workspaces', V::id($b['workspace_id'] ?? null, 'workspace_id'), $tid, 'workspace_id', true),
            'name' => V::str($b, 'name', 120), 'bank_name' => V::str($b, 'bank_name', 120, false), 'currency' => V::cur($b),
            'limit_minor' => V::amount($b + ['limit_minor' => 0], 'limit_minor', false, true), 'statement_day' => self::day($b, 'statement_day', 1), 'due_day' => self::day($b, 'due_day', 10), 'created_at' => Util::now(),
        ]);
        Audit::log('data', 'card_created', ['tenant' => $tid, 'entity' => 'card', 'id' => $id]);
        Res::ok(['id' => $id], 201);
    }

    private static function cardUpdate(Req $r, int $id, int $tid): never
    {
        if (!DB::val('SELECT id FROM cards WHERE id=? AND tenant_id=?', [$id, $tid])) {
            throw new ApiError('not_found', 'Kart bulunamadı.', 404);
        }
        $b = $r->body;
        $u = [];
        foreach (['name' => 120, 'bank_name' => 120] as $k => $m) {
            if (array_key_exists($k, $b)) {
                $u[$k] = V::str($b, $k, $m, $k === 'name');
            }
        }
        if (isset($b['limit_minor'])) {
            $u['limit_minor'] = V::amount($b, 'limit_minor', true, true);
        }
        foreach (['statement_day', 'due_day'] as $k) {
            if (isset($b[$k])) {
                $u[$k] = self::day($b, $k, 1);
            }
        }
        if (isset($b['archived'])) {
            $u['archived'] = V::bool($b, 'archived');
        }
        if ($u) {
            DB::update('cards', $u, 'id=? AND tenant_id=?', [$id, $tid]);
        }
        Res::ok();
    }

    private static function cardRow(int $id, int $tid): array
    {
        $c = DB::one('SELECT * FROM cards WHERE id=? AND tenant_id=?', [$id, $tid]);
        if (!$c) {
            throw new ApiError('not_found', 'Kart bulunamadı.', 404);
        }
        return $c;
    }

    private static function cardDetail(int $id, int $tid): never
    {
        $c = self::cardRow($id, $tid);
        $s = self::statements($tid, $id, $c);
        $purch = DB::all('SELECT id,txn_date,description,amount_minor,installments,status,category_id FROM card_purchases WHERE tenant_id=? AND card_id=? ORDER BY txn_date DESC, id DESC LIMIT 300', [$tid, $id]);
        $pays = DB::all("SELECT id,txn_date,amount_minor,account_id,status FROM transactions WHERE tenant_id=? AND card_id=? AND kind='card_payment' ORDER BY txn_date DESC, id DESC", [$tid, $id]);
        $debt = array_sum(array_column($s['statements'], 'remaining_minor')) - $s['credit_minor'];
        Res::ok(['card' => $c, 'statements' => $s['statements'], 'credit_minor' => $s['credit_minor'], 'debt_minor' => $debt, 'available_minor' => (int)$c['limit_minor'] - $debt, 'purchases' => $purch, 'payments' => $pays]);
    }

    /** Kartla alışveriş: harcama alışveriş tarihinde GİDER olur; ekstre ödemesi ikinci gider yazmaz. */
    private static function purchase(Req $r, int $id, int $tid): never
    {
        $c = self::cardRow($id, $tid);
        if ((int)$c['archived'] === 1) {
            throw new ApiError('archived', 'Arşivlenmiş karta işlem yapılamaz.', 422);
        }
        $b = $r->body;
        [$gross, $vat, $rate] = LedgerCtl::vatCalc($b);
        $n = (int)V::int($b, 'installments', 1, 36, false, 1);
        $date = (string)V::date($b + ['txn_date' => Util::today()], 'txn_date');
        if ($date > Util::addDays(Util::today(), 1)) {
            throw new ApiError('future_date', 'Alışveriş tarihi ileri olamaz.', 422, 'txn_date');
        }
        if ($gross < $n) {
            throw new ApiError('validation', 'Tutar taksit sayısından küçük olamaz.', 422, 'amount_minor');
        }
        $catId = V::id($b['category_id'] ?? null, 'category_id', false);
        if ($catId !== null) {
            $k = DB::val('SELECT kind FROM categories WHERE id=? AND tenant_id=?', [$catId, $tid]);
            if ($k !== 'expense') {
                throw new ApiError('validation', 'Gider kategorisi seçin.', 422, 'category_id');
            }
        }
        $warn = null;
        $pid = DB::tx(function () use ($tid, $c, $b, $gross, $vat, $rate, $n, $date, $catId, &$warn) {
            $sd = (int)$c['statement_day'];
            $dd = (int)$c['due_day'];
            $day = (int)substr($date, 8, 2);
            $firstStatement = $day <= $sd ? substr($date, 0, 7) . '-01' : Util::addMonths(substr($date, 0, 7) . '-01', 1, 1);
            $pid = DB::insert('card_purchases', [
                'tenant_id' => $tid, 'card_id' => $c['id'], 'workspace_id' => $c['workspace_id'], 'txn_date' => $date, 'description' => V::str($b, 'description', 250),
                'amount_minor' => $gross, 'vat_rate_bp' => $rate, 'vat_minor' => $vat, 'installments' => $n, 'category_id' => $catId,
                'contact_id' => Fin::ownedId('contacts', V::id($b['contact_id'] ?? null, 'contact_id', false), $tid, 'contact_id'),
                'project_id' => Fin::ownedId('projects', V::id($b['project_id'] ?? null, 'project_id', false), $tid, 'project_id'), 'created_at' => Util::now(),
            ]);
            $base = intdiv($gross, $n);
            for ($k = 0; $k < $n; $k++) {
                $stMonth = Util::addMonths($firstStatement, $k, 1);
                $dueMonth = $dd > $sd ? $stMonth : Util::addMonths($stMonth, 1, 1);
                $due = Util::addMonths($dueMonth, 0, $dd);
                DB::insert('card_installments', ['tenant_id' => $tid, 'card_id' => $c['id'], 'purchase_id' => $pid, 'seq' => $k + 1, 'due_date' => $due, 'amount_minor' => $k === $n - 1 ? $gross - $base * ($n - 1) : $base]);
            }
            $s = self::statements($tid, (int)$c['id'], $c);
            $debt = array_sum(array_column($s['statements'], 'remaining_minor')) - $s['credit_minor'];
            if ($debt > (int)$c['limit_minor'] && (int)$c['limit_minor'] > 0) {
                $warn = 'Kart limiti aşıldı.';
            }
            Audit::log('data', 'card_purchase', ['tenant' => $tid, 'entity' => 'card_purchase', 'id' => $pid, 'details' => ['amount_minor' => $gross, 'installments' => $n]]);
            return $pid;
        });
        Res::ok(['id' => $pid, 'warning' => $warn], 201);
    }

    private static function voidPurchase(int $id, int $tid): never
    {
        $p = DB::one('SELECT * FROM card_purchases WHERE id=? AND tenant_id=?', [$id, $tid]);
        if (!$p) {
            throw new ApiError('not_found', 'Alışveriş bulunamadı.', 404);
        }
        if ($p['status'] === 'voided') {
            throw new ApiError('already_voided', 'Zaten iptal edilmiş.', 409);
        }
        DB::exec('UPDATE card_purchases SET status=\'voided\' WHERE id=? AND tenant_id=?', [$id, $tid]);
        Audit::log('data', 'card_purchase_voided', ['tenant' => $tid, 'entity' => 'card_purchase', 'id' => $id, 'details' => ['amount_minor' => (int)$p['amount_minor']]]);
        Res::ok();
    }

    /** Kart borcu ödemesi: banka hesabından çıkar, GİDER OLMAZ (kind=card_payment). */
    private static function pay(Req $r, int $id, int $tid): never
    {
        $c = self::cardRow($id, $tid);
        $b = $r->body;
        $acc = Fin::ownedId('accounts', V::id($b['account_id'] ?? null, 'account_id'), $tid, 'account_id', true);
        $amt = (int)V::amount($b, 'amount_minor');
        $date = (string)V::date($b + ['date' => Util::today()], 'date');
        $res = DB::tx(function () use ($tid, $c, $acc, $amt, $date) {
            DB::one('SELECT id FROM cards WHERE id=? FOR UPDATE', [$c['id']]);
            $a = DB::one('SELECT currency FROM accounts WHERE id=? AND tenant_id=?', [$acc, $tid]);
            if ($a['currency'] !== $c['currency']) {
                throw new ApiError('currency_mismatch', "Kart para birimi {$c['currency']}; aynı para biriminde hesap seçin.", 422, 'account_id');
            }
            $s = self::statements($tid, (int)$c['id'], $c);
            $debt = array_sum(array_column($s['statements'], 'remaining_minor'));
            if ($amt > $debt) {
                throw new ApiError('overpayment', 'Ödeme tutarı kart borcundan büyük olamaz.', 422, 'amount_minor', ['remaining_minor' => $debt]);
            }
            $tx = Fin::post($tid, ['account_id' => $acc, 'direction' => 'out', 'kind' => 'card_payment', 'amount_minor' => $amt, 'txn_date' => $date, 'description' => 'Kart ödemesi: ' . $c['name'], 'card_id' => $c['id']]);
            Audit::log('data', 'card_payment', ['tenant' => $tid, 'entity' => 'card', 'id' => $c['id'], 'details' => ['txn' => $tx, 'amount_minor' => $amt]]);
            return ['id' => $tx, 'remaining_minor' => $debt - $amt];
        });
        Res::ok($res, 201);
    }

    // ---------- krediler ----------
    /** Eşit taksitli (annuity) plan; faiz tam sayı hesabı, son taksit kalan anaparayı kapatır. */
    public static function schedule(int $principal, int $rateBp, int $n, string $start): array
    {
        if ($rateBp === 0) {
            $pay = intdiv($principal, $n);
        } else {
            $r = $rateBp / 10000;
            $pay = (int)round($principal * $r / (1 - (1 + $r) ** -$n));
        }
        $bal = $principal;
        $out = [];
        $day = (int)substr($start, 8, 2);
        for ($i = 1; $i <= $n; $i++) {
            $interest = Money::mulDiv($bal, $rateBp, 10000);
            $pr = $i === $n ? $bal : max(0, min($bal, $pay - $interest));
            $out[] = ['seq' => $i, 'due_date' => Util::addMonths($start, $i, $day), 'principal_minor' => $pr, 'interest_minor' => $interest];
            $bal -= $pr;
        }
        return $out;
    }

    private static function loans(int $tid): never
    {
        $rows = DB::all('SELECT l.*, w.name AS workspace_name FROM loans l JOIN workspaces w ON w.id=l.workspace_id WHERE l.tenant_id=? ORDER BY l.id DESC', [$tid]);
        foreach ($rows as &$l) {
            $l['id'] = (int)$l['id'];
            $paid = DB::one("SELECT COALESCE(SUM(li.principal_minor),0) pr, COALESCE(SUM(li.interest_minor),0) it, COUNT(*) n FROM loan_installments li
                             WHERE li.tenant_id=? AND li.loan_id=? AND EXISTS (SELECT 1 FROM transactions t WHERE t.loan_installment_id=li.id AND t.status='posted')", [$tid, $l['id']]);
            $l['paid_principal_minor'] = (int)$paid['pr'];
            $l['remaining_principal_minor'] = (int)$l['principal_minor'] - (int)$paid['pr'];
            $l['paid_count'] = (int)$paid['n'];
            $l['next'] = DB::one("SELECT li.id,li.seq,li.due_date,li.principal_minor,li.interest_minor FROM loan_installments li WHERE li.tenant_id=? AND li.loan_id=?
                                  AND NOT EXISTS (SELECT 1 FROM transactions t WHERE t.loan_installment_id=li.id AND t.status='posted') ORDER BY li.due_date LIMIT 1", [$tid, $l['id']]);
        }
        Res::ok(['loans' => $rows]);
    }

    private static function loanCreate(Req $r, int $tid): never
    {
        $b = $r->body;
        $ws = Fin::ownedId('workspaces', V::id($b['workspace_id'] ?? null, 'workspace_id'), $tid, 'workspace_id', true);
        $principal = (int)V::amount($b, 'principal_minor');
        $rate = (int)V::int($b, 'monthly_rate_bp', 0, 5000, false, 0);
        $n = (int)V::int($b, 'term_months', 1, 360);
        $start = (string)V::date($b, 'start_date');
        $sched = null;
        if (isset($b['schedule']) && is_array($b['schedule'])) {
            $sched = [];
            $sum = 0;
            foreach (array_values($b['schedule']) as $i => $x) {
                $pm = (int)V::int($x, 'principal_minor', 0, Money::MAX);
                $sched[] = ['seq' => $i + 1, 'due_date' => V::date($x, 'due_date'), 'principal_minor' => $pm, 'interest_minor' => (int)V::int($x, 'interest_minor', 0, Money::MAX)];
                $sum += $pm;
            }
            if ($sum !== $principal || count($sched) !== $n) {
                throw new ApiError('validation', 'Özel planın anapara toplamı kredi tutarına ve taksit sayısı vadeye eşit olmalı.', 422, 'schedule');
            }
        }
        $id = DB::tx(function () use ($tid, $ws, $b, $principal, $rate, $n, $start, $sched) {
            $id = DB::insert('loans', ['tenant_id' => $tid, 'workspace_id' => $ws, 'name' => V::str($b, 'name', 120), 'bank_name' => V::str($b, 'bank_name', 120, false), 'currency' => V::cur($b),
                'principal_minor' => $principal, 'monthly_rate_bp' => $rate, 'term_months' => $n, 'start_date' => $start, 'created_at' => Util::now()]);
            foreach ($sched ?? self::schedule($principal, $rate, $n, $start) as $s) {
                DB::insert('loan_installments', $s + ['tenant_id' => $tid, 'loan_id' => $id]);
            }
            Audit::log('data', 'loan_created', ['tenant' => $tid, 'entity' => 'loan', 'id' => $id, 'details' => ['principal_minor' => $principal, 'term' => $n]]);
            return $id;
        });
        Res::ok(['id' => $id], 201);
    }

    private static function loanRow(int $id, int $tid): array
    {
        $l = DB::one('SELECT * FROM loans WHERE id=? AND tenant_id=?', [$id, $tid]);
        if (!$l) {
            throw new ApiError('not_found', 'Kredi bulunamadı.', 404);
        }
        return $l;
    }

    private static function loanDetail(int $id, int $tid): never
    {
        $l = self::loanRow($id, $tid);
        $rows = DB::all("SELECT li.*, (SELECT t.id FROM transactions t WHERE t.loan_installment_id=li.id AND t.status='posted' LIMIT 1) AS txn_id,
                         (SELECT t.txn_date FROM transactions t WHERE t.loan_installment_id=li.id AND t.status='posted' LIMIT 1) AS paid_date
                         FROM loan_installments li WHERE li.tenant_id=? AND li.loan_id=? ORDER BY li.seq", [$tid, $id]);
        $disb = DB::val("SELECT id FROM transactions WHERE tenant_id=? AND loan_id=? AND kind='loan_proceeds' AND status='posted'", [$tid, $id]);
        Res::ok(['loan' => $l, 'installments' => $rows, 'disbursed' => (bool)$disb, 'totals' => ['principal' => array_sum(array_column($rows, 'principal_minor')), 'interest' => array_sum(array_column($rows, 'interest_minor'))]]);
    }

    /** Kredi kullandırım: hesaba para girer, GELİR OLMAZ (kind=loan_proceeds). */
    private static function disburse(Req $r, int $id, int $tid): never
    {
        $l = self::loanRow($id, $tid);
        $acc = Fin::ownedId('accounts', V::id($r->body['account_id'] ?? null, 'account_id'), $tid, 'account_id', true);
        $date = (string)V::date($r->body + ['date' => $l['start_date']], 'date');
        $tx = DB::tx(function () use ($tid, $l, $acc, $date) {
            DB::one('SELECT id FROM loans WHERE id=? FOR UPDATE', [$l['id']]);
            if (DB::val("SELECT id FROM transactions WHERE tenant_id=? AND loan_id=? AND kind='loan_proceeds' AND status='posted'", [$tid, $l['id']])) {
                throw new ApiError('already_disbursed', 'Kredi kullandırımı zaten kaydedilmiş.', 409);
            }
            $a = DB::one('SELECT currency FROM accounts WHERE id=? AND tenant_id=?', [$acc, $tid]);
            if ($a['currency'] !== $l['currency']) {
                throw new ApiError('currency_mismatch', 'Hesap para birimi kredi ile aynı olmalı.', 422, 'account_id');
            }
            return Fin::post($tid, ['account_id' => $acc, 'direction' => 'in', 'kind' => 'loan_proceeds', 'amount_minor' => $l['principal_minor'], 'txn_date' => $date, 'description' => 'Kredi kullandırım: ' . $l['name'], 'loan_id' => $l['id']]);
        });
        Res::ok(['id' => $tx], 201);
    }

    /** Taksit ödemesi: anapara gider değildir; yalnızca faiz payı gider sayılır. */
    private static function loanPay(Req $r, int $id, int $tid): never
    {
        $l = self::loanRow($id, $tid);
        $b = $r->body;
        $acc = Fin::ownedId('accounts', V::id($b['account_id'] ?? null, 'account_id'), $tid, 'account_id', true);
        $inst = V::id($b['installment_id'] ?? null, 'installment_id');
        $date = (string)V::date($b + ['date' => Util::today()], 'date');
        $tx = DB::tx(function () use ($tid, $l, $acc, $inst, $date) {
            $i = DB::one('SELECT * FROM loan_installments WHERE id=? AND loan_id=? AND tenant_id=? FOR UPDATE', [$inst, $l['id'], $tid]);
            if (!$i) {
                throw new ApiError('not_found', 'Taksit bulunamadı.', 404, 'installment_id');
            }
            if (DB::val("SELECT id FROM transactions WHERE tenant_id=? AND loan_installment_id=? AND status='posted'", [$tid, $inst])) {
                throw new ApiError('already_paid', 'Bu taksit zaten ödenmiş.', 409);
            }
            $a = DB::one('SELECT currency FROM accounts WHERE id=? AND tenant_id=?', [$acc, $tid]);
            if ($a['currency'] !== $l['currency']) {
                throw new ApiError('currency_mismatch', 'Hesap para birimi kredi ile aynı olmalı.', 422, 'account_id');
            }
            $total = (int)$i['principal_minor'] + (int)$i['interest_minor'];
            $tx = Fin::post($tid, ['account_id' => $acc, 'direction' => 'out', 'kind' => 'loan_payment', 'amount_minor' => $total, 'interest_minor' => $i['interest_minor'], 'txn_date' => $date,
                'description' => "Kredi taksiti {$i['seq']}/{$l['term_months']}: {$l['name']}", 'loan_id' => $l['id'], 'loan_installment_id' => $inst]);
            Audit::log('data', 'loan_payment', ['tenant' => $tid, 'entity' => 'loan', 'id' => $l['id'], 'details' => ['txn' => $tx, 'seq' => (int)$i['seq']]]);
            return $tx;
        });
        Res::ok(['id' => $tx], 201);
    }
}
