<?php
// E2E (tarayıcı) testi için veritabanını sıfırlar ve yönetici oluşturur.  php app/tests/e2e_seed.php
define('FB_APP_DIR', str_replace(chr(92), '/', dirname(__DIR__)));
putenv('FB_CONFIG=' . FB_APP_DIR . '/tests/config.e2e.php');
require FB_APP_DIR . '/src/Kernel.php';
FB\Kernel::boot();
$pdo = FB\DB::pdo();
$pdo->exec('SET FOREIGN_KEY_CHECKS=0');
foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_NUM) as $t) { $pdo->exec('DROP TABLE `' . $t[0] . '`'); }
$pdo->exec('SET FOREIGN_KEY_CHECKS=1');
FB\Installer::migrate(FB_APP_DIR . '/migrations');
FB\Installer::createAdmin('admin@e2e.local', 'Vk7#pL2$wN9xQz');
@unlink(FB_APP_DIR . '/storage/logs/mail.log');
echo "seeded\n";
