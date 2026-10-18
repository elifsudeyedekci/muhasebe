<?php
declare(strict_types=1);

// FunBreak Ön Muhasebe — ilk kurulum sihirbazı. Kurulum bitince bu dosya silinir (silinemezse elle silin).
// Güvenlik: (1) kurulum kilidi, (2) sunucuda bulunan tek kullanımlık kurulum anahtarı, (3) CSRF, (4) HTTPS.

$cands = array_filter([
    getenv('FB_APP_DIR') ?: null,
    is_file(__DIR__ . '/api/app_path.txt') ? trim((string)file_get_contents(__DIR__ . '/api/app_path.txt')) : null,
    __DIR__ . '/../app', __DIR__ . '/../fbmuh/app', dirname(__DIR__, 2) . '/fbmuh/app', dirname(__DIR__, 2) . '/fbmuh_app', __DIR__ . '/../fbmuh_app',
]);
foreach ($cands as $c) {
    if (is_file($c . '/src/Kernel.php')) {
        define('FB_APP_DIR', rtrim(str_replace('\\', '/', $c), '/'));
        break;
    }
}
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
header("Content-Security-Policy: default-src 'self'; style-src 'self'; script-src 'none'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');

function e(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
function page(string $title, string $body): never
{
    echo '<!doctype html><html lang="tr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>' . e($title) . '</title><link rel="stylesheet" href="/assets/css/app.css"></head><body><div class="auth"><div class="auth-card card stack">'
        . '<div class="brand"><div class="logo">F</div><div>FunBreak Ön Muhasebe<small>Kurulum</small></div></div>' . $body . '</div></div></body></html>';
    exit;
}
if (!defined('FB_APP_DIR')) {
    page('Kurulum', '<div class="alert err">Uygulama dizini (app/) bulunamadı. <code>app</code> klasörünü, web kökünün DIŞINA (ör. <code>/home/KULLANICI/fbmuh/app</code>) yükleyin; ya da <code>api/app_path.txt</code> dosyasına tam yolunu yazın.</div>');
}
$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
$local = in_array($_SERVER['SERVER_NAME'] ?? '', ['localhost', '127.0.0.1'], true);
if (!$https && !$local) {
    page('Kurulum', '<div class="alert err">Kurulum yalnızca HTTPS üzerinden yapılabilir. cPanel › SSL/TLS Status bölümünden alt alan adı için sertifikayı etkinleştirin.</div>');
}
if (is_file(FB_APP_DIR . '/storage/installed.lock')) {
    page('Kurulum tamamlanmış', '<div class="alert ok">Kurulum zaten tamamlanmış. Güvenliğiniz için bu <code>install.php</code> dosyasını silin.</div><a class="btn primary block" href="/">Uygulamaya git</a>');
}
ini_set('session.use_strict_mode', '1');
session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => $https, 'httponly' => true, 'samesite' => 'Strict']);
session_name('fbsetup');
session_start();
$_SESSION['csrf'] ??= bin2hex(random_bytes(32));

require FB_APP_DIR . '/src/Kernel.php';
require_once FB_APP_DIR . '/src/Core.php';
require_once FB_APP_DIR . '/src/Security.php';
spl_autoload_register(function (string $c) {
    if (str_starts_with($c, 'FB\\') && is_file(FB_APP_DIR . '/src/' . substr($c, 3) . '.php')) {
        require FB_APP_DIR . '/src/' . substr($c, 3) . '.php';
    }
});

