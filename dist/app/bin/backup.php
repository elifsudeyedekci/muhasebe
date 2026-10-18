<?php
// Kullanım: php bin/backup.php   — cPanel Cron: 0 3 * * * /usr/local/bin/php /home/KULLANICI/fbmuh/app/bin/backup.php
require __DIR__ . '/_boot.php';
foreach (FB\Backup::run() as $f) {
    echo "Yedek: $f (" . round(filesize($f) / 1024) . " KB)\n";
}
