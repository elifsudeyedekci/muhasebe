# Natro cPanel'e kurulum — adım adım

## 0. Uygunluk
Sistem **PHP 8.1+ ve MySQL/MariaDB** kullanır; bunlar standart cPanel paket bileşenleridir. Python/Node.js gerekmez.
Kurulum sihirbazı (`install.php`) gereksinimleri **kendisi denetler**; zorunlu bir eklenti eksikse kurulumu başlatmaz.

Ben Natro sunucusuna erişemedim; yerelde PHP 8.4 + MariaDB 11.4 ile test ettim. **Sizin doğrulamanız gerekenler:**
- cPanel › **MultiPHP Manager**: alt alan için PHP **8.1 – 8.4** seçin.
- cPanel › **Select PHP Version › Extensions**: `pdo_mysql`, `mbstring`, `openssl`, `gd`, `fileinfo` (zorunlu); `zip`, `bcmath`, `curl` (önerilir: XLSX/yedek/kur çekme).
- Parola özeti Argon2id'dir; PHP'de yoksa otomatik bcrypt (maliyet 12). Sihirbaz hangisinin kullanılacağını gösterir.
- Pakette **Cron Jobs** ve **MySQL Databases** bulunmalı.

Bunlardan biri paketinizde yoksa Natro'dan açtırın; olmuyorsa VPS/bulut sunucu (Ubuntu + Nginx + PHP-FPM + MariaDB) kullanın — kod aynıdır (Nginx'te `.htaccess` kuralları `location` bloklarına çevrilir; `api/` → `api/index.php` yönlendirmesi ve `app/` dizininin kapalı tutulması şarttır).

## 1. Alt alan adı ve HTTPS
1. cPanel › **Domains**: `muhasebe` alt alanını oluşturun; **belge kökü** örn. `/home/KULLANICI/muhasebe.funbreak.com.tr`.
2. cPanel › **SSL/TLS Status**: alt alan için **AutoSSL** çalıştırın; sertifika geçerli olmadan devam etmeyin. **API için ayrı alt alan açmayın** (`/api/...` aynı alandadır).

## 2. Veritabanı
cPanel › **MySQL Database Wizard**: veritabanı + kullanıcı oluşturup tüm yetkileri verin; ad/kullanıcı/parolayı not edin (kurulumda sorulur, kaynak koda yazılmaz).

## 3. Dosyaları yükleme
Yerelde `powershell -File tools\build.ps1` → `dist\funbreak-public.zip`, `dist\funbreak-app.zip`.
- `funbreak-public.zip` → **belge köküne** açın (gizli dosyalar `.htaccess`, `.user.ini` de gelmeli; Dosya Yöneticisi › Ayarlar › "Show Hidden Files").
- `funbreak-app.zip` → belge kökünün **dışına**, `/home/KULLANICI/fbmuh/app/` içine açın (`app/src`, `app/migrations`, `app/storage`…).
  Farklı yola koyarsanız `public/api/app_path.txt` dosyasına tam yolu yazın.
- İzinler: klasör 750/755, dosya 640/644; `app/storage` yazılabilir.

## 2→4. Kurulum sihirbazı (ilk yönetici hesabı)
1. `https://muhasebe.funbreak.com.tr/install.php`
2. **Kurulum anahtarı:** Dosya Yöneticisi ile `app/storage/setup.token` içeriğini yapıştırın (yalnızca sunucuya erişimi olan siz).
3. Veritabanı bilgileri. Yönetici e-postası `doganizzetcan@gmail.com`. **Güçlü yönetici parolası belirleyin** (≥12 karakter). Parola kaynak koda/Git'e/loglara yazılmaz; yalnızca Argon2id özeti saklanır.
4. Sihirbaz: `app/config.php` (0640, gizli anahtarlar) + migration'lar + yönetici + kurulum kilidi oluşturur. `install.php` ve `setup.token` kendini siler (silinemezse elle silin). Boş olmayan (kullanıcı verisi olan) veritabanına kurulumu reddeder.
5. **`app/config.php` dosyasını güvenli yere yedekleyin** (`pepper`/`app_key` kaybolursa parolalar ve 2FA çözülemez).
6. Giriş yapın → **iki aşamalı doğrulama kurulumu zorunludur**; kurtarma kodlarını saklayın.

