<?php
declare(strict_types=1);

use FB\DB;
use FB\Util;

$today = Util::today();
$yearStart = substr($today, 0, 4) . '-01-01';
$d = fn(int $n) => Util::addDays($today, $n);

$A = new Client();
check($A->login('alfa@test.local', $GLOBALS['ALFA_PASS'])['ok'], 'Alfa girişi');
$B = new Client();
check($B->login('beta@test.local', $GLOBALS['BETA_PASS'])['ok'], 'Beta girişi');
$ALFA = $GLOBALS['ALFA'];

function id(array $r): int
{
    return (int)($r['json']['id'] ?? ($r['json']['ids'][0] ?? 0));
}
function bal(Client $c, int $accId): int
{
    foreach ($c->get('/accounts')['json']['accounts'] as $a) {
        if ($a['id'] === $accId) {
            return $a['balance_minor'];
        }
    }
    return PHP_INT_MIN;
}
function plTotals(Client $c, string $scope = 'business', array $extra = []): array
{
    global $yearStart, $today;
    $r = $c->get('/reports/income-expense', ['scope' => $scope, 'from' => $yearStart, 'to' => $today] + $extra);
    return $r['json']['totals']['TRY'] ?? ['income_minor' => 0, 'expense_minor' => 0, 'profit_minor' => 0];
}

section('Kurulum: alanlar, hesaplar, cariler');
check($A->get('/meta')['json']['workspaces'] === [], 'yeni müşteride örnek işletme/hesap yok (müşteri kendi ekler)');
$cats = $A->get('/categories')['json']['categories'];
check(count($cats) > 10, 'varsayılan kategoriler mevcut (yakıt, kira, vergi, …)');
$catId = fn(string $kind, string $name) => (int)array_values(array_filter($cats, fn($c) => $c['kind'] === $kind && $c['name'] === $name))[0]['id'];
$WS = id($A->post('/workspaces', ['name' => 'Horizon', 'kind' => 'business']));
$WSP = id($A->post('/workspaces', ['name' => 'Şahsi', 'kind' => 'personal']));
check($WS > 0 && $WSP > 0, 'işletme + şahsi alan oluşturulur');
eq($A->post('/workspaces', ['name' => 'Horizon'])['status'], 409, 'yinelenen alan adı reddedilir');
$BANK = id($A->post('/accounts', ['workspace_id' => $WS, 'name' => 'Ana Banka TL', 'kind' => 'bank', 'currency' => 'TRY', 'opening_minor' => tl(50000), 'opening_date' => $yearStart, 'bank_name' => 'Test Bank']));
$CASH = id($A->post('/accounts', ['workspace_id' => $WS, 'name' => 'Kasa TL', 'kind' => 'cash', 'currency' => 'TRY', 'opening_minor' => 0, 'opening_date' => $yearStart]));
$EUR = id($A->post('/accounts', ['workspace_id' => $WS, 'name' => 'Banka EUR', 'kind' => 'bank', 'currency' => 'EUR', 'opening_minor' => tl(1000), 'opening_date' => $yearStart]));
$USD = id($A->post('/accounts', ['workspace_id' => $WS, 'name' => 'Banka USD', 'kind' => 'bank', 'currency' => 'USD', 'opening_minor' => tl(2000), 'opening_date' => $yearStart]));
$PBANK = id($A->post('/accounts', ['workspace_id' => $WSP, 'name' => 'Şahsi Banka', 'kind' => 'bank', 'currency' => 'TRY', 'opening_minor' => tl(10000), 'opening_date' => $yearStart]));
eq($A->post('/accounts', ['workspace_id' => $WS, 'name' => 'Kötü IBAN', 'iban' => 'TR00 0000 0000 0000 0000 0000 00', 'opening_date' => $yearStart])['status'], 422, 'geçersiz IBAN (mod-97) reddedilir');
check($A->post('/accounts', ['workspace_id' => $WS, 'name' => 'IBANlı', 'iban' => 'TR33 0006 1005 1978 6457 8413 26', 'opening_date' => $yearStart])['ok'], 'geçerli IBAN kabul edilir');
$CUST = id($A->post('/contacts', ['name' => 'Müşteri A', 'kind' => 'customer']));
$SUPP = id($A->post('/contacts', ['name' => 'Tedarikçi B', 'kind' => 'supplier']));
eq(bal($A, $BANK), tl(50000), 'banka bakiyesi açılış bakiyesine eşit');
$t0 = plTotals($A);
eq($t0['income_minor'], 0, 'açılış bakiyesi gelir sayılmaz');
$GLOBALS['IDS'] = compact('WS', 'WSP', 'BANK', 'CASH', 'EUR', 'USD', 'PBANK', 'CUST', 'SUPP');

section('Finansal #1: 100.000 TL alacak, 60.000 TL tahsilat → kalan 40.000 TL');
$E1 = id($A->post('/entries', ['kind' => 'receivable', 'workspace_id' => $WS, 'title' => 'Büyük satış', 'amount_minor' => tl(100000), 'due_date' => $d(10), 'contact_id' => $CUST]));
$r = $A->post("/entries/$E1/settle", ['account_id' => $BANK, 'amount_minor' => tl(60000), 'date' => $today]);
check($r['ok'] && $r['json']['remaining_minor'] === tl(40000), 'kalan alacak 40.000 TL', $r['json']);
eq(bal($A, $BANK), tl(110000), 'tahsilat banka bakiyesini artırır');
$e = array_values(array_filter($A->get('/entries', ['kind' => 'receivable'])['json']['entries'], fn($x) => $x['id'] === $E1))[0];
eq([$e['paid_minor'], $e['remaining_minor'], $e['state']], [tl(60000), tl(40000), 'partial'], 'alacak kalemi kısmi tahsilat durumu');
$r = $A->post("/entries/$E1/settle", ['account_id' => $BANK, 'amount_minor' => tl(50000)]);
eq($r['json']['error']['code'] ?? null, 'overpayment', 'kalandan fazla tahsilat reddedilir');
check($A->post("/entries/$E1/settle", ['account_id' => $BANK, 'amount_minor' => tl(40000)])['ok'], 'kalan tahsil edilir');
$left = array_filter($A->get('/entries', ['kind' => 'receivable'])['json']['entries'], fn($x) => $x['id'] === $E1);
eq(count($left), 0, 'tamamen ödenen kalem ödeme planında/listesinde görünmez');
$t1 = plTotals($A);
eq($t1['income_minor'], 0, 'tahsilat gelir sayılmaz (çift sayım yok)');

