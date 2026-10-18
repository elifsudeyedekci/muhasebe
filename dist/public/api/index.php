<?php
declare(strict_types=1);

// API ön denetleyici. Uygulama kodu (app/) web kökünün DIŞINDA durmalıdır.
$cands = array_filter([
    getenv('FB_APP_DIR') ?: null,
    is_file(__DIR__ . '/app_path.txt') ? trim((string)file_get_contents(__DIR__ . '/app_path.txt')) : null,
    dirname(__DIR__, 2) . '/app',
    dirname(__DIR__, 2) . '/fbmuh/app',
    dirname(__DIR__, 3) . '/fbmuh/app',
    dirname(__DIR__, 3) . '/fbmuh_app',
    dirname(__DIR__, 2) . '/fbmuh_app',
]);
foreach ($cands as $c) {
    if (is_file($c . '/src/Kernel.php')) {
        define('FB_APP_DIR', rtrim(str_replace('\\', '/', $c), '/'));
        break;
    }
}
if (!defined('FB_APP_DIR')) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo '{"ok":false,"error":{"code":"server_error","message":"Uygulama dizini bulunamadı."}}';
    exit;
}
require FB_APP_DIR . '/src/Kernel.php';
FB\Kernel::run();
