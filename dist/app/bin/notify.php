<?php
// E-posta bildirimleri (isteğe bağlı). cPanel Cron: 0 8 * * * /usr/local/bin/php /home/KULLANICI/fbmuh/app/bin/notify.php
// Yalnızca ayarlarda "e-posta bildirimi" açık olan müşterilere, tutar içermeyen kısa bir özet gönderir.
require __DIR__ . '/_boot.php';
use FB\DB;
use FB\Mailer;
use FB\ReportCtl;
$sent = 0;
foreach (DB::all("SELECT t.id, t.name, t.settings, u.email FROM tenants t JOIN users u ON u.tenant_id=t.id WHERE t.status='active' AND u.status='active'") as $t) {
    $s = json_decode((string)$t['settings'], true) ?: [];
    if (empty($s['notify']['email'])) {
        continue;
    }
    $n = ReportCtl::notifications((int)$t['id']);
    $important = array_filter($n, fn($x) => in_array($x['level'], ['danger', 'warn'], true));
    if (!$important) {
        continue;
    }
    $lines = array_map(fn($x) => '• ' . $x['title'], array_slice($important, 0, 15));
    Mailer::send($t['email'], 'FunBreak Ön Muhasebe — bugünkü hatırlatmalar', "Merhaba,\n\n" . implode("\n", $lines) . "\n\nAyrıntılar için giriş yapın: " . FB\Cfg::get('base_url') . "\n");
    $sent++;
}
FB\RateLimit::gc();
echo "Gönderilen: $sent\n";
