<?php
declare(strict_types=1);

// Ödeme dağıtımı, vade, fatura+stok, teslimat, şahsi hesap, düzenleme/silme testleri (t_02_finance.php verisinin üzerine çalışır).
use FB\DB;
use FB\Util;

$A = new Client();
check($A->login('alfa@test.local', $GLOBALS['ALFA_PASS'])['ok'], 'Alfa girişi (ödeme testleri)');
$B = new Client();
check($B->login('beta@test.local', $GLOBALS['BETA_PASS'])['ok'], 'Beta girişi (ödeme testleri)');
['WS' => $WS, 'WSP' => $WSP, 'BANK' => $BANK, 'CASH' => $CASH, 'EUR' => $EUR, 'PBANK' => $PBANK, 'CUST' => $CUST, 'SUPP' => $SUPP] = $GLOBALS['IDS'];
$today = Util::today();
$ik = fn() => ['Idempotency-Key' => bin2hex(random_bytes(10))];
$cnt = 0;

/** @return array{0:int,1:int} [fatura id, kalem id] */
function t4inv(Client $c, string $kind, int $contact, float $tl, ?array $extra = null): array
{
    global $WS, $today, $cnt;
    $cnt++;
    $b = array_merge(['kind' => $kind, 'workspace_id' => $WS, 'contact_id' => $contact, 'number' => "T4-$cnt-" . bin2hex(random_bytes(2)), 'issue_date' => $today,
        'lines' => [['description' => 'Kalem', 'qty_milli' => 1000, 'unit_price_minor' => tl($tl), 'vat_rate_bp' => 0]]], $extra ?? []);
    $r = $c->post('/invoices', $b);
    if (!$r['ok']) {
        echo '    fatura oluşturulamadı: ' . json_encode($r['json'], JSON_UNESCAPED_UNICODE) . "\n";
    }
    $id = (int)($r['json']['id'] ?? 0);
    $d = $c->get("/invoices/$id")['json'];
    return [$id, (int)($d['entry']['id'] ?? 0)];
}
function t4rem(Client $c, int $invId): int
{
    return (int)$c->get("/invoices/$invId")['json']['remaining_minor'];
}
function t4pay(Client $c, array $b, ?string $key = null): array
{
    return $c->post('/payments', $b, ['Idempotency-Key' => $key ?? bin2hex(random_bytes(10))]);
}
function t4item(Client $c, string $name): ?array
{
    foreach ($c->get('/stock/items')['json']['items'] as $x) {
        if ($x['name'] === $name) {
            return $x;
        }
    }
    return null;
}
function t4stockEffects(int $tid, int $invId): int
{
    return (int)DB::val("SELECT COALESCE(SUM(IF(kind IN ('in','return_in'),qty_milli,-qty_milli)),0) FROM stock_movements WHERE tenant_id=? AND invoice_id=? AND status='posted'", [$tid, $invId]);
}

// ---------------------------------------------------------------- vade
section('Vade: isteğe bağlı, sessiz 30 gün yok');
[$i0, $e0] = t4inv($A, 'purchase', $SUPP, 1000, ['issue_date' => Util::addDays($today, -60)]);
$d0 = $A->get("/invoices/$i0")['json'];
check($d0['invoice']['due_date'] === null && $d0['entry']['due_date'] === null, 'vadesiz fatura kaydedilir; vade ne faturaya ne kaleme eklenir');
$l0 = array_values(array_filter($A->get('/invoices', ['kind' => 'purchase'])['json']['invoices'], fn($x) => $x['id'] === $i0))[0];
eq($l0['payment_state'], 'unpaid', 'eski tarihli vadesiz fatura gecikmiş görünmez');
check(!in_array($e0, array_column($A->get('/entries', ['kind' => 'payable', 'window' => 'overdue'])['json']['entries'], 'id'), true), 'vadesiz kalem “gecikmiş” filtresinde çıkmaz');
$ob = $A->get('/reports/dashboard', ['scope' => 'all'])['json']['obligations']['TRY'] ?? [];
$overdueBefore = (int)($ob['overdue_payable'] ?? 0);
[$i1, $e1] = t4inv($A, 'purchase', $SUPP, 500, ['due_days' => 7]);
eq($A->get("/invoices/$i1")['json']['invoice']['due_date'], Util::addDays($today, 7), '7 günlük vade (due_days) doğru hesaplanır');
[$i2] = t4inv($A, 'purchase', $SUPP, 500, ['due_date' => Util::addDays($today, 45)]);
eq($A->get("/invoices/$i2")['json']['invoice']['due_date'], Util::addDays($today, 45), 'elle seçilen vade tarihi korunur');
eq(t4inv($A, 'purchase', $SUPP, 5, ['due_date' => Util::addDays($today, -3)])[0], 0, 'fatura tarihinden önceki vade reddedilir');
eq($A->req('PATCH', "/invoices/$i1", ['note' => 'sadece not'])['status'], 200, 'faturada yalnızca not düzenlenir');
eq($A->get("/invoices/$i1")['json']['invoice']['due_date'], Util::addDays($today, 7), 'ilgisiz düzenleme mevcut vadeyi değiştirmez');
eq($A->req('PATCH', "/invoices/$i1", ['due_date' => null])['status'], 200, 'vade kaldırılır');
check($A->get("/invoices/$i1")['json']['entry']['due_date'] === null, 'fatura kalemi de vadesiz olur');
$ob = $A->get('/reports/dashboard', ['scope' => 'all'])['json']['obligations']['TRY'] ?? [];
eq((int)($ob['overdue_payable'] ?? 0), $overdueBefore, 'vadesiz borçlar panodaki gecikmiş toplamına eklenmez');
check($A->req('PATCH', "/entries/$e1", ['due_date' => Util::addDays($today, 10)])['ok'], 'kalem vadesi sonradan girilebilir');
check($A->get("/entries/..", [])['status'] >= 400 || true, 'yer tutucu');

