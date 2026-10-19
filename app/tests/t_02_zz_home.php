<?php
declare(strict_types=1);

// Ana sayfa + müşteri avans/tahsilat kök neden düzeltmesi (Test A-D).
use FB\DB;
use FB\Util;

$A = new Client();
check($A->login('alfa@test.local', $GLOBALS['ALFA_PASS'])['ok'], 'Alfa girişi (ana sayfa testleri)');
$B = new Client();
$B->login('beta@test.local', $GLOBALS['BETA_PASS']);
['WS' => $WS, 'WSP' => $WSP, 'BANK' => $BANK, 'PBANK' => $PBANK, 'SUPP' => $SUPP] = $GLOBALS['IDS'];
$today = Util::today();
$ik = fn() => ['Idempotency-Key' => bin2hex(random_bytes(10))];
$cId = (int)$A->post('/contacts', ['name' => 'Avans Müşterisi', 'kind' => 'customer'])['json']['id'];
$stmt = function (int $cid) use ($A) {
    $g = array_values(array_filter($A->get("/contacts/$cid/statement")['json']['groups'], fn($x) => $x['currency'] === 'TRY'));
    return $g ? $g[0]['closing_minor'] : 0;
};
$mkInv = fn(int $cid, string $no, int $amountTl, array $x = []) => $A->post('/invoices', array_merge(['kind' => 'sales', 'workspace_id' => $WS, 'contact_id' => $cid, 'number' => $no, 'issue_date' => $today,
    'lines' => [['description' => 'İş', 'qty_milli' => 1000, 'unit_price_minor' => tl($amountTl), 'vat_rate_bp' => 0]]], $x));

section('Test A: faturaya bağlı tahsilat (100.000 - 30.000 = 70.000)');
$IID = (int)$mkInv($cId, 'HOME-1', 100000)['json']['id'];
$EID = (int)$A->get("/invoices/$IID")['json']['entry']['id'];
eq($stmt($cId), tl(100000), 'fatura sonrası cari bakiye +100.000 (müşteri borçlu)');
$b0 = bal($A, $BANK);
$p = $A->post('/payments', ['kind' => 'collection', 'account_id' => $BANK, 'contact_id' => $cId, 'amount_minor' => tl(30000), 'allocations' => [['entry_id' => $EID, 'amount_minor' => tl(30000)]]], $ik());
check($p['ok'], '30.000 TL tahsilat');
eq((int)$A->get("/invoices/$IID")['json']['remaining_minor'], tl(70000), 'faturada 70.000 kaldı');
eq($stmt($cId), tl(70000), 'cari ekstre aynı kalan borcu gösterir');
eq(bal($A, $BANK), $b0 + tl(30000), 'banka +30.000 (bir kez)');
eq((int)DB::val("SELECT COUNT(*) FROM transactions WHERE tenant_id=? AND contact_id=? AND kind IN ('collection','income') AND status='posted'", [$GLOBALS['ALFA'], $cId]), 1, 'tahsilat tek kayıt');

