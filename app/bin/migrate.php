<?php
// Kullanım: php bin/migrate.php   — bekleyen migration'ları uygular.
require __DIR__ . '/_boot.php';
$done = FB\Installer::migrate(FB_APP_DIR . '/migrations');
echo $done ? 'Uygulandı: ' . implode(', ', $done) . "\n" : "Güncel; bekleyen migration yok.\n";
