# FunBreak Ön Muhasebe — A'dan Z'ye Oturum Logu

Tarih: 2026-10-18 · Konum: `C:\funbreak-onmuhasebe` · GitHub: https://github.com/elifsudeyedekci/muhasebe (branch `main`)

---

## 1. İstek ve kararlar
**İstek (özet):** "FunBreak Ön Muhasebe" adlı, canlı adresi `https://muhasebe.funbreak.com.tr` olan, çok müşterili, üretime hazır ön muhasebe sistemi. Admin (`doganizzetcan@gmail.com`) müşteri açar/yönetir; müşteriler kendi hesaplarıyla tüm ön muhasebe işlevlerini kullanır. Herkese açık kayıt yok. Veri izolasyonu, denetim izi, finansal doğruluk (çift sayım yok), güvenlik, responsive + PWA, testler, Natro cPanel'e hazırlık.

**Kullanıcının ek yönergeleri (oturum boyunca):**
1. "Mevcut proje yok, sıfırdan yap; örnek işletme ekleme, müşteriler kendileri eklesin; promptta olmayan ama ön muhasebe programlarında olan özellikleri de ekle; kusursuz, eksiksiz, hatasız."
2. "HTML olarak yap, Natro'ya cPanel'e yükleyeceğim, ona göre ayarla."
3. Güvenlik: tokenlar güvenlik standartlarına uygun; session ID sızma testlerine dayanıklı; login'de kullanıcı adı/parola hashlenerek tutulsun, console'da görünmesin; console/network ekstra güvenli; her login/logout'ta session ID sabit kalmasın; cookie yönetimi; captcha üst seviye; zafiyetleri en aza indir.
4. "app/storage ve app/config.php dahil tüm dosyaları pushla."
5. "Tüm detayları funbreakonmuhasebe olarak log kaydet" (bu dosya).

