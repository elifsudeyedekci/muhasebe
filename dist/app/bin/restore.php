<?php
// Kullanım: php bin/restore.php <yedek.sql.gz> [belgeler.zip] --yes
// DİKKAT: yapılandırmadaki veritabanının tabloları yedekle DEĞİŞTİRİLİR. Önce mevcut durumun yedeğini alın.
require __DIR__ . '/_boot.php';
$args = array_values(array_filter(array_slice($argv, 1), fn($a) => !str_starts_with($a, '--')));
if (!in_array('--yes', $argv, true) || !$args) {
    fwrite(STDERR, "Kullanım: php bin/restore.php <yedek.sql.gz> [belgeler.zip] --yes\n");
    exit(1);
}
$r = FB\Backup::restore($args[0], FB\DB::pdo(), $args[1] ?? null, FB_APP_DIR . '/storage/uploads');
echo "Geri yüklendi: {$r['statements']} ifade, {$r['files']} belge dosyası.\n";
