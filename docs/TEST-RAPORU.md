# Test raporu (2026-10-18)

Ortam: Windows 11, PHP 8.4.26 (CLI + yerleşik sunucu), MariaDB 11.4.13, Chromium (Playwright 1.49). **Natro sunucusunda çalıştırılmadı.**

## 1) API / güvenlik / finans testleri — `php app/tests/run.php`
Gerçek HTTP (curl, çerez + CSRF) ile yerel sunucuya karşı; gerçek MariaDB. **Sonuç: 302 geçti, 0 başarısız.**

| Grup | Kapsam (özet) |
|---|---|
| Yetkilendirme | admin girişi + zorunlu 2FA; müşteri admin uç noktalarını çağıramaz (403, denetim kaydı); kayıt (register) uç noktası yok; rol/tenant alanı değiştirilemez (mass-assignment); ikinci admin yok (uygulama + DB UNIQUE); admin 2FA kapatamaz; askıya alınan müşteri giriş yapamaz ve mevcut oturumu anında düşer; arşivleme/kalıcı silme kuralları |
| Oturum | 256-bit jeton, HttpOnly çerez; giriş sonrası yeni kimlik (fixation); periyodik kimlik dönüşü + grace; çıkışta çerez geçersiz; CSRF jetonsuz/yanlış/yabancı Origin reddi; güvenlik başlıkları |
| Kaba kuvvet | CAPTCHA zorunlu/tek kullanımlık/yanlış/PoW geçersiz/bal küpü; 5 hata → kilit + admin kilidi açar; bilinmeyen kullanıcı genel hata |
| Parola | zayıf/yaygın reddi; geçici parola → zorunlu değişim; davet jetonu tek kullanımlık ve yalnızca hash saklı; sıfırlama (kullanıcı sızdırmaz, tüm oturumlar düşer); Argon2id |
| Denetim | hash zinciri doğrulaması; müdahale tespiti |
| Finans #1–15 | 100.000 TL alacak/60.000 tahsilat → kalan 40.000; transfer gelir/gider yaratmaz; planlı ödeme ve tekrarlayan şablon bakiyeyi değiştirmez; fatura ≠ tahsilat; mükerrer fatura (uygulama + DB UNIQUE); EUR/USD/TL ayrı; kısmi ödeme/fazla ödeme reddi; kart ekstre ödemesi gider değil; kredi anapara gider değil (yalnızca faiz); şahsi fatura şirket giderine girmez; aynı ekstre tekrar yüklenince mükerrer yok; proje gelir/maliyeti çift sayılmaz (stok dahil); iptal/düzeltme geçmişi korunur; çift gönderim (Idempotency-Key) tek kayıt |
| Doğruluk | KDV dahil/hariç hesabı; ileri tarih / açılış öncesi / sıfır tutar / kategori uyuşmazlığı reddi; bakiye düzeltme; açılış bakiyesi gelir değil; raporlar kaynak hareketlerle uyuşur (dashboard = hesap toplamı, aylık = genel, nakit akışı = defter, cari ekstre kapanışı = kayıtlar) |
| Veri yalıtımı | Müşteri B, A'nın hesap/işlem/kalem/fatura/cari/proje/kart/kredi/belge kayıtlarını okuyamaz/değiştiremez/iptal edemez/dosya indiremez (hepsi 404); `?tenant=` müşteri için yok sayılır |
| Admin | müşteri finansını girişsiz, salt okunur, **denetimli** görür; normal uç noktadan müşteri verisini değiştiremez; düzeltme (iptal) denetimli; yönetim özeti |
| Belgeler | yükleme, sahibi indirir, başkası/oturumsuz indiremez, dosya web kökü dışında, PHP/sahte-uzantılı dosya reddi (MIME sniff) |
| Dışa aktarma | CSV (BOM, formül enjeksiyonu etkisiz), XLSX, KDV CSV, tam JSON dışa aktarma |
| Güvenlik | SQL enjeksiyon denemeleri 5xx üretmez; bozuk JSON/tür/uzunluk/taşma; yanıtlarda `password_hash`/gizli alan yok; denetim ve sunucu loglarında parola yok; hata yanıtı SQL/dosya bilgisi içermez |
| Yedekleme | yedek → boş veritabanına geri yükleme: tüm tablo satır sayıları, işlem tutar toplamı, hesap bakiyesi, denetim kayıtları ve belge SHA-256 **birebir aynı** |
| IP izin listesi | tekil, IPv4/IPv6 CIDR, geçersiz IP |

