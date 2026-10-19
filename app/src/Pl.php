<?php
declare(strict_types=1);

namespace FB;

/**
 * Kâr/zarar olguları: gelir ve gider TEK kaynaktan (tahakkuk esasına yakın) hesaplanır.
 * Sayılanlar: doğrudan gelir/gider hareketleri, kesilmiş faturalar (KDV hariç), affects_pl alacak/borç kalemleri,
 * kart alışverişleri, kredi faizi, stok tüketim maliyeti.
 * Sayılmayanlar (çift sayımı önler): tahsilat, ödeme, transfer, döviz, kart ekstre ödemesi, kredi anaparası, kredi kullanımı, bakiye düzeltme.
 */
final class Pl
{
    private static function union(int $tid): array
    {
        $parts = [];
        $p = [];
        $parts[] = "SELECT txn_date d, currency, 'income' k, amount_minor - vat_minor net, vat_minor vat, vat_rate_bp rate, 'txn' src, COALESCE(for_workspace_id, workspace_id) ws, project_id proj, category_id cat, contact_id contact, id ref
                    FROM transactions WHERE tenant_id=? AND status='posted' AND kind='income'";
        $p[] = $tid;
        $parts[] = "SELECT txn_date, currency, 'expense', amount_minor - vat_minor, vat_minor, vat_rate_bp, 'txn', COALESCE(for_workspace_id, workspace_id), project_id, category_id, contact_id, id
                    FROM transactions WHERE tenant_id=? AND status='posted' AND kind='expense'";
        $p[] = $tid;
        $parts[] = "SELECT issue_date, currency, 'income', subtotal_minor, vat_minor, 0, 'invoice', workspace_id, project_id, NULL, contact_id, id
                    FROM invoices WHERE tenant_id=? AND status='issued' AND kind='sales'";
        $p[] = $tid;
        $parts[] = "SELECT i.issue_date, i.currency, 'expense',
                      i.subtotal_minor - COALESCE((SELECT SUM(l.net_minor) FROM invoice_lines l JOIN stock_items s ON s.id=l.item_id AND s.kind='product' WHERE l.invoice_id=i.id AND l.warehouse_id IS NOT NULL),0),
                      i.vat_minor, 0, 'invoice', i.workspace_id, i.project_id, NULL, i.contact_id, i.id
                    FROM invoices i WHERE i.tenant_id=? AND i.status='issued' AND i.kind='purchase'";
        $p[] = $tid;
        $parts[] = "SELECT due_date, currency, IF(kind='receivable','income','expense'), amount_minor, 0, 0, 'entry', workspace_id, project_id, category_id, contact_id, id
                    FROM entries WHERE tenant_id=? AND status='open' AND affects_pl=1 AND invoice_id IS NULL";
        $p[] = $tid;
        $parts[] = "SELECT cp.txn_date, c.currency, 'expense', cp.amount_minor - cp.vat_minor, cp.vat_minor, cp.vat_rate_bp, 'card', cp.workspace_id, cp.project_id, cp.category_id, cp.contact_id, cp.id
                    FROM card_purchases cp JOIN cards c ON c.id=cp.card_id WHERE cp.tenant_id=? AND cp.status='posted'";
        $p[] = $tid;
        $parts[] = "SELECT txn_date, currency, 'expense', interest_minor, 0, 0, 'interest', workspace_id, project_id, NULL, NULL, id
                    FROM transactions WHERE tenant_id=? AND status='posted' AND kind='loan_payment' AND interest_minor > 0";
        $p[] = $tid;
        $parts[] = "SELECT m.move_date, s.currency, 'expense', IF(m.kind='return_in',-1,1) * ROUND(m.qty_milli * m.unit_cost_minor / 1000), 0, 0, 'stock', (SELECT i.workspace_id FROM invoices i WHERE i.id=m.invoice_id), m.project_id, NULL, NULL, m.id
                    FROM stock_movements m JOIN stock_items s ON s.id=m.item_id WHERE m.tenant_id=? AND m.status='posted' AND m.kind IN ('out','return_in')";
        $p[] = $tid;
        return [implode(' UNION ALL ', $parts), $p];
    }