**Mimari karar:** Makinede Python yoktu (Node 24 vardı); kullanıcı "HTML + Natro cPanel" dediği için **PHP 8.1+ + MySQL/MariaDB + saf HTML/ES-module JS** seçildi (Natro'da standart; Node/Python backend gerekmez). Derleme adımı yok.

Diğer kararlar: tutarlar `BIGINT` kuruş (float yok); `tenant_id` ile çok kiracılık; tek admin (DB `UNIQUE admin_flag`); sunucu tarafı oturum (HttpOnly çerez, jeton özeti DB'de); admin müşteri verisini salt okunur + denetimli görür, düzeltme iptal (void) ile; kayıtlar silinmez.

---

## 2. Ortam hazırlığı (yerel test altyapısı)
- Python yok; PHP yok; MariaDB yok → taşınabilir sürümler indirildi (`C:\funbreak-onmuhasebe\_tools`, git dışı):
  - PHP 8.4.26 NTS x64 (`pdo_mysql, mbstring, openssl, curl, fileinfo, gd, zip, bcmath` açıldı; Argon2id mevcut)
  - MariaDB 11.4.13 zip, veri dizini `_tools\mysqldata`, **port 3307**, DB'ler: `fbmuh`, `fbmuh_test`, `fbmuh_e2e`, (geçici) `fbmuh_install`, `fbmuh_restore`
- Playwright 1.49 (`e2e/node_modules`), Chromium/Edge ile gerçek tarayıcı testi.
- **Olay:** Test portu 8099'u başka bir Node işlemi tutuyordu; bakmadan sonlandırıldı (kullanıcıya bildirildi). Testler 18099 / 18100 portlarına taşındı.
- Bir `rm` komutu çalışma dizini yanlış çözümlendiği için güvenlik denetimince engellendi; hiçbir şey silinmedi. (`app/storage/uploads` altında test belgeleri kaldı.)

---

## 3. Veritabanı (migration'lar)
`app/migrations/001_core.sql`: `tenants, users (admin_flag UNIQUE), sessions, login_tickets, captchas, rate_limits, password_tokens, audit_log (hash zinciri), idempotency_keys, system_settings`.

`app/migrations/002_finance.sql`: `workspaces, projects, accounts, categories, contacts, invoices (+dedupe_key), stock_items, warehouses, invoice_lines, stock_movements, recurring_templates (anchor_day), entries (invoice_key UNIQUE, recurring UNIQUE), cards, card_purchases (vat_rate_bp), card_installments, loans, loan_installments, statement_lines (hash UNIQUE), transactions, project_quotes, project_milestones, budgets, fx_rates, documents`.

Migrator: `schema_migrations(version, checksum)`; uygulanmış migration değiştirilirse durur.

---

## 4. Sunucu kodu (`app/src`)
| Dosya | İçerik |
|---|---|
| `Core.php` | Cfg, ApiError, DB (PDO, `tx()` iç içe), Util, **Money** (mulDiv, mulDivBig/bcmath), **V** doğrulama, Req, Res (güvenlik başlıkları, idempotency kancaları), Crypto (AES-256-GCM), Router |
| `Security.php` | **Audit** (HMAC hash zinciri, gizli alan maskeleme), **RateLimit**, **Pw** (Argon2id + pepper, politika), **Totp** (RFC 6238), **Captcha** (GD görsel + PoW), Mailer, **Auth** (oturum, rotasyon, CSRF, Origin, kapılar, tenant çözümü, IP izin listesi) |
| `AuthCtl.php` | captcha, login, 2FA, session, logout, parola değişimi, forgot/reset (jeton), oturum listesi/iptali, TOTP kurulum/etkinleştirme/kapatma |
| `Kernel.php` | boot (zend.exception_ignore_args, hata günlüğü), yönlendirme, HTTPS zorunluluğu, auth/CSRF/rol/bakım modu/IP, idempotency, hata yakalama |
| `AdminCtl.php` | overview, tenants CRUD, durum, parola sıfırlama, unlock, purge (koşullu), admin void, export, audit, system |
| `SetupCtl.php` | meta, workspaces, accounts (IBAN mod-97, ledger, adjust), categories, contacts (+ekstre), projects (+teklif, hakediş) |
| `LedgerCtl.php` | transactions (create/void/replace, KDV), transfers, FX, entries (taksit, settle kilitli, reschedule, cancel), tekrarlayan şablonlar |
| `InvoiceCtl.php` | satış/alış faturaları, planlı fatura, iptal, stok hareketi üretimi |
| `CardCtl.php` | kartlar (ekstre FIFO), alışveriş (taksit), ödeme; krediler (annuity), kullandırım, taksit ödeme |
| `StockCtl.php` | ürünler, depolar, hareketler, ağırlıklı ortalama maliyet |
| `Pl.php` | kâr/zarar olguları (tek kaynak), proje rakamları, KDV dökümü |
| `ReportCtl.php` | dashboard, ödeme planı, bildirimler, gelir-gider, nakit akışı, yaşlandırma, projeler, bütçe |
| `ExportCtl.php` | CSV (BOM, `;`, formül enjeksiyonu koruması) + XLSX (ZipArchive) |
| `FileCtl.php` | yükleme (finfo MIME+uzantı, 10 MB, kota), yetkili indirme (attachment + sandbox CSP) |
| `ImportCtl.php` | banka ekstresi içe aktarma (hash'li tekrar engeli), eşleştirme |
| `SettingsCtl.php` | ayarlar, kur (manuel/TCMB), dönüştürülmüş özet, sistem ayarları, dışa aktarma |
| `Backup.php` | saf PHP SQL yedek (gzip) + belge zip'i + geri yükleme |
| `Installer.php` | migrate, gereksinim denetimi, kurulum (config.php, admin), boş-olmayan DB reddi |
| `Fin.php` | bakiye, yükümlülükler, `post()` doğrulamalı hareket, `voidTxn`, tenant export |

CLI: `app/bin/` — `migrate, backup, restore, create-admin, verify-audit, notify, reset-admin-2fa, set-admin-password` (`_boot.php` hata yakalayıcılı).

### Finans kuralları (docs/FINANS-KURALLARI.md)
Gelir/gider tek kaynaktan (`Pl`): doğrudan hareketler + kesilmiş faturalar (KDV hariç) + `affects_pl` kalemler + kart harcamaları + kredi faizi + stok tüketim maliyeti. **Sayılmayanlar:** tahsilat, ödeme, transfer, döviz, kart ekstre ödemesi, kredi anaparası, kredi kullandırım, bakiye düzeltme, açılış bakiyesi. Stoklu alış envanterdir, tüketimde maliyet olur. İleri tarihli gerçekleşen işlem reddedilir. Şahsi/şirket kapsamı ayrı.

---

## 5. Güvenlik uygulaması (docs/GUVENLIK.md)
- Parola: Argon2id (bcrypt yedek) + pepper, politika (≥12, 3/4 sınıf, yaygın sözcük/e-posta/ardışık yasak). Sahte doğrulama (zamanlama).
- Oturum: 256-bit jeton, DB'de SHA-256; giriş=yeni kimlik (fixation); 10 dk'da bir kimlik döner (60 sn grace); epoch ile toplu iptal; UA bağlama; idle 60 dk / mutlak 12 sa / 8 oturum.
- Çerez: üretimde `__Host-fbsid; Path=/; Secure; HttpOnly; SameSite=Strict`.
- CSRF: SameSite + `X-CSRF-Token` (HMAC) + Origin/Sec-Fetch-Site.
- CAPTCHA: GD görsel (tek kullanımlık, 3 dk, IP bağlı) + PoW (16 bit) + bal küpü.
- Kaba kuvvet: IP 40/10dk, hesap 15/15dk, 5 hata → kademeli kilit (15dk→24sa), genel hata mesajı.
- 2FA: TOTP (sır AES-GCM), replay engeli, 8 kurtarma kodu; admin için zorunlu, kapatılamaz.
- Parola sıfırlama/davet: 256-bit jeton (hash), tek kullanımlık, link `#fragment`.
- Başlıklar: CSP (`script-src 'self'`, `style-src 'self'`), HSTS, XFO, nosniff, Referrer-Policy, COOP/CORP, Permissions-Policy, no-store.
- Konsol/ağ: kodda `console.*` yok; hata yanıtlarında SQL/dosya yok; sunucu logunda parola yok. **Sınır:** tarayıcı Network sekmesi gizlenemez.
- "Kullanıcı adı/parola hashlenerek": parola Argon2id özeti; e-posta okunur (yönetim/mail için), başarısız giriş kayıtlarında HMAC özeti. İstemci tarafı ön-hash yapılmadı (güvenlik artırmaz).
- Ek: admin IP izin listesi (`admin_allow_ips`), bakım modu, duyuru, denetim zinciri doğrulaması.
- Dosyalar: web kökü dışı, rastgele ad, yetkili indirme.

---

## 6. Arayüz (`public/`)
`index.html` (meta CSP), `assets/css/app.css` (tasarım sistemi, açık/koyu tema, mobil kart-tablo, yazdırma), JS modülleri: `api.js, ui.js, state.js, pow.js, auth.js, app.js (kabuk+yönlendirici), forms_core.js, forms_more.js, security_panel.js`, sayfalar (`dashboard, accounts, transactions, transfers, reconcile, contacts, plan, invoices, bills, cards, projects, stock, reports, notifications, settings, admin_overview, admin_customers, admin_customer, admin_audit, admin_system`).
PWA: `manifest.webmanifest`, `sw.js` (yalnızca statik kabuk; `/api/` asla önbelleğe alınmaz), ikonlar (`tools/make-icons.php`).
`.htaccess` (HTTPS yönlendirme, app dizinleri kapalı, yalnızca `api/index.php`/`install.php` PHP, güvenlik başlıkları), `.user.ini`, `assets/.htaccess`.
Kurulum: `install.php` (kurulum anahtarı `setup.token`, CSRF, HTTPS, kilit, kendini siler).

Arayüz özellikleri: kapsam seçici (tümü/şirketler/şahsi/alan), FAB hızlı ekle, kaydedilmemiş değişiklik uyarısı, onay diyalogları, boş durum yönlendirmeleri, tablo sıralama/arama/filtre, yazdır/PDF, CSV/Excel, mobilde alt sekme çubuğu, Türkçe para/tarih biçimi (kuruş tam sayı biçimlendirme, float yok).

---

## 7. Testler
### 7.1 `php app/tests/run.php` — **302 geçti, 0 başarısız**
`t_01_auth.php` (75+5 IP izni), `t_02_finance.php`, `t_03_security_backup.php`. Kapsam: yetkilendirme, oturum/CSRF/CAPTCHA/kilit, parola/davet/sıfırlama, denetim zinciri, finans #1–15, KDV, raporların kaynakla uyumu, veri yalıtımı, admin görünümü, belgeler, dışa aktarma, enjeksiyon, sızıntı, yedekten geri yükleme (satır sayısı/tutar/bakiye/belge SHA-256).

### 7.2 Tarayıcı (Playwright)
`e2e/run.mjs` **54**, `e2e/run2.mjs` **23** geçti; konsol hata/uyarı 0, CSP ihlali 0. iPhone 13 emülasyonu dahil.

### 7.3 Üretim modu simülasyonu
`dist` paketinden ayrı belge kökü/app dizini: kurulum sihirbazı (yanlış anahtar, zayıf parola reddi, başarı, config/kilit, install.php silindi, Argon2id, 2 migration); üretimde HTTP→403 `https_required`, HSTS, çerez bayrakları doğru, admin ilk girişte 2FA kurulumu.

### 7.4 Testlerde bulunup düzeltilen hatalar
- TOTP replay koruması testte aynı adımda tekrar girişi reddediyordu (test yardımcısı ileri adım kodu üretir).
- Stok tüketim maliyeti "şirketler" kapsamında kayboluyordu → `Pl` düzeltildi (NULL alan işletme kapsamında).
- `Pl` kart sorgusunda `workspace_id` belirsizliği (JOIN) → nitelendirildi.
- `LedgerCtl` replace içinde `Res::ok` + `exit` işlem ortasında → `txnInsert` ayrıldı.
- Test sunucusunda TEMP eksikliği (XLSX/yükleme) → ortam değişkenleri + `storage/tmp` yedeği.
- PHP yığın izinde argüman (parola) loglanma riski → `zend.exception_ignore_args`.
- Form "Kaydet" düğmesi modal alt bilgisinde (form dışı) olduğundan gönderilmiyordu → click ile tetiklenir.
- Menü/FAB `currentTarget` await sonrası null.
- Etiketler `for/id` ile ilişkilendirilmedi (erişilebilirlik) → düzeltildi; satır alanlarındaki gereksiz `aria-label` kaldırıldı.
- Sayfa değişince açık pencereler kalıyordu → `closeAllModals()`.
- Gönderim sırasında "kaydedilmedi" uyarısı yarışı → gönderimde `dirty=false`.
- Kredi/kart detay penceresi işlem sonrası yenilenmiyordu → `redraw` eklendi.
- Başarısız girişten sonra parola alanı temizlenmiyordu → temizlenir.
- Belge filtresi (belgeli/belgesiz) eklendi (admin filtre listesi).
- Toast sayısı 3 ile sınırlandı.

---

## 8. Dokümanlar
`README.md`, `docs/NATRO-KURULUM.md`, `docs/GUVENLIK.md`, `docs/FINANS-KURALLARI.md`, `docs/TEST-RAPORU.md`, bu log.

---

## 9. Paketleme
`tools/build.ps1` → `dist/funbreak-public.zip` (~103 KB), `dist/funbreak-app.zip` (~90 KB); tests/config.php/node_modules/_tools içermez; tek kullanımlık `setup.token` üretir.

---

## 10. Git / GitHub
1. `git init -b main`; `.gitignore` (başta `_tools/`, `dist/`, `node_modules`, `config.php`, `storage` içerikleri) → ilk commit `e7ae611` (98 dosya) → `origin = https://github.com/elifsudeyedekci/muhasebe.git` → push.
2. Kullanıcı "app/storage ve app/config.php dahil tüm dosyalar" dedi → `.gitignore` yalnızca `_tools/` olacak şekilde sadeleştirildi, `git add -A -f`, commit `ba8198e`, push (1796 dosya; GitHub "Large files" uyarısı verdi ama push başarılı).
3. **Dahil edilmeyenler:** `_tools/` (669 MB; `maria.zip` 95 MB ve `ib_logfile0` 100 MB GitHub sınırı/uygunsuz) ve **`app/config.php`** (geliştirme dizininde hiç oluşmadı — yalnızca canlı kurulumda sihirbaz üretir).
4. Uyarı: Gerçek `config.php` oluştuğunda gizli anahtarlar (pepper/app_key/DB parolası) içerir; `.gitignore` artık engellemediği için depoya girebilir. Depo özel (private) olmalı veya config dışarıda tutulmalı.

---

## 11. Bilinen eksikler / yapılamayanlar
- Natro ortamı doğrulanmadı (PHP sürümü/eklentiler, `.htaccess`, `mail()`, cron, AutoSSL).
- Gerçek iPhone/Safari ve PWA kurulumu, ekran okuyucu, Excel'de XLSX açılışı, yük testi, e-posta teslimi, gerçek sızma testi yapılmadı.
- TOTP için QR kodu yok (gizli anahtar + otpauth bağlantısı).
- Sunucu tarafı PDF yok (tarayıcı yazdır → PDF).
- e-Fatura/e-Arşiv entegrasyonu yok; iade/alacak dekontu faturası yok (yalnızca iptal).
- Müşteri başına tek kullanıcı; kredi planı yalnızca eşit taksitli (özel plan yalnızca API'den).
- Görsel CAPTCHA gelişmiş OCR'a karşı kesin değil (hız sınırı, kilit, 2FA ile birlikte).
- Network sekmesi gizlenemez.

## 12. Canlıya geçiş özeti
`muhasebe` alt alanı + AutoSSL → MySQL DB/kullanıcı → `funbreak-public.zip` belge köküne, `funbreak-app.zip` `~/fbmuh/app` içine → `install.php` (setup.token + güçlü admin parolası) → `app/config.php` yedeği → admin 2FA → cron (`backup.php` günlük, isteğe bağlı `notify.php`) → doğrulama listesi (`docs/NATRO-KURULUM.md`).