section('Finansal #2: 20.000 TL banka→kasa transferi');
$before = [bal($A, $BANK), bal($A, $CASH)];
$tr = $A->post('/transfers', ['from_account_id' => $BANK, 'to_account_id' => $CASH, 'amount_minor' => tl(20000), 'date' => $today]);
check($tr['ok'], 'transfer yapılır');
eq([bal($A, $BANK), bal($A, $CASH)], [$before[0] - tl(20000), $before[1] + tl(20000)], 'kaynak azalır, hedef artar');
eq(plTotals($A), $t1, 'transfer gelir/gider oluşturmaz');
eq((int)DB::val('SELECT COUNT(*) FROM transactions WHERE transfer_group=?', [$tr['json']['group']]), 2, 'transferin iki bacağı birlikte kaydedildi');
eq($A->post('/transfers', ['from_account_id' => $BANK, 'to_account_id' => $EUR, 'amount_minor' => 100, 'date' => $today])['json']['error']['code'] ?? null, 'currency_mismatch', 'farklı para birimleri arası transfer reddedilir');
eq($A->post('/transfers', ['from_account_id' => $BANK, 'to_account_id' => $BANK, 'amount_minor' => 100])['status'], 422, 'aynı hesaba transfer reddedilir');

section('Finansal #3: planlanan ödeme + tekrarlayan şablon bakiyeyi değiştirmez');
$bb = bal($A, $BANK);
check($A->post('/entries', ['kind' => 'payable', 'workspace_id' => $WS, 'title' => 'Plan: kira', 'amount_minor' => tl(5000), 'due_date' => $d(20), 'contact_id' => $SUPP, 'affects_pl' => true])['ok'], 'planlı borç eklenir');
$rt = $A->post('/recurring', ['workspace_id' => $WS, 'entry_kind' => 'payable', 'title' => 'Aylık abonelik', 'amount_minor' => tl(300), 'next_date' => $d(3), 'frequency' => 'monthly', 'currency' => 'TRY']);
check($rt['ok'], 'tekrarlayan şablon oluşturulur');
$n = (int)DB::val("SELECT COUNT(*) FROM entries WHERE tenant_id=? AND source='recurring'", [$ALFA]);
check($n >= 2, "şablon planlı kalemler üretir ($n)");
$A->post('/recurring/run');
eq((int)DB::val("SELECT COUNT(*) FROM entries WHERE tenant_id=? AND source='recurring'", [$ALFA]), $n, 'şablon tekrar çalışınca mükerrer kalem üretmez');
eq(bal($A, $BANK), $bb, 'planlanan/tekrarlayan ödemeler banka bakiyesini düşürmez');
eq(plTotals($A)['expense_minor'], $t1['expense_minor'], 'gelecek tarihli planlı kalemler gerçekleşen gidere girmez');

section('Finansal #4-5: fatura kesmek tahsilat değildir; mükerrer fatura engeli');
$bb = bal($A, $BANK);
$inv = $A->post('/invoices', ['kind' => 'sales', 'workspace_id' => $WS, 'contact_id' => $CUST, 'number' => 'FB-2026-001', 'issue_date' => $today, 'due_date' => $d(15), 'lines' => [['description' => 'Danışmanlık', 'qty_milli' => 1000, 'unit_price_minor' => tl(1000), 'vat_rate_bp' => 2000]]]);
check($inv['ok'], 'satış faturası kesilir', $inv['json']);
$INV = id($inv);
$det = $A->get("/invoices/$INV")['json'];
eq([$det['invoice']['subtotal_minor'], $det['invoice']['vat_minor'], $det['invoice']['total_minor']], [tl(1000), tl(200), tl(1200)], 'fatura KDV hesabı: 1000 + 200 = 1200');
eq(bal($A, $BANK), $bb, 'fatura kesmek banka bakiyesini değiştirmez');
eq($det['entry']['amount_minor'], tl(1200), 'fatura bir alacak kalemi doğurur');
$dup = $A->post('/invoices', ['kind' => 'sales', 'workspace_id' => $WS, 'contact_id' => $CUST, 'number' => 'fb-2026-001', 'issue_date' => $today, 'lines' => [['description' => 'x', 'qty_milli' => 1000, 'unit_price_minor' => 100, 'vat_rate_bp' => 0]]]);
eq($dup['json']['error']['code'] ?? null, 'duplicate_invoice', 'aynı fatura numarası ikinci alacak oluşturmaz');
eq((int)DB::val('SELECT COUNT(*) FROM entries WHERE invoice_id=?', [$INV]), 1, 'faturaya tek alacak kalemi');
try {
    DB::exec("INSERT INTO entries (tenant_id,workspace_id,kind,source,invoice_id,invoice_key,title,currency,amount_minor,due_date,original_due_date,created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)", [$ALFA, $WS, 'receivable', 'invoice', $INV, (string)$INV, 'x', 'TRY', 1, $today, $today, Util::now()]);
    check(false, 'DB seviyesinde ikinci fatura alacağı engellenir');
} catch (Throwable) {
    check(true, 'DB seviyesinde ikinci fatura alacağı engellenir (UNIQUE)');
}
$ib = plTotals($A);
eq($ib['income_minor'], tl(1000), 'fatura geliri KDV hariç tahakkuk eder (1.000 TL)');
$ENT_INV = $det['entry']['id'];
check($A->post("/entries/$ENT_INV/settle", ['account_id' => $BANK, 'amount_minor' => tl(1200)])['ok'], 'fatura tahsil edilir');
eq(plTotals($A)['income_minor'], tl(1000), 'fatura tahsilatı geliri ikinci kez saymaz');
eq($A->post("/invoices/$INV/cancel", ['reason' => 'test'])['json']['error']['code'] ?? null, 'has_payments', 'tahsilatlı fatura iptal edilemez');

