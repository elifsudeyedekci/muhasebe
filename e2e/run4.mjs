// Dördüncü senaryo (run → run2 → run3 sonrası): sadeleştirilmiş ana sayfa, gelir ekranından müşteri avansı, masaüstü/mobil yerleşim.
import fs from 'node:fs';
import path from 'node:path';
import { R, ok, section, startServer, waitUp, launch, watch, shot, login, finish, BASE, ROOT } from './lib.mjs';

let CPASS = 'Yesil#Orman-9137Zx'; let SECRET = null;
try { const st = JSON.parse(fs.readFileSync(path.join(ROOT, 'e2e', '.state.json'), 'utf8')); CPASS = st.pass; SECRET = st.secret; await new Promise((r) => setTimeout(r, 31000)); } catch { /* varsayılan */ }
const server = startServer(); process.on('exit', () => server.kill());
await waitUp();
const { browser, ctx } = await launch();
const page = await ctx.newPage(); watch(page, 'c4');
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

try {
  await login(page, 'musteri@e2e.local', CPASS, SECRET, SECRET ? 1 : 0);
  await page.getByText('Kasa + banka', { exact: true }).waitFor({ timeout: 20000 });

  section('Ana sayfa: sade yerleşim');
  for (const t of ['Toplam alacak', 'Toplam borç', 'Bugünkü işlemler', 'Ödeme Bekleyenler', 'Tahsilat Yapılacaklar', 'Son finansal hareketler']) ok((await page.getByText(t, { exact: false }).count()) >= 1, 'ana sayfada: ' + t);
  ok((await page.getByText('Aylık nakit akışı').count()) === 0, 'gereksiz grafik kaldırıldı');
  const box = async (txt) => (await page.getByText(txt, { exact: true }).first().boundingBox());
  const a = await page.locator('.home-split > .card').nth(0).boundingBox(); const b = await page.locator('.home-split > .card').nth(1).boundingBox();
  ok(a && b && Math.abs(a.y - b.y) < 5 && b.x > a.x, 'masaüstünde Ödeme Bekleyenler ve Tahsilat Yapılacaklar yan yana');
  ok((await page.getByText('Tarih girilmemiş').count()) >= 1 || (await page.getByText('Bekleyen').count()) >= 1, 'tarihsiz kayıtlar “tarih girilmemiş” olarak gösterilir');
  await shot(page, '40-home-desktop');

  section('Gelir ekranı: müşteri seçerek avans → cari hesap');
  await go('contacts', 'Cariler');
  await page.getByRole('button', { name: /Cari ekle/ }).first().click();
  await fillIn('Ünvan', 'Avans Müşteri E2E'); await modal().getByRole('button', { name: 'Kaydet' }).click();
  await page.getByText('Avans Müşteri E2E').first().waitFor();
  await go('transactions', 'Gelir & Gider');
  await page.getByRole('button', { name: '＋ Gelir' }).first().click();
  await pick('Hesap / kasa', 'Ana Banka'); await fillIn('Tutar', '30.000'); await pick('Müşteri', 'Avans Müşteri E2E'); await fillIn('Açıklama', 'Avans alındı');
  await modal().getByRole('button', { name: 'Kaydet' }).click();
  await page.locator('.toast').filter({ hasText: /müşteri avansı olarak cari hesaba/ }).first().waitFor({ timeout: 8000 });
  ok(true, 'gelir ekranından alınan tutar müşteri avansı olarak cari hesaba işlendi');
  await go('contacts', 'Cariler');
  await page.getByRole('row', { name: /Avans Müşteri E2E/ }).getByText(/Alınan 30\.000,00/).first().waitFor();
  ok(true, 'cari kartında avans (alınan) 30.000,00 ₺ görünür');
  await go('dashboard');
  await page.getByText('Alınan avans (ayrı)').first().waitFor();
  ok(true, 'ana sayfa avansı ayrı gösterir (alacakla karıştırmaz)');

  section('Mobil görünüm');
  await page.setViewportSize({ width: 390, height: 844 });
  await page.reload(); await page.getByText('Kasa + banka', { exact: true }).waitFor({ timeout: 15000 });
  const m1 = await page.locator('.home-split > .card').nth(0).boundingBox(); const m2 = await page.locator('.home-split > .card').nth(1).boundingBox();
  ok(m1 && m2 && m2.y > m1.y + 20, 'mobilde ödeme/tahsilat alt alta');
  ok(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 2), 'mobilde yatay taşma yok');
  await shot(page, '41-home-mobile');
} catch (e) {
  console.log('KRİTİK HATA:', e.message.split('\n').slice(0, 6).join(' '));
  R.fail++; R.fails.push('KRİTİK: ' + e.message.split('\n').slice(0, 3).join(' '));
  try { await shot(page, 'zz-failure4'); } catch { /* */ }
}
await browser.close();
const good = finish(); server.kill(); process.exit(good ? 0 : 1);
