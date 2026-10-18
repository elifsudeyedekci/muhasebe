<?php
// Acil durum (telefon + kurtarma kodları kaybedildi): yönetici 2FA'sını sunucudan sıfırlar. Yalnızca sunucuya erişimi olan kişi çalıştırabilir.
// Sıfırlama sonrası yönetici bir sonraki girişte 2FA'yı yeniden kurmak zorundadır. İşlem denetim kaydına yazılır.
require __DIR__ . '/_boot.php';
$a = FB\DB::one("SELECT id,email FROM users WHERE role='admin'");
if (!$a) { fwrite(STDERR, "Yönetici bulunamadı.\n"); exit(1); }
FB\DB::exec('UPDATE users SET totp_enabled=0, totp_secret_enc=NULL, recovery_codes=NULL, totp_last_step=NULL, session_epoch=session_epoch+1 WHERE id=?', [$a['id']]);
FB\DB::exec('UPDATE sessions SET revoked_at=? WHERE user_id=? AND revoked_at IS NULL', [FB\Util::now(), $a['id']]);
FB\Audit::log('security', 'admin_2fa_reset_cli', ['actor' => (int)$a['id'], 'role' => 'admin', 'ip' => 'cli']);
echo "Yönetici ({$a['email']}) 2FA sıfırlandı; tüm oturumlar kapatıldı. Girişte yeniden kurulum istenecek.\n";
