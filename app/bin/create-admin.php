<?php
// Kullanım: FB_ADMIN_EMAIL=... FB_ADMIN_PASSWORD=... php bin/create-admin.php
// Parola komut satırı argümanı OLARAK VERİLMEZ (shell geçmişine düşer); ortam değişkeni veya etkileşimli istem kullanın.
require __DIR__ . '/_boot.php';
$email = getenv('FB_ADMIN_EMAIL') ?: 'doganizzetcan@gmail.com';
$pass = getenv('FB_ADMIN_PASSWORD');
if (!$pass) {
    fwrite(STDERR, "Yönetici parolası (en az 12 karakter, görünür girilir): ");
    $pass = trim((string)fgets(STDIN));
}
$id = FB\Installer::createAdmin($email, $pass);
echo "Yönetici oluşturuldu (id=$id, $email). İlk girişte iki aşamalı doğrulama kurulumu zorunludur.\n";
