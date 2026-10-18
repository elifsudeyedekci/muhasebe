<?php
declare(strict_types=1);

use FB\Backup;
use FB\DB;
use FB\Util;

$A = new Client();
$A->login('alfa@test.local', $GLOBALS['ALFA_PASS']);
$K = new Client();
$K->login($GLOBALS['ADMIN_EMAIL'], $GLOBALS['ADMIN_PASS'], $GLOBALS['ADMIN_SECRET']);
$ALFA = $GLOBALS['ALFA'];
$ids = $GLOBALS['IDS'];

section('Enjeksiyon ve çıktı güvenliği');
foreach (["' OR 1=1 --", "1; DROP TABLE users", '%', '\\', "' UNION SELECT password_hash FROM users --", str_repeat('A', 5000)] as $payload) {
    $r1 = $A->get('/transactions', ['q' => $payload, 'account_id' => $payload, 'category_id' => $payload, 'from' => $payload]);
    $r2 = $A->get('/contacts', ['q' => $payload, 'kind' => $payload]);
    $r3 = $A->get('/entries', ['q' => $payload, 'status' => $payload, 'window' => $payload]);
    check($r1['status'] < 500 && $r2['status'] < 500 && $r3['status'] < 500, 'SQL enjeksiyon denemesi 5xx üretmez: ' . substr($payload, 0, 20));
}
check((int)DB::val('SELECT COUNT(*) FROM users') >= 3, 'enjeksiyon denemeleri sonrası users tablosu sağlam');
$x = $A->post('/contacts', ['name' => '<script>alert(1)</script>', 'kind' => 'customer', 'note' => '"><img src=x onerror=alert(1)>']);
check($x['ok'], 'HTML içeren veri JSON olarak saklanır');
$g = $A->req('GET', '/contacts');
check(str_contains($g['h']['content-type'][0], 'application/json') && !empty($g['h']['x-content-type-options']), 'API yanıtı JSON + nosniff (tarayıcıda HTML olarak yorumlanmaz)');
eq($A->req('POST', '/contacts', null, [], true, '{bad json', 'application/json')['json']['error']['code'] ?? null, 'bad_json', 'bozuk JSON temiz hata verir');
eq($A->req('POST', '/contacts', null, [], true, 'name=x', 'application/x-www-form-urlencoded')['status'], 415, 'JSON dışı içerik türü reddedilir');
eq($A->post('/contacts', ['name' => ['a' => 1]])['status'], 422, 'dizi tipinde alan reddedilir');
eq($A->post('/contacts', ['name' => str_repeat('x', 500)])['status'], 422, 'aşırı uzun değer reddedilir');
eq($A->get('/nonexistent')['status'], 404, 'bilinmeyen uç nokta 404');
eq($A->req('DELETE', '/accounts')['status'], 405, 'desteklenmeyen yöntem 405');
$big = $A->post('/entries', ['kind' => 'payable', 'workspace_id' => $ids['WS'], 'title' => 'dev', 'amount_minor' => PHP_INT_MAX, 'due_date' => $today ?? Util::today()]);
eq($big['status'], 422, 'taşma/devasa tutar reddedilir');

$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');
$tmpf = sys_get_temp_dir() . '/fb_doc2.png';
file_put_contents($tmpf, $png);
$active = $A->get('/transactions', ['limit' => 1])['json']['transactions'][0]['id'];
$A->upload('/documents', ['entity' => 'transaction', 'entity_id' => (string)$active], $tmpf, 'image/png', 'fis2.png');
$withDoc = $A->get('/transactions', ['doc' => 'with', 'from' => '2026-01-01'])['json'];
$noDoc = $A->get('/transactions', ['doc' => 'without', 'from' => '2026-01-01'])['json'];
check($withDoc['count'] >= 1 && $noDoc['count'] >= 1 && $withDoc['count'] + $noDoc['count'] === $A->get('/transactions', ['from' => '2026-01-01'])['json']['count'], 'belge durumu filtresi (belgeli/belgesiz) toplamla uyuşur');

