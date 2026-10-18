<?php
declare(strict_types=1);
// CLI ortak önyükleme: yalnızca komut satırından çalışır.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
define('FB_APP_DIR', str_replace(chr(92), '/', dirname(__DIR__)));
require FB_APP_DIR . '/src/Kernel.php';
FB\Kernel::boot();
set_exception_handler(function (Throwable $e) {
    fwrite(STDERR, 'HATA: ' . $e->getMessage() . "\n");
    exit(1);
});