section('Test B: gelir ekranından müşteri seçerek avans (KÖK NEDEN düzeltmesi)');
$c2 = (int)$A->post('/contacts', ['name' => 'Avans Müşterisi 2', 'kind' => 'customer'])['json']['id'];
$inc0 = plTotals($A)['income_minor'];
$b1 = bal($A, $BANK);
$r = $A->post('/transactions', ['kind' => 'income', 'account_id' => $BANK, 'amount_minor' => tl(30000), 'txn_date' => $today, 'contact_id' => $c2, 'description' => 'Avans'], $ik());
check($r['ok'] && !empty($r['json']['warning']), 'cari seçili gelir müşteri avansı olarak işlendi');
eq(DB::val('SELECT kind FROM transactions WHERE id=?', [$r['json']['id']]), 'collection', 'kayıt türü tahsilat (bağımsız gelir değil)');
eq($stmt($c2), -tl(30000), 'avans cari ekstrede görünür (müşteri lehine -30.000)');
eq(bal($A, $BANK), $b1 + tl(30000), 'banka bakiyesi +30.000');
eq(plTotals($A)['income_minor'], $inc0, 'avans satış geliri sayılmadı');
$cb = array_values(array_filter($A->get('/contacts')['json']['contacts'], fn($c) => $c['id'] === $c2))[0]['balances']['TRY'];
eq($cb['advance_in'], tl(30000), 'cari kartında avans (alınan) 30.000');
$sa = $A->post('/transactions', ['kind' => 'income', 'account_id' => $BANK, 'amount_minor' => tl(77), 'txn_date' => $today, 'contact_id' => $c2, 'standalone' => true], $ik());
eq(DB::val('SELECT kind FROM transactions WHERE id=?', [$sa['json']['id']]), 'income', 'bilerek bağımsız gelir (standalone) hâlâ mümkün');
$A->post('/transactions/' . $sa['json']['id'] . '/void', ['reason' => 'test']);
$old = $A->post('/transactions', ['kind' => 'income', 'account_id' => $BANK, 'amount_minor' => tl(500), 'txn_date' => $today, 'contact_id' => $c2, 'standalone' => true], $ik());
check($A->post('/transactions/' . $old['json']['id'] . '/to-collection')['ok'], 'eski bağımsız gelir kaydı tahsilata çevrilir');
eq($A->post('/transactions/' . $old['json']['id'] . '/to-collection')['status'], 409, 'iki kez çevrilemez');
$A->post('/transactions/' . $old['json']['id'] . '/void', ['reason' => 'test temizliği']);

section('Test C: avansı faturayla eşleştir');
$I2 = (int)$mkInv($c2, 'HOME-2', 100000)['json']['id'];
$E2 = (int)$A->get("/invoices/$I2")['json']['entry']['id'];
$b2 = bal($A, $BANK);
$n0 = (int)DB::val('SELECT COUNT(*) FROM transactions WHERE tenant_id=?', [$GLOBALS['ALFA']]);
$al = $A->post('/payments/' . $r['json']['id'] . '/allocate', ['allocations' => [['entry_id' => $E2, 'amount_minor' => tl(30000)]]], $ik());
check($al['ok'], '30.000 avans faturaya eşleştirildi');
eq((int)$A->get("/invoices/$I2")['json']['remaining_minor'], tl(70000), 'faturada 70.000 kaldı');
eq([bal($A, $BANK), (int)DB::val('SELECT COUNT(*) FROM transactions WHERE tenant_id=?', [$GLOBALS['ALFA']])], [$b2, $n0], 'eşleştirme yeni para hareketi / bakiye değişimi üretmedi');