## 2) Tarayıcı (E2E) testleri — Playwright (Chromium)
`e2e/run.mjs` **54**, `run2.mjs` **23**, `run3.mjs` **17**, `run4.mjs` **14 geçti** (sıra: run → run2 → run3 → run4); **konsol hata/uyarı: 0**, CSP ihlali: 0.
Gerçek arayüzde: giriş + CAPTCHA/PoW, yanlış parola, admin 2FA kurulumu + kurtarma kodları, müşteri oluşturma (davet), davetle parola belirleme (zayıf parola reddi), müşteri onboarding (örnek veri yok), müşteri `#/admin`'e giremez, alan/hesap (EUR dahil), gelir/gider/iptal/çift tıklama, alacak→tahsilat (kalan 40.000,00 ₺), transfer, fatura (canlı KDV), kart + taksit + ödeme, kredi (12 taksit, kullandırım, ödeme), proje + hakediş, stok, şahsi fatura, tekrarlayan şablon, takvim, ekstre CSV içe aktarma (+tekrar yükleme), ekstreden hareket, cari ekstre, rapor CSV/XLSX indirme, tema, kur, müşteri 2FA, parola değişimi, yeni parola+2FA ile giriş, admin finans görünümü (salt okunur), admin denetim kaydı, **iPhone 13 emülasyonu**: alt sekme çubuğu, FAB ile gider ekleme, yatay taşma yok.

## 3) Üretim modu doğrulaması (yerel simülasyon, dist paketi, ayrı belge kökü/app dizini)
- Kurulum sihirbazı: yanlış anahtar reddedildi, zayıf yönetici parolası reddedildi, kurulum başarılı, `config.php`/kilit oluştu, `install.php` ve `setup.token` silindi, yönetici Argon2id, 2 migration.
- Üretimde HTTP isteği 403 `https_required`; HSTS başlığı; çerez `__Host-fbsid; path=/; secure; HttpOnly; SameSite=Strict`; yönetici ilk girişte 2FA kurulumuna yönlendirildi.

## Yapılamayan / doğrulanamayan
- **Natro cPanel ortamı** (PHP sürümü/eklentileri, `.htaccess` davranışı, `mail()`, cron, AutoSSL) — sunucuya erişim yok; `docs/NATRO-KURULUM.md` kontrol listesi sizin doğrulamanız içindir.
- Gerçek iPhone/Safari ve PWA kurulumu (yalnızca Chromium iPhone emülasyonu); ekran okuyucu testi.
- XLSX dosyasının Excel'de açılması (yapı üretildi, PK doğrulandı; Excel'de açılış elle denenmeli).
- Eşzamanlı yük/stres testi (kilitler ve tekil anahtarlar tasarlandı; paralel yük altında ölçülmedi). E-posta teslimi (yalnızca log taşıyıcısı test edildi).
- Gerçek bir sızma testi (penetration test) yapılmadı; kontroller kendi testlerimizdir.

## Başarısız oldu / sonradan düzeltilen hatalar (şeffaflık)
Testler sırasında bulunup düzeltilenler: stok tüketim maliyetinin "şirketler" kapsamında kaybolması; form "Kaydet" düğmesinin form dışında kalması (tıklayınca gönderilmiyordu); menü/FAB `currentTarget` hatası; sayfa değişince açık pencerelerin kalması; uyarı çıkaran dirty-state yarışı; PHP yığın izinde argüman (parola) sızma riski (`zend.exception_ignore_args`).

## Master geliştirme turu (2026-10-19/20) — eklenen testler
- PHP: **527 geçti / 0 başarısız** (önceki 302). Yeni: `t_02_z_payments.php` (vade, tek ödeme→çok fatura, avans, geri alma, idempotency, alış faturası+stok, stoksuz satış+teslimat, şahsi hesap, silme/düzenleme, yetki, bütünlük), `t_02_zz_home.php` (Test A–D: tahsilat, bağımsız avans/kök neden, avans eşleştirme, ana sayfa).
- Migration 003 eski şemalı veri kopyası üzerinde doğrulandı: bakiyeler, işlem toplamı, fatura/kalem vadeleri, stok hareketleri birebir aynı; ödenen tutar kalem bazında eski yöntemle aynı; ikinci çalıştırma boş.
- **Yapılamayan:** canlı (Natro) ortama dağıtım ve canlı veri üzerinde migration — yapılmadı; gerçek veritabanı yedeği/geri yükleme canlıda denenmedi. Yük/stres testi yok.
- Test sırasında bulunup düzeltilenler: tek kalemli ödeme formunda kalem listesi yüklenmeden tutar girilince dağıtımın boş kalması (yarış); test API hız sınırı.