section('Finansal #6: döviz bakiyeleri ayrı; kur işlemi');
$dash = $A->get('/reports/dashboard')['json'];
check(isset($dash['balances']['TRY'], $dash['balances']['EUR'], $dash['balances']['USD']), 'TL/EUR/USD toplamları ayrı gösterilir');
eq($dash['balances']['EUR']['balance_minor'], tl(1000), 'EUR bakiyesi TL ile toplanmaz');
eq($A->post("/entries/$E1/settle", ['account_id' => $BANK, 'amount_minor' => 100])['json']['error']['code'] ?? null, 'overpayment', 'tamamen tahsil edilmiş kaleme ikinci tahsilat reddedilir');
$E2 = id($A->post('/entries', ['kind' => 'receivable', 'workspace_id' => $WS, 'title' => 'EUR alacak', 'currency' => 'EUR', 'amount_minor' => tl(500), 'due_date' => $d(5), 'contact_id' => $CUST]));
eq($A->post("/entries/$E2/settle", ['account_id' => $BANK, 'amount_minor' => tl(100)])['json']['error']['code'] ?? null, 'currency_mismatch', 'EUR kalemi TL hesaba tahsil edilemez');
$fx = $A->post('/fx', ['from_account_id' => $BANK, 'to_account_id' => $EUR, 'from_amount_minor' => tl(10000), 'to_amount_minor' => tl(250), 'fee_minor' => tl(15), 'date' => $today]);
check($fx['ok'], 'döviz alımı kaydedilir', $fx['json']);
eq(bal($A, $EUR), tl(1250), 'EUR hesabı artar');
$pl = plTotals($A);
eq($pl['expense_minor'], $t1['expense_minor'] + tl(15), 'döviz işlemi yalnızca masrafı gider yazar');
$conv = $A->get('/reports/converted', ['to' => 'TRY'])['json'];
eq($conv['missing_rates'], ['EUR', 'USD'], 'kur girilmeden dönüştürülmüş özet kur eksikliğini bildirir');
check($A->post('/fx-rates', ['currency' => 'EUR', 'rate' => '40.5', 'rate_date' => $today])['ok'] && $A->post('/fx-rates', ['currency' => 'USD', 'rate' => '35.25', 'rate_date' => $today])['ok'], 'kurlar girilir');
$conv = $A->get('/reports/converted', ['to' => 'TRY'])['json'];
$eurRow = array_values(array_filter($conv['rows'], fn($x) => $x['currency'] === 'EUR'))[0];
eq([$eurRow['rate'], $eurRow['rate_date'], $eurRow['converted_minor']], ['40.50000000', $today, (int)round(bal($A, $EUR) * 40.5)], 'dönüştürülmüş özet kuru ve tarihi belirtir');

section('Finansal #7: kısmi ödeme yalnızca kalan borcu azaltır');
$P = id($A->post('/entries', ['kind' => 'payable', 'workspace_id' => $WS, 'title' => 'Tedarikçi borcu', 'amount_minor' => tl(10000), 'due_date' => $d(-3), 'contact_id' => $SUPP]));
$ov = $A->get('/entries', ['kind' => 'payable', 'window' => 'overdue'])['json']['entries'];
check(count(array_filter($ov, fn($x) => $x['id'] === $P)) === 1, 'vadesi geçmiş borç "gecikmiş" listesinde');
$r = $A->post("/entries/$P/settle", ['account_id' => $BANK, 'amount_minor' => tl(3000)]);
eq($r['json']['remaining_minor'] ?? null, tl(7000), 'kısmi ödeme sonrası kalan 7.000 TL');
$sch = $A->get('/reports/schedule', ['overdue' => 1])['json']['items'];
$it = array_values(array_filter($sch, fn($x) => $x['type'] === 'payable' && $x['ref'] === $P))[0];
eq([$it['remaining_minor'], $it['days_overdue']], [tl(7000), 3], 'ödeme planında yalnızca kalan kısım, gecikme günü ile');
$px = $A->get('/entries', ['kind' => 'payable'])['json']['entries'];
$moved = array_values(array_filter($px, fn($x) => $x['id'] === $P))[0];
check($A->req('PATCH', "/entries/$P", ['due_date' => $d(10)])['ok'], 'vade yeniden planlanır');
$moved = array_values(array_filter($A->get('/entries', ['kind' => 'payable'])['json']['entries'], fn($x) => $x['id'] === $P))[0];
check($moved['rescheduled'] && $moved['original_due_date'] === $d(-3) && $moved['due_date'] === $d(10), 'ilk vade ve yeni vade ayrı tutulur');

section('Finansal #8: kredi kartı — ekstre ödemesi ikinci gider yazmaz');
$card = $A->post('/cards', ['workspace_id' => $WS, 'name' => 'Kurumsal Kart', 'currency' => 'TRY', 'limit_minor' => tl(50000), 'statement_day' => 5, 'due_day' => 15]);
$CARD = id($card);
check($CARD > 0, 'kart tanımlanır');
$exp0 = plTotals($A)['expense_minor'];
$pu = $A->post("/cards/$CARD/purchases", ['description' => 'Ofis malzemesi', 'amount_minor' => tl(1200), 'installments' => 3, 'txn_date' => $today, 'vat_rate_bp' => 2000, 'category_id' => $catId('expense', 'Malzeme')]);
check($pu['ok'], 'taksitli kart harcaması', $pu['json']);
$exp1 = plTotals($A)['expense_minor'];
eq($exp1 - $exp0, tl(1000), 'harcama alışveriş tarihinde (KDV hariç) bir kez gider');
$cd = $A->get("/cards/$CARD")['json'];
eq([$cd['debt_minor'], count($cd['statements'])], [tl(1200), 3], 'kart borcu ve 3 taksit ekstresi');
$bb = bal($A, $BANK);
$pay = $A->post("/cards/$CARD/pay", ['account_id' => $BANK, 'amount_minor' => tl(400), 'date' => $today]);
check($pay['ok'], 'ekstre ödemesi');
eq(bal($A, $BANK), $bb - tl(400), 'ödeme banka bakiyesini düşürür');
eq(plTotals($A)['expense_minor'], $exp1, 'kart ödemesi ikinci gider oluşturmaz');
eq($A->get("/cards/$CARD")['json']['debt_minor'], tl(800), 'kart borcu 800 TL kaldı');
eq($A->post("/cards/$CARD/pay", ['account_id' => $BANK, 'amount_minor' => tl(900)])['json']['error']['code'] ?? null, 'overpayment', 'borçtan fazla kart ödemesi reddedilir');