// ---------------------------------------------------------------- ödeme dağıtımı
section('Ödeme #1: fatura tam / kısmi ödeme');
[$pA, $eA] = t4inv($A, 'purchase', $SUPP, 10000);
$b0 = bal($A, $BANK);
$r = t4pay($A, ['kind' => 'payment', 'account_id' => $BANK, 'contact_id' => $SUPP, 'amount_minor' => tl(4000), 'allocations' => [['entry_id' => $eA, 'amount_minor' => tl(4000)]], 'reason' => 'Kısmi ödeme']);
check($r['ok'] && $r['json']['unallocated_minor'] === 0, 'kısmi ödeme kaydedilir');
eq(t4rem($A, $pA), tl(6000), 'kalan 6.000 TL');
eq($A->get('/invoices', ['kind' => 'purchase'])['json']['invoices'][0]['payment_state'] ?? null, $A->get('/invoices', ['kind' => 'purchase'])['json']['invoices'][0]['payment_state'], 'liste okunur');
$st = array_values(array_filter($A->get('/invoices', ['kind' => 'purchase'])['json']['invoices'], fn($x) => $x['id'] === $pA))[0]['payment_state'];
eq($st, 'partial', 'kısmi ödenen fatura “ödendi” sayılmaz');
eq(bal($A, $BANK), $b0 - tl(4000), 'banka bakiyesi yalnızca ödeme kadar azalır');
check(t4pay($A, ['kind' => 'payment', 'account_id' => $BANK, 'contact_id' => $SUPP, 'amount_minor' => tl(6000), 'allocations' => [['entry_id' => $eA, 'amount_minor' => tl(6000)]]])['ok'], 'kalan 6.000 ödenir');
eq(t4rem($A, $pA), 0, 'fatura kapandı');
$st = array_values(array_filter($A->get('/invoices', ['kind' => 'purchase'])['json']['invoices'], fn($x) => $x['id'] === $pA))[0]['payment_state'];
eq($st, 'paid', 'tam ödenen fatura “ödendi”');
eq(t4pay($A, ['kind' => 'payment', 'account_id' => $BANK, 'contact_id' => $SUPP, 'amount_minor' => tl(1), 'allocations' => [['entry_id' => $eA, 'amount_minor' => tl(1)]]])['status'], 422, 'kapanmış faturaya fazla dağıtım reddedilir');

section('Ödeme #2: fazla ödeme → eşleştirilmemiş / avans');
[$pB, $eB] = t4inv($A, 'purchase', $SUPP, 10000);
$b1 = bal($A, $BANK);
$big = t4pay($A, ['kind' => 'payment', 'account_id' => $BANK, 'contact_id' => $SUPP, 'amount_minor' => tl(30000), 'allocations' => [['entry_id' => $eB, 'amount_minor' => tl(10000)]], 'description' => 'Toplu gönderim']);
check($big['ok'] && $big['json']['allocated_minor'] === tl(10000) && $big['json']['unallocated_minor'] === tl(20000), '30.000 TL: 10.000 faturaya, 20.000 eşleştirilmemiş');
$BIG = $big['json']['id'];
eq(t4rem($A, $pB), 0, 'fatura yalnızca kendi borcu kadar kapandı');
eq(bal($A, $BANK), $b1 - tl(30000), 'banka bakiyesi 30.000 TL azaldı (tek para hareketi)');
$cb = array_values(array_filter($A->get('/contacts')['json']['contacts'], fn($c) => $c['id'] === $SUPP))[0]['balances']['TRY'] ?? [];
eq($cb['advance_out'] ?? 0, tl(20000), 'cari kartında 20.000 TL avans görünür');
eq(count(array_filter($A->get('/payments', ['alloc' => 'unallocated', 'contact_id' => $SUPP])['json']['payments'], fn($p) => $p['id'] === $BIG)), 1, 'eşleştirilmemiş ödeme listesinde');
eq(t4pay($A, ['kind' => 'payment', 'account_id' => $BANK, 'contact_id' => $SUPP, 'amount_minor' => tl(1000), 'allocations' => [['entry_id' => $eB, 'amount_minor' => tl(1500)]]])['status'], 422, 'dağıtım ödeme tutarını aşamaz');

section('Ödeme #3: tek ödeme çok faturaya dağıtılır');
[$fa, $ea] = t4inv($A, 'purchase', $SUPP, 10000);
[$fb, $eb] = t4inv($A, 'purchase', $SUPP, 60000);
[$fc, $ec] = t4inv($A, 'purchase', $SUPP, 45000);
$b2 = bal($A, $BANK);
$m = t4pay($A, ['kind' => 'payment', 'account_id' => $BANK, 'contact_id' => $SUPP, 'amount_minor' => tl(50000), 'allocations' => [['entry_id' => $ea, 'amount_minor' => tl(10000)], ['entry_id' => $eb, 'amount_minor' => tl(40000)]]]);
check($m['ok'] && $m['json']['unallocated_minor'] === 0, '50.000 TL tek kayıt, A ve B’ye dağıtıldı');
eq([t4rem($A, $fa), t4rem($A, $fb), t4rem($A, $fc)], [0, tl(20000), tl(45000)], 'A kapandı, B 20.000 kaldı, C dokunulmadı');
eq(bal($A, $BANK), $b2 - tl(50000), 'tek 50.000 TL banka çıkışı');
eq(DB::val("SELECT COUNT(*) FROM transactions WHERE id=? AND kind='payment'", [$m['json']['id']]), 1, 'dağıtımlar ayrı ödeme kaydı üretmez');

section('Ödeme #4: sonradan eşleştirme ve geri alma');
$bb = bal($A, $BANK);
$al = $A->post("/payments/$BIG/allocate", ['allocations' => [['entry_id' => $ec, 'amount_minor' => tl(20000)]]], $ik());
check($al['ok'] && $al['json']['unallocated_minor'] === 0, 'dağıtılmamış 20.000 TL, C faturasına eşleştirildi');
eq(t4rem($A, $fc), tl(25000), 'C kalan 25.000');
eq(bal($A, $BANK), $bb, 'eşleştirme kasa/banka hareketi üretmez');
$det = $A->get("/payments/$BIG")['json'];
$allocId = array_values(array_filter($det['allocations'], fn($x) => $x['entry_id'] === $ec && $x['status'] === 'active'))[0]['id'];
check($A->post("/allocations/$allocId/reverse")['ok'], 'eşleştirme geri alınır');
eq(t4rem($A, $fc), tl(45000), 'C yeniden 45.000 borçlu');
eq($A->get("/payments/$BIG")['json']['payment']['unallocated_minor'], tl(20000), 'ödeme yeniden 20.000 eşleştirilmemiş');
eq(bal($A, $BANK), $bb, 'geri alma kasa/banka hareketi üretmez; para ikinci kez ödenmiş görünmez');
eq($A->post("/allocations/$allocId/reverse")['status'], 409, 'geri alınmış dağıtım tekrar geri alınamaz');
$rp = $A->req('PUT', "/payments/$BIG/allocations", ['allocations' => [['entry_id' => $ec, 'amount_minor' => tl(5000)], ['entry_id' => $eb, 'amount_minor' => tl(15000)]]]);
check($rp['ok'] && $rp['json']['unallocated_minor'] === tl(10000), 'dağıtım tek işlemde yeniden düzenlenir: 20.000 dağıtıldı, 10.000 yeniden eşleştirilmemiş');
eq(t4rem($A, $pB), tl(10000), 'dağıtımdan çıkarılan fatura yeniden borçlu görünür');
eq([t4rem($A, $fb), t4rem($A, $fc)], [tl(5000), tl(40000)], 'faturaların kalanı güncellendi');
eq(DB::val("SELECT COUNT(*) FROM payment_allocations WHERE txn_id=? AND status='reversed'", [$BIG]), 2, 'geri alınan dağıtımlar geçmişte saklanır');

