<?php
declare(strict_types=1);

namespace FB;

/** Dashboard, ödeme planı, bildirimler ve raporlar. Hepsi gerçek veritabanı kayıtlarından hesaplanır. */
final class ReportCtl
{
    public static function routes(Router $r): void
    {
        $t = ['auth' => 'tenant'];
        $r->add('GET', '/reports/dashboard', fn($q, $p, $tid) => Res::ok(self::dashboard($q->query, $tid)), $t);
        $r->add('GET', '/reports/schedule', fn($q, $p, $tid) => Res::ok(self::schedule($q->query, $tid)), $t);
        $r->add('GET', '/reports/notifications', fn($q, $p, $tid) => Res::ok(['notifications' => self::notifications($tid)]), $t);
        $r->add('GET', '/reports/income-expense', fn($q, $p, $tid) => Res::ok(self::incomeExpense($q->query, $tid)), $t);
        $r->add('GET', '/reports/cashflow', fn($q, $p, $tid) => Res::ok(self::cashflow($q->query, $tid)), $t);
        $r->add('GET', '/reports/vat', fn($q, $p, $tid) => Res::ok(Pl::vat($tid, self::pf($q->query, 'business'))), $t);
        $r->add('GET', '/reports/aging', fn($q, $p, $tid) => Res::ok(self::aging($q->query, $tid)), $t);
        $r->add('GET', '/reports/projects', fn($q, $p, $tid) => Res::ok(self::projects($tid)), $t);
        $r->add('GET', '/reports/budget', fn($q, $p, $tid) => Res::ok(self::budget($q->query, $tid)), $t);
        $r->add('GET', '/budgets', fn($q, $p, $tid) => Res::ok(self::budgetList($q->query, $tid)), $t);
        $r->add('PUT', '/budgets', fn($q, $p, $tid) => self::budgetSet($q, $tid), $t);
        $r->add('GET', '/export/{name}', fn($q, $p, $tid) => ExportCtl::run($q, $p['name'], $tid), $t);
    }

    /** Rapor filtreleri: tarih aralığı, kapsam (işletme/şahsi/tümü), işletme alanı, proje, para birimi. */
    public static function pf(array $q, string $defaultScope = 'all'): array
    {
        $f = [];
        $f['from'] = V::date($q, 'from', false);
        $f['to'] = V::date($q, 'to', false);
        $scope = $q['scope'] ?? $defaultScope;
        $f['scope'] = in_array($scope, ['business', 'personal', 'all'], true) ? $scope : $defaultScope;
        $f['workspace_id'] = !empty($q['workspace_id']) ? (int)$q['workspace_id'] : null;
        $f['project_id'] = !empty($q['project_id']) ? (int)$q['project_id'] : null;
        $f['currency'] = in_array($q['currency'] ?? '', Money::currencies(), true) ? $q['currency'] : null;
        return $f;
    }

    private static function wsIds(int $tid, array $f): ?array
    {
        if ($f['workspace_id']) {
            return [(int)$f['workspace_id']];
        }
        if (in_array($f['scope'], ['business', 'personal'], true)) {
            return array_map('intval', array_column(DB::all('SELECT id FROM workspaces WHERE tenant_id=? AND kind=?', [$tid, $f['scope']]), 'id'));
        }
        return null;
    }

    private static function addTo(array &$a, string $cur, string $key, int $v): void
    {
        $a[$cur][$key] = ($a[$cur][$key] ?? 0) + $v;
    }