section('Finansal #9: kredi anapara ödemesi gider değildir');
$loan = $A->post('/loans', ['workspace_id' => $WS, 'name' => 'İşletme Kredisi', 'currency' => 'TRY', 'principal_minor' => tl(120000), 'monthly_rate_bp' => 200, 'term_months' => 12, 'start_date' => $today]);
$LOAN = id($loan);
check($LOAN > 0, 'kredi oluşturulur ve plan üretilir');
$ld = $A->get("/loans/$LOAN")['json'];
eq([count($ld['installments']), $ld['totals']['principal']], [12, tl(120000)], '12 taksit, anapara toplamı kredi tutarı');
$exp = plTotals($A);
$bb = bal($A, $BANK);
check($A->post("/loans/$LOAN/disburse", ['account_id' => $BANK, 'date' => $today])['ok'], 'kredi kullandırımı');
eq(bal($A, $BANK), $bb + tl(120000), 'kredi hesaba girer');
eq(plTotals($A)['income_minor'], $exp['income_minor'], 'kredi kullandırımı gelir değildir');
eq($A->post("/loans/$LOAN/disburse", ['account_id' => $BANK])['status'], 409, 'kredi iki kez kullandırılamaz');
$i1 = $ld['installments'][0];
$bb = bal($A, $BANK);
check($A->post("/loans/$LOAN/pay", ['account_id' => $BANK, 'installment_id' => $i1['id'], 'date' => $today])['ok'], 'taksit ödenir');
eq(bal($A, $BANK), $bb - ($i1['principal_minor'] + $i1['interest_minor']), 'banka taksit tutarı kadar düşer');
eq(plTotals($A)['expense_minor'] - $exp['expense_minor'], (int)$i1['interest_minor'], 'yalnızca faiz payı gider (anapara faaliyet gideri değil)');
eq($A->post("/loans/$LOAN/pay", ['account_id' => $BANK, 'installment_id' => $i1['id']])['status'], 409, 'taksit ikinci kez ödenemez');

section('Finansal #10: şahsi fatura şirket giderine eklenmez');
$biz0 = plTotals($A, 'business');
$bill = $A->post('/entries', ['kind' => 'payable', 'workspace_id' => $WSP, 'source' => 'personal_bill', 'title' => 'Elektrik', 'provider' => 'Enerjisa', 'period' => '2026-10', 'amount_minor' => tl(800), 'due_date' => $today]);
check($bill['ok'], 'şahsi fatura oluşturulur');
eq(plTotals($A, 'business')['expense_minor'], $biz0['expense_minor'], 'şirket giderleri değişmez');
eq(plTotals($A, 'personal')['expense_minor'], tl(800), 'şahsi görünümde 800 TL');
eq($A->post('/entries', ['kind' => 'payable', 'workspace_id' => $WS, 'source' => 'personal_bill', 'title' => 'x', 'amount_minor' => 100, 'due_date' => $today])['status'], 422, 'şahsi fatura işletme alanında açılamaz');
$s = $A->get('/reports/schedule', ['scope' => 'personal'])['json']['items'];
check(count($s) === 1 && $s[0]['type'] === 'bill', 'şahsi fatura ödeme takviminde (şahsi görünüm)');
$sAll = $A->get('/reports/schedule', ['scope' => 'all'])['json']['items'];
check(count(array_filter($sAll, fn($x) => $x['type'] === 'bill')) === 1, 'tümü görünümünde de yer alır');

section('Finansal #11: aynı banka ekstresi ikinci kez yüklenince mükerrer oluşmaz');
$lines = [['date' => $today, 'description' => 'POS TAHSILAT', 'amount_minor' => tl(250)], ['date' => $today, 'description' => 'KOMISYON', 'amount_minor' => -tl(5)], ['date' => $today, 'description' => 'KOMISYON', 'amount_minor' => -tl(5)]];
$r1 = $A->post("/accounts/$BANK/statement", ['lines' => $lines]);
eq([$r1['json']['added'], $r1['json']['duplicates']], [3, 0], 'ilk yükleme: 3 satır (aynı gün/tutarlı gerçek tekrar korunur)');
$r2 = $A->post("/accounts/$BANK/statement", ['lines' => $lines]);
eq([$r2['json']['added'], $r2['json']['duplicates']], [0, 3], 'ikinci yükleme: mükerrer kayıt yok');
eq((int)DB::val('SELECT COUNT(*) FROM statement_lines WHERE account_id=?', [$BANK]), 3, 'veritabanında 3 satır');
$sl = $A->get('/statement-lines', ['account_id' => $BANK, 'status' => 'unmatched'])['json']['lines'];
$first = array_values(array_filter($sl, fn($x) => $x['amount_minor'] === tl(250)))[0];
$cr = $A->post("/statement-lines/{$first['id']}/create", ['category_id' => $catId('income', 'Satış Geliri')]);
check($cr['ok'], 'ekstre satırından gelir hareketi oluşturulur');
eq($A->post("/statement-lines/{$first['id']}/create", ['category_id' => $catId('income', 'Satış Geliri')])['status'], 409, 'işlenmiş satır tekrar işlenemez');
$rec = $A->get("/accounts/$BANK/reconciliation")['json'];
check(!empty($rec['counts']), 'mutabakat özeti');