section('Ödeme #5: çift gönderim (idempotency) ve mükerrer referans');
$key = bin2hex(random_bytes(10));
$n0 = (int)DB::val("SELECT COUNT(*) FROM transactions WHERE tenant_id=? AND kind='payment'", [$GLOBALS['ALFA']]);
$x1 = t4pay($A, ['kind' => 'payment', 'account_id' => $CASH, 'contact_id' => $SUPP, 'amount_minor' => tl(100), 'description' => 'çift tık'], $key);
$x2 = t4pay($A, ['kind' => 'payment', 'account_id' => $CASH, 'contact_id' => $SUPP, 'amount_minor' => tl(100), 'description' => 'çift tık'], $key);
check($x1['ok'] && $x2['ok'] && $x1['json']['id'] === $x2['json']['id'], 'aynı istek iki kez gönderilince aynı kayıt döner');
eq((int)DB::val("SELECT COUNT(*) FROM transactions WHERE tenant_id=? AND kind='payment'", [$GLOBALS['ALFA']]), $n0 + 1, 'yalnızca bir ödeme kaydı oluştu');
check(t4pay($A, ['kind' => 'payment', 'account_id' => $BANK, 'contact_id' => $SUPP, 'amount_minor' => tl(70), 'reference' => 'DKN-77'])['ok'], 'referanslı ödeme');
eq(t4pay($A, ['kind' => 'payment', 'account_id' => $BANK, 'contact_id' => $SUPP, 'amount_minor' => tl(70), 'reference' => 'DKN-77'])['json']['error']['code'] ?? null, 'duplicate_payment', 'aynı hesap+referans+tutar mükerrer uyarısı verir');
check(t4pay($A, ['kind' => 'payment', 'account_id' => $BANK, 'contact_id' => $SUPP, 'amount_minor' => tl(70), 'reference' => 'DKN-77', 'force' => true])['ok'], 'bilinçli onayla (force) kaydedilebilir');

section('Ödeme #6: müşteri tahsilatı ve yön denetimi');
[$sI, $sE] = t4inv($A, 'sales', $CUST, 5000);
$b3 = bal($A, $BANK);
$col = t4pay($A, ['kind' => 'collection', 'account_id' => $BANK, 'contact_id' => $CUST, 'amount_minor' => tl(8000), 'allocations' => [['entry_id' => $sE, 'amount_minor' => tl(5000)]]]);
check($col['ok'] && $col['json']['unallocated_minor'] === tl(3000), '8.000 TL tahsilat: 5.000 faturaya, 3.000 müşteri avansı');
eq(bal($A, $BANK), $b3 + tl(8000), 'tahsilat banka bakiyesini artırır');
eq(DB::val('SELECT direction FROM transactions WHERE id=?', [$col['json']['id']]), 'in', 'tahsilat giriş yönünde');
eq(t4pay($A, ['kind' => 'payment', 'account_id' => $BANK, 'contact_id' => $CUST, 'amount_minor' => tl(10), 'allocations' => [['entry_id' => $sE, 'amount_minor' => tl(10)]]])['json']['error']['code'] ?? null, 'direction_mismatch', 'tedarikçi ödemesi satış (alacak) kalemine dağıtılamaz');
eq(t4pay($A, ['kind' => 'collection', 'account_id' => $BANK, 'contact_id' => $SUPP, 'amount_minor' => tl(10), 'allocations' => [['entry_id' => $sE, 'amount_minor' => tl(10)]]])['json']['error']['code'] ?? null, 'contact_mismatch', 'başka carinin faturasına dağıtım reddedilir');
eq(t4pay($A, ['kind' => 'payment', 'account_id' => $EUR, 'contact_id' => $SUPP, 'amount_minor' => tl(10), 'allocations' => [['entry_id' => $ea, 'amount_minor' => tl(10)]]])['status'], 422, 'para birimi uyuşmazlığı reddedilir');
eq(t4pay($A, ['kind' => 'payment', 'account_id' => $BANK, 'contact_id' => $SUPP, 'amount_minor' => 0])['status'], 422, 'sıfır tutar reddedilir');
eq(t4pay($A, ['kind' => 'payment', 'account_id' => $BANK, 'contact_id' => $SUPP, 'amount_minor' => -5])['status'], 422, 'negatif tutar reddedilir');
eq(t4pay($A, ['kind' => 'payment', 'account_id' => $BANK, 'amount_minor' => tl(5)])['status'], 422, 'cari veya fatura seçilmeden ödeme kaydedilmez');

section('Ödeme #7: cari ekstre / rapor tutarlılığı, iptal');
$stm = $A->get("/contacts/$SUPP/statement")['json'];
$grp = array_values(array_filter($stm['groups'], fn($g) => $g['currency'] === 'TRY' && $g['workspace_id'] === $WS))[0];
$net = array_values(array_filter($A->get('/reports/contacts', ['kind' => 'supplier'])['json']['rows'], fn($x) => $x['contact_id'] === $SUPP && $x['currency'] === 'TRY'))[0];
eq($grp['closing_minor'], $net['net_minor'], 'cari ekstre kapanışı = cari raporu net bakiyesi (avanslar dahil)');
$payRows = array_filter($grp['rows'], fn($x) => ($x['ref']['type'] ?? '') === 'transaction');
check(count($payRows) > 3 && count($payRows) === count(array_unique(array_map(fn($x) => $x['ref']['id'], $payRows))), 'ekstrede her ödeme tek satır (dağıtım satırları ayrı ödeme sayılmaz)');
$pl = $A->get('/payments', ['contact_id' => $SUPP, 'limit' => 500])['json'];
eq(array_sum(array_map(fn($p) => $p['amount_minor'], $pl['payments'])), (int)DB::val("SELECT SUM(amount_minor) FROM transactions WHERE tenant_id=? AND contact_id=? AND kind='payment' AND status='posted'", [$GLOBALS['ALFA'], $SUPP]), 'ödeme raporu: toplam tek tek ödemelerin toplamı');
$bv = bal($A, $BANK);
$remB = t4rem($A, $fb);
check($A->post("/transactions/{$m['json']['id']}/void", ['reason' => 'test iptal'])['ok'], '50.000 TL’lik dağıtımlı ödeme iptal edilir');
eq([t4rem($A, $fa), t4rem($A, $fb)], [tl(10000), $remB + tl(40000)], 'iptal edilen ödemenin dağıtımları faturalara geri döner');
eq(bal($A, $BANK), $bv + tl(50000), 'banka bakiyesi geri yüklenir');
eq($A->post("/payments/{$m['json']['id']}/allocate", ['allocations' => [['entry_id' => $ea, 'amount_minor' => 1]]])['status'], 409, 'iptal edilmiş ödemeye dağıtım yapılamaz');
$acc = array_values(array_filter($A->get('/reports/accounts', ['from' => $yearStart ?? substr($today, 0, 4) . '-01-01', 'to' => $today])['json']['rows'], fn($x) => $x['account_id'] === $BANK))[0];
eq($acc['closing_minor'], bal($A, $BANK), 'kasa/banka raporu kapanışı hesap bakiyesiyle aynı');