    /** $f: from, to, workspace_id, scope(business|personal|all), project_id, currency */
    public static function facts(int $tid, array $f = []): array
    {
        [$sql, $p] = self::union($tid);
        $w = ['1=1'];
        if (!empty($f['from'])) {
            $w[] = 'f.d >= ?';
            $p[] = $f['from'];
        }
        if (!empty($f['to'])) {
            $w[] = 'f.d <= ?';
            $p[] = $f['to'];
        }
        if (!empty($f['workspace_id'])) {
            $w[] = 'f.ws = ?';
            $p[] = (int)$f['workspace_id'];
        } elseif (!empty($f['scope']) && in_array($f['scope'], ['business', 'personal'], true)) {
            // Stok maliyeti satırlarının alanı olmayabilir (elle tüketim): işletme kapsamına dahil edilir
            $w[] = $f['scope'] === 'business' ? '(f.ws IN (SELECT id FROM workspaces WHERE tenant_id=? AND kind=?) OR f.ws IS NULL)' : 'f.ws IN (SELECT id FROM workspaces WHERE tenant_id=? AND kind=?)';
            array_push($p, $tid, $f['scope']);
        }
        if (!empty($f['project_id'])) {
            $w[] = 'f.proj = ?';
            $p[] = (int)$f['project_id'];
        }
        if (!empty($f['currency'])) {
            $w[] = 'f.currency = ?';
            $p[] = $f['currency'];
        }
        $rows = DB::all("SELECT f.* FROM ($sql) f WHERE " . implode(' AND ', $w) . ' ORDER BY f.d, f.src, f.ref', $p);
        foreach ($rows as &$r) {
            foreach (['net', 'vat', 'rate', 'ws', 'proj', 'cat', 'contact', 'ref'] as $k) {
                $r[$k] = $r[$k] !== null ? (int)$r[$k] : null;
            }
        }
        return $rows;
    }

    public static function totals(array $facts): array
    {
        $out = [];
        foreach ($facts as $r) {
            $c = $r['currency'];
            $out[$c] ??= ['income_minor' => 0, 'expense_minor' => 0, 'profit_minor' => 0];
            $out[$c][$r['k'] . '_minor'] += $r['net'];
        }
        foreach ($out as &$o) {
            $o['profit_minor'] = $o['income_minor'] - $o['expense_minor'];
        }
        return $out;
    }