// Kurulum anahtarı: sunucuda dosya olarak durur; yalnızca dosya sistemine erişimi olan kişi (siz) okuyabilir.
$tokenFile = FB_APP_DIR . '/storage/setup.token';
if (!is_file($tokenFile)) {
    @mkdir(dirname($tokenFile), 0750, true);
    @file_put_contents($tokenFile, bin2hex(random_bytes(16)), LOCK_EX);
}
$token = trim((string)@file_get_contents($tokenFile));
$err = '';
$req = FB\Installer::requirements();
$allOk = true;
foreach ($req as [$name, $ok]) {
    if (!$ok && !str_contains($name, 'isteğe bağlı') && !str_contains($name, 'Argon2')) {
        $allOk = false;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!hash_equals($_SESSION['csrf'], (string)($_POST['csrf'] ?? ''))) {
            throw new RuntimeException('Oturum doğrulaması başarısız; sayfayı yenileyin.');
        }
        $_SESSION['tries'] = ($_SESSION['tries'] ?? 0) + 1;
        if ($_SESSION['tries'] > 10) {
            throw new RuntimeException('Çok fazla deneme. Tarayıcıyı kapatıp yeniden açın.');
        }
        if ($token === '' || !hash_equals($token, trim((string)($_POST['setup_key'] ?? '')))) {
            sleep(1);
            throw new RuntimeException('Kurulum anahtarı hatalı. Anahtar: sunucudaki app/storage/setup.token dosyasının içeriği.');
        }
        if (!$allOk) {
            throw new RuntimeException('Gerekli PHP eklentileri eksik; yukarıdaki listeyi düzeltin.');
        }
        $pass = (string)($_POST['admin_password'] ?? '');
        if ($pass !== (string)($_POST['admin_password2'] ?? '')) {
            throw new RuntimeException('Yönetici parolaları uyuşmuyor.');
        }
        $base = ($https ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? 'localhost');
        // Parola politikası kontrolü (veritabanına dokunmadan önce)
        putenv('FB_CONFIG=' . FB_APP_DIR . '/config.php');
        $tmpCfg = ['pepper' => bin2hex(random_bytes(8)), 'app_key' => base64_encode(random_bytes(32))];
        FB\Pw::policy($pass, (string)($_POST['admin_email'] ?? ''));
        FB\Installer::install([
            'db' => ['host' => trim((string)$_POST['db_host']), 'port' => (int)($_POST['db_port'] ?: 3306), 'name' => trim((string)$_POST['db_name']), 'user' => trim((string)$_POST['db_user']), 'pass' => (string)$_POST['db_pass']],
            'admin_email' => strtolower(trim((string)$_POST['admin_email'])), 'admin_password' => $pass, 'base_url' => $base, 'env' => 'production',
        ]);
        @unlink($tokenFile);
        $_SESSION = [];
        $gone = @unlink(__FILE__);
        page('Kurulum tamamlandı', '<div class="alert ok">Kurulum tamamlandı. Yönetici hesabı oluşturuldu.</div><ol class="small"><li>' . ($gone ? '<b>install.php</b> otomatik silindi.' : '<b>install.php dosyasını şimdi elle silin</b> (cPanel › Dosya Yöneticisi).') . '</li><li>Giriş yapın; ilk girişte <b>iki aşamalı doğrulama</b> kurulumu zorunludur.</li><li><code>app/config.php</code> dosyasını yedekleyin (gizli anahtarlar içerir).</li><li>cPanel › Cron Jobs: <code>app/bin/backup.php</code> günlük.</li></ol><a class="btn primary block" href="/">Giriş sayfasına git</a>');
    } catch (Throwable $ex) {
        $err = $ex instanceof FB\ApiError || $ex instanceof RuntimeException ? $ex->getMessage() : 'Kurulum başarısız: ' . $ex->getMessage();
    }
}

$rows = '';
foreach ($req as [$name, $ok, $info]) {
    $rows .= '<div class="list-item"><span class="dot ' . ($ok ? 'ok' : (str_contains($name, 'isteğe') || str_contains($name, 'Argon2') ? 'warn' : 'danger')) . '"></span><div class="grow">' . e($name) . '</div><span class="xs muted">' . e((string)$info) . '</span></div>';
}
$field = fn(string $label, string $name, string $type = 'text', string $val = '', string $extra = '') => '<div class="field"><label>' . e($label) . '</label><input class="input" type="' . $type . '" name="' . $name . '" value="' . e($val) . '" ' . $extra . ' required></div>';
$form = '<form method="post" class="stack" autocomplete="off">'
    . '<input type="hidden" name="csrf" value="' . e($_SESSION['csrf']) . '">'
    . ($err ? '<div class="alert err" role="alert">' . e($err) . '</div>' : '')
    . '<h2>1. Gereksinimler</h2><div class="list">' . $rows . '</div>'
    . '<h2>2. Kurulum anahtarı</h2><div class="alert info small">Sunucudaki <code>app/storage/setup.token</code> dosyasını cPanel › Dosya Yöneticisi ile açıp içeriğini yapıştırın. (Böylece yalnızca sunucuya erişimi olan kişi kurulum yapabilir.)</div>'
    . $field('Kurulum anahtarı', 'setup_key', 'password', '', 'autocomplete="off"')
    . '<h2>3. Veritabanı (cPanel › MySQL Veritabanları)</h2>'
    . $field('Sunucu', 'db_host', 'text', $_POST['db_host'] ?? 'localhost') . $field('Port', 'db_port', 'number', $_POST['db_port'] ?? '3306') . $field('Veritabanı adı', 'db_name', 'text', $_POST['db_name'] ?? '') . $field('Kullanıcı', 'db_user', 'text', $_POST['db_user'] ?? '') . $field('Parola', 'db_pass', 'password')
    . '<h2>4. Yönetici hesabı</h2>'
    . $field('Yönetici e-postası', 'admin_email', 'email', $_POST['admin_email'] ?? 'doganizzetcan@gmail.com')
    . $field('Yönetici parolası (en az 12 karakter)', 'admin_password', 'password', '', 'autocomplete="new-password" minlength="12"') . $field('Parola (tekrar)', 'admin_password2', 'password', '', 'autocomplete="new-password" minlength="12"')
    . '<button class="btn primary block" type="submit"' . ($allOk ? '' : ' disabled') . '>Kurulumu tamamla</button></form>';
page('Kurulum', $form);