    // ---------- dashboard ----------
    public static function dashboard(array $q, int $tid): array
    {
        LedgerCtl::runRecurring($tid);
        $f = self::pf($q, 'all');
        $ws = self::wsIds($tid, $f);
        $today = Util::today();
        $accounts = array_values(array_filter(Fin::accounts($tid, false), fn($a) => $ws === null || in_array($a['workspace_id'], $ws, true)));
        $bal = [];
        $byWs = [];
        foreach ($accounts as $a) {
            self::addTo($bal, $a['currency'], 'balance_minor', $a['balance_minor']);
            self::addTo($byWs, $a['workspace_name'], $a['currency'], $a['balance_minor']);
        }
        // Gerçekleşen (bugüne kadar) gelir-gider ve planlanan (ileri tarihli) ayrı tutulur
        $monthStart = substr($today, 0, 8) . '01';
        $weekStart = Util::addDays($today, -6);
        $pf = $f;
        $pf['scope'] = $f['scope'] === 'all' ? 'business' : $f['scope'];
        $facts = Pl::facts($tid, ['scope' => $pf['scope'], 'workspace_id' => $f['workspace_id'], 'from' => $monthStart, 'to' => Util::addDays($today, 62)]);
        $per = ['today' => [], 'week' => [], 'month' => [], 'planned' => []];
        foreach ($facts as $x) {
            $k = $x['k'] . '_minor';
            if ($x['d'] > $today) {
                self::addTo($per['planned'], $x['currency'], $k, $x['net']);
                continue;
            }
            self::addTo($per['month'], $x['currency'], $k, $x['net']);
            if ($x['d'] >= $weekStart) {
                self::addTo($per['week'], $x['currency'], $k, $x['net']);
            }
            if ($x['d'] === $today) {
                self::addTo($per['today'], $x['currency'], $k, $x['net']);
            }
        }
        $open = array_values(array_filter(Fin::entriesOpen($tid), fn($e) => (int)$e['remaining_minor'] > 0 && ($ws === null || in_array((int)$e['workspace_id'], $ws, true))));
        $ob = [];
        foreach ($open as $e) {
            $c = $e['currency'];
            $rem = (int)$e['remaining_minor'];
            $kind = $e['kind'];
            self::addTo($ob, $c, $kind, $rem);
            if ($e['due_date'] === null) {
                // vadesiz: gecikmiş sayılmaz
            } elseif ($e['due_date'] < $today) {
                self::addTo($ob, $c, 'overdue_' . $kind, $rem);
            } elseif ($e['due_date'] <= Util::addDays($today, 30)) {
                self::addTo($ob, $c, 'due30_' . $kind, $rem);
            }
        }
        $short = fn($e) => ['id' => (int)$e['id'], 'kind' => $e['kind'], 'title' => $e['title'], 'currency' => $e['currency'], 'remaining_minor' => (int)$e['remaining_minor'], 'due_date' => $e['due_date'], 'source' => $e['source'], 'days_overdue' => ($e['due_date'] !== null && $e['due_date'] < $today) ? (int)((strtotime($today) - strtotime($e['due_date'])) / 86400) : 0];
        usort($open, fn($a, $b) => ($a['due_date'] ?? '9999-12-31') <=> ($b['due_date'] ?? '9999-12-31'));
        $overduePay = array_slice(array_map($short, array_filter($open, fn($e) => $e['kind'] === 'payable' && $e['due_date'] !== null && $e['due_date'] < $today && $e['source'] !== 'personal_bill')), 0, 8);
        $upPay = array_slice(array_map($short, array_filter($open, fn($e) => $e['kind'] === 'payable' && $e['due_date'] !== null && $e['due_date'] >= $today && $e['due_date'] <= Util::addDays($today, 14))), 0, 8);
        $expRec = array_slice(array_map($short, array_filter($open, fn($e) => $e['kind'] === 'receivable' && $e['due_date'] !== null && $e['due_date'] <= Util::addDays($today, 30))), 0, 8);
        $bills = array_slice(array_map($short, array_filter($open, fn($e) => $e['source'] === 'personal_bill' && $e['due_date'] !== null && $e['due_date'] <= Util::addDays($today, 14))), 0, 8);
        $toIssue = DB::all("SELECT i.id,i.number,i.issue_date,i.total_minor,i.currency,c.name AS contact_name FROM invoices i JOIN contacts c ON c.id=i.contact_id
                            WHERE i.tenant_id=? AND i.status='planned' AND i.kind='sales' AND i.issue_date<=? ORDER BY i.issue_date LIMIT 8", [$tid, Util::addDays($today, 7)]);
        $sched = self::schedule(['from' => $today, 'to' => Util::addDays($today, 30), 'scope' => $f['scope'], 'workspace_id' => $f['workspace_id'], 'overdue' => '1'], $tid);
        $cf = self::cashflow(['from' => Util::addMonths(substr($today, 0, 8) . '01', -5, 1), 'to' => $today, 'scope' => $f['scope'], 'workspace_id' => $f['workspace_id']], $tid);
        $proj = array_slice(self::projects($tid)['projects'], 0, 5);
        $cards = [];
        foreach (DB::all('SELECT * FROM cards WHERE tenant_id=? AND archived=0', [$tid]) as $c) {
            $s = CardCtl::statements($tid, (int)$c['id'], $c);
            $debt = array_sum(array_column($s['statements'], 'remaining_minor')) - $s['credit_minor'];
            $nx = array_values(array_filter($s['statements'], fn($x) => $x['remaining_minor'] > 0))[0] ?? null;
            $cards[] = ['id' => (int)$c['id'], 'name' => $c['name'], 'currency' => $c['currency'], 'debt_minor' => $debt, 'limit_minor' => (int)$c['limit_minor'], 'next_due' => $nx];
        }
        return [
            'scope' => $f['scope'], 'today' => $today, 'balances' => $bal, 'by_workspace' => $byWs, 'accounts' => $accounts,
            'period' => $per, 'obligations' => $ob, 'overdue_payables' => array_values($overduePay), 'upcoming_payables' => array_values($upPay),
            'expected_receivables' => array_values($expRec), 'personal_bills' => array_values($bills), 'invoices_to_issue' => $toIssue,
            'schedule' => array_slice($sched['items'], 0, 12), 'cashflow' => $cf, 'projects' => $proj, 'cards' => $cards,
            'notification_count' => count(self::notifications($tid)),
            'note' => 'Planlanan tahsilatlar ve ileri tarihli kalemler banka bakiyesine dahil değildir.',
        ];
    }

    // ---------- ödeme planı ----------
    public static function schedule(array $q, int $tid): array
    {
        $f = self::pf($q, 'all');
        $ws = self::wsIds($tid, $f);
        $today = Util::today();
        $from = $f['from'] ?? (!empty($q['overdue']) ? '2000-01-01' : $today);
        $to = $f['to'] ?? Util::addDays($today, 60);
        $items = [];
        foreach (Fin::entriesOpen($tid) as $e) {
            if ((int)$e['remaining_minor'] <= 0 || $e['due_date'] === null || $e['due_date'] < $from || $e['due_date'] > $to || ($ws !== null && !in_array((int)$e['workspace_id'], $ws, true))) {
                continue;
            }
            if ($f['currency'] && $e['currency'] !== $f['currency']) {
                continue;
            }
            $items[] = ['type' => $e['source'] === 'personal_bill' ? 'bill' : $e['kind'], 'ref' => (int)$e['id'], 'date' => $e['due_date'], 'original_date' => $e['original_due_date'], 'title' => $e['title'], 'currency' => $e['currency'],
                'amount_minor' => (int)$e['amount_minor'], 'remaining_minor' => (int)$e['remaining_minor'], 'priority' => (int)$e['priority'], 'direction' => $e['kind'] === 'receivable' ? 'in' : 'out', 'workspace_id' => (int)$e['workspace_id']];
        }
        foreach (DB::all('SELECT * FROM cards WHERE tenant_id=? AND archived=0', [$tid]) as $c) {
            if ($ws !== null && !in_array((int)$c['workspace_id'], $ws, true)) {
                continue;
            }
            foreach (CardCtl::statements($tid, (int)$c['id'], $c)['statements'] as $s) {
                if ($s['remaining_minor'] > 0 && $s['due_date'] >= $from && $s['due_date'] <= $to) {
                    $items[] = ['type' => 'card', 'ref' => (int)$c['id'], 'date' => $s['due_date'], 'original_date' => $s['due_date'], 'title' => 'Kart ekstresi: ' . $c['name'], 'currency' => $c['currency'], 'amount_minor' => $s['amount_minor'], 'remaining_minor' => $s['remaining_minor'], 'priority' => 1, 'direction' => 'out', 'workspace_id' => (int)$c['workspace_id']];
                }
            }
        }
        $inst = DB::all("SELECT li.id,li.seq,li.due_date,li.principal_minor,li.interest_minor,l.name,l.currency,l.workspace_id,l.term_months FROM loan_installments li JOIN loans l ON l.id=li.loan_id
                         WHERE li.tenant_id=? AND l.status='active' AND li.due_date BETWEEN ? AND ? AND NOT EXISTS (SELECT 1 FROM transactions t WHERE t.loan_installment_id=li.id AND t.status='posted')", [$tid, $from, $to]);
        foreach ($inst as $i) {
            if ($ws !== null && !in_array((int)$i['workspace_id'], $ws, true)) {
                continue;
            }
            $amt = (int)$i['principal_minor'] + (int)$i['interest_minor'];
            $items[] = ['type' => 'loan', 'ref' => (int)$i['id'], 'date' => $i['due_date'], 'original_date' => $i['due_date'], 'title' => "Kredi taksiti {$i['seq']}/{$i['term_months']}: {$i['name']}", 'currency' => $i['currency'], 'amount_minor' => $amt, 'remaining_minor' => $amt, 'priority' => 1, 'direction' => 'out', 'workspace_id' => (int)$i['workspace_id']];
        }
        usort($items, fn($a, $b) => [$a['date'], $a['priority']] <=> [$b['date'], $b['priority']]);
        $sums = [];
        foreach ($items as &$it) {
            $it['days_overdue'] = $it['date'] < $today ? (int)((strtotime($today) - strtotime($it['date'])) / 86400) : 0;
            $it['rescheduled'] = $it['date'] !== $it['original_date'];
            self::addTo($sums, $it['currency'], $it['direction'] === 'in' ? 'in_minor' : 'out_minor', $it['remaining_minor']);
        }
        return ['items' => $items, 'sums' => $sums, 'today' => $today, 'from' => $from, 'to' => $to];
    }

    // ---------- bildirimler ----------
    public static function notifications(int $tid): array
    {
        $set = json_decode((string)DB::val('SELECT settings FROM tenants WHERE id=?', [$tid]), true) ?: [];
        $n = $set['notify'] ?? ['due' => true, 'overdue' => true, 'card' => true, 'bills' => true];
        $today = Util::today();
        $out = [];
        foreach (Fin::entriesOpen($tid) as $e) {
            $rem = (int)$e['remaining_minor'];
            if ($rem <= 0 || $e['due_date'] === null) {
                continue;
            }
            $days = (int)((strtotime($e['due_date']) - strtotime($today)) / 86400);
            $base = ['date' => $e['due_date'], 'amount_minor' => $rem, 'currency' => $e['currency'], 'ref' => ['type' => 'entry', 'id' => (int)$e['id']]];
            if ($days < 0 && !empty($n['overdue'])) {
                $out[] = $base + ['level' => 'danger', 'type' => $e['kind'] === 'payable' ? 'overdue_payable' : 'overdue_receivable', 'title' => ($e['kind'] === 'payable' ? 'Gecikmiş borç: ' : 'Gecikmiş alacak: ') . $e['title'] . ' (' . abs($days) . ' gün)'];
            } elseif ($e['source'] === 'personal_bill' && $days <= 7 && !empty($n['bills'])) {
                $out[] = $base + ['level' => $days <= 2 ? 'warn' : 'info', 'type' => 'bill', 'title' => 'Şahsi fatura: ' . $e['title']];
            } elseif ($days <= 3 && !empty($n['due'])) {
                $out[] = $base + ['level' => 'warn', 'type' => $e['kind'] === 'payable' ? 'due_payable' : 'due_receivable', 'title' => ($e['kind'] === 'payable' ? 'Yaklaşan ödeme: ' : 'Beklenen tahsilat: ') . $e['title']];
            }
        }
        if (!empty($n['card'])) {
            foreach (DB::all('SELECT * FROM cards WHERE tenant_id=? AND archived=0', [$tid]) as $c) {
                foreach (CardCtl::statements($tid, (int)$c['id'], $c)['statements'] as $s) {
                    $days = (int)((strtotime($s['due_date']) - strtotime($today)) / 86400);
                    if ($s['remaining_minor'] > 0 && $days <= 5) {
                        $out[] = ['level' => $days < 0 ? 'danger' : 'warn', 'type' => 'card', 'title' => 'Kart son ödeme: ' . $c['name'], 'date' => $s['due_date'], 'amount_minor' => $s['remaining_minor'], 'currency' => $c['currency'], 'ref' => ['type' => 'card', 'id' => (int)$c['id']]];
                    }
                }
            }
        }
        foreach (DB::all("SELECT i.id,i.number,i.issue_date,i.total_minor,i.currency FROM invoices i WHERE i.tenant_id=? AND i.status='planned' AND i.kind='sales' AND i.issue_date<=?", [$tid, $today]) as $i) {
            $out[] = ['level' => 'warn', 'type' => 'invoice_to_issue', 'title' => 'Kesilmesi gereken fatura' . ($i['number'] ? ': ' . $i['number'] : ''), 'date' => $i['issue_date'], 'amount_minor' => (int)$i['total_minor'], 'currency' => $i['currency'], 'ref' => ['type' => 'invoice', 'id' => (int)$i['id']]];
        }
        foreach (Fin::accounts($tid, false) as $a) {
            if ($a['balance_minor'] < 0 && $a['kind'] === 'cash') {
                $out[] = ['level' => 'danger', 'type' => 'negative_cash', 'title' => 'Negatif kasa bakiyesi: ' . $a['name'], 'date' => $today, 'amount_minor' => $a['balance_minor'], 'currency' => $a['currency'], 'ref' => ['type' => 'account', 'id' => $a['id']]];
            }
        }
        $unmatched = (int)DB::val("SELECT COUNT(*) FROM statement_lines WHERE tenant_id=? AND status='unmatched'", [$tid]);
        if ($unmatched > 0) {
            $out[] = ['level' => 'info', 'type' => 'reconcile', 'title' => "$unmatched eşleşmemiş banka hareketi var", 'date' => $today, 'amount_minor' => 0, 'currency' => 'TRY', 'ref' => ['type' => 'reconcile', 'id' => 0]];
        }
        foreach (StockCtl::levels($tid) as $iid => $lv) {
            $it = DB::one('SELECT name,min_qty_milli FROM stock_items WHERE id=? AND tenant_id=? AND archived=0', [$iid, $tid]);
            if ($it && $lv['qty_milli'] < 0) {
                $out[] = ['level' => 'danger', 'type' => 'negative_stock', 'title' => 'Negatif stok: ' . $it['name'], 'date' => $today, 'amount_minor' => 0, 'currency' => 'TRY', 'ref' => ['type' => 'stock', 'id' => $iid]];
            }
        }
        $order = ['danger' => 0, 'warn' => 1, 'info' => 2];
        usort($out, fn($a, $b) => [$order[$a['level']], $a['date']] <=> [$order[$b['level']], $b['date']]);
        return $out;
    }

    // ---------- gelir-gider ----------
    public static function incomeExpense(array $q, int $tid): array
    {
        $f = self::pf($q, 'business');
        $f['from'] ??= substr(Util::today(), 0, 4) . '-01-01';
        $f['to'] ??= Util::today();
        $facts = Pl::facts($tid, $f);
        $months = [];
        $cats = [];
        $catNames = array_column(DB::all('SELECT id,name FROM categories WHERE tenant_id=?', [$tid]), 'name', 'id');
        $today = Util::today();
        foreach ($facts as $x) {
            $m = substr($x['d'], 0, 7);
            $months[$m][$x['currency']][$x['k'] . '_minor'] = ($months[$m][$x['currency']][$x['k'] . '_minor'] ?? 0) + $x['net'];
            $cn = $x['cat'] ? ($catNames[$x['cat']] ?? 'Diğer') : 'Kategorisiz';
            $cats[$x['k']][$x['currency']][$cn] = ($cats[$x['k']][$x['currency']][$cn] ?? 0) + $x['net'];
        }
        ksort($months);
        $rows = [];
        foreach ($months as $m => $byCur) {
            foreach ($byCur as $cur => $v) {
                $inc = $v['income_minor'] ?? 0;
                $exp = $v['expense_minor'] ?? 0;
                $rows[] = ['month' => $m, 'currency' => $cur, 'income_minor' => $inc, 'expense_minor' => $exp, 'profit_minor' => $inc - $exp];
            }
        }
        $planned = Pl::totals(array_values(array_filter($facts, fn($x) => $x['d'] > $today)));
        $realized = Pl::totals(array_values(array_filter($facts, fn($x) => $x['d'] <= $today)));
        return ['filters' => $f, 'rows' => $rows, 'totals' => Pl::totals($facts), 'realized' => $realized, 'planned' => $planned, 'by_category' => $cats,
            'method' => 'Gelir/gider: doğrudan hareketler + kesilmiş faturalar (KDV hariç) + planlı kalemler + kart harcamaları + kredi faizi + stok maliyeti. Tahsilat/ödeme/transfer/kart ekstre ödemesi/kredi anaparası dahil değildir.'];
    }

    // ---------- nakit akışı ----------
    public static function cashflow(array $q, int $tid): array
    {
        $f = self::pf($q, 'all');
        $today = Util::today();
        $f['from'] ??= Util::addMonths(substr($today, 0, 8) . '01', -5, 1);
        $f['to'] ??= $today;
        $ws = self::wsIds($tid, $f);
        $w = "t.tenant_id=? AND t.status='posted' AND t.txn_date BETWEEN ? AND ? AND t.kind NOT IN ('transfer','fx','adjustment','owner_in','owner_out')";
        $p = [$tid, $f['from'], $f['to']];
        if ($ws !== null) {
            $w .= ' AND t.workspace_id IN (' . implode(',', $ws ?: [0]) . ')';
        }
        $rows = DB::all("SELECT DATE_FORMAT(t.txn_date,'%Y-%m') m, t.currency, t.direction, t.kind, SUM(t.amount_minor) s FROM transactions t WHERE $w GROUP BY m, t.currency, t.direction, t.kind ORDER BY m", $p);
        $months = [];
        foreach ($rows as $x) {
            $key = $x['direction'] === 'in' ? 'cash_in_minor' : 'cash_out_minor';
            $months[$x['m']][$x['currency']][$key] = ($months[$x['m']][$x['currency']][$key] ?? 0) + (int)$x['s'];
            $months[$x['m']][$x['currency']]['detail'][$x['kind']] = (int)$x['s'];
        }
        $out = [];
        foreach ($months as $m => $byCur) {
            foreach ($byCur as $cur => $v) {
                $in = $v['cash_in_minor'] ?? 0;
                $outv = $v['cash_out_minor'] ?? 0;
                $out[] = ['month' => $m, 'currency' => $cur, 'cash_in_minor' => $in, 'cash_out_minor' => $outv, 'net_minor' => $in - $outv, 'detail' => $v['detail'] ?? []];
            }
        }
        // Tahmin: açık alacak/borç, kart ekstresi, kredi taksidi (gerçekleşmiş nakitten ayrı)
        $sc = self::schedule(['from' => $today, 'to' => Util::addMonths($today, 3), 'scope' => $f['scope'], 'workspace_id' => $f['workspace_id'], 'overdue' => '1'], $tid);
        $fc = [];
        foreach ($sc['items'] as $it) {
            $m = $it['date'] < $today ? substr($today, 0, 7) : substr($it['date'], 0, 7);
            $fc[$m][$it['currency']][$it['direction'] === 'in' ? 'expected_in_minor' : 'expected_out_minor'] = ($fc[$m][$it['currency']][$it['direction'] === 'in' ? 'expected_in_minor' : 'expected_out_minor'] ?? 0) + $it['remaining_minor'];
        }
        ksort($fc);
        $forecast = [];
        foreach ($fc as $m => $byCur) {
            foreach ($byCur as $cur => $v) {
                $forecast[] = ['month' => $m, 'currency' => $cur, 'expected_in_minor' => $v['expected_in_minor'] ?? 0, 'expected_out_minor' => $v['expected_out_minor'] ?? 0];
            }
        }
        return ['filters' => $f, 'realized' => $out, 'forecast' => $forecast, 'note' => 'Gerçekleşen nakit yalnızca hesap hareketlerinden; tahmin ayrıca ve eldeki para olarak gösterilmez.'];
    }

    // ---------- yaşlandırma ----------
    public static function aging(array $q, int $tid): array
    {
        $kind = ($q['kind'] ?? 'receivable') === 'payable' ? 'payable' : 'receivable';
        $f = self::pf($q, 'all');
        $ws = self::wsIds($tid, $f);
        $today = Util::today();
        $names = array_column(DB::all('SELECT id,name FROM contacts WHERE tenant_id=?', [$tid]), 'name', 'id');
        $rows = [];
        foreach (Fin::entriesOpen($tid, ['kind' => $kind]) as $e) {
            $rem = (int)$e['remaining_minor'];
            if ($rem <= 0 || ($ws !== null && !in_array((int)$e['workspace_id'], $ws, true))) {
                continue;
            }
            $late = $e['due_date'] === null ? 0 : (int)((strtotime($today) - strtotime($e['due_date'])) / 86400);
            $b = $late <= 0 ? 'current' : ($late <= 30 ? 'd30' : ($late <= 60 ? 'd60' : ($late <= 90 ? 'd90' : 'd90p')));
            $name = $e['contact_id'] ? ($names[(int)$e['contact_id']] ?? '—') : '(Cari yok)';
            $key = $name . '|' . $e['currency'];
            $rows[$key] ??= ['contact' => $name, 'contact_id' => $e['contact_id'] ? (int)$e['contact_id'] : null, 'currency' => $e['currency'], 'current' => 0, 'd30' => 0, 'd60' => 0, 'd90' => 0, 'd90p' => 0, 'total_minor' => 0];
            $rows[$key][$b] += $rem;
            $rows[$key]['total_minor'] += $rem;
        }
        $rows = array_values($rows);
        usort($rows, fn($a, $b) => $b['total_minor'] <=> $a['total_minor']);
        $tot = [];
        foreach ($rows as $r) {
            foreach (['current', 'd30', 'd60', 'd90', 'd90p', 'total_minor'] as $k) {
                $tot[$r['currency']][$k] = ($tot[$r['currency']][$k] ?? 0) + $r[$k];
            }
        }
        return ['kind' => $kind, 'rows' => $rows, 'totals' => $tot, 'today' => $today];
    }

    public static function projects(int $tid): array
    {
        $rows = DB::all("SELECT id,name,currency,contract_minor,est_cost_minor,status FROM projects WHERE tenant_id=? AND archived=0 ORDER BY id DESC", [$tid]);
        foreach ($rows as &$p) {
            $p['id'] = (int)$p['id'];
            $p = array_merge($p, Pl::projectFigures($tid, $p['id']));
        }
        usort($rows, fn($a, $b) => $b['profit_minor'] <=> $a['profit_minor']);
        return ['projects' => $rows];
    }

    // ---------- bütçe ----------
    private static function budgetList(array $q, int $tid): array
    {
        $m = (string)($q['month'] ?? substr(Util::today(), 0, 7));
        if (!preg_match('/^\d{4}-\d{2}$/', $m)) {
            throw new ApiError('validation', 'Ay biçimi YYYY-AA olmalı.', 422, 'month');
        }
        return ['month' => $m, 'budgets' => DB::all('SELECT b.id,b.category_id,b.currency,b.amount_minor,c.name AS category_name FROM budgets b JOIN categories c ON c.id=b.category_id WHERE b.tenant_id=? AND b.month=?', [$tid, $m])];
    }

    private static function budgetSet(Req $r, int $tid): never
    {
        $b = $r->body;
        $m = (string)V::str($b, 'month', 7);
        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $m)) {
            throw new ApiError('validation', 'Ay biçimi YYYY-AA olmalı.', 422, 'month');
        }
        $cat = Fin::ownedId('categories', V::id($b['category_id'] ?? null, 'category_id'), $tid, 'category_id', true);
        $cur = V::cur($b);
        $amt = (int)V::amount($b, 'amount_minor', true, true);
        if ($amt === 0) {
            DB::exec('DELETE FROM budgets WHERE tenant_id=? AND category_id=? AND month=? AND currency=?', [$tid, $cat, $m, $cur]);
        } else {
            DB::q('INSERT INTO budgets (tenant_id,category_id,month,currency,amount_minor) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor)', [$tid, $cat, $m, $cur, $amt]);
        }
        Res::ok();
    }

