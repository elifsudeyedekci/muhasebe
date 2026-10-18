<?php
declare(strict_types=1);

namespace FB;

/** Migration çalıştırıcı ve ilk kurulum (config + ilk yönetici). Web kurulum sihirbazı ve CLI aynı sınıfı kullanır. */
final class Installer
{
    public static function migrate(string $dir): array
    {
        $pdo = DB::pdo();
        $pdo->exec('CREATE TABLE IF NOT EXISTS schema_migrations (version VARCHAR(100) PRIMARY KEY, checksum CHAR(64) NOT NULL, applied_at DATETIME NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        $applied = array_column(DB::all('SELECT version, checksum FROM schema_migrations'), 'checksum', 'version');
        $files = glob($dir . '/*.sql') ?: [];
        sort($files);
        $done = [];
        foreach ($files as $f) {
            $ver = basename($f, '.sql');
            $sql = (string)file_get_contents($f);
            $sum = hash('sha256', $sql);
            if (isset($applied[$ver])) {
                if ($applied[$ver] !== $sum) {
                    throw new \RuntimeException("Migration değiştirilmiş: $ver (uygulanmış migration düzenlenemez)");
                }
                continue;
            }
            $clean = preg_replace('/^\s*--.*$/m', '', $sql) ?? '';
            foreach (preg_split('/;\s*\n/', $clean) ?: [] as $stmt) {
                $stmt = trim($stmt);
                if ($stmt !== '') {
                    $pdo->exec($stmt);
                }
            }
            DB::q('INSERT INTO schema_migrations (version, checksum, applied_at) VALUES (?,?,?)', [$ver, $sum, Util::now()]);
            $done[] = $ver;
        }
        return $done;
    }

    public static function requirements(): array
    {
        $dirs = ['storage/uploads', 'storage/logs', 'storage/backups'];
        $r = [
            ['PHP ≥ 8.1', version_compare(PHP_VERSION, '8.1.0', '>='), PHP_VERSION],
            ['pdo_mysql', extension_loaded('pdo_mysql'), ''],
            ['openssl', extension_loaded('openssl'), ''],
            ['mbstring', extension_loaded('mbstring'), ''],
            ['gd (CAPTCHA)', extension_loaded('gd') && function_exists('imagepng'), ''],
            ['fileinfo', extension_loaded('fileinfo'), ''],
            ['zip (XLSX dışa aktarma — isteğe bağlı)', class_exists('ZipArchive'), ''],
            ['bcmath (kur dönüşümü — isteğe bağlı)', extension_loaded('bcmath'), ''],
            ['Argon2id parola özeti', defined('PASSWORD_ARGON2ID'), defined('PASSWORD_ARGON2ID') ? 'argon2id' : 'bcrypt kullanılacak'],
        ];
        foreach ($dirs as $d) {
            $p = FB_APP_DIR . '/' . $d;
            if (!is_dir($p)) {
                @mkdir($p, 0750, true);
            }
            $r[] = ["Yazılabilir: $d", is_dir($p) && is_writable($p), ''];
        }
        return $r;
    }

    /** @param array{db:array,admin_email:string,admin_password:string,base_url:string,env?:string,mail_transport?:string} $o */
    public static function install(array $o): void
    {
        $cfgPath = getenv('FB_CONFIG') ?: (FB_APP_DIR . '/config.php');
        if (is_file(FB_APP_DIR . '/storage/installed.lock')) {
            throw new \RuntimeException('Kurulum zaten tamamlanmış.');
        }
        if (!filter_var($o['admin_email'], FILTER_VALIDATE_EMAIL)) {
            throw new \RuntimeException('Yönetici e-posta adresi geçersiz.');
        }
        $cfg = [
            'env' => $o['env'] ?? 'production',
            'base_url' => rtrim($o['base_url'], '/'),
            'db' => $o['db'],
            'app_key' => base64_encode(random_bytes(32)),
            'pepper' => bin2hex(random_bytes(32)),
            'require_admin_2fa' => true,
            'mail_from' => 'no-reply@funbreak.com.tr',
            'mail_transport' => $o['mail_transport'] ?? 'mail',
            'trust_proxy_proto' => false,
            'trust_cloudflare' => false,
            'pow_bits' => 16,
            'session_idle' => 3600,
            'session_absolute' => 43200,
            'session_rotate' => 600,
            'max_sessions' => 8,
        ];
        // Bağlantıyı doğrula (parola loglara/çıktıya yazılmaz)
        try {
            $probe = new \PDO(sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $cfg['db']['host'], (int)($cfg['db']['port'] ?? 3306), $cfg['db']['name']), $cfg['db']['user'], $cfg['db']['pass'], [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        } catch (\PDOException) {
            throw new \RuntimeException('Veritabanına bağlanılamadı; bilgileri kontrol edin.');
        }
        // Mevcut gerçek verinin üzerine kurulum yapılmasını engelle
        if ($probe->query("SHOW TABLES LIKE 'users'")->fetchColumn() && (int)$probe->query('SELECT COUNT(*) FROM users')->fetchColumn() > 0) {
            throw new \RuntimeException('Bu veritabanında zaten kullanıcı verisi var; kurulum veriyi ezmemek için durduruldu. Boş bir veritabanı kullanın.');
        }
        $export = "<?php\n// FunBreak Ön Muhasebe — GİZLİ yapılandırma. Git'e eklemeyin; web erişimine kapalı tutun; YEDEKLEYİN (pepper/app_key kaybı parolaları ve 2FA'yı geçersiz kılar).\nreturn " . var_export($cfg, true) . ";\n";
        if (@file_put_contents($cfgPath, $export, LOCK_EX) === false) {
            throw new \RuntimeException('config.php yazılamadı.');
        }
        @chmod($cfgPath, 0640);
        putenv('FB_CONFIG=' . $cfgPath);
        Cfg::load();
        DB::reset();
        self::migrate(FB_APP_DIR . '/migrations');
        self::createAdmin($o['admin_email'], $o['admin_password']);
        file_put_contents(FB_APP_DIR . '/storage/installed.lock', Util::now());
    }

    public static function createAdmin(string $email, string $password): int
    {
        $email = strtolower(trim($email));
        if (DB::val('SELECT id FROM users WHERE role=\'admin\'')) {
            throw new \RuntimeException('Yönetici hesabı zaten var; ikinci yönetici oluşturulamaz.');
        }
        Pw::policy($password, $email);
        $id = DB::insert('users', [
            'tenant_id' => null, 'email' => $email, 'display_name' => 'Yönetici', 'password_hash' => Pw::hash($password), 'role' => 'admin', 'admin_flag' => 1,
            'status' => 'active', 'must_change_password' => 0, 'email_verified_at' => Util::now(), 'created_at' => Util::now(), 'session_epoch' => 1, 'password_changed_at' => Util::now(),
        ]);
        Audit::log('admin', 'admin_created', ['actor' => $id, 'role' => 'admin', 'entity' => 'user', 'id' => $id, 'ip' => 'installer']);
        return $id;
    }
}
