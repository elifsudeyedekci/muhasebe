<?php
declare(strict_types=1);

namespace FB;

use PDO;

/** Saf PHP yedekleme/geri yükleme (mysqldump gerektirmez; paylaşımlı barındırmada çalışır). */
final class Backup
{
    private const END = ";\n/*FBEND*/\n";

    public static function dir(): string
    {
        $d = FB_APP_DIR . '/storage/backups';
        if (!is_dir($d)) {
            @mkdir($d, 0750, true);
        }
        return $d;
    }

    /** Veritabanını .sql.gz olarak yazar; belgeleri ayrıca .zip'ler. Dönüş: oluşturulan dosyalar. */
    public static function run(int $keep = 14): array
    {
        $stamp = gmdate('Ymd-His');
        $dir = self::dir();
        $sqlFile = "$dir/fbmuh-$stamp.sql.gz";
        $gz = gzopen($sqlFile, 'wb9');
        if (!$gz) {
            throw new \RuntimeException('Yedek dosyası yazılamadı.');
        }
        $pdo = DB::pdo();
        gzwrite($gz, "/*FBMUH BACKUP $stamp*/\nSET NAMES utf8mb4" . self::END . 'SET FOREIGN_KEY_CHECKS=0' . self::END);
        $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        $counts = [];
        foreach ($tables as $t) {
            $create = $pdo->query("SHOW CREATE TABLE `$t`")->fetch(PDO::FETCH_NUM)[1];
            gzwrite($gz, "DROP TABLE IF EXISTS `$t`" . self::END . $create . self::END);
            $st = $pdo->query("SELECT * FROM `$t`", PDO::FETCH_NUM);
            $batch = [];
            $n = 0;
            while ($row = $st->fetch()) {
                $batch[] = '(' . implode(',', array_map(fn($v) => $v === null ? 'NULL' : $pdo->quote((string)$v), $row)) . ')';
                $n++;
                if (count($batch) >= 200) {
                    gzwrite($gz, "INSERT INTO `$t` VALUES " . implode(',', $batch) . self::END);
                    $batch = [];
                }
            }
            if ($batch) {
                gzwrite($gz, "INSERT INTO `$t` VALUES " . implode(',', $batch) . self::END);
            }
            $counts[$t] = $n;
        }
        gzwrite($gz, 'SET FOREIGN_KEY_CHECKS=1' . self::END . '/*FBCOUNTS ' . json_encode($counts) . "*/\n");
        gzclose($gz);
        @chmod($sqlFile, 0640);
        $out = [$sqlFile];
        $up = FB_APP_DIR . '/storage/uploads';
        if (class_exists('ZipArchive') && is_dir($up)) {
            $zipFile = "$dir/fbmuh-$stamp-belgeler.zip";
            $z = new \ZipArchive();
            if ($z->open($zipFile, \ZipArchive::CREATE) === true) {
                $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($up, \FilesystemIterator::SKIP_DOTS));
                $any = false;
                foreach ($it as $f) {
                    if ($f->isFile()) {
                        $z->addFile($f->getPathname(), ltrim(str_replace('\\', '/', substr($f->getPathname(), strlen($up))), '/'));
                        $any = true;
                    }
                }
                $z->close();
                if ($any) {
                    @chmod($zipFile, 0640);
                    $out[] = $zipFile;
                } else {
                    @unlink($zipFile);
                }
            }
        }
        // saklama: son $keep yedek
        foreach (['*.sql.gz', '*-belgeler.zip'] as $pat) {
            $all = glob("$dir/fbmuh-$pat") ?: glob("$dir/$pat") ?: [];
            rsort($all);
            foreach (array_slice($all, $keep) as $old) {
                @unlink($old);
            }
        }
        DB::q('INSERT INTO system_settings (k,v,updated_at) VALUES (?,?,?) ON DUPLICATE KEY UPDATE v=VALUES(v), updated_at=VALUES(updated_at)', ['last_backup', $stamp, Util::now()]);
        return $out;
    }

    /** Yedeği verilen PDO'ya geri yükler (hedef veritabanındaki tabloların üzerine yazar!). */
    public static function restore(string $file, PDO $pdo, ?string $uploadsZip = null, ?string $uploadsDir = null): array
    {
        $gz = gzopen($file, 'rb');
        if (!$gz) {
            throw new \RuntimeException('Yedek dosyası açılamadı.');
        }
        $sql = '';
        while (!gzeof($gz)) {
            $sql .= gzread($gz, 1 << 20);
        }
        gzclose($gz);
        if (!str_contains($sql, '/*FBMUH BACKUP')) {
            throw new \RuntimeException('Bu bir FunBreak yedeği değil.');
        }
        $n = 0;
        foreach (explode(self::END, $sql) as $stmt) {
            $stmt = trim($stmt);
            if ($stmt === '' || str_starts_with($stmt, '/*')) {
                if (!str_starts_with($stmt, '/*FBMUH')) {
                    continue;
                }
                $stmt = trim(preg_replace('#^/\*FBMUH BACKUP[^*]*\*/#', '', $stmt) ?? '');
                if ($stmt === '') {
                    continue;
                }
            }
            if (str_starts_with($stmt, '/*FBCOUNTS')) {
                continue;
            }
            $pdo->exec($stmt);
            $n++;
        }
        $files = 0;
        if ($uploadsZip && $uploadsDir && is_file($uploadsZip) && class_exists('ZipArchive')) {
            $z = new \ZipArchive();
            if ($z->open($uploadsZip) === true) {
                $z->extractTo($uploadsDir);
                $files = $z->numFiles;
                $z->close();
            }
        }
        preg_match('#/\*FBCOUNTS (.*?)\*/#', $sql, $m);
        return ['statements' => $n, 'files' => $files, 'counts' => json_decode($m[1] ?? '{}', true)];
    }
}