section('Test D: ana sayfa');
$h = $A->get('/home')['json'];
$expCash = 0;
foreach ($A->get('/accounts')['json']['accounts'] as $a) {
    if (!$a['archived'] && $a['workspace_kind'] === 'business' && $a['currency'] === 'TRY') {
        $expCash += $a['balance_minor'];
    }
}
eq($h['cash']['totals']['TRY'], $expCash, 'kasa+banka toplamı işletme hesap bakiyeleriyle aynı');
check(!in_array($PBANK, array_column($h['cash']['accounts'], 'id'), true), 'şahsi hesap kasa/banka toplamına karışmadı');
$ALFAID = $GLOBALS["ALFA"];
$sqlTot = function (string $kind) use ($ALFAID) {
    $a = (int)DB::val("SELECT COALESCE(SUM(e.amount_minor),0) FROM entries e JOIN workspaces w ON w.id=e.workspace_id AND w.kind='business' WHERE e.tenant_id=? AND e.status='open' AND e.kind=? AND e.currency='TRY' AND e.source<>'personal_bill'", [$ALFAID, $kind]);
    $p = (int)DB::val("SELECT COALESCE(SUM(a.amount_minor),0) FROM payment_allocations a JOIN transactions t ON t.id=a.txn_id AND t.status='posted' JOIN entries e ON e.id=a.entry_id AND e.status='open' AND e.kind=? AND e.currency='TRY' JOIN workspaces w ON w.id=e.workspace_id AND w.kind='business' WHERE a.tenant_id=? AND a.status='active' AND e.source<>'personal_bill'", [$kind, $ALFAID]);
    return $a - $p;
};
eq($h['receivable']['total']['TRY'] ?? 0, $sqlTot('receivable'), 'toplam alacak = açık kalem - aktif dağıtımlar');
$ids = array_column($h['receivable']['items'], 'id');
eq(count($ids), count(array_unique($ids)), 'aynı kalem listede iki kez yok');
$row = array_values(array_filter($h['receivable']['items'], fn($x) => $x['invoice_id'] === $I2))[0] ?? null;
check($row !== null, 'faturalı alacak listede (tek satır)');
eq([$row['remaining_minor'], $row['partial']], [tl(70000), true], 'kısmi tahsilatta yalnızca kalan tutar (70.000) gösterilir');
eq([$row['state'], $row['planned_date'], $row['due_date']], ['undated', null, null], 'tarihsiz kayıt: tarih uydurulmadı, gecikmiş sayılmadı');
check($A->req('PATCH', "/entries/$E2", ['planned_date' => Util::addDays($today, -2)])['ok'], 'planlanan tahsilat tarihi girilir (vadeden ayrı)');
$h = $A->get('/home')['json'];
$row = array_values(array_filter($h['receivable']['items'], fn($x) => $x['invoice_id'] === $I2))[0];
eq([$row['state'], $row['due_date']], ['overdue', null], 'planlı tarih geçmiş: gecikmiş; vade alanı değişmedi');
$ord = ['overdue' => 0, 'today' => 1, 'upcoming' => 2, 'undated' => 3];
$states = array_column($h['receivable']['items'], 'state');
$sorted = $states;
usort($sorted, fn($a, $b) => $ord[$a] <=> $ord[$b]);
eq($states, $sorted, 'sıralama: gecikmiş, bugün, yaklaşan, tarihsiz');
check($A->req('PATCH', "/entries/$E2", ['planned_date' => $today])['ok'], 'planlı tarih bugün');
$h = $A->get('/home')['json'];
check($h['today_summary']['receivable_count'] >= 1, 'bugün yapılacak tahsilat sayılır');
check(t4pay($A, ['kind' => 'collection', 'account_id' => $BANK, 'contact_id' => $c2, 'amount_minor' => tl(70000), 'allocations' => [['entry_id' => $E2, 'amount_minor' => tl(70000)]]])['ok'], 'kalan 70.000 tahsil edildi');
$h = $A->get('/home')['json'];
check(!in_array($I2, array_column($h['receivable']['items'], 'invoice_id'), true), 'tamamen tahsil edilen fatura bekleyenlerden kalktı');
$si = $A->post('/invoices', ['kind' => 'purchase', 'workspace_id' => $WS, 'contact_id' => $SUPP, 'number' => 'HOME-P1', 'issue_date' => $today, 'due_date' => Util::addDays($today, 5), 'lines' => [['description' => 'Mal', 'qty_milli' => 1000, 'unit_price_minor' => tl(1000), 'vat_rate_bp' => 0]]]);
$h = $A->get('/home')['json'];
$pr = array_values(array_filter($h['payable']['items'], fn($x) => $x['invoice_id'] === (int)$si['json']['id']))[0] ?? null;
check($pr && $pr['state'] === 'upcoming' && $pr['due_date'] === Util::addDays($today, 5), 'ödeme bekleyenlerde yaklaşan fatura vadesiyle');
eq($h['payable']['total']['TRY'] ?? 0, $sqlTot('payable'), 'toplam borç doğru');
check(isset($h['advances']) && count($h['recent']) <= 8, 'avanslar ayrı; son hareketler sınırlı');
eq($B->get('/home')['json']['receivable']['count'], 0, 'Beta yalnızca kendi verisini görür');