## 5. Otomatik yedekleme (cPanel › Cron Jobs)
```
0 3 * * *  /usr/local/bin/php /home/KULLANICI/fbmuh/app/bin/backup.php  >/dev/null 2>&1
0 8 * * *  /usr/local/bin/php /home/KULLANICI/fbmuh/app/bin/notify.php  >/dev/null 2>&1   # isteğe bağlı e-posta özetleri
```
PHP yolu Natro'da farklı olabilir (`ea-php83` vb.). Yedekler `app/storage/backups` içine `fbmuh-TARİH.sql.gz` + `…-belgeler.zip` yazılır; son 14 tutulur. **Yedekleri düzenli olarak sunucu dışına indirin.** Yönetici › Sistem, son yedek zamanını gösterir.

### Geri yükleme
```
php /home/KULLANICI/fbmuh/app/bin/restore.php /yol/fbmuh-20261018-030000.sql.gz /yol/fbmuh-20261018-030000-belgeler.zip --yes
```
Restore tabloların üzerine yazar; önce mevcut durumun yedeğini alın.

## 6. Güvenli dağıtım ve geri alma
1. `php app/bin/backup.php`
2. Yeni `public` ve `app` dosyalarını yükleyin (**`app/config.php` ve `app/storage` içeriğini ezmeyin**).
3. `php app/bin/migrate.php` (yalnızca bekleyen migration'ları uygular; uygulanmış migration değiştirilmişse durur).
4. Sorun olursa eski dosyaları geri koyun; veri değiştiyse `restore.php` ile yedeğe dönün.

## 7. Canlı doğrulama listesi
- [ ] `http://` → `https://` yönleniyor; sertifika geçerli.
- [ ] Yönetici girişi + 2FA; Admin Paneli görünür.
- [ ] Yönetici panelinden test müşterisi oluşturun; davet bağlantısıyla parola belirleyip girin; müşteri `#/admin` adresine giremez.
- [ ] Test müşterisiyle: alan → hesap → gelir/gider → alacak/tahsilat → fatura. iPhone Safari'de "Ana Ekrana Ekle".
- [ ] Cron çalıştı; `app/storage/backups` içinde dosya var.
- [ ] `/app/config.php`, `/storage/`, `/src/` adresleri 403/404 veriyor.
- [ ] Test müşterisini arşivleyin.

## 8. Acil durumlar
- Yönetici 2FA cihazı + kurtarma kodları kayboldu: `php app/bin/reset-admin-2fa.php`
- Yönetici parolası unutuldu: `FB_ADMIN_PASSWORD='…' php app/bin/set-admin-password.php`
- Müşteri kilitlendi: Yönetici › Müşteri › "Kilidi aç". Müşteri parolası: "Parola sıfırla" (bağlantı üretir, ekranda gösterilir).
- Yönetici IP kısıtı (isteğe bağlı): `app/config.php` → `'admin_allow_ips' => ['203.0.113.7', '198.51.100.0/24']`
- E-posta (davet/sıfırlama) hosting `mail()` ile gider; ulaşmazsa SPF/DKIM'i cPanel › Email Deliverability'den düzeltin. Davet/sıfırlama bağlantısı yönetici ekranında da gösterilir.

## Güncelleme (003 + 004 migration) — canlıda uygulamadan önce
1. **Yedek:** `php app/bin/backup.php` (SQL + belgeler) ve çıkan dosyaları bilgisayarınıza indirin.
2. Eski `app/src`, `app/migrations`, `public` klasörlerinin kopyasını alın (geri dönüş).
3. Yeni `funbreak-app.zip` / `funbreak-public.zip` dosyalarını yükleyin (**config.php ve storage/ üzerine yazmayın**).
4. `php app/bin/migrate.php` — 003 ve 004 yalnızca ekleme yapar; mevcut ödemeler `payment_allocations` tablosuna birebir kopyalanır, vadeler değişmez.
5. Kontrol: Ana ekran toplamları, bir cari ekstresi ve bir fatura kalanı eskisiyle aynı olmalı. Sorun olursa kodu eski kopyayla değiştirin ve `restore.php` ile yedeği geri yükleyin.