section('Finansal #13: proje gelir/maliyeti iki kez sayılmaz');
$PR = id($A->post('/projects', ['name' => 'Santral Kurulumu', 'workspace_id' => $WS, 'contact_id' => $CUST, 'contract_minor' => tl(50000), 'est_cost_minor' => tl(30000), 'currency' => 'TRY']));
$si = $A->post('/invoices', ['kind' => 'sales', 'workspace_id' => $WS, 'contact_id' => $CUST, 'number' => 'PR-1', 'issue_date' => $today, 'project_id' => $PR, 'lines' => [['description' => 'Hakediş 1', 'qty_milli' => 1000, 'unit_price_minor' => tl(10000), 'vat_rate_bp' => 2000]]]);
$sInv = $A->get('/invoices/' . id($si))['json'];
check($A->post("/entries/{$sInv['entry']['id']}/settle", ['account_id' => $BANK, 'amount_minor' => tl(12000)])['ok'], 'hakediş tahsil edilir');
$pi = $A->post('/invoices', ['kind' => 'purchase', 'workspace_id' => $WS, 'contact_id' => $SUPP, 'number' => 'ALIS-9', 'issue_date' => $today, 'project_id' => $PR, 'lines' => [['description' => 'Malzeme', 'qty_milli' => 1000, 'unit_price_minor' => tl(4000), 'vat_rate_bp' => 2000]]]);
$pInv = $A->get('/invoices/' . id($pi))['json'];
check($A->post("/entries/{$pInv['entry']['id']}/settle", ['account_id' => $BANK, 'amount_minor' => tl(4800)])['ok'], 'alış faturası ödenir');
check($A->post('/transactions', ['kind' => 'expense', 'account_id' => $BANK, 'amount_minor' => tl(500), 'project_id' => $PR, 'txn_date' => $today, 'category_id' => $catId('expense', 'Yakıt'), 'description' => 'Yakıt'])['ok'], 'projeye doğrudan gider');
$pf = $A->get("/projects/$PR")['json']['figures'];
eq([$pf['revenue_minor'], $pf['cost_minor'], $pf['profit_minor']], [tl(10000), tl(4500), tl(5500)], 'proje: gelir 10.000, maliyet 4.500 (tahsilat/ödeme tekrar sayılmadı), kâr 5.500');
eq([$pf['cash_in_minor'], $pf['cash_out_minor']], [tl(12000), tl(5300)], 'proje nakit girişi/çıkışı ayrı (kâr ≠ nakit)');
$ms = $A->post("/projects/$PR/milestones", ['title' => 'Hakediş 2', 'amount_minor' => tl(20000), 'due_date' => $d(30)]);
check($ms['ok'], 'hakediş planı alacak kalemi doğurur');
// stok: alış faturası ile stoğa giriş (gider değil), projeye tüketim (maliyet bir kez)
$WH = id($A->post('/stock/warehouses', ['name' => 'Ana Depo']));
$IT = id($A->post('/stock/items', ['kind' => 'product', 'code' => 'KBL-1', 'name' => 'Kablo 10m', 'unit' => 'adet', 'vat_rate_bp' => 2000]));
$exp0 = plTotals($A)['expense_minor'];
check($A->post('/invoices', ['kind' => 'purchase', 'workspace_id' => $WS, 'contact_id' => $SUPP, 'number' => 'STK-1', 'issue_date' => $today, 'lines' => [['description' => 'Kablo', 'item_id' => $IT, 'warehouse_id' => $WH, 'qty_milli' => 10000, 'unit_price_minor' => tl(100), 'vat_rate_bp' => 2000]]])['ok'], 'stoklu alış faturası');
eq(plTotals($A)['expense_minor'], $exp0, 'stoğa alınan mal alışta gider değildir');
$lv = $A->get('/stock/items')['json']['items'][0];
eq([$lv['qty_milli'], $lv['avg_cost_minor'], $lv['value_minor']], [10000, tl(100), tl(1000)], 'stok 10 adet, ortalama maliyet 100 TL');
$mv = $A->post('/stock/movements', ['item_id' => $IT, 'warehouse_id' => $WH, 'kind' => 'out', 'qty_milli' => 2000, 'project_id' => $PR, 'move_date' => $today]);
check($mv['ok'], 'projeye malzeme tüketimi');
eq(plTotals($A)['expense_minor'], $exp0 + tl(200), 'tüketim maliyeti (2 × 100 TL) bir kez gider');
$pf2 = $A->get("/projects/$PR")['json']['figures'];
eq($pf2['cost_minor'], tl(4500) + tl(200), 'proje maliyetine yalnızca tüketim eklenir');
$mv2 = $A->post('/stock/movements', ['item_id' => $IT, 'warehouse_id' => $WH, 'kind' => 'out', 'qty_milli' => 9000, 'move_date' => $today]);
eq($mv2['status'], 409, 'stok eksiye düşürülemez (varsayılan politika)');
check($A->req('PUT', '/settings', ['stock' => ['allow_negative' => true]])['ok'], 'negatif stok ayarı açılır');
$mv2 = $A->post('/stock/movements', ['item_id' => $IT, 'warehouse_id' => $WH, 'kind' => 'out', 'qty_milli' => 9000, 'move_date' => $today]);
check(!empty($mv2['json']['warning']), 'negatif stok uyarısı verilir (ayar açıkken)');
check($A->req('PUT', '/settings', ['stock' => ['allow_negative' => false]])['ok'], 'negatif stok ayarı kapatılır');

section('Finansal #14-15: iptal/düzeltme geçmişi, çift gönderim');
$ik = bin2hex(random_bytes(10));
$body = ['kind' => 'income', 'account_id' => $BANK, 'amount_minor' => tl(1000), 'txn_date' => $today, 'description' => 'Çift tık testi', 'category_id' => $catId('income', 'Hizmet Geliri')];
$x1 = $A->req('POST', '/transactions', $body, ['Idempotency-Key' => $ik]);
$x2 = $A->req('POST', '/transactions', $body, ['Idempotency-Key' => $ik]);
eq($x1['json']['id'] === $x2['json']['id'], true, 'aynı Idempotency-Key aynı sonucu döner');
eq(isset($x2['h']['idempotent-replayed']), true, 'ikinci istek tekrar yanıtı olarak işaretli');
eq((int)DB::val("SELECT COUNT(*) FROM transactions WHERE description='Çift tık testi'"), 1, 'çift tık / yeniden gönderim tek kayıt oluşturur');
$TX = $x1['json']['id'];
$bb = bal($A, $BANK);
eq($A->post("/transactions/$TX/void", ['reason' => 'Hatalı giriş'])['ok'], true, 'işlem iptal edilir');
eq(bal($A, $BANK), $bb - tl(1000), 'iptal bakiyeyi geri alır');
$row = DB::one('SELECT status, void_reason FROM transactions WHERE id=?', [$TX]);
eq([$row['status'], $row['void_reason']], ['voided', 'Hatalı giriş'], 'iptal edilen kayıt silinmez, geçmişte kalır');
check(DB::val("SELECT COUNT(*) FROM audit_log WHERE action='transaction_voided' AND entity_id=?", [(string)$TX]) == 1, 'iptal denetim kaydında');
eq($A->post("/transactions/$TX/void", ['reason' => 'tekrar'])['status'], 409, 'iptal edilmiş işlem tekrar iptal edilemez');
check(count(array_filter($A->get('/transactions', ['status' => 'voided'])['json']['transactions'], fn($x) => $x['id'] === $TX)) === 1, 'iptal edilenler ayrıca listelenir');
check(count(array_filter($A->get('/transactions')['json']['transactions'], fn($x) => $x['id'] === $TX)) === 0, 'varsayılan liste yalnızca aktif kayıtlar');
$y = $A->post('/transactions', ['kind' => 'expense', 'account_id' => $BANK, 'amount_minor' => tl(100), 'txn_date' => $today, 'description' => 'eski']);
$rp = $A->post('/transactions/' . id($y) . '/replace', ['kind' => 'expense', 'account_id' => $BANK, 'amount_minor' => tl(120), 'txn_date' => $today, 'description' => 'düzeltilmiş']);
check($rp['ok'] && (int)DB::val('SELECT replaces_id FROM transactions WHERE id=?', [id($rp)]) === id($y), 'düzenleme = eski iptal + yeni kayıt (bağlantılı)');
eq(DB::val('SELECT status FROM transactions WHERE id=?', [id($y)]), 'voided', 'eski kayıt iptal olarak korunur');

