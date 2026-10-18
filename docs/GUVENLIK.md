# Güvenlik mimarisi

## Kimlik doğrulama
| Konu | Uygulama |
|---|---|
| Parola | Argon2id (bcrypt yedek) + sunucu tarafı **pepper** (HMAC-SHA256 ön özeti). Düz metin saklanmaz/loglanmaz. Politika: ≥12 karakter, 3/4 karakter sınıfı, yaygın sözcük/e-posta/ardışık dizi yasak. Giriş sırasında maliyet parametreleri eskiyse otomatik yeniden özetlenir. |
| "Kullanıcı adı ve parola hashlenerek tutulsun" | **Parola** yalnızca Argon2id özeti olarak saklanır. **E-posta** yönetim/e-posta gönderimi için okunabilir kalır; başarısız giriş denetim kayıtlarında e-posta yerine HMAC özetinin ilk 12 karakteri yazılır. Tarayıcıda parolayı önceden hashlemek güvenliği artırmaz (özet, parolanın yerini alır) — bu yüzden yapılmadı; koruma TLS + sunucu tarafı Argon2id ile sağlanır. |
| Oturum jetonu | 256-bit CSPRNG (`random_bytes`), base64url (43 karakter). Veritabanında yalnızca **SHA-256 özeti**. Sabit süreli karşılaştırma. |
| Oturum sabitleme | Her girişte **yeni** oturum kimliği; istekle gelen eski çerez iptal edilir. Çıkışta oturum iptal + `Clear-Site-Data`. |
| Oturum kimliği sabit kalmaz | Her 10 dk'da bir kimlik **döner** (eski kimlik 60 sn grace). Parola değişimi/2FA değişimi/askıya alma: tüm oturumlar düşer (`session_epoch`). |
| Süreler | Boşta 60 dk, mutlak 12 sa, en çok 8 eşzamanlı oturum. Tarayıcı (User-Agent) değişirse oturum iptal. |
| Çerez | Üretimde `__Host-fbsid; Path=/; Secure; HttpOnly; SameSite=Strict` (Domain yok). JS okuyamaz. |
| CSRF | Üç katman: SameSite=Strict, `X-CSRF-Token` (oturuma bağlı HMAC), Origin/Fetch-Metadata doğrulaması. JSON dışı içerik reddedilir. |
| CAPTCHA | Kendi barındırdığımız (üçüncü taraf yok) GD görseli, tek kullanımlık, 3 dk, IP'ye bağlı + **iş kanıtı (proof-of-work, 16 bit)** + bal küpü alanı. Giriş ve parola sıfırlamada zorunlu. Not: görsel CAPTCHA'lar gelişmiş OCR'a karşı kesin koruma değildir; asıl koruma hız sınırı + kilitleme + 2FA ile birlikte çalışır. |
| Kaba kuvvet | IP başına 40/10 dk, hesap başına 15/15 dk, 5 hatada kademeli kilit (15 dk → 24 sa), bilinmeyen kullanıcıda sabit süreli sahte doğrulama, genel hata mesajı (kullanıcı sızdırmaz). |
| 2FA | TOTP (RFC 6238), sır AES-256-GCM ile şifreli, yeniden kullanım (replay) engeli, 8 tek kullanımlık kurtarma kodu (özet). **Yönetici için zorunlu** ve kapatılamaz. |
| Parola sıfırlama / davet | 256-bit jeton, yalnızca özeti saklanır, tek kullanımlık (30 dk / 72 sa), bağlantı `#fragment` içinde (sunucu loglarına/Referer'a düşmez), kayıtlı olmayan e-postada yanıt aynı. |
| Yönetici | Tek yönetici DB düzeyinde (`UNIQUE admin_flag`); rol değiştirme/silme uç noktası yok; isteğe bağlı IP izin listesi; CLI acil durum araçları denetlenir. |

## Yetki ve veri yalıtımı
- Her istek sunucuda doğrulanır; menüden gizleme güvenlik değildir (`/api/admin/*` rol denetimli).
- Tüm finans tablolarında `tenant_id`; her sorgu bu alanla süzülür, kayıt kimlikleri sahiplik doğrulanarak çözülür (başka müşterinin kimliği → 404).
- Yönetici müşteri verisini yalnızca **salt okunur** (`?tenant=`) ve **denetim kaydıyla** görür; değişiklik yalnızca iptal/düzeltme uç noktasıyla (silme yok, gerekçeli, denetimli).
- Belgeler web kökü dışında, rastgele adla saklanır; indirme yalnızca oturum + sahiplik (veya yönetici, denetimli) ile, `attachment` + `sandbox` CSP. Yükleme: tür/uzantı/içerik (finfo) denetimi, 10 MB, kota.

## Ağ / tarayıcı sertleştirme ("console ve network")
- HTTPS zorunlu (301), HSTS (preload), üretimde HTTP istekleri API'de 403.
- CSP: `default-src 'self'; script-src 'self'; style-src 'self'` — satır içi script/stil **yok**, `eval` yok, üçüncü taraf kaynak yok. Ayrıca `X-Frame-Options: DENY`, `nosniff`, `Referrer-Policy: no-referrer`, COOP/CORP, Permissions-Policy.
- API yanıtları `no-store`; hassas veri URL'de taşınmaz (parola/jeton yalnızca POST gövdesi/HttpOnly çerez).
- Uygulama kodu **hiçbir `console.*` çağrısı yapmaz**; hata yanıtları SQL/dosya/yığın bilgisi içermez (yalnızca `request_id`); sunucu logunda parola/jeton yok (`zend.exception_ignore_args`).
- **Sınır:** Tarayıcı geliştirici araçlarındaki Network sekmesi, kullanıcının kendi tarayıcısında kendi trafiğini göstermeye devam eder; bunu teknik olarak gizlemek mümkün değildir. Önlem, orada **hassas olmayan/en az veri** görünmesi ve TLS ile kopyalanamamasıdır.

## Veri bütünlüğü ve denetim
- `BIGINT` küçük birim; DB işlemleri (transaction) ve satır kilitleriyle atomik transfer, kalan-tutar denetimi; `Idempotency-Key` ile çift gönderim engeli; tekil anahtarlar (fatura→tek alacak, ekstre satırı, şablon+vade).
- Finans kayıtları **silinmez**: iptal = `voided` (gerekçe, kim, ne zaman); düzenleme = eski iptal + yeni kayıt (`replaces_id`).
- `audit_log`: yalnızca eklenir; her satır öncekinin HMAC zinciriyle bağlıdır — `bin/verify-audit.php` ve yönetici panelinden doğrulanır; sonradan değişiklik tespit edilir.

## Bilinen sınırlar (dürüst liste)
- Kayıt dışı e-posta/telefon alanları şifrelenmemiştir (yalnızca erişim kontrolüyle korunur).
- Görsel CAPTCHA tek başına bot geçirmez değildir (yukarıya bakın); Cloudflare Turnstile/hCaptcha entegre etmek isterseniz `Captcha` sınıfı tek noktadır.
- TOTP kurulumunda QR kodu yoktur (gizli anahtar + `otpauth://` bağlantısı gösterilir).
- Paylaşımlı barındırmada işletim sistemi/PHP güvenliği barındırma firmasındadır; WAF/DDoS koruması yoktur (Cloudflare önerilir; `trust_cloudflare` ayarı hazırdır).
- Resmî e-Fatura/e-Arşiv gönderimi yoktur; mevzuata uygunluk garantisi verilmez.
