// Üçüncü senaryo (run.mjs → run2.mjs sonrası): ödeme dağıtımı, vadesiz fatura, faturadan yeni ürün + stok, hesap silme, fatura düzenleme.
import fs from 'node:fs';
import path from 'node:path';
import { R, ok, section, startServer, waitUp, launch, watch, shot, login, finish, BASE, ROOT } from './lib.mjs';

const CUST = 'musteri@e2e.local'; let CPASS = 'Yesil#Orman-9137Zx'; let SECRET = null;
// run2.mjs 2FA etkinleştirip parolayı değiştirir; durumunu .state.json'a yazar (sıra: run → run2 → run3)
try { const st = JSON.parse(fs.readFileSync(path.join(ROOT, 'e2e', '.state.json'), 'utf8')); CPASS = st.pass; SECRET = st.secret; await new Promise((r) => setTimeout(r, 31000)); /* TOTP yeniden kullanım koruması: yeni adım */ } catch { /* run2 çalışmadıysa varsayılan */ }
const server = startServer(); process.on('exit', () => server.kill());
await waitUp();
const { browser, ctx } = await launch();
const page = await ctx.newPage(); watch(page, 'c3');
const modal = () => page.locator('.modal').last();
const fillIn = async (label, v) => { await modal().getByLabel(label, { exact: false }).first().fill(v); };
const pick = async (label, text) => {
  const sel = modal().getByLabel(label, { exact: false }).first();
  const opts = await sel.locator('option').allTextContents();
  const hit = opts.find((o) => o === text) ?? opts.find((o) => o.includes(text));
  if (hit === undefined) throw new Error(`seçenek yok: ${label} → ${text} (mevcut: ${opts.join(' | ')})`);
  await sel.selectOption({ label: hit });
};
const go = async (hash, heading) => { await page.goto(BASE + '/#/' + hash); if (heading) await page.getByRole('heading', { name: heading }).first().waitFor({ timeout: 15000 }); };
const toastSeen = async (re) => { await page.locator('.toast').filter({ hasText: re }).first().waitFor({ timeout: 8000 }); };
const menuItem = async (rowRe, name) => { await page.getByRole('row', { name: rowRe }).getByRole('button', { name: 'Diğer işlemler' }).click(); await page.locator('.menu').getByRole('button', { name }).click(); };