    public static function budget(array $q, int $tid): array
    {
        $m = (string)($q['month'] ?? substr(Util::today(), 0, 7));
        if (!preg_match('/^\d{4}-\d{2}$/', $m)) {
            throw new ApiError('validation', 'Ay biçimi YYYY-AA olmalı.', 422, 'month');
        }
        $f = self::pf($q, 'business');
        $f['from'] = $m . '-01';
        $f['to'] = date('Y-m-t', strtotime($f['from']));
        $actual = [];
        foreach (Pl::facts($tid, $f) as $x) {
            if ($x['k'] === 'expense' && $x['cat']) {
                $actual[$x['cat'] . '|' . $x['currency']] = ($actual[$x['cat'] . '|' . $x['currency']] ?? 0) + $x['net'];
            }
        }
        $rows = [];
        foreach (DB::all('SELECT b.category_id,b.currency,b.amount_minor,c.name FROM budgets b JOIN categories c ON c.id=b.category_id WHERE b.tenant_id=? AND b.month=? ORDER BY c.name', [$tid, $m]) as $b) {
            $a = $actual[$b['category_id'] . '|' . $b['currency']] ?? 0;
            $rows[] = ['category_id' => (int)$b['category_id'], 'category' => $b['name'], 'currency' => $b['currency'], 'budget_minor' => (int)$b['amount_minor'], 'actual_minor' => $a, 'diff_minor' => (int)$b['amount_minor'] - $a, 'used_pct' => (int)$b['amount_minor'] > 0 ? round($a * 100 / (int)$b['amount_minor'], 1) : null];
        }
        return ['month' => $m, 'rows' => $rows];
    }
}
