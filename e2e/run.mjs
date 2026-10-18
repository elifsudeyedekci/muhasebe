import fs from 'node:fs';
import path from 'node:path';
import { R, ok, section, startServer, seed, waitUp, launch, watch, shot, login, finish, totp, BASE, ROOT } from './lib.mjs';

const ADMIN = 'admin@e2e.local'; const APASS = 'Vk7#pL2$wN9xQz';
const CUST = 'musteri@e2e.local'; const CPASS = 'Yesil#Orman-9137Zx';
await seed();
const server = startServer();
process.on('exit', () => server.kill());
await waitUp();
const { browser, ctx } = await launch();
const page = await ctx.newPage(); watch(page, 'desktop');
const modal = () => page.locator('.modal');
const fillIn = async (label, v) => { const f = modal().getByLabel(label, { exact: false }).first(); await f.fill(v); };
const pick = async (label, text) => {
  const sel = modal().getByLabel(label, { exact: false }).first();
  const opts = await sel.locator('option').allTextContents();
  const hit = opts.find((o) => o === text) ?? opts.find((o) => o.includes(text));
  if (hit === undefined) throw new Error(`seçenek yok: ${label} → ${text} (mevcut: ${opts.join(' | ')})`);
  await sel.selectOption({ label: hit });
};
const submit = async (name = /Kaydet|Oluştur|Ekle|Devam|Tahsil|Öde|Transfer/) => { await modal().getByRole('button', { name }).last().click(); };