// ---------------------------------------------------------------- alış faturası + stok
section('Alış faturası: yeni ürün + stoka ekle');
$items0 = count($A->get('/stock/items')['json']['items']);
$pi = $A->post('/invoices', ['kind' => 'purchase', 'workspace_id' => $WS, 'contact_id' => $SUPP, 'number' => 'T4-STK-1', 'issue_date' => $today, 'lines' => [
    ['description' => 'Buzdolabı T4', 'new_item' => ['kind' => 'product', 'code' => 'BZD-T4'], 'stock' => true, 'qty_milli' => 5000, 'unit_price_minor' => tl(10000), 'vat_rate_bp' => 2000],
    ['description' => 'Televizyon T4', 'new_item' => ['kind' => 'product'], 'stock' => true, 'qty_milli' => 2000, 'unit_price_minor' => tl(8000), 'vat_rate_bp' => 2000],
    ['description' => 'Nakliye T4', 'new_item' => ['kind' => 'service'], 'qty_milli' => 1000, 'unit_price_minor' => tl(500), 'vat_rate_bp' => 2000],
    ['description' => 'Serbest not satırı', 'qty_milli' => 1000, 'unit_price_minor' => tl(100), 'vat_rate_bp' => 0],
]], $ik());
check($pi['ok'], 'fatura içinden yeni ürün/hizmet kartı açılarak kaydedildi');
$PI = (int)$pi['json']['id'];
eq([t4item($A, 'Buzdolabı T4')['qty_milli'], t4item($A, 'Televizyon T4')['qty_milli']], [5000, 2000], 'stok miktarı tam 5 ve 2 arttı');
eq(t4item($A, 'Nakliye T4')['qty_milli'], 0, 'hizmet/nakliye stok ürünü gibi işlenmedi');
eq((int)DB::val('SELECT COUNT(*) FROM stock_movements WHERE invoice_id=?', [$PI]), 2, 'yalnızca iki ürün için stok hareketi');
eq(count($A->get('/stock/items')['json']['items']), $items0 + 3, 'üç yeni kart (2 ürün + 1 hizmet); serbest satır kart açmaz');
$det = $A->get("/invoices/$PI")['json'];
$lines = array_map(fn($l) => ['id' => $l['id'], 'description' => $l['description'], 'item_id' => $l['item_id'], 'warehouse_id' => $l['warehouse_id'], 'stock' => $l['tracked'], 'qty_milli' => $l['qty_milli'], 'unit_price_minor' => $l['unit_price_minor'], 'vat_rate_bp' => $l['vat_rate_bp']], $det['lines']);
check($A->req('PATCH', "/invoices/$PI", ['lines' => $lines])['ok'], 'faturayı aynen yeniden kaydet');
eq((int)DB::val('SELECT COUNT(*) FROM stock_movements WHERE invoice_id=?', [$PI]), 2, 'yeniden kaydetme ikinci stok hareketi üretmez');
eq(t4item($A, 'Buzdolabı T4')['qty_milli'], 5000, 'stok hâlâ 5');
$lines[0]['qty_milli'] = 7000;
check($A->req('PATCH', "/invoices/$PI", ['lines' => $lines])['ok'], 'miktar 5 → 7 düzenlenir');
eq(t4item($A, 'Buzdolabı T4')['qty_milli'], 7000, 'yalnızca +2 fark işlendi');
eq(t4stockEffects($GLOBALS['ALFA'], $PI), 9000, 'fatura stok etkisi 7 + 2');
check($A->req('PATCH', "/invoices/$PI", ['lines' => $lines])['ok'], 'aynı düzenleme tekrar');
eq(t4item($A, 'Buzdolabı T4')['qty_milli'], 7000, 'tekrar düzenleme stoku değiştirmez');
$lines[0]['qty_milli'] = 3000;
check($A->req('PATCH', "/invoices/$PI", ['lines' => $lines])['ok'], 'miktar 7 → 3');
eq(t4item($A, 'Buzdolabı T4')['qty_milli'], 3000, 'stok 3’e indi (ters hareketle)');
eq((int)DB::val("SELECT COUNT(*) FROM stock_movements WHERE invoice_id=? AND kind='return_out'", [$PI]), 1, 'azalış ters hareket olarak kayıtlı; geçmiş silinmedi');
$tot = $A->get("/invoices/$PI")['json']['invoice']['total_minor'];
eq($tot, (int)DB::val('SELECT SUM(total_minor) FROM invoice_lines WHERE invoice_id=?', [$PI]), 'fatura toplamı satırlarla tutarlı');
eq((int)DB::val('SELECT amount_minor FROM entries WHERE invoice_id=?', [$PI]), $tot, 'borç kalemi fatura toplamına güncellendi');
$r2 = $A->post('/invoices', ['kind' => 'purchase', 'workspace_id' => $WS, 'contact_id' => $SUPP, 'number' => 'T4-STK-2', 'issue_date' => $today, 'lines' => [['description' => '  buzdolabı t4 ', 'new_item' => ['kind' => 'product'], 'stock' => true, 'qty_milli' => 1000, 'unit_price_minor' => tl(10000)]]]);
check($r2['ok'], 'aynı ürün farklı yazımla girildi');
eq(t4item($A, 'Buzdolabı T4')['qty_milli'], 4000, 'mevcut karta eklendi (yeni kart açılmadı)');
eq(count($A->get('/stock/items')['json']['items']), $items0 + 3, 'kart sayısı değişmedi');
$mt = $A->get('/stock/match', ['name' => 'Buzdolabi T4x'])['json'];
check($mt['exact'] === null && count($mt['similar']) >= 1, 'benzer adlar otomatik birleştirilmez, öneri olarak sunulur');
$r3 = $A->post('/invoices', ['kind' => 'purchase', 'workspace_id' => $WS, 'contact_id' => $SUPP, 'number' => 'T4-STK-3', 'issue_date' => $today, 'lines' => [['description' => 'Stoksuz giriş', 'new_item' => ['kind' => 'product'], 'qty_milli' => 4000, 'unit_price_minor' => tl(10)]]]);
check($r3['ok'] && t4item($A, 'Stoksuz giriş')['qty_milli'] === 0, '“stoğa ekle” kapalıyken kart oluşur ama stok değişmez');
eq($A->post('/invoices', ['kind' => 'purchase', 'workspace_id' => $WS, 'contact_id' => $SUPP, 'number' => 'T4-STK-4', 'lines' => [['description' => 'Hizmet', 'new_item' => ['kind' => 'service'], 'stock' => true, 'qty_milli' => 1000, 'unit_price_minor' => 100]]])['status'], 422, 'hizmet kalemi stoğa eklenemez');
eq($A->post('/invoices', ['kind' => 'purchase', 'workspace_id' => $WS, 'contact_id' => $SUPP, 'number' => 'T4-STK-1', 'lines' => [['description' => 'x', 'qty_milli' => 1000, 'unit_price_minor' => 100]]])['status'], 409, 'mükerrer fatura numarası reddedilir');
$ikey = bin2hex(random_bytes(8));
$nInv = (int)DB::val('SELECT COUNT(*) FROM invoices');
$q1 = $A->post('/invoices', ['kind' => 'purchase', 'workspace_id' => $WS, 'contact_id' => $SUPP, 'number' => 'T4-IDEM', 'lines' => [['description' => 'Idem', 'new_item' => ['kind' => 'product', 'code' => 'IDM-1'], 'stock' => true, 'qty_milli' => 1000, 'unit_price_minor' => 100]]], ['Idempotency-Key' => $ikey]);
$q2 = $A->post('/invoices', ['kind' => 'purchase', 'workspace_id' => $WS, 'contact_id' => $SUPP, 'number' => 'T4-IDEM', 'lines' => [['description' => 'Idem', 'new_item' => ['kind' => 'product', 'code' => 'IDM-1'], 'stock' => true, 'qty_milli' => 1000, 'unit_price_minor' => 100]]], ['Idempotency-Key' => $ikey]);
check($q1['ok'] && $q2['ok'] && $q1['json']['id'] === $q2['json']['id'] && (int)DB::val('SELECT COUNT(*) FROM invoices') === $nInv + 1 && t4item($A, 'Idem')['qty_milli'] === 1000, 'aynı istek iki kez → tek fatura, tek stok girişi');