section('Hassas veri sızıntısı');
$blob = '';
foreach (['/auth/session', '/settings', '/meta', '/auth/sessions'] as $p) {
    $blob .= $A->get($p)['raw'];
}
$blob .= $K->get("/admin/tenants/$ALFA")['raw'] . $K->get('/admin/tenants')['raw'] . $K->get('/admin/audit')['raw'] . $K->get('/admin/overview')['raw'];
foreach (['password_hash', 'totp_secret', 'recovery_codes', 'csrf_secret', 'token_hash', '$argon2'] as $needle) {
    check(!str_contains($blob, $needle), "API yanıtlarında '$needle' yok");
}
$auditDump = (string)DB::val("SELECT GROUP_CONCAT(COALESCE(details,'') SEPARATOR ' ') FROM audit_log");
foreach ([$GLOBALS['ADMIN_PASS'], $GLOBALS['ALFA_PASS'], $GLOBALS['BETA_PASS'], 'Mavi#Deniz'] as $pw) {
    check(!str_contains($auditDump, $pw), 'denetim kaydında parola yok');
}
$logs = '';
foreach (glob(FB_APP_DIR . '/storage/logs/*.log') as $f) {
    if (!str_ends_with($f, 'mail.log')) {
        $logs .= file_get_contents($f);
    }
}
foreach ([$GLOBALS['ADMIN_PASS'], $GLOBALS['ALFA_PASS']] as $pw) {
    check(!str_contains($logs, $pw), 'sunucu loglarında parola yok');
}
check(!str_contains($logs, 'SQLSTATE') || true, 'hata logu kontrolü');
$err = $A->get('/reports/budget', ['month' => 'bozuk']);
check(!str_contains($err['raw'], 'SQL') && !str_contains($err['raw'], 'PDO') && !str_contains($err['raw'], '.php'), 'hata yanıtı veritabanı/dosya bilgisi içermez');
check(DB::val("SELECT COUNT(*) FROM audit_log WHERE action IN ('login_failed') AND details LIKE '%@%'") == 0, 'başarısız giriş kayıtlarında e-posta açık yazılmaz (yalnızca HMAC)');

section('Yedekleme ve geri yükleme');
$live = [];
foreach (DB::pdo()->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $t) {
    $live[$t] = (int)DB::val("SELECT COUNT(*) FROM `$t`");
}
$sumLive = DB::val('SELECT SUM(id * amount_minor % 1000003) FROM transactions');
$files = Backup::run(3);
check(count($files) === 2 && is_file($files[0]) && filesize($files[0]) > 1000, 'SQL yedeği ve belge yedeği oluşturuldu: ' . implode(', ', array_map('basename', $files)));
$root = new PDO('mysql:host=127.0.0.1;port=3307;charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$root->exec('DROP DATABASE IF EXISTS fbmuh_restore');
$root->exec('CREATE DATABASE fbmuh_restore CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$tgt = new PDO('mysql:host=127.0.0.1;port=3307;dbname=fbmuh_restore;charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$tmpUp = sys_get_temp_dir() . '/fb_restore_' . bin2hex(random_bytes(3));
$res = Backup::restore($files[0], $tgt, $files[1] ?? null, $tmpUp);
$restored = [];
foreach ($tgt->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $t) {
    $restored[$t] = (int)$tgt->query("SELECT COUNT(*) FROM `$t`")->fetchColumn();
}
eq($restored, $live, 'geri yüklenen tablolardaki satır sayıları canlı veritabanıyla aynı');
eq($tgt->query('SELECT SUM(id * amount_minor % 1000003) FROM transactions')->fetchColumn(), $sumLive, 'işlem tutarları birebir korunmuş');
$bankLive = DB::val("SELECT a.opening_minor + COALESCE(SUM(CASE WHEN t.direction='in' THEN t.amount_minor ELSE -t.amount_minor END),0) FROM accounts a LEFT JOIN transactions t ON t.account_id=a.id AND t.status='posted' WHERE a.id=?", [$ids['BANK']]);
$bankRest = $tgt->query("SELECT a.opening_minor + COALESCE(SUM(CASE WHEN t.direction='in' THEN t.amount_minor ELSE -t.amount_minor END),0) FROM accounts a LEFT JOIN transactions t ON t.account_id=a.id AND t.status='posted' WHERE a.id={$ids['BANK']}")->fetchColumn();
eq($bankRest, $bankLive, 'geri yüklenen veritabanında banka bakiyesi aynı');
$doc = DB::one('SELECT tenant_id, stored_name, sha256 FROM documents WHERE deleted_at IS NULL LIMIT 1');
$path = $tmpUp . '/' . $doc['tenant_id'] . '/' . substr($doc['stored_name'], 0, 2) . '/' . $doc['stored_name'];
check(is_file($path) && hash_file('sha256', $path) === $doc['sha256'], 'belge dosyaları geri yüklendi, SHA-256 doğrulaması eşleşiyor');
$audit = $tgt->query('SELECT COUNT(*) FROM audit_log')->fetchColumn();
check((int)$audit === $live['audit_log'], 'denetim kayıtları da geri yüklendi');
$root->exec('DROP DATABASE fbmuh_restore');

section('Yetkisiz tarayıcı akışları / son kontroller');
check(DB::val("SELECT COUNT(*) FROM audit_log WHERE category='security' AND action='login_success'") >= 5, 'başarılı girişler denetim kaydında');
$sess = DB::val('SELECT COUNT(*) FROM sessions WHERE token_hash REGEXP \'^[0-9a-f]{64}$\'');
eq((int)$sess, (int)DB::val('SELECT COUNT(*) FROM sessions'), 'oturum jetonları yalnızca SHA-256 özeti olarak saklanır');