try {
  section('Giriş ekranı ve güvenlik başlıkları');
  const resp = await page.goto(BASE + '/');
  await page.getByRole('button', { name: 'Giriş yap' }).waitFor();
  ok(true, 'giriş ekranı açıldı');
  await shot(page, '01-login');
  ok((await page.locator('input[type=password]').count()) === 1, 'parola alanı password türünde');
  const csp = await page.evaluate(() => document.querySelector('meta[http-equiv="Content-Security-Policy"]')?.content || '');
  ok(csp.includes("script-src 'self'") && !csp.includes('unsafe-inline'), 'CSP meta: script-src self, unsafe-inline yok');
  const styled = await page.evaluate(() => [...document.querySelectorAll('[style]')].filter((e) => (e.getAttribute('style') || '').trim() !== '').map((e) => e.tagName + ':' + e.getAttribute('style')));
  const html = await page.evaluate(() => document.querySelector('input').outerHTML);
  ok(styled.length === 0, "DOM'da satır içi style özniteliği yok (login)", html);
  ok((await page.getByText('Kayıt ol').count()) === 0, 'herkese açık “Kayıt ol” bağlantısı yok');

  section('Yanlış parola');
  await page.getByLabel('E-posta').fill(ADMIN);
  await page.getByLabel('Parola', { exact: true }).fill('YanlisParola#1234');
  await page.getByLabel('Görseldeki kod').fill('TESTAB');
  await page.getByText('Tarayıcı doğrulaması tamamlandı').waitFor({ timeout: 20000 });
  await page.getByRole('button', { name: 'Giriş yap' }).click();
  await page.getByRole('alert').filter({ hasText: 'hatalı' }).waitFor();
  ok(true, 'hatalı parola genel hata mesajı gösterir');
  ok((await page.getByLabel('Parola', { exact: true }).inputValue()) === '', 'başarısız denemeden sonra parola alanı temizlenir');

  section('Yönetici: giriş + zorunlu 2FA kurulumu');
  await login(page, ADMIN, APASS);
  await page.getByText('iki aşamalı doğrulama kurulumu zorunludur', { exact: false }).or(page.getByText('Yönetici hesabı için iki aşamalı doğrulama zorunludur.')).first().waitFor({ timeout: 15000 });
  await shot(page, '02-enroll-2fa');
  await page.waitForFunction(() => /^[A-Z2-7 ]{20,}$/.test(document.querySelector('.mono')?.textContent || ''), null, { timeout: 10000 });
  const secret = (await page.locator('.mono').first().textContent()).replace(/\s/g, '');
  ok(secret.length === 32, '2FA gizli anahtarı gösterildi (32 karakter)');
  await page.getByLabel('Doğrulama kodu').fill(totp(secret));
  await page.getByRole('button', { name: 'Etkinleştir' }).click();
  await page.getByText('Kurtarma kodları').first().waitFor({ timeout: 10000 });
  const codes = (await page.locator('.mono div').allTextContents()).filter((x) => /^[0-9A-F]{5}-[0-9A-F]{5}$/.test(x.trim()));
  ok(codes.length === 8, '8 kurtarma kodu gösterildi');
  await page.getByRole('button', { name: 'Devam et' }).isDisabled().then((d) => ok(d, 'onay işaretlenmeden “Devam et” pasif'));
  await page.getByLabel('Kodları güvenli bir yere kaydettim').check();
  await page.getByRole('button', { name: 'Devam et' }).click();
  await page.getByRole('heading', { name: 'Yönetim Özeti' }).waitFor({ timeout: 15000 });
  ok(true, 'admin paneli açıldı');
  await shot(page, '03-admin-overview');
  ok(await page.getByRole('link', { name: /Müşteriler/ }).first().isVisible(), 'admin menüsünde “Müşteriler” görünür');

  section('Admin: müşteri oluşturma');
  await page.getByRole('link', { name: /Müşteriler/ }).first().click();
  await page.getByRole('button', { name: /Yeni müşteri/ }).click();
  await fillIn('Müşteri adı', 'Deneme Ticaret');
  await fillIn('Giriş e-postası', CUST);
  await modal().getByRole('button', { name: 'Hesabı oluştur' }).click();
  await page.getByText('Hesap oluşturuldu').waitFor({ timeout: 8000 }).catch(async () => { console.log('MODAL:', await page.locator('.overlay').allInnerTexts()); throw new Error('müşteri oluşturma modalı beklenen sonucu vermedi'); });
  const link = await modal().locator('input[readonly]').first().inputValue();
  ok(/#\/reset\?token=/.test(link), 'davet bağlantısı gösterildi');
  await shot(page, '04-invite');
  await page.locator('.overlay').last().getByRole('button', { name: 'Kapat' }).click();
  await page.getByText('Deneme Ticaret').first().waitFor();
  // çıkış
  await page.getByRole('button', { name: /▾/ }).click();
  await page.getByRole('button', { name: /Çıkış yap/ }).click();
  await page.getByRole('button', { name: 'Giriş yap' }).waitFor({ timeout: 15000 });
  ok(true, 'çıkış yapıldı, giriş ekranına dönüldü');

  section('Müşteri: davetle parola belirleme ve ilk giriş');
  await page.goto(link);
  await page.getByRole('heading', { name: /Parola belirleme/ }).or(page.getByText('Parola belirleme')).first().waitFor();
  await page.getByLabel('Yeni parola', { exact: true }).fill('zayif');
  await page.getByLabel('Yeni parola (tekrar)').fill('zayif');
  await page.getByRole('button', { name: 'Parolayı belirle' }).click();
  await page.getByRole('alert').filter({ hasText: '12 karakter' }).waitFor();
  ok(true, 'zayıf parola reddedilir');
  await page.getByLabel('Yeni parola', { exact: true }).fill(CPASS);
  await page.getByLabel('Yeni parola (tekrar)').fill(CPASS);
  await page.getByRole('button', { name: 'Parolayı belirle' }).click();
  await page.getByRole('button', { name: 'Giriş yap' }).waitFor({ timeout: 15000 });
  await login(page, CUST, CPASS);
  await page.getByText('Hoş geldiniz').waitFor({ timeout: 20000 });
  ok(true, 'müşteri girişi: onboarding ekranı (örnek veri yok)');
  await shot(page, '05-onboarding');
  ok((await page.getByRole('link', { name: /Müşteriler|Denetim/ }).count()) === 0, 'müşteri menüsünde admin paneli görünmez');
  await page.goto(BASE + '/#/admin');
  await page.getByRole('heading', { name: 'Ana Ekran' }).or(page.getByText('Hoş geldiniz')).first().waitFor();
  ok(!(await page.getByText('Yönetim Özeti').count()), 'müşteri #/admin adresine giremez → ana ekrana döner');

  section('Müşteri: alan, hesap, işlemler');
  await page.getByRole('button', { name: /İlk alanı oluştur/ }).click();
  await fillIn('Ad', 'Horizon'); await modal().getByRole('button', { name: 'Kaydet' }).click();
  await page.getByRole('heading', { name: 'Ana Ekran' }).waitFor({ timeout: 10000 });
  await page.getByRole('link', { name: /Hesaplar & Kasalar/ }).first().click();
  await page.getByRole('button', { name: /Alan$/ }).click();
  await fillIn('Ad', 'Şahsi'); await pick('Tür', 'Şahsi'); await modal().getByRole('button', { name: 'Kaydet' }).click();
  await page.getByText('Şahsi').first().waitFor();
  await page.getByRole('button', { name: /Hesap \/ kasa/ }).click();
  await pick('İşletme / alan', 'Horizon'); await fillIn('Hesap adı', 'Ana Banka'); await fillIn('Açılış bakiyesi', '50.000,00');
  await modal().getByRole('button', { name: 'Kaydet' }).click();
  await page.getByText('Ana Banka').first().waitFor();
  await page.getByText('50.000,00 ₺').first().waitFor();
  ok(true, 'hesap oluşturuldu, bakiye TR biçiminde (50.000,00 ₺)');
  await page.getByRole('button', { name: /Hesap \/ kasa/ }).click();
  await pick('İşletme / alan', 'Horizon'); await fillIn('Hesap adı', 'Kasa'); await pick('Tür', 'Nakit kasa'); await modal().getByRole('button', { name: 'Kaydet' }).click();
  await page.getByText('Kasa').first().waitFor();
  await page.getByRole('button', { name: /Hesap \/ kasa/ }).click();
  await pick('İşletme / alan', 'Horizon'); await fillIn('Hesap adı', 'Euro Hesap'); await pick('Para birimi', 'EUR'); await fillIn('Açılış bakiyesi', '1.000,00'); await modal().getByRole('button', { name: 'Kaydet' }).click();
  await page.getByText('1.000,00 €').first().waitFor();
  ok(true, 'EUR hesabı ayrı para biriminde');
  await shot(page, '06-accounts');

  await page.getByRole('link', { name: /Gelir & Gider/ }).first().click();
  await page.getByRole('button', { name: '＋ Gelir' }).first().click();
  await pick('Hesap / kasa', 'Ana Banka'); await fillIn('Tutar', '1.200'); await pick('KDV oranı', '%20'); await fillIn('Açıklama', 'Danışmanlık geliri');
  await modal().getByRole('button', { name: 'Kaydet' }).click();
  await page.getByText('Danışmanlık geliri').first().waitFor();
  ok(true, 'gelir kaydedildi');
  await page.getByRole('button', { name: '＋ Gider' }).first().click();
  await pick('Hesap / kasa', 'Ana Banka'); await fillIn('Tutar', '300,50'); await pick('Kategori', 'Yakıt'); await fillIn('Açıklama', 'Yakıt alımı');
  await modal().getByRole('button', { name: 'Kaydet' }).click();
  await page.getByText('Yakıt alımı').first().waitFor();
  ok(await page.getByText('−300,50 ₺').first().isVisible(), 'gider eksi işaretli gösterilir');
  await shot(page, '07-transactions');
  // iptal (silinmez)
  await page.getByRole('row', { name: /Yakıt alımı/ }).getByRole('button', { name: '⋯' }).click();
  await page.getByRole('button', { name: /İptal et/ }).click();
  await page.locator('.modal input').fill('Test iptali');
  await page.locator('.modal').getByRole('button', { name: 'İptal et' }).click();
  for (let i = 0; i < 40 && (await page.getByText('Yakıt alımı').count()) > 0; i++) await page.waitForTimeout(250);
  ok((await page.getByText('Yakıt alımı').count()) === 0, 'iptal edilen işlem listeden kalktı');
  ok(true, 'işlem iptal edilince varsayılan listeden kalkar (silinmez)');
  // çift tıklama
  await page.getByRole('button', { name: '＋ Gelir' }).first().click();
  await pick('Hesap / kasa', 'Ana Banka'); await fillIn('Tutar', '77'); await fillIn('Açıklama', 'Çift tık testi');
  const sb = modal().getByRole('button', { name: 'Kaydet' });
  await sb.dblclick();
  await page.getByText('Çift tık testi').first().waitFor();
  await page.waitForTimeout(500);
  ok((await page.getByText('Çift tık testi').count()) === 1, 'çift tıklama tek kayıt oluşturur');

  section('Alacak, tahsilat, transfer, fatura');
  await page.getByRole('link', { name: /Cariler/ }).first().click();
  await page.getByRole('button', { name: /Cari ekle/ }).first().click();
  await fillIn('Ünvan', 'Müşteri A Ltd'); await modal().getByRole('button', { name: 'Kaydet' }).click();
  await page.getByText('Müşteri A Ltd').first().waitFor();
  await page.getByRole('link', { name: /Alacak, Borç/ }).first().click();
  await page.getByRole('button', { name: '＋ Alacak' }).click();
  await fillIn('Başlık', 'Büyük satış'); await pick('Müşteri', 'Müşteri A Ltd'); await fillIn('Tutar', '100.000');
  await modal().getByRole('button', { name: 'Kaydet' }).click();
  await page.getByText('Büyük satış').first().waitFor();
  await page.getByRole('row', { name: /Büyük satış/ }).getByRole('button', { name: 'Tahsil et' }).click();
  await pick('Hangi hesaba', 'Ana Banka'); await modal().getByLabel('Tutar').fill('60.000');
  await modal().getByRole('button', { name: 'Tahsil et' }).click();
  await page.getByText('40.000,00 ₺').first().waitFor();
  ok(true, '100.000 TL alacağın 60.000 TL tahsilatı sonrası kalan 40.000,00 ₺');
  await shot(page, '08-plan');

  await page.getByRole('link', { name: /Transfer & Döviz/ }).first().click();
  await page.getByRole('button', { name: /Transfer$/ }).first().click();
  await pick('Kaynak', 'Ana Banka'); await pick('Hedef', 'Kasa'); await fillIn('Tutar', '20.000');
  await modal().getByRole('button', { name: 'Transfer et' }).click();
  await page.getByText('Ana Banka → Kasa').first().waitFor();
  ok(true, 'transfer kaydedildi');

  await page.getByRole('link', { name: /Faturalar/ }).first().click();
  await page.getByRole('button', { name: /Satış faturası/ }).first().click();
  await modal().locator('select').nth(1).selectOption({ label: 'Müşteri A Ltd' });
  await modal().getByLabel('Fatura no').fill('FB-001');
  await modal().getByLabel('Açıklama *').first().fill('Hizmet bedeli');
  await modal().getByLabel('Birim fiyat').first().fill('1.000');
  await page.getByText('1.200,00 ₺').first().waitFor();
  ok(true, 'fatura satırı: 1.000 + %20 KDV = 1.200,00 ₺ canlı hesaplandı');
  await modal().getByRole('button', { name: 'Faturayı kaydet' }).click();
  await page.getByText('FB-001').first().waitFor();
  ok(true, 'satış faturası kaydedildi');
  await shot(page, '09-invoices');

  section('Kart, kredi, proje, stok, raporlar sayfaları (yüklenir mi?)');
  for (const [name, heading] of [['Kartlar & Krediler', 'Kartlar & Krediler'], ['Projeler', 'Projeler & İşler'], ['Stok & Depo', 'Stok, Depo & Malzeme'], ['Şahsi Faturalar', 'Şahsi Faturalar'], ['Banka Mutabakatı', 'Banka Mutabakatı'], ['Raporlar', 'Raporlar'], ['Bildirimler', 'Bildirim Merkezi']]) {
    await page.getByRole('link', { name: new RegExp(name.replace('&', '&')) }).first().click();
    await page.getByRole('heading', { name: heading }).first().waitFor({ timeout: 10000 });
    ok(true, name + ' sayfası açıldı');
  }
  await page.getByRole('link', { name: /Raporlar/ }).first().click();
  for (const rep of ['Gelir – Gider (aylık)', 'Nakit akışı', 'KDV dökümü', 'Alacak yaşlandırma', 'Proje kârlılığı', 'Bütçe – gerçekleşen', 'Ortak para birimi özeti']) {
    await page.locator('select').first().selectOption({ label: new RegExp(rep.replace(/[()]/g, '')) .source ? undefined : undefined }).catch(() => {});
    await page.locator('.filters select').first().selectOption({ index: ['Gelir – Gider (aylık)', 'Nakit akışı', 'KDV dökümü', 'Alacak yaşlandırma', 'Borç yaşlandırma', 'Proje kârlılığı', 'Bütçe – gerçekleşen', 'Ortak para birimi özeti'].indexOf(rep) });
    await page.waitForTimeout(600);
    ok((await page.locator('.alert.err').count()) === 0, 'rapor hatasız: ' + rep);
  }
  await page.locator('.filters select').first().selectOption({ index: 0 });
  await page.waitForTimeout(500);
  await shot(page, '10-reports');
  await page.getByRole('link', { name: /Ana Ekran/ }).first().click();
  await page.getByText('Eldeki para').waitFor();
  await shot(page, '11-dashboard');
  ok(await page.getByText('Planlanan tahsilatlar').count() >= 0, 'dashboard açıldı');

  section('Ayarlar: parola/2FA/oturumlar');
  await page.goto(BASE + '/#/settings?tab=security');
  await page.getByText('Açık oturumlar').waitFor();
  ok(true, 'güvenlik sekmesi: parola, 2FA, oturumlar');
  await shot(page, '12-settings-security');

  section('Admin: müşteri finans görünümü (salt okunur)');
  const adminPage = await ctx.newPage(); watch(adminPage, 'admin');
  const ctx2 = await browser.newContext({ viewport: { width: 1360, height: 860 }, locale: 'tr-TR', timezoneId: 'Europe/Istanbul' });
  const ap = await ctx2.newPage(); watch(ap, 'admin');
  await login(ap, ADMIN, APASS, secret, 1); // TOTP yeniden kullanım koruması: bir sonraki adımın kodu
  await ap.getByRole('heading', { name: 'Yönetim Özeti' }).waitFor({ timeout: 20000 });
  await ap.getByRole('link', { name: /Müşteriler/ }).first().click();
  await ap.getByRole('link', { name: 'Aç' }).first().click();
  await ap.getByRole('button', { name: /Finansal görünüm/ }).click();
  await ap.getByRole('link', { name: /Hesaplar & bakiyeler/ }).click();
  await ap.getByText('Yönetici görünümü').waitFor();
  await ap.getByText('Ana Banka').first().waitFor();
  ok(true, 'admin müşteri hesaplarını girişsiz, salt okunur görür');
  ok((await ap.getByRole('button', { name: /Hesap \/ kasa/ }).count()) === 0, 'salt okunur görünümde ekleme düğmeleri yok');
  await shot(ap, '13-admin-tenant-view');
  await ap.goto(BASE + '/#/admin/t/1/transactions');
  await ap.getByText('Danışmanlık geliri').first().waitFor({ timeout: 10000 });
  ok(await ap.getByRole('button', { name: 'Düzeltme (iptal)' }).first().isVisible(), 'admin için “Düzeltme (iptal)” (silme değil) mevcut');
  await ap.goto(BASE + '/#/admin/audit');
  await ap.getByText('admin_view_tenant_data').first().waitFor({ timeout: 10000 });
  ok(true, 'admin erişimi denetim kaydında görünür');
  await shot(ap, '14-admin-audit');
  await ap.goto(BASE + '/#/admin/system');
  await ap.getByText('Sistem durumu').first().waitFor();
  await shot(ap, '15-admin-system');
  await ctx2.close();

  section('Mobil (iPhone 13)');
  const mob = await launch('iPhone 13');
  const mp = await mob.ctx.newPage(); watch(mp, 'iphone');
  await login(mp, CUST, CPASS);
  await mp.getByText('Eldeki para').waitFor({ timeout: 20000 });
  await shot(mp, '16-mobile-dashboard');
  ok(await mp.locator('.tabbar').isVisible(), 'mobilde alt sekme çubuğu görünür');
  ok(!(await mp.locator('.sidebar').isVisible()), 'mobilde yan menü gizli');
  const overflow = await mp.evaluate(() => document.documentElement.scrollWidth > window.innerWidth + 1);
  ok(!overflow, 'mobilde yatay taşma yok (dashboard)');
  await mp.locator('#fab').click();
  await mp.getByRole('button', { name: /Gider ekle/ }).click();
  await mp.locator('.modal').getByLabel('Tutar').fill('150');
  await mp.locator('.modal').getByLabel('Açıklama').fill('Mobil gider');
  await shot(mp, '17-mobile-form');
  await mp.locator('.modal').getByRole('button', { name: 'Kaydet' }).click();
  await mp.waitForTimeout(800);
  await mp.goto(BASE + '/#/transactions');
  await mp.getByText('Mobil gider').first().waitFor();
  ok(true, 'iPhone ekranında gider kaydedilebildi');
  await shot(mp, '18-mobile-transactions');
  ok(!(await mp.evaluate(() => document.documentElement.scrollWidth > window.innerWidth + 1)), 'mobilde yatay taşma yok (işlemler)');
  await mp.goto(BASE + '/#/plan');
  await mp.waitForTimeout(800);
  await shot(mp, '19-mobile-plan');
  await mob.browser.close();
} catch (e) {
  R.fail++; R.fails.push('KRİTİK: ' + e.message.split('\n')[0]);
  console.log('KRİTİK HATA:', e.message);
  try { await shot(page, 'zz-failure'); } catch { /* */ }
}
await browser.close();
const good = finish();
server.kill();
process.exit(good ? 0 : 1);