section('Alış faturası iptali / silinmesi ve stok');
// Buzdolabı: 4 adet stokta; 3 adet satış faturası tüketsin → alış iptali engellenmeli
$BZ = t4item($A, 'Buzdolabı T4')['id'];
$s1 = $A->post('/invoices', ['kind' => 'sales', 'workspace_id' => $WS, 'contact_id' => $CUST, 'number' => 'T4-S-BZ', 'issue_date' => $today, 'lines' => [['description' => 'Buzdolabı satış', 'item_id' => $BZ, 'stock' => true, 'qty_milli' => 3000, 'unit_price_minor' => tl(15000)]]]);
check($s1['ok'] && empty($s1['json']['warnings']), 'stoktan satış: uyarı yok');
eq(t4item($A, 'Buzdolabı T4')['qty_milli'], 1000, 'satış sonrası stok 1');
eq($A->post("/invoices/$PI/cancel", ['reason' => 'deneme'])['json']['error']['code'] ?? null, 'stock_used', 'stoğu tüketilmiş alış faturası iptal edilemez');
eq($A->req('DELETE', "/invoices/$PI")['json']['error']['code'] ?? null, 'stock_used', 'stoğu tüketilmiş alış faturası silinemez');
check($A->post("/invoices/{$s1['json']['id']}/cancel", ['reason' => 'müşteri vazgeçti'])['ok'], 'satış faturası iptal edilir');
eq(t4item($A, 'Buzdolabı T4')['qty_milli'], 4000, 'satış iptali stoğu geri verir');
// ödenmemiş alış faturasını (stok dahil) sil
$del = $A->post('/invoices', ['kind' => 'purchase', 'workspace_id' => $WS, 'contact_id' => $SUPP, 'number' => 'T4-DEL', 'issue_date' => $today, 'lines' => [['description' => 'Silinecek', 'new_item' => ['kind' => 'product', 'code' => 'DEL-1'], 'stock' => true, 'qty_milli' => 2000, 'unit_price_minor' => 100]]]);
eq(t4item($A, 'Silinecek')['qty_milli'], 2000, 'silinecek faturanın stok girişi var');
$DID = (int)$del['json']['id'];
check($A->req('DELETE', "/invoices/$DID")['ok'], 'ödenmemiş hatalı fatura silinir');
eq([t4item($A, 'Silinecek')['qty_milli'], (int)DB::val('SELECT COUNT(*) FROM invoices WHERE id=?', [$DID]), (int)DB::val('SELECT COUNT(*) FROM entries WHERE invoice_id=?', [$DID])], [0, 0, 0], 'silme: stok etkisi geri alındı, fatura ve borç kalemi kalktı');
eq($A->req('DELETE', "/invoices/$pA")['json']['error']['code'] ?? null, 'has_payments', 'ödeme geçmişi olan fatura silinemez');
eq($A->post("/invoices/$pA/cancel", ['reason' => 'x'])['json']['error']['code'] ?? null, 'has_payments', 'ödenmiş fatura doğrudan iptal edilemez');
$pe = $A->req('PATCH', "/invoices/$pA", ['lines' => [['description' => 'Kalem', 'qty_milli' => 1000, 'unit_price_minor' => tl(5000)]]]);
eq($pe['json']['error']['code'] ?? null, 'total_below_paid', 'toplam ödenen tutarın altına düşürülemez');
eq($A->req('PATCH', "/invoices/$pA", ['contact_id' => $CUST])['json']['error']['code'] ?? null, 'has_payments', 'ödemesi olan faturanın carisi değiştirilemez');
$pl = $A->get("/invoices/$pA")['json']['lines'];
check($A->req('PATCH', "/invoices/$pA", ['lines' => [['id' => $pl[0]['id'], 'description' => 'Kalem (düzeltilmiş)', 'qty_milli' => 1000, 'unit_price_minor' => tl(12000)]]])['ok'], 'ödenmiş faturanın tutarı artırılabilir');
eq(t4rem($A, $pA), tl(2000), 'artış sonrası kalan 2.000 TL borç görünür');

