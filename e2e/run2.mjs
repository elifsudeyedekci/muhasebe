// İkinci senaryo: run.mjs'in bıraktığı veriyle (musteri@e2e.local) kart, kredi, proje, stok, ekstre, 2FA vb. formları dener.
import fs from 'node:fs';
import path from 'node:path';
import { R, ok, section, startServer, waitUp, launch, watch, shot, login, finish, totp, BASE, ROOT } from './lib.mjs';

const CUST = 'musteri@e2e.local'; const CPASS = 'Yesil#Orman-9137Zx';
const server = startServer(); process.on('exit', () => server.kill());
await waitUp();
const { browser, ctx } = await launch();
const page = await ctx.newPage(); watch(page, 'c2');
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
const save = async (name = 'Kaydet') => { await modal().getByRole('button', { name }).click(); };
const closeTop = async () => { await page.locator('.overlay').last().getByRole('button', { name: 'Kapat' }).click(); };
const toastSeen = async (re) => { await page.locator('.toast').filter({ hasText: re }).first().waitFor({ timeout: 8000 }); };

try {
  await login(page, CUST, CPASS);
  await page.getByText('Kasa + banka', { exact: true }).waitFor({ timeout: 20000 });

  section('Kredi kartı');
  await go('cards', 'Kartlar & Krediler');
  await page.getByRole('button', { name: '＋ Kart', exact: true }).click();
  await pick('Alan', 'Horizon'); await fillIn('Kart adı', 'Kurumsal Kart'); await fillIn('Kart limiti', '50.000'); await save();
  await page.getByText('Kurumsal Kart').first().waitFor();
  await page.getByRole('button', { name: '＋ Alışveriş' }).click();
  await fillIn('Açıklama', 'Ofis malzemesi'); await fillIn('Tutar', '1.200'); await modal().getByLabel('Taksit').fill('3'); await pick('Kategori', 'Malzeme'); await pick('KDV oranı', '%20'); await save();
  await page.getByText('1.200,00 ₺').first().waitFor();
  ok(true, 'kart: taksitli alışveriş kaydedildi, borç 1.200,00 ₺');
  await page.getByRole('button', { name: 'Ayrıntı' }).click();
  await page.getByText('Ekstre dönemleri').waitFor();
  ok((await modal().locator('tbody tr').count()) >= 3, 'kart ekstre dönemleri (3 taksit) görünür');
  await shot(page, '20-card-detail');
  await closeTop();
  await page.getByRole('button', { name: 'Öde' }).first().click();
  await pick('Ödeme hesabı', 'Ana Banka'); await fillIn('Tutar', '400'); await save();
  await page.getByText('800,00 ₺').first().waitFor();
  ok(true, 'kart ödemesi sonrası borç 800,00 ₺');

  section('Kredi');
  await page.getByRole('button', { name: 'Banka kredileri' }).click();
  await page.getByRole('button', { name: '＋ Kredi', exact: true }).click();
  await pick('Alan', 'Horizon'); await fillIn('Kredi adı', 'İşletme Kredisi'); await fillIn('Kredi tutarı', '120.000'); await modal().getByLabel('Aylık faiz').fill('2,5'); await modal().getByLabel('Vade (ay)').fill('12'); await save();
  await page.getByText('İşletme Kredisi').first().waitFor();
  await page.getByRole('button', { name: 'Taksit planı' }).click();
  await page.getByText('Kullandırımı kaydet').click();
  await pick('Hangi hesaba', 'Ana Banka'); await save();
  await page.getByText('Toplam anapara').waitFor();
  await page.waitForTimeout(500);
  ok((await modal().locator('tbody tr').count()) === 12, 'kredi: 12 taksitlik plan, kullandırım sonrası detay yenilendi');
  ok((await page.getByText('Kullandırımı kaydet').count()) === 0, 'kullandırım uyarısı kalktı');
  await modal().getByRole('button', { name: 'Öde' }).first().click();
  await pick('Ödeme hesabı', 'Ana Banka'); await save();
  await page.getByText(/Ödendi \d/).first().waitFor({ timeout: 8000 });
  ok(true, 'kredi taksiti ödendi, plan güncellendi');
  await shot(page, '21-loan');
  await page.keyboard.press('Escape');

  section('Proje');
  await go('projects', 'Projeler & İşler');
  await page.getByRole('button', { name: '＋ Proje', exact: true }).first().click();
  await fillIn('Proje adı', 'Santral Kurulumu'); await pick('Müşteri', 'Müşteri A Ltd'); await fillIn('Sözleşme tutarı', '50.000'); await fillIn('Tahmini maliyet', '30.000'); await save();
  await page.getByText('Santral Kurulumu').first().waitFor();
  await page.getByRole('button', { name: 'Aç' }).first().click();
  await page.getByText('Gelir (tahakkuk)').waitFor();
  await page.getByRole('button', { name: '＋ Hakediş' }).click();
  await fillIn('Başlık', '1. Hakediş'); await fillIn('Tutar', '20.000'); await modal().getByRole('button', { name: 'Kaydet' }).click();
  await page.getByText('1. Hakediş').first().waitFor();
  ok(true, 'proje + hakediş planı');
  await shot(page, '22-project');
  await page.keyboard.press('Escape');

  section('Stok');
  await go('stock', 'Stok, Depo & Malzeme');
  await page.getByRole('button', { name: '＋ Depo' }).click(); await fillIn('Depo adı', 'Ana Depo'); await save();
  await page.getByRole('button', { name: '＋ Ürün / hizmet' }).click(); await fillIn('Ad', 'Kablo 10m'); await fillIn('Ürün kodu', 'KBL-1'); await save();
  await page.getByText('Kablo 10m').first().waitFor();
  await page.getByRole('button', { name: '↕ Stok hareketi' }).click();
  await pick('Ürün', 'Kablo'); await pick('Depo', 'Ana Depo'); await fillIn('Miktar', '10'); await fillIn('Birim maliyet', '100'); await save();
  await page.getByText('10 adet').first().waitFor();
  ok(true, 'stok girişi: 10 adet');
  await shot(page, '23-stock');

  section('Şahsi fatura, tekrarlayan, ekstre, cari ekstre');
  await go('bills', 'Şahsi Faturalar');
  await page.getByRole('button', { name: '＋ Şahsi fatura' }).first().click();
  await fillIn('Fatura (ör. Elektrik)', 'Elektrik'); await fillIn('Sağlayıcı', 'Enerjisa'); await fillIn('Tutar', '800'); await save();
  await page.getByText('Elektrik').first().waitFor();
  ok(true, 'şahsi fatura eklendi');
  await go('plan?tab=recurring');
  await page.getByRole('button', { name: 'Tekrarlayanlar' }).click();
  await page.getByRole('button', { name: '＋ Tekrarlayan şablon' }).click();
  await fillIn('Başlık', 'Ofis kirası'); await fillIn('Tutar', '5.000'); await save();
  await page.getByText('Ofis kirası').first().waitFor();
  ok(true, 'tekrarlayan şablon oluşturuldu');
  await page.getByRole('button', { name: 'Takvim' }).click();
  await page.locator('.cal').waitFor();
  ok(true, 'ödeme takvimi (ay görünümü) açıldı');
  await shot(page, '24-calendar');

  await go('reconcile', 'Banka Mutabakatı');
  const csv = path.join(ROOT, 'e2e', 'ekstre.csv');
  const today = new Intl.DateTimeFormat('en-CA', { timeZone: 'Europe/Istanbul' }).format(new Date()).split('-').reverse().join('.');
  fs.writeFileSync(csv, `Tarih;Açıklama;Tutar\n${today};POS TAHSILAT;250,00\n${today};KOMISYON;-5,00\n${today};KOMISYON;-5,00\n`);
  await page.locator('input[type=file]').setInputFiles(csv);
  await page.getByText('Sütunları eşleştirin').waitFor();
  await modal().getByRole('button', { name: 'İçe aktar' }).click();
  await toastSeen(/3 yeni satır/);
  ok(true, 'banka ekstresi: 3 satır içe aktarıldı');
  await page.locator('input[type=file]').setInputFiles(csv);
  await modal().getByRole('button', { name: 'İçe aktar' }).click();
  await toastSeen(/0 yeni satır eklendi, 3 tekrar atlandı/);
  ok(true, 'aynı ekstre tekrar yüklenince mükerrer oluşmaz (3 tekrar atlandı)');
  await page.getByRole('button', { name: /Gelir olarak kaydet/ }).first().click();
  await save(); await toastSeen(/Hareket oluşturuldu/);
  ok(true, 'ekstre satırından hareket oluşturuldu ve eşleşti');
  await shot(page, '25-reconcile');

  await go('contacts', 'Cariler');
  await page.getByRole('button', { name: 'Ekstre' }).first().click();
  await page.getByText('Cari ekstre —').first().waitFor();
  await page.getByText('Kapanış bakiyesi').first().waitFor();
  ok(true, 'cari ekstre açıldı');
  await shot(page, '26-statement');
  await closeTop();

  section('Raporlar & dışa aktarma');
  await go('reports', 'Raporlar');
  const [dl] = await Promise.all([page.waitForEvent('download'), page.getByRole('button', { name: '⬇ CSV' }).click()]);
  ok(/\.csv$/.test(dl.suggestedFilename()), 'rapor CSV indirildi: ' + dl.suggestedFilename());
  const f = await dl.path(); const txt = fs.readFileSync(f, 'utf8');
  ok(txt.startsWith('﻿') && txt.includes(';'), 'CSV UTF-8 BOM + ; ayraç (Excel uyumlu)');
  const [dl2] = await Promise.all([page.waitForEvent('download'), page.getByRole('button', { name: '⬇ Excel' }).click()]);
  ok(/\.xlsx$/.test(dl2.suggestedFilename()), 'rapor XLSX indirildi');

  section('Ayarlar: tercihler, güvenlik');
  await go('settings?tab=prefs', 'Ayarlar');
  await page.locator('.card select').first().selectOption('dark');
  await page.getByRole('button', { name: 'Kaydet' }).click();
  await toastSeen(/Tercihler kaydedildi/);
  ok(await page.evaluate(() => document.documentElement.getAttribute('data-theme')) === 'dark', 'koyu tema uygulanır');
  await shot(page, '27-dark');
  await page.locator('.card select').first().selectOption('auto');
  await page.getByRole('button', { name: 'Kaydet' }).click();
  await go('settings?tab=cats', 'Ayarlar');
  await page.getByText('Gelir kategorileri').waitFor();
  await go('settings?tab=fx', 'Ayarlar');
  await page.getByRole('button', { name: '＋ Kur ekle' }).click(); await fillIn('Kur (TL)', '40,5'); await save();
  await page.getByText('40,50').first().waitFor();
  ok(true, 'kur girildi');

  await go('settings?tab=security', 'Ayarlar');
  await page.getByRole('button', { name: 'Etkinleştir' }).click();
  await page.locator('.modal .mono').first().waitFor({ timeout: 8000 }).catch(() => {});
  const secret = (await modal().locator('.mono').first().textContent()).replace(/\s/g, '');
  await modal().getByLabel('6 haneli kod').fill(totp(secret));
  await modal().getByRole('button', { name: 'Etkinleştir' }).click();
  await page.getByText('Kurtarma kodları').first().waitFor({ timeout: 8000 });
  ok(true, 'müşteri 2FA etkinleştirdi');
  await closeTop();
  // yeni parola
  const NP = 'Turuncu#Gunes-4417Pl';
  const inputs = page.locator('input[type=password]');
  await inputs.nth(0).fill(CPASS); await inputs.nth(1).fill(NP); await inputs.nth(2).fill(NP);
  await page.getByRole('button', { name: 'Parolayı değiştir' }).click();
  await toastSeen(/Parola değiştirildi/);
  ok(true, 'parola değiştirildi (oturum yenilendi)');
  await page.getByRole('button', { name: /▾/ }).click();
  await page.getByRole('button', { name: /Çıkış yap/ }).click();
  await page.getByRole('button', { name: 'Giriş yap' }).waitFor({ timeout: 15000 });
  await login(page, CUST, NP, secret, 1);
  fs.writeFileSync(path.join(ROOT, 'e2e', '.state.json'), JSON.stringify({ pass: NP, secret })); // run3.mjs bu bilgiyle girer
  await page.getByText('Kasa + banka', { exact: true }).waitFor({ timeout: 20000 });
  ok(true, 'yeni parola + 2FA ile giriş');
  await go('notifications', 'Bildirim Merkezi');
  await shot(page, '28-notifications');
} catch (e) {
  R.fail++; R.fails.push('KRİTİK: ' + e.message.split('\n').slice(0, 3).join(' '));
  console.log('KRİTİK HATA:', e.message);
  try { await shot(page, 'zz-failure2'); } catch { /* */ }
}
await browser.close();
const good = finish(); server.kill(); process.exit(good ? 0 : 1);