section('KDV, tarih, bakiye düzeltme, arşiv');
$v1 = $A->post('/transactions', ['kind' => 'expense', 'account_id' => $BANK, 'amount_minor' => tl(1200), 'vat_rate_bp' => 2000, 'txn_date' => $today, 'description' => 'KDV dahil']);
$v2 = $A->post('/transactions', ['kind' => 'expense', 'account_id' => $BANK, 'amount_minor' => tl(1000), 'vat_rate_bp' => 2000, 'vat_mode' => 'excluded', 'txn_date' => $today, 'description' => 'KDV hariç']);
eq([(int)DB::val('SELECT vat_minor FROM transactions WHERE id=?', [id($v1)]), (int)DB::val('SELECT amount_minor FROM transactions WHERE id=?', [id($v2)]), (int)DB::val('SELECT vat_minor FROM transactions WHERE id=?', [id($v2)])], [tl(200), tl(1200), tl(200)], 'KDV dahil/hariç hesabı doğru');
eq($A->post('/transactions', ['kind' => 'expense', 'account_id' => $BANK, 'amount_minor' => 100, 'txn_date' => $d(10)])['json']['error']['code'] ?? null, 'future_date', 'ileri tarihli gerçekleşen işlem reddedilir');
eq($A->post('/transactions', ['kind' => 'expense', 'account_id' => $BANK, 'amount_minor' => 100, 'txn_date' => '2025-01-01'])['json']['error']['code'] ?? null, 'before_opening', 'açılış tarihinden önceki işlem reddedilir');
eq($A->post('/transactions', ['kind' => 'income', 'account_id' => $BANK, 'amount_minor' => 0, 'txn_date' => $today])['status'], 422, 'sıfır/negatif tutar reddedilir');
eq($A->post('/transactions', ['kind' => 'expense', 'account_id' => $BANK, 'amount_minor' => 100, 'txn_date' => $today, 'category_id' => $catId('income', 'Satış Geliri')])['status'], 422, 'kategori türü uyuşmazlığı reddedilir');
$inc0 = plTotals($A)['income_minor'];
$bb = bal($A, $CASH);
check($A->post("/accounts/$CASH/adjust", ['delta_minor' => -tl(50), 'reason' => 'Sayım farkı'])['ok'], 'bakiye düzeltme kaydı');
eq([bal($A, $CASH), plTotals($A)['income_minor']], [$bb - tl(50), $inc0], 'düzeltme bakiyeyi değiştirir, gelir/gider oluşturmaz');
eq($A->req('PATCH', "/accounts/$BANK", ['archived' => true])['json']['error']['code'] ?? null, 'nonzero_balance', 'bakiyesi olan hesap arşivlenemez');
eq($A->req('PATCH', "/accounts/$BANK", ['opening_minor' => 1])['json']['error']['code'] ?? null, 'has_transactions', 'hareketli hesabın açılış bakiyesi değiştirilemez');

section('Raporlar kaynak kayıtlarla uyuşur');
$dash = $A->get('/reports/dashboard', ['scope' => 'all'])['json'];
$sumAcc = 0;
foreach ($A->get('/accounts')['json']['accounts'] as $a) {
    if ($a['currency'] === 'TRY' && !$a['archived']) {
        $sumAcc += $a['balance_minor'];
    }
}
eq($dash['balances']['TRY']['balance_minor'], $sumAcc, 'dashboard TL toplamı hesap bakiyeleriyle aynı');
$ie = $A->get('/reports/income-expense', ['scope' => 'business', 'from' => $yearStart, 'to' => $today])['json'];
$sumInc = 0;
$sumExp = 0;
foreach ($ie['rows'] as $row) {
    if ($row['currency'] === 'TRY') {
        $sumInc += $row['income_minor'];
        $sumExp += $row['expense_minor'];
    }
}
eq([$sumInc, $sumExp], [$ie['totals']['TRY']['income_minor'], $ie['totals']['TRY']['expense_minor']], 'aylık rapor toplamı genel toplamla aynı');
$cf = $A->get('/reports/cashflow', ['from' => $yearStart, 'to' => $today])['json']['realized'];
$cin = 0;
$cout = 0;
foreach ($cf as $r) {
    if ($r['currency'] === 'TRY') {
        $cin += $r['cash_in_minor'];
        $cout += $r['cash_out_minor'];
    }
}
$ledgerNet = (int)DB::val("SELECT SUM(CASE WHEN direction='in' THEN amount_minor ELSE -amount_minor END) FROM transactions WHERE tenant_id=? AND currency='TRY' AND status='posted' AND kind NOT IN ('transfer','fx','adjustment')", [$ALFA]);
eq($cin - $cout, $ledgerNet, 'nakit akışı raporu defter hareketleriyle uyuşur');
$vat = $A->get('/reports/vat', ['scope' => 'business', 'from' => $yearStart, 'to' => $today])['json'];
$out = $vat['summary']['TRY']['output_vat_minor'];
$in = $vat['summary']['TRY']['input_vat_minor'];
check($out >= tl(200) + tl(2000) && $in > 0, "KDV raporu: hesaplanan $out / indirilecek $in", $vat['summary']);
eq($vat['summary']['TRY']['payable_vat_minor'], $out - $in, 'ödenecek KDV = hesaplanan − indirilecek');
$ag = $A->get('/reports/aging', ['kind' => 'payable'])['json'];
check(isset($ag['totals']['TRY']['total_minor']), 'yaşlandırma raporu');
$stm = $A->get("/contacts/$CUST/statement")['json'];
check(!empty($stm['groups']), 'cari ekstre üretilir');
eq($stm['groups'][0]['closing_minor'], (int)DB::val("SELECT SUM(CASE WHEN e.kind='receivable' THEN e.amount_minor ELSE -e.amount_minor END) - COALESCE((SELECT SUM(pa.amount_minor) FROM payment_allocations pa JOIN transactions t ON t.id=pa.txn_id JOIN entries e2 ON e2.id=pa.entry_id WHERE pa.tenant_id=? AND pa.status='active' AND e2.contact_id=? AND e2.currency='TRY' AND t.status='posted'),0) FROM entries e WHERE e.tenant_id=? AND e.contact_id=? AND e.currency='TRY' AND e.status='open'", [$ALFA, $CUST, $ALFA, $CUST]), 'cari ekstre kapanış bakiyesi kayıtlarla uyuşur');
check($A->req('PUT', '/budgets', ['month' => substr($today, 0, 7), 'category_id' => $catId('expense', 'Malzeme'), 'amount_minor' => tl(2000)])['ok'], 'bütçe girilir');
$bd = $A->get('/reports/budget', ['month' => substr($today, 0, 7), 'scope' => 'business'])['json']['rows'][0];
eq([$bd['budget_minor'], $bd['actual_minor']], [tl(2000), tl(1000)], 'bütçe-gerçekleşen karşılaştırması');
$n = $A->get('/reports/notifications')['json']['notifications'];
check(count($n) > 0 && isset($n[0]['level']), 'bildirim merkezi gerçek kayıtlardan üretilir (' . count($n) . ')');