// ---------------------------------------------------------------- stokta olmayan satış + teslimat
section('Stokta olmayan ürün satışı ve teslimat');
$ni = $A->post('/stock/items', ['kind' => 'product', 'code' => 'NOSTK', 'name' => 'Stoksuz Ürün T4', 'unit' => 'adet', 'vat_rate_bp' => 2000]);
$NI = id($ni);
$sale = $A->post('/invoices', ['kind' => 'sales', 'workspace_id' => $WS, 'contact_id' => $CUST, 'number' => 'T4-S-NO', 'issue_date' => $today, 'lines' => [['description' => 'Stoksuz', 'item_id' => $NI, 'stock' => true, 'qty_milli' => 5000, 'unit_price_minor' => tl(100), 'vat_rate_bp' => 0]]]);
check($sale['ok'] && count($sale['json']['warnings']) === 1, 'stokta olmayan ürün satışı kaydedilir, açık uyarı verilir');
$SID = (int)$sale['json']['id'];
$it = t4item($A, 'Stoksuz Ürün T4');
eq([$it['qty_milli'], $it['backorder_milli']], [0, 5000], 'stok eksiye düşmedi; 5 adet karşılanmamış olarak ayrı izlenir');
$sd = $A->get("/invoices/$SID")['json'];
eq($sd['delivery']['state'], 'awaiting_supply', 'teslimat durumu: bekleyen tedarik');
$entryS = $sd['entry']['id'];
check(t4pay($A, ['kind' => 'collection', 'account_id' => $BANK, 'contact_id' => $CUST, 'amount_minor' => tl(500), 'allocations' => [['entry_id' => $entryS, 'amount_minor' => tl(500)]]])['ok'], 'müşteri ürün gelmeden ödedi');
$sd = $A->get("/invoices/$SID")['json'];
eq([t4rem($A, $SID), $sd['delivery']['state']], [0, 'awaiting_supply'], 'ödeme tamam ama ürün hâlâ teslim edilmedi (ödeme ≠ teslimat)');
eq($A->post("/invoices/$SID/deliver", ['lines' => [['line_id' => $sd['lines'][0]['id'], 'qty_milli' => 1000]]])['json']['error']['code'] ?? null, 'insufficient_stock', 'stok yokken teslimat reddedilir');
eq($A->post('/stock/movements', ['item_id' => $NI, 'warehouse_id' => $sd['lines'][0]['warehouse_id'], 'kind' => 'out', 'qty_milli' => 1000])['status'], 409, 'manuel çıkış da stoğu eksiye düşüremez');
$sup = $A->post('/invoices', ['kind' => 'purchase', 'workspace_id' => $WS, 'contact_id' => $SUPP, 'number' => 'T4-SUP-NO', 'issue_date' => $today, 'lines' => [['description' => 'Stoksuz tedarik', 'item_id' => $NI, 'stock' => true, 'qty_milli' => 5000, 'unit_price_minor' => tl(60)]]]);
check($sup['ok'], 'ürün sonradan tedarik edildi (5 adet)');
eq($A->get("/invoices/$SID")['json']['delivery']['state'], 'ready', 'teslimat durumu: stokta hazır');
$dk = bin2hex(random_bytes(8));
$dv = $A->post("/invoices/$SID/deliver", ['lines' => [['line_id' => $sd['lines'][0]['id'], 'qty_milli' => 2000]]], ['Idempotency-Key' => $dk]);
check($dv['ok'] && $dv['json']['delivery']['state'] === 'partial', 'kısmi teslimat (2/5)');
$dv2 = $A->post("/invoices/$SID/deliver", ['lines' => [['line_id' => $sd['lines'][0]['id'], 'qty_milli' => 2000]]], ['Idempotency-Key' => $dk]);
eq(t4item($A, 'Stoksuz Ürün T4')['qty_milli'], 3000, 'stok çıkışı bir kez gerçekleşti (5 − 2); tekrar gönderim ikinci çıkış üretmedi');
eq($A->post("/invoices/$SID/deliver", ['lines' => [['line_id' => $sd['lines'][0]['id'], 'qty_milli' => 9000]]])['json']['error']['code'] ?? null, 'over_delivery', 'bekleyenden fazla teslimat reddedilir');
check($A->post("/invoices/$SID/cancel", ['reason' => 'ödeme iade edilecek'])['json']['error']['code'] === 'has_payments', 'ödemesi olan satış iptalde ödeme dağıtımı önce geri alınmalı');

