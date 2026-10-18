<?php
// Acil durum: yönetici parolasını sunucudan belirler. Kullanım: FB_ADMIN_PASSWORD='...' php bin/set-admin-password.php
// Parola argüman olarak verilmez (komut geçmişine düşmesin). Parola politikası uygulanır; tüm oturumlar kapanır.
require __DIR__ . '/_boot.php';
$pass = getenv('FB_ADMIN_PASSWORD');
if (!$pass) { fwrite(STDERR, "FB_ADMIN_PASSWORD ortam değişkenini ayarlayın.\n"); exit(1); }
$a = FB\DB::one("SELECT id,email FROM users WHERE role='admin'");
if (!$a) { fwrite(STDERR, "Yönetici bulunamadı.\n"); exit(1); }
FB\Pw::policy($pass, $a['email']);
FB\DB::exec('UPDATE users SET password_hash=?, session_epoch=session_epoch+1, failed_attempts=0, lock_level=0, locked_until=NULL, password_changed_at=? WHERE id=?', [FB\Pw::hash($pass), FB\Util::now(), $a['id']]);
FB\DB::exec('UPDATE sessions SET revoked_at=? WHERE user_id=? AND revoked_at IS NULL', [FB\Util::now(), $a['id']]);
FB\Audit::log('security', 'admin_password_set_cli', ['actor' => (int)$a['id'], 'role' => 'admin', 'ip' => 'cli']);
echo "Yönetici parolası güncellendi; tüm oturumlar kapatıldı.\n";