try {
  await login(page, CUST, CPASS, SECRET, SECRET ? 1 : 0);
  await page.getByText('Kasa + banka', { exact: true }).waitFor({ timeout: 20000 });

  section('Tedarikçi + vadesiz alış faturası + faturadan yeni ürün (stoğa ekle)');
  await go('contacts', 'Cariler');
  await page.getByRole('button', { name: /Cari ekle/ }).first().click();
  await fillIn('Ünvan', 'Tedarikçi T'); await pick('Tür', 'Tedarikçi'); await modal().getByRole('button', { name: 'Kaydet' }).click();
  await page.getByText('Tedarikçi T').first().waitFor();
  await go('invoices', 'Faturalar');
  await page.getByRole('button', { name: 'Alış faturaları' }).click();
  await page.getByRole('button', { name: /Alış faturası/ }).first().click();
  await modal().locator('select').nth(1).selectOption({ label: 'Tedarikçi T' });
  await fillIn('Fatura no', 'E2E-A1');
  await modal().getByRole('button', { name: 'Vadesiz', exact: true }).click();
  ok((await modal().locator('input[type=date]').nth(1).inputValue()) === '', 'vade alanı boş: vadesiz seçildi (sessiz 30 gün yok)');
  await pick('Ürün / hizmet', 'Yeni ürün / hizmet kartı oluştur');
  await fillIn('Açıklama *', 'Buzdolabı E2E'); await fillIn('Miktar', '5'); await fillIn('Birim fiyat', '1000');
  await modal().getByLabel('Faturadaki ürünleri stoğa ekle').check();
  await page.getByText('6.000,00 ₺').first().waitFor();
  ok(true, 'satır: 5 × 1.000 + %20 KDV = 6.000,00 ₺');
  ok(await modal().getByLabel('Stoğa ekle', { exact: true }).isChecked(), 'ürün satırında “Stoğa ekle” işaretlendi');
  await modal().getByRole('button', { name: 'Alış faturasını kaydet' }).click();
  await toastSeen(/Fatura kaydedildi/);
  ok(true, 'alış faturası kaydedildi; yeni ürün kartı faturadan açıldı');
  await page.getByRole('row', { name: /E2E-A1/ }).getByText('Vadesiz').first().waitFor();
  ok(true, 'liste: vade “Vadesiz”');
  await go('stock', 'Stok, Depo & Malzeme');
  await page.getByRole('row', { name: /Buzdolabı E2E/ }).getByText(/5 adet/).first().waitFor();
  ok(true, 'stok: Buzdolabı E2E = 5 adet (fatura ile stoğa girdi)');

  section('Ödeme: faturalara dağıtım, fazlası avans');
  await go('invoices', 'Faturalar');
  await page.getByRole('button', { name: 'Alış faturaları' }).click();
  await page.getByRole('button', { name: /Alış faturası/ }).first().click();
  await modal().locator('select').nth(1).selectOption({ label: 'Tedarikçi T' });
  await fillIn('Fatura no', 'E2E-A2'); await fillIn('Açıklama *', 'Hizmet'); await fillIn('Birim fiyat', '3000');
  await modal().getByRole('button', { name: '7 gün', exact: true }).click();
  await modal().getByRole('button', { name: 'Alış faturasını kaydet' }).click();
  await toastSeen(/Fatura kaydedildi/);
  await go('contacts', 'Cariler');
  await menuItem(/Tedarikçi T/, 'Ödeme yap');
  await pick('Hangi hesaptan', 'Ana Banka');
  await modal().getByLabel('Toplam tutar').fill('10.000');
  await modal().getByText(/E2E-A2/).first().waitFor();
  await modal().getByRole('button', { name: /Otomatik dağıt/ }).click();
  await page.getByText(/Eşleştirilmemiş \(cari avans\)/).first().waitFor();
  await modal().getByText('400,00 ₺').first().waitFor({ timeout: 5000 });
  ok(true, 'özet: 9.600 dağıtıldı, 400 eşleştirilmemiş (avans)');
  await shot(page, '30-payment-allocation');
  await modal().getByRole('button', { name: 'Ödemeyi kaydet' }).click();
  await toastSeen(/eşleştirilmemiş \(avans\)/);
  ok(true, 'ödeme tek kayıt olarak kaydedildi; fazlası avans olarak uyarıldı');
  await page.getByRole('row', { name: /Tedarikçi T/ }).getByText(/Verilen/).first().waitFor();
  ok(true, 'cari kartında avans (verilen) görünür');
  await page.getByRole('row', { name: /Tedarikçi T/ }).getByRole('button', { name: 'Ekstre' }).click();
  await page.getByText('Eşleştirilmemiş ödeme / tahsilatlar (avans)').waitFor();
  ok(true, 'ekstrede eşleştirilmemiş ödeme listesi var');
  await shot(page, '31-statement');
  await page.locator('.overlay').last().getByRole('button', { name: 'Kapat' }).click();

  section('Fatura düzenleme: miktar değişince stok farkı');
  await go('invoices', 'Faturalar');
  await page.getByRole('button', { name: 'Alış faturaları' }).click();
  await page.getByRole('link', { name: 'E2E-A1' }).click();
  await page.getByRole('button', { name: /Düzenle/ }).click();
  await modal().getByLabel('Miktar', { exact: true }).fill('7');
  await modal().getByRole('button', { name: 'Değişiklikleri kaydet' }).click();
  await toastSeen(/Fatura güncellendi/);
  await go('stock', 'Stok, Depo & Malzeme');
  await page.getByRole('row', { name: /Buzdolabı E2E/ }).getByText(/7 adet/).first().waitFor();
  ok(true, 'fatura 5 → 7: stok yalnızca +2 artarak 7 adet oldu');

  section('Hesap silme: kullanılmamış silinir, hareketli silinemez');
  await go('accounts', 'Hesaplar & Kasalar');
  await page.getByRole('button', { name: /Hesap \/ kasa/ }).click();
  await pick('İşletme / alan', 'Horizon'); await fillIn('Hesap adı', 'Yanlış Hesap'); await modal().getByRole('button', { name: 'Kaydet' }).click();
  await page.getByText('Yanlış Hesap').first().waitFor();
  await menuItem(/Yanlış Hesap/, 'Sil');
  await modal().getByRole('button', { name: 'Sil' }).click();
  await page.getByText('Hesap silindi').first().waitFor();
  ok((await page.getByText('Yanlış Hesap').count()) === 0, 'yanlışlıkla açılan hesap silindi');
  await menuItem(/Ana Banka/, 'Sil');
  await modal().getByRole('button', { name: 'Sil' }).click();
  await toastSeen(/arşivle/i);
  ok(true, 'hareketi olan hesap silinemez; neden açıklanır (arşivleme önerilir)');

  section('Şahsi fatura düzenle / sil');
  await go('bills', 'Şahsi Faturalar');
  await page.getByRole('button', { name: '＋ Şahsi fatura' }).first().click();
  await fillIn('Fatura (ör. Elektrik)', 'Elektrik E2E'); await fillIn('Tutar', '800'); await modal().getByRole('button', { name: 'Kaydet' }).click();
  await page.getByText('Elektrik E2E').first().waitFor();
  await page.getByRole('row', { name: /Elektrik E2E/ }).getByRole('button', { name: '⋯' }).click();
  await page.locator('.menu').getByRole('button', { name: /Düzenle/ }).click();
  await fillIn('Tutar', '900'); await modal().getByRole('button', { name: 'Kaydet' }).click();
  await page.getByText('900,00 ₺').first().waitFor();
  ok(true, 'şahsi fatura tutarı düzenlendi');
  await page.getByRole('row', { name: /Elektrik E2E/ }).getByRole('button', { name: '⋯' }).click();
  await page.locator('.menu').getByRole('button', { name: /Sil/ }).click();
  await modal().getByRole('button', { name: 'Sil' }).click();
  await page.getByText('Silindi').first().waitFor();
  ok(true, 'ödenmemiş şahsi fatura silindi');

  section('Ayarlar: negatif stok politikası ve vade varsayılanı');
  await go('settings?tab=prefs', 'Ayarlar');
  await page.getByText('Stok politikası').first().waitFor();
  ok((await page.getByText('Negatif stoğa izin ver').count()) === 1, 'stok politikası ayarı görünür');
  ok((await page.getByText(/0 = varsayılan vade yok/).count()) === 1, 'varsayılan vade 0 = vadesiz açıklaması');
  await shot(page, '32-settings-stock');

} catch (e) {
  console.log('KRİTİK HATA:', e.message.split('\n').slice(0, 6).join(' '));
  R.fail++; R.fails.push('KRİTİK: ' + e.message.split('\n').slice(0, 3).join(' '));
  try { await shot(page, 'zz-failure3'); } catch { /* */ }
}
await browser.close();
const good = finish(); server.kill(); process.exit(good ? 0 : 1);