    public static function projectFigures(int $tid, int $pid): array
    {
        $proj = DB::one('SELECT currency, contract_minor, est_cost_minor FROM projects WHERE id=? AND tenant_id=?', [$pid, $tid]);
        if (!$proj) {
            return ['revenue_minor' => 0, 'cost_minor' => 0, 'profit_minor' => 0];
        }
        $facts = self::facts($tid, ['project_id' => $pid, 'currency' => $proj['currency']]);
        $rev = 0;
        $cost = 0;
        $by = [];
        foreach ($facts as $r) {
            if ($r['k'] === 'income') {
                $rev += $r['net'];
            } else {
                $cost += $r['net'];
            }
            $by[$r['src']][$r['k']] = ($by[$r['src']][$r['k']] ?? 0) + $r['net'];
        }
        $cash = DB::one("SELECT
              COALESCE(SUM(CASE WHEN direction='in' AND kind IN ('income','collection') THEN amount_minor END),0) cash_in,
              COALESCE(SUM(CASE WHEN direction='out' AND kind IN ('expense','payment') THEN amount_minor END),0) cash_out
              FROM transactions WHERE tenant_id=? AND project_id=? AND status='posted' AND currency=?", [$tid, $pid, $proj['currency']]);
        $pend = DB::one("SELECT COALESCE(SUM(CASE WHEN e.kind='receivable' THEN e.amount_minor - COALESCE(x.paid,0) END),0) rec,
                                COALESCE(SUM(CASE WHEN e.kind='payable' THEN e.amount_minor - COALESCE(x.paid,0) END),0) pay
                         FROM entries e LEFT JOIN (SELECT a.entry_id, SUM(a.amount_minor) paid FROM payment_allocations a JOIN transactions pt ON pt.id=a.txn_id WHERE a.tenant_id=? AND a.status='active' AND pt.status='posted' GROUP BY a.entry_id) x ON x.entry_id=e.id
                         WHERE e.tenant_id=? AND e.project_id=? AND e.status='open' AND e.currency=?", [$tid, $tid, $pid, $proj['currency']]);
        return [
            'currency' => $proj['currency'], 'contract_minor' => (int)$proj['contract_minor'], 'est_cost_minor' => (int)$proj['est_cost_minor'],
            'revenue_minor' => $rev, 'cost_minor' => $cost, 'profit_minor' => $rev - $cost,
            'est_profit_minor' => (int)$proj['contract_minor'] - (int)$proj['est_cost_minor'],
            'cash_in_minor' => (int)$cash['cash_in'], 'cash_out_minor' => (int)$cash['cash_out'], 'cash_net_minor' => (int)$cash['cash_in'] - (int)$cash['cash_out'],
            'pending_receivable_minor' => (int)$pend['rec'], 'pending_payable_minor' => (int)$pend['pay'], 'by_source' => $by,
        ];
    }

    /**
     * KDV: hesaplanan (satış) / indirilecek (alış) — oran bazında. Faturalar satır oranlarından, hareketler kendi oranından.
     */
    public static function vat(int $tid, array $f): array
    {
        $rows = [];
        $w = ["i.tenant_id=?", "i.status='issued'"];
        $p = [$tid];
        if (!empty($f['from'])) {
            $w[] = 'i.issue_date>=?';
            $p[] = $f['from'];
        }
        if (!empty($f['to'])) {
            $w[] = 'i.issue_date<=?';
            $p[] = $f['to'];
        }
        if (!empty($f['workspace_id'])) {
            $w[] = 'i.workspace_id=?';
            $p[] = (int)$f['workspace_id'];
        } elseif (!empty($f['scope']) && in_array($f['scope'], ['business', 'personal'], true)) {
            $w[] = 'i.workspace_id IN (SELECT id FROM workspaces WHERE tenant_id=? AND kind=?)';
            array_push($p, $tid, $f['scope']);
        }
        $inv = DB::all('SELECT i.kind, i.currency, l.vat_rate_bp rate, SUM(l.net_minor) net, SUM(l.vat_minor) vat, COUNT(DISTINCT i.id) docs
                        FROM invoices i JOIN invoice_lines l ON l.invoice_id=i.id WHERE ' . implode(' AND ', $w) . ' GROUP BY i.kind, i.currency, l.vat_rate_bp', $p);
        foreach ($inv as $r) {
            $rows[] = ['side' => $r['kind'] === 'sales' ? 'output' : 'input', 'currency' => $r['currency'], 'rate_bp' => (int)$r['rate'], 'net_minor' => (int)$r['net'], 'vat_minor' => (int)$r['vat'], 'source' => 'invoice', 'docs' => (int)$r['docs']];
        }
        foreach (self::facts($tid, $f) as $r) {
            if ($r['src'] === 'txn' || $r['src'] === 'card') {
                $rows[] = ['side' => $r['k'] === 'income' ? 'output' : 'input', 'currency' => $r['currency'], 'rate_bp' => $r['rate'] ?? 0, 'net_minor' => $r['net'], 'vat_minor' => $r['vat'], 'source' => $r['src'] === 'txn' ? 'transaction' : 'card', 'docs' => 1];
            }
        }
        $agg = [];
        foreach ($rows as $r) {
            $k = $r['side'] . '|' . $r['currency'] . '|' . $r['rate_bp'];
            $agg[$k] ??= ['side' => $r['side'], 'currency' => $r['currency'], 'rate_bp' => $r['rate_bp'], 'net_minor' => 0, 'vat_minor' => 0, 'gross_minor' => 0, 'docs' => 0];
            $agg[$k]['net_minor'] += $r['net_minor'];
            $agg[$k]['vat_minor'] += $r['vat_minor'];
            $agg[$k]['gross_minor'] += $r['net_minor'] + $r['vat_minor'];
            $agg[$k]['docs'] += $r['docs'];
        }
        ksort($agg);
        $sum = [];
        foreach ($agg as $a) {
            $sum[$a['currency']] ??= ['output_vat_minor' => 0, 'input_vat_minor' => 0, 'payable_vat_minor' => 0];
            $sum[$a['currency']][$a['side'] . '_vat_minor'] += $a['vat_minor'];
        }
        foreach ($sum as &$s) {
            $s['payable_vat_minor'] = $s['output_vat_minor'] - $s['input_vat_minor'];
        }
        return ['rows' => array_values($agg), 'summary' => $sum];
    }
}