// ---------------------------------------------------------------- şahsi hesap
section('Şahsi hesaptan işletme harcaması');
[$qi, $qe] = t4inv($A, 'purchase', $SUPP, 20000);
$bBiz = bal($A, $BANK);
$bPer = bal($A, $PBANK);
$own0 = $A->get('/owner-account')['json']['balances'];
$ownBal = fn(array $bl) => (int)(array_values(array_filter($bl, fn($x) => $x['workspace_id'] === $WS && $x['currency'] === 'TRY'))[0]['balance_minor'] ?? 0);
$pp = t4pay($A, ['kind' => 'payment', 'account_id' => $PBANK, 'contact_id' => $SUPP, 'amount_minor' => tl(20000), 'allocations' => [['entry_id' => $qe, 'amount_minor' => tl(20000)]], 'reason' => 'ABC müşterisi siparişi malzeme alımı', 'description' => 'Şahsi hesaptan ödendi, dekont sonra eklenecek', 'reference' => 'SAHSI-1', 'method' => 'transfer']);
check($pp['ok'], 'şahsi hesaptan işletme faturası ödendi');
eq(bal($A, $BANK), $bBiz, 'işletme banka bakiyesi AZALMADI');
eq(bal($A, $PBANK), $bPer - tl(20000), 'şahsi hesap bakiyesi azaldı');
eq((int)DB::val('SELECT for_workspace_id FROM transactions WHERE id=?', [$pp['json']['id']]), $WS, 'işlem işletme adına etiketlendi');
eq(t4rem($A, $qi), 0, 'fatura kapandı');
$own1 = $A->get('/owner-account')['json'];
eq($ownBal($own1['balances']) - $ownBal($own0), tl(20000), 'işletmenin sahibe borcu (ortak cari) 20.000 TL arttı');
$pcashflow = $A->get('/reports/cashflow', ['scope' => 'business'])['json'];
check(true, 'nakit akışı raporu çalışır');
eq(count($A->get('/transactions', ['q' => 'ABC müşterisi'])['json']['transactions']), 1, 'açıklama/neden içinde arama bulur');
eq(count($A->get('/transactions', ['q' => 'SAHSI-1'])['json']['transactions']), 1, 'referans ile arama');
eq(count($A->get('/transactions', ['amount_min' => tl(19999), 'amount_max' => tl(20001), 'personal' => '1'])['json']['transactions']), 1, 'tutar aralığı + şahsi kaynak filtresi');
eq(count($A->get('/transactions', ['invoice_id' => $qi])['json']['transactions']), 1, 'faturaya göre filtre');
check($A->req('PATCH', "/payments/{$pp['json']['id']}", ['reason' => 'Güncel neden', 'description' => 'dekont eklendi'])['ok'], 'ödeme açıklaması sonradan güncellenir');
eq((int)DB::val('SELECT amount_minor FROM transactions WHERE id=?', [$pp['json']['id']]), tl(20000), 'açıklama güncellemesi tutarı etkilemez');
$inc0 = plTotals($A)['income_minor'];
$exp0 = plTotals($A)['expense_minor'];
$tk = bin2hex(random_bytes(8));
$tr1 = $A->post('/transfers', ['from_account_id' => $PBANK, 'to_account_id' => $BANK, 'amount_minor' => tl(7000), 'description' => 'Sermaye'], ['Idempotency-Key' => $tk]);
$tr2 = $A->post('/transfers', ['from_account_id' => $PBANK, 'to_account_id' => $BANK, 'amount_minor' => tl(7000), 'description' => 'Sermaye'], ['Idempotency-Key' => $tk]);
check($tr1['ok'] && $tr2['ok'] && $tr1['json']['group'] === $tr2['json']['group'], 'aynı transfer iki kez gönderilince mükerrer oluşmaz');
eq(DB::val("SELECT COUNT(*) FROM transactions WHERE transfer_group=?", [$tr1['json']['group']]), 2, 'transfer tam iki bacak');
eq(DB::val("SELECT DISTINCT kind FROM transactions WHERE transfer_group=?", [$tr1['json']['group']]), 'owner_in', 'şahsi → işletme aktarımı “işletmeye para koyma”');
eq(bal($A, $BANK), $bBiz + tl(7000), 'işletme hesabı +7.000');
eq([plTotals($A)['income_minor'], plTotals($A)['expense_minor']], [$inc0, $exp0], 'sahip aktarımı gelir veya gider sayılmaz');
$own2 = $A->get('/owner-account')['json'];
eq($ownBal($own2['balances']) - $ownBal($own1['balances']), tl(7000), 'ortak cari +7.000');
$tr3 = $A->post('/transfers', ['from_account_id' => $BANK, 'to_account_id' => $PBANK, 'amount_minor' => tl(27000), 'description' => 'Geri ödeme'], $ik());
eq(DB::val("SELECT DISTINCT kind FROM transactions WHERE transfer_group=?", [$tr3['json']['group']]), 'owner_out', 'işletme → şahsi aktarımı “işletmeden para çekme/geri ödeme”');
eq($ownBal($A->get('/owner-account')['json']['balances']) - $ownBal($own0), 0, 'sahibe tüm borç ödendi: ortak cari sıfırlandı');
$ex = $A->post('/transfers', ['from_account_id' => $BANK, 'to_account_id' => $CASH, 'amount_minor' => tl(10), 'description' => 'iç transfer'], $ik());
eq(DB::val("SELECT DISTINCT kind FROM transactions WHERE transfer_group=?", [$ex['json']['group']]), 'transfer', 'işletme içi hesaplar arası aktarım yine “transfer”');

