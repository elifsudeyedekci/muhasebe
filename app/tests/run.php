<?php
declare(strict_types=1);

// Kullanım: php app/tests/run.php [filtre]   (MariaDB 127.0.0.1:3307/fbmuh_test gerekir — bkz. config.test.php)
$app = str_replace('\\', '/', dirname(__DIR__));
define('FB_APP_DIR', $app);
putenv('FB_CONFIG=' . $app . '/tests/config.test.php');
putenv('FB_TEST=1');
require $app . '/src/Kernel.php';
FB\Kernel::boot();
require __DIR__ . '/lib.php';

use FB\DB;
use FB\Installer;

$only = $argv[1] ?? null;
resetDb();
DB::reset();
Installer::migrate($app . '/migrations');
@unlink($app . '/storage/logs/mail.log');
@unlink($app . '/storage/logs/app-error.log');
@unlink($app . '/storage/logs/php-error.log');
$ADMIN_EMAIL = 'owner@test.local';
$ADMIN_PASS = 'Vk7#pL2$wN9xQz';
Installer::createAdmin($ADMIN_EMAIL, $ADMIN_PASS);
$GLOBALS['ADMIN_EMAIL'] = $ADMIN_EMAIL;
$GLOBALS['ADMIN_PASS'] = $ADMIN_PASS;

$cmd = [PHP_BINARY, '-S', '127.0.0.1:18099', '-t', dirname($app) . '/public', $app . '/tests/router.php'];
$proc = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['file', sys_get_temp_dir() . '/fbsrv.out', 'w'], 2 => ['file', sys_get_temp_dir() . '/fbsrv.err', 'w']], $pipes, null, ['FB_CONFIG' => $app . '/tests/config.test.php', 'FB_TEST' => '1', 'PATH' => getenv('PATH'), 'TEMP' => sys_get_temp_dir(), 'TMP' => sys_get_temp_dir(), 'SystemRoot' => getenv('SystemRoot') ?: 'C:\\Windows']);
register_shutdown_function(function () use ($proc) {
    proc_terminate($proc);
});
for ($i = 0; $i < 50; $i++) {
    $s = @fsockopen('127.0.0.1', 18099, $en, $es, 0.2);
    if ($s) {
        fclose($s);
        break;
    }
    usleep(200000);
}

$files = glob(__DIR__ . '/t_*.php');
sort($files);
foreach ($files as $f) {
    if ($only && !str_contains($f, $only)) {
        continue;
    }
    require $f;
}

$T = $GLOBALS['T'];
echo "\n==================\nGeçen: {$T['pass']}  Kalan/başarısız: {$T['fail']}\n";
foreach ($T['fails'] as $f) {
    echo " - $f\n";
}
$err = @file_get_contents($app . '/storage/logs/app-error.log');
if ($err) {
    echo "\nSunucu hata logu:\n$err\n";
}
exit($T['fail'] > 0 ? 1 : 0);