section('Veri yalıtımı: Beta, Alfa verisine erişemez');
$bm = $B->get('/meta')['json'];
eq([count($bm['accounts']), count($bm['workspaces']), count($bm['contacts'])], [0, 0, 0], 'Beta Alfa verisini göremez');
eq($B->get("/accounts/$BANK/ledger")['status'], 404, 'başka müşterinin hesap defteri okunamaz');
eq($B->req('PATCH', "/accounts/$BANK", ['name' => 'Hack'])['status'], 404, 'başka müşterinin hesabı değiştirilemez');
eq($B->post("/transactions/$TX/void", ['reason' => 'x'])['status'], 404, 'başka müşterinin işlemi iptal edilemez');
eq($B->post("/entries/$E2/settle", ['account_id' => $BANK, 'amount_minor' => 100])['status'], 404, 'başka müşterinin kalemine tahsilat girilemez');
eq($B->get("/invoices/$INV")['status'], 404, 'başka müşterinin faturası okunamaz');
eq($B->get("/contacts/$CUST/statement")['status'], 404, 'başka müşterinin cari ekstresi okunamaz');
eq($B->get("/projects/$PR")['status'], 404, 'başka müşterinin projesi okunamaz');
eq($B->get("/cards/$CARD")['status'], 404, 'başka müşterinin kartı okunamaz');
eq($B->get("/loans/$LOAN")['status'], 404, 'başka müşterinin kredisi okunamaz');
$BW = id($B->post('/workspaces', ['name' => 'Beta Ortak', 'kind' => 'business']));
eq($B->post('/accounts', ['workspace_id' => $WS, 'name' => 'Sızma', 'opening_date' => $yearStart])['status'], 404, 'başka müşterinin alanına hesap açılamaz');
$BB = id($B->post('/accounts', ['workspace_id' => $BW, 'name' => 'Beta Banka', 'currency' => 'TRY', 'opening_minor' => tl(1), 'opening_date' => $yearStart]));
eq($B->post('/transactions', ['kind' => 'income', 'account_id' => $BANK, 'amount_minor' => 100, 'txn_date' => $today])['status'], 404, 'başka müşterinin hesabına işlem yazılamaz');
eq($B->post('/transfers', ['from_account_id' => $BB, 'to_account_id' => $BANK, 'amount_minor' => 1, 'date' => $today])['status'], 404, 'başka müşterinin hesabına transfer yapılamaz');
eq(count($B->get('/transactions')['json']['transactions']), 0, 'Beta işlem listesi boş');
eq(count($B->get('/reports/schedule')['json']['items']), 0, 'Beta ödeme planı boş');
eq($B->get('/reports/dashboard')['json']['balances'], ['TRY' => ['balance_minor' => tl(1)]], 'Beta dashboard yalnızca kendi bakiyesi');
eq($B->get('/transactions', ['tenant' => $ALFA])['json']['count'], 0, '?tenant= parametresi müşteri için yok sayılır');
eq((int)DB::val('SELECT COUNT(*) FROM transactions WHERE tenant_id=(SELECT id FROM tenants WHERE name=?)', ['Beta AŞ']), 0, 'Beta tenant\'ında Alfa kaydı oluşmadı');

section('Belgeler: yükleme, yetkili indirme, güvenli dosya türleri');
$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');
$tmp = sys_get_temp_dir() . '/fb_test.png';
file_put_contents($tmp, $png);
$up = $A->upload('/documents', ['entity' => 'transaction', 'entity_id' => (string)id($y), 'x' => '1'], $tmp, 'image/png', 'fis.png');
check($up['ok'], 'fiş yüklenir', $up['json']);
$DOC = id($up);
$dl = $A->req('GET', "/documents/$DOC/download");
check($dl['status'] === 200 && $dl['raw'] === $png, 'sahibi belgeyi indirir (içerik aynı)');
check(str_contains(implode('', $dl['h']['content-disposition'] ?? []), 'attachment') && str_contains(implode('', $dl['h']['content-security-policy'] ?? []), 'sandbox'), 'indirme attachment + sandbox CSP');
eq($B->req('GET', "/documents/$DOC/download")['status'], 404, 'başka müşteri belgeyi indiremez');
eq((new Client())->req('GET', "/documents/$DOC/download")['status'], 401, 'oturumsuz indirme engellenir');
$stored = DB::one('SELECT stored_name, tenant_id FROM documents WHERE id=?', [$DOC]);
check(!file_exists(dirname(__DIR__, 2) . '/public/' . $stored['stored_name']) && !str_contains(FB_APP_DIR, 'public'), 'dosya web kökünün dışında saklanır');
$php = sys_get_temp_dir() . '/shell.php';
file_put_contents($php, '<?php echo 1;');
eq($A->upload('/documents', ['entity' => 'transaction', 'entity_id' => (string)id($y)], $php, 'image/png', 'shell.png.php')['status'], 415, 'PHP/çalıştırılabilir dosya reddedilir');
$fake = sys_get_temp_dir() . '/fake.png';
file_put_contents($fake, '<?php system($_GET[1]); ?>');
eq($A->upload('/documents', ['entity' => 'transaction', 'entity_id' => (string)id($y)], $fake, 'image/png', 'fake.png')['status'], 415, 'uzantısı png ama içeriği PHP olan dosya reddedilir (MIME sniff)');
eq($B->upload('/documents', ['entity' => 'transaction', 'entity_id' => (string)$TX], $tmp, 'image/png', 'x.png')['status'], 404, 'başka müşterinin kaydına belge eklenemez');