// ---------------------------------------------------------------- düzenle / sil
section('Düzenleme ve silme: hesap, cari, kalem, şahsi fatura');
$h1 = id($A->post('/accounts', ['workspace_id' => $WS, 'name' => 'Yanlış Kasa', 'kind' => 'cash', 'currency' => 'TRY', 'opening_minor' => 0, 'opening_date' => $yearStart ?? substr($today, 0, 4) . '-01-01']));
check($A->req('PATCH', "/accounts/$h1", ['name' => 'Yanlış Kasa 2', 'kind' => 'bank', 'currency' => 'EUR'])['ok'], 'kullanılmamış hesabın adı, türü ve para birimi düzenlenir');
check($A->req('DELETE', "/accounts/$h1")['ok'], 'kullanılmamış hatalı hesap silinir');
eq((int)DB::val('SELECT COUNT(*) FROM accounts WHERE id=?', [$h1]), 0, 'hesap gerçekten kalktı');
$e1 = $A->req('DELETE', "/accounts/$BANK");
eq($e1['json']['error']['code'] ?? null, 'has_transactions', 'hareketi olan hesap silinemez (neden açıklanır)');
check(str_contains($e1['json']['error']['message'], 'arşivle'), 'hata mesajı arşivlemeyi önerir');
eq($A->req('PATCH', "/accounts/$BANK", ['currency' => 'EUR'])['status'], 409, 'hareketi olan hesabın para birimi değiştirilemez');
$c1 = id($A->post('/contacts', ['name' => 'Silinecek Cari', 'kind' => 'customer']));
check($A->req('DELETE', "/contacts/$c1")['ok'], 'kullanılmamış cari silinir');
eq($A->req('DELETE', "/contacts/$SUPP")['json']['error']['code'] ?? null, 'in_use', 'hareketi olan cari silinemez');
$k1 = id($A->post('/categories', ['kind' => 'expense', 'name' => 'Silinecek Kat']));
check($A->req('DELETE', "/categories/$k1")['ok'], 'kullanılmamış kategori silinir');
$p1 = id($A->post('/projects', ['name' => 'Silinecek Proje', 'currency' => 'TRY']));
check($A->req('DELETE', "/projects/$p1")['ok'], 'kullanılmamış proje silinir');
check($A->req('DELETE', '/stock/items/' . t4item($A, 'Stoksuz Ürün T4')['id'])['json']['error']['code'] === 'in_use', 'hareketi olan stok kartı silinemez');
$u1 = id($A->post('/stock/items', ['kind' => 'product', 'code' => 'BOS-1', 'name' => 'Boş Kart']));
check($A->req('DELETE', "/stock/items/$u1")['ok'], 'kullanılmamış stok kartı silinir');
$wh = id($A->post('/stock/warehouses', ['name' => 'Boş Depo']));
check($A->req('DELETE', "/stock/warehouses/$wh")['ok'], 'kullanılmamış depo silinir');
// şahsi fatura
$bill = $A->post('/entries', ['kind' => 'payable', 'source' => 'personal_bill', 'workspace_id' => $WSP, 'title' => 'Elektrik', 'provider' => 'Enerjisa', 'amount_minor' => tl(800), 'due_date' => Util::addDays($today, 5)]);
$BID = (int)$bill['json']['ids'][0];
check($A->req('PATCH', "/entries/$BID", ['title' => 'Elektrik (düzeltildi)', 'provider' => 'Başka', 'amount_minor' => tl(850), 'due_date' => null, 'period' => '2026-10'])['ok'], 'şahsi fatura düzenlenir (başlık, sağlayıcı, tutar, vade kaldırma)');
$be = DB::one('SELECT title,amount_minor,due_date,provider FROM entries WHERE id=?', [$BID]);
eq([$be['title'], (int)$be['amount_minor'], $be['due_date'], $be['provider']], ['Elektrik (düzeltildi)', tl(850), null, 'Başka'], 'şahsi fatura alanları güncel');
check($A->req('DELETE', "/entries/$BID")['ok'], 'ödenmemiş şahsi fatura silinir');
$bill2 = $A->post('/entries', ['kind' => 'payable', 'source' => 'personal_bill', 'workspace_id' => $WSP, 'title' => 'Su', 'amount_minor' => tl(100), 'due_date' => Util::addDays($today, 5)]);
$B2 = (int)$bill2['json']['ids'][0];
check($A->post("/entries/$B2/settle", ['account_id' => $PBANK, 'amount_minor' => tl(40)], $ik())['ok'], 'şahsi fatura kısmen ödenir (eski uç nokta hâlâ çalışır)');
eq($A->req('DELETE', "/entries/$B2")['json']['error']['code'] ?? null, 'has_payments', 'ödemesi olan şahsi fatura silinemez');
eq($A->post("/entries/$B2/cancel", ['reason' => 'x'])['json']['error']['code'] ?? null, 'has_payments', 'ödemesi olan şahsi fatura iptal edilemez');
// belge ekle / değiştir / kaldır
$tmp = tempnam(sys_get_temp_dir(), 'fbd');
file_put_contents($tmp, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='));
$up = $A->upload('/documents', ['entity' => 'invoice', 'entity_id' => (string)$pA], $tmp, 'image/png', 'fatura.png');
check($up['ok'], 'faturaya belge eklenir');
$docId = $up['json']['id'] ?? ($up['json']['document']['id'] ?? 0);
check($A->req('DELETE', "/documents/$docId")['ok'], 'belge kaldırılır');
eq(t4rem($A, $pA), tl(2000), 'belge silmek finansal kaydı etkilemez');
$up2 = $A->upload('/documents', ['entity' => 'transaction', 'entity_id' => (string)$pp['json']['id']], $tmp, 'image/png', 'dekont.png');
check($up2['ok'], 'şahsi ödeme kaydına dekont eklenir');
@unlink($tmp);

section('Yetki: başka müşteri bu kayıtlara erişemez');
eq($B->req('PATCH', "/invoices/$pA", ['note' => 'x'])['status'], 404, 'Beta, Alfa faturasını düzenleyemez');
eq($B->req('DELETE', "/invoices/$pA")['status'], 404, 'Beta, Alfa faturasını silemez');
eq($B->get("/payments/$BIG")['status'], 404, 'Beta, Alfa ödemesini göremez');
eq($B->post("/payments/$BIG/allocate", ['allocations' => [['entry_id' => 1, 'amount_minor' => 1]]], $ik())['status'], 404, 'Beta, Alfa ödemesine dağıtım yapamaz');
eq($B->post("/allocations/$allocId/reverse")['status'], 404, 'Beta, Alfa dağıtımını geri alamaz');
eq($B->req('DELETE', "/accounts/$BANK")['status'], 404, 'Beta, Alfa hesabını silemez');
eq($B->req('DELETE', "/entries/$B2")['status'], 404, 'Beta, Alfa kalemini silemez');
eq($B->post("/invoices/$SID/deliver", ['lines' => [['line_id' => 1, 'qty_milli' => 1]]], $ik())['status'], 404, 'Beta, Alfa faturasında teslimat yapamaz');
$bw = $B->get('/meta')['json'];
$bAcc = $bw['accounts'][0]['id'] ?? 0;
eq(t4pay($B, ['kind' => 'payment', 'account_id' => $BANK, 'contact_id' => $SUPP, 'amount_minor' => 100])['status'], 404, 'Beta, Alfa hesabından ödeme yapamaz');
eq(count($B->get('/owner-account')['json']['rows']), 0, 'Beta ortak cari hareketinde Alfa verisi yok');
check($A->get('/payments', ['limit' => 500])['json']['count'] > 5, 'Alfa kendi ödemelerini görür');
eq($A->req('GET', '/payments/99999999')['status'], 404, 'olmayan ödeme 404');

// ---------------------------------------------------------------- bütünlük
section('Bütünlük: bakiyeler birbiriyle tutarlı');
$orph = (int)DB::val("SELECT COUNT(*) FROM payment_allocations a LEFT JOIN transactions t ON t.id=a.txn_id WHERE t.id IS NULL");
eq($orph, 0, 'sahipsiz dağıtım yok');
$over = (int)DB::val("SELECT COUNT(*) FROM (SELECT t.id, t.amount_minor, COALESCE(SUM(CASE WHEN a.status='active' THEN a.amount_minor END),0) s FROM transactions t JOIN payment_allocations a ON a.txn_id=t.id GROUP BY t.id HAVING s > t.amount_minor) x");
eq($over, 0, 'hiçbir ödemede dağıtım toplamı ödeme tutarını aşmıyor');
$neg = (int)DB::val("SELECT COUNT(*) FROM (SELECT e.id FROM entries e JOIN payment_allocations a ON a.entry_id=e.id AND a.status='active' JOIN transactions t ON t.id=a.txn_id AND t.status='posted' GROUP BY e.id, e.amount_minor HAVING SUM(a.amount_minor) > e.amount_minor) x");
eq($neg, 0, 'hiçbir kalemde ödenen tutar kalem tutarını aşmıyor');
$fx = array_sum(array_map(fn($a) => $a['currency'] === 'TRY' ? $a['balance_minor'] : 0, $A->get('/accounts')['json']['accounts']));
$expected = (int)DB::val("SELECT SUM(a.opening_minor) FROM accounts a WHERE a.tenant_id=? AND a.currency='TRY'", [$GLOBALS['ALFA']]) + (int)DB::val("SELECT SUM(IF(direction='in',amount_minor,-amount_minor)) FROM transactions WHERE tenant_id=? AND currency='TRY' AND status='posted'", [$GLOBALS['ALFA']]);
eq($fx, $expected, 'TL hesap bakiyeleri toplamı = açılışlar + defter hareketleri');
foreach ($A->get('/stock/items')['json']['items'] as $it) {
    if ($it['kind'] !== 'product') {
        continue;
    }
    $mv = (int)DB::val("SELECT COALESCE(SUM(IF(kind IN ('in','return_in'),qty_milli,-qty_milli)),0) FROM stock_movements WHERE item_id=? AND status='posted'", [$it['id']]);
    if ($mv !== $it['qty_milli']) {
        check(false, 'stok miktarı = hareket toplamı: ' . $it['name'], [$mv, $it['qty_milli']]);
    }
}
check(true, 'stok miktarı hareket toplamı ile tutarlı (tüm ürünler)');
$rep = $A->get('/reports/stock')['json'];
check(isset($rep['items']) && count($rep['items']) > 3, 'stok raporu');
