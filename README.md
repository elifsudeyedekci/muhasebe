# FunBreak Ön Muhasebe

Çok müşterili, güvenli ön muhasebe ve finans yönetimi. **Canlı adres:** https://muhasebe.funbreak.com.tr

| Katman | Teknoloji |
|---|---|
| Arayüz | Saf HTML + ES modülleri + CSS (derleme/Node gerekmez), PWA, katı CSP (satır içi script/stil yok) |
| Sunucu | PHP 8.1+ (8.4 ile test edildi), tek ön denetleyici `public/api/index.php` → `/api/...` (aynı alan adı) |
| Veritabanı | MySQL / MariaDB (InnoDB, utf8mb4). Tutarlar `BIGINT` küçük birim (kuruş) — kayan nokta yok |
| Yetki | `admin` (tek) ve `customer`; her sorgu `tenant_id` ile süzülür |

## Dizin yapısı
```
public/            → belge kökü (muhasebe.funbreak.com.tr)  index.html, assets/, api/index.php, install.php, .htaccess
app/               → WEB KÖKÜNÜN DIŞINDA durur: src/, migrations/, bin/ (CLI araçları), storage/ (belgeler, loglar, yedekler)
app/tests/         → otomatik testler (üretime yüklenmez)
e2e/               → tarayıcı (Playwright) testleri (üretime yüklenmez)
tools/             → build.ps1 (zip paketi), make-icons.php
docs/              → kurulum, güvenlik, finans kuralları, test raporu
```

## Hızlı başlangıç
Canlıya alma: **docs/NATRO-KURULUM.md**. Paket: `powershell -File tools\build.ps1` → `dist\funbreak-public.zip` + `dist\funbreak-app.zip`.

## Testler (yerelde)
```
php app/tests/run.php                      # 302 API / güvenlik / finans testi (MariaDB 127.0.0.1:3307, fbmuh_test)
cd e2e && node run.mjs && node run2.mjs    # gerçek tarayıcı testleri (Playwright; Chromium/Edge)
```
Sonuçlar: **docs/TEST-RAPORU.md**.

## CLI araçları (`app/bin`)
`migrate.php` · `backup.php` · `restore.php` · `create-admin.php` · `verify-audit.php` · `notify.php` · `reset-admin-2fa.php` · `set-admin-password.php`

## Belgeler
- `docs/NATRO-KURULUM.md` — cPanel kurulum, cron, yedek/geri yükleme, dağıtım/geri alma
- `docs/GUVENLIK.md` — güvenlik mimarisi ve sınırları
- `docs/FINANS-KURALLARI.md` — neyin gelir/gider/nakit sayıldığı (çift sayım önleme)
- `docs/TEST-RAPORU.md` — gerçek test sonuçları ve yapılamayanlar