section('Dışa aktarma');
$csvR = $A->req('GET', '/export/transactions?format=csv&from=' . $yearStart);
check($csvR['status'] === 200 && str_starts_with($csvR['raw'], "\xEF\xBB\xBF"), 'CSV (UTF-8 BOM, Excel uyumlu)');
$inj = $A->post('/transactions', ['kind' => 'expense', 'account_id' => $BANK, 'amount_minor' => 100, 'txn_date' => $today, 'description' => '=HYPERLINK("http://evil","x")']);
$csvR = $A->req('GET', '/export/transactions?format=csv');
check(str_contains($csvR['raw'], '"\'=HYPERLINK') && !str_contains($csvR['raw'], ';"=HYPERLINK'), 'CSV formül enjeksiyonu etkisizleştirilir');
$xl = $A->req('GET', '/export/income-expense?format=xlsx');
check($xl['status'] === 200 && str_starts_with($xl['raw'], 'PK'), 'XLSX dosyası üretilir');
check(str_contains($A->req('GET', '/export/vat?format=csv')['raw'], 'Hesaplanan'), 'KDV CSV dışa aktarımı');
$full = $A->req('GET', '/settings/export');
check(($full['json']['tenant_id'] ?? 0) === $ALFA && count($full['json']['transactions']) > 10, 'müşteri tüm verisini JSON olarak dışa aktarır');

section('Ayarlar');
$st = $A->req('PUT', '/settings', ['theme' => 'dark', 'notify' => ['bills' => false], 'vat_rates' => [0, 1000, 2000, 800], 'default_account_id' => $BANK]);
check($st['ok'] && $st['json']['settings']['theme'] === 'dark' && $st['json']['settings']['notify']['bills'] === false, 'ayarlar kaydedilir');
eq($A->req('PUT', '/settings', ['default_account_id' => $BB])['status'], 404, 'başka müşterinin hesabı varsayılan yapılamaz');
eq($A->req('PUT', '/settings', ['theme' => 'neon'])['status'], 422, 'geçersiz tema reddedilir');
$ses = $A->get('/auth/sessions')['json']['sessions'];
check(count($ses) >= 1 && $ses[0]['current'], 'aktif oturumlar listelenir');

section('Admin: müşteri finans detayı, denetimli erişim, düzeltme');
$K = new Client();
$K->login($GLOBALS['ADMIN_EMAIL'], $GLOBALS['ADMIN_PASS'], $GLOBALS['ADMIN_SECRET']);
$av = $K->get('/accounts', ['tenant' => $ALFA]);
check($av['ok'] && count($av['json']['accounts']) >= 5, 'admin müşteri hesaplarını girişsiz görür');
check(DB::val("SELECT COUNT(*) FROM audit_log WHERE action='admin_view_tenant_data' AND tenant_id=?", [$ALFA]) >= 1, 'admin erişimi denetim kaydına yazıldı');
eq($K->get('/accounts')['status'], 422, 'admin müşteri seçmeden müşteri verisi okuyamaz');
eq($K->post('/transactions?tenant=' . $ALFA, ['kind' => 'income', 'account_id' => $BANK, 'amount_minor' => 100, 'txn_date' => $today])['status'], 403, 'admin normal uç noktadan müşteri verisini değiştiremez');
$t = $K->get('/transactions', ['tenant' => $ALFA, 'limit' => 5])['json'];
check($t['count'] > 10, 'admin işlem geçmişini filtreleyerek inceler');
$rep = $K->get('/reports/income-expense', ['tenant' => $ALFA, 'scope' => 'business', 'from' => $yearStart, 'to' => $today])['json'];
eq($rep['totals']['TRY'], plTotals($A), 'admin raporu müşteri raporuyla aynı');
$cx = id($A->post('/transactions', ['kind' => 'expense', 'account_id' => $BANK, 'amount_minor' => tl(77), 'txn_date' => $today, 'description' => 'admin düzeltilecek']));
$bb = bal($A, $BANK);
$cr = $K->post("/admin/tenants/$ALFA/transactions/$cx/void", ['reason' => 'Mükerrer giriş']);
check($cr['ok'], 'admin düzeltme (iptal) yapar');
eq(bal($A, $BANK), $bb + tl(77), 'düzeltme bakiyeye yansır');
check(DB::val("SELECT COUNT(*) FROM audit_log WHERE action='admin_correction_void' AND tenant_id=?", [$ALFA]) == 1, 'admin düzeltmesi denetim kaydında (kayıt silinmedi)');
$ad = $K->get("/admin/tenants/$ALFA")['json'];
check(isset($ad['counts']['transactions'], $ad['balances']['TRY']) && $ad['user']['email'] === 'alfa@test.local', 'müşteri detay ekranı verisi');
$ov = $K->get('/admin/overview')['json'];
check($ov['counts']['total'] == 2 && isset($ov['tenants'][0]['balances']) && !empty($ov['ops']['php']), 'yönetim özeti: sayılar, para birimi bazında özet, operasyon');
eq($K->post("/admin/tenants/$ALFA/purge", ['admin_password' => $GLOBALS['ADMIN_PASS']])['status'], 409, 'arşivlenmemiş müşteri kalıcı silinemez');
$K->post("/admin/tenants/$ALFA/status", ['status' => 'archived']);
eq($K->post("/admin/tenants/$ALFA/purge", ['admin_password' => $GLOBALS['ADMIN_PASS']])['json']['error']['code'] ?? null, 'has_financial_records', 'finansal geçmişi olan müşteri fiziksel silinmez (arşivlenir)');
eq((int)DB::val('SELECT COUNT(*) FROM transactions WHERE tenant_id=?', [$ALFA]) > 10, true, 'arşivde finansal kayıtlar korunur');
eq($K->post("/admin/tenants/$ALFA/status", ['status' => 'active'])['ok'], true, 'arşivden geri alınır');
$GLOBALS['A_BANK'] = $BANK;
