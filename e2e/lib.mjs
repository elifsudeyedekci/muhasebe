import { spawn } from 'node:child_process';
import crypto from 'node:crypto';
import fs from 'node:fs';
import path from 'node:path';
import { chromium, devices } from 'playwright';

export const ROOT = path.resolve(import.meta.dirname, '..');
export const PHP = path.join(ROOT, '_tools/php/php.exe');
export const BASE = 'http://127.0.0.1:18100';
export const SHOTS = path.join(ROOT, 'e2e/shots');
fs.mkdirSync(SHOTS, { recursive: true });

export const R = { pass: 0, fail: 0, fails: [], consoleErrors: [] };
export const ok = (c, name, d) => { if (c) { R.pass++; console.log('  ok  ', name); } else { R.fail++; R.fails.push(name + (d ? ' → ' + d : '')); console.log('  FAIL', name, d || ''); } };
export const section = (s) => console.log('\n== ' + s + ' ==');

export function startServer() {
  const env = { ...process.env, FB_CONFIG: path.join(ROOT, 'app/tests/config.e2e.php'), FB_TEST: '1' };
  const p = spawn(PHP, ['-S', '127.0.0.1:18100', '-t', path.join(ROOT, 'public'), path.join(ROOT, 'app/tests/router.php')], { env, stdio: 'ignore' });
  return p;
}
export function seed() { return new Promise((res, rej) => { const p = spawn(PHP, [path.join(ROOT, 'app/tests/e2e_seed.php')], { stdio: 'inherit' }); p.on('exit', (c) => (c === 0 ? res() : rej(new Error('seed failed')))); }); }
export async function waitUp() { for (let i = 0; i < 60; i++) { try { const r = await fetch(BASE + '/'); if (r.ok) return; } catch { /* */ } await new Promise((r) => setTimeout(r, 250)); } throw new Error('server did not start'); }

// ---- TOTP (RFC 6238) ----
const B32 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
function b32dec(s) { let bits = ''; for (const c of s.replace(/\s/g, '').toUpperCase()) bits += B32.indexOf(c).toString(2).padStart(5, '0'); const out = []; for (let i = 0; i + 8 <= bits.length; i += 8) out.push(parseInt(bits.slice(i, i + 8), 2)); return Buffer.from(out); }
export function totp(secret, step = Math.floor(Date.now() / 30000)) {
  const buf = Buffer.alloc(8); buf.writeBigUInt64BE(BigInt(step));
  const h = crypto.createHmac('sha1', b32dec(secret)).update(buf).digest(); const o = h[19] & 15;
  return String(((h[o] & 0x7f) << 24 | h[o + 1] << 16 | h[o + 2] << 8 | h[o + 3]) % 1000000).padStart(6, '0');
}

export async function launch(deviceName) {
  let browser;
  try { browser = await chromium.launch({ headless: true }); } catch { browser = await chromium.launch({ headless: true, channel: 'msedge' }); }
  const opts = deviceName ? { ...devices[deviceName] } : { viewport: { width: 1360, height: 860 }, locale: 'tr-TR', timezoneId: 'Europe/Istanbul' };
  opts.locale = 'tr-TR'; opts.timezoneId = 'Europe/Istanbul';
  const ctx = await browser.newContext(opts);
  return { browser, ctx };
}
export function watch(page, tag) {
  page.on('console', (m) => { if (['error', 'warning'].includes(m.type())) { const t = m.text(); R.consoleErrors.push(`[${tag}] ${m.type()}: ${t}`); } });
  page.on('pageerror', (e) => R.consoleErrors.push(`[${tag}] pageerror: ${e.message}`));
  page.on('requestfailed', (r) => { if (!/favicon/.test(r.url())) R.consoleErrors.push(`[${tag}] requestfailed: ${r.url()} ${r.failure()?.errorText}`); });
}
export async function shot(page, name) { await page.screenshot({ path: path.join(SHOTS, name + '.png'), fullPage: false }); }

export async function login(page, email, pass, secret, offset = 0) {
  await page.goto(BASE + '/#/login');
  await page.getByLabel('E-posta').fill(email);
  await page.getByLabel('Parola', { exact: true }).fill(pass);
  await page.getByLabel('Görseldeki kod').fill('TESTAB');
  await page.getByText('Tarayıcı doğrulaması tamamlandı').waitFor({ timeout: 20000 });
  await page.getByRole('button', { name: 'Giriş yap' }).click();
  if (secret) {
    await page.getByLabel('Doğrulama kodu').waitFor({ timeout: 10000 });
    await page.getByLabel('Doğrulama kodu').fill(totp(secret, Math.floor(Date.now() / 30000) + offset));
    await page.getByRole('button', { name: 'Doğrula' }).click();
  }
}
export const finish = () => {
  console.log('\n==================');
  console.log(`E2E geçen: ${R.pass}  başarısız: ${R.fail}`);
  R.fails.forEach((f) => console.log(' - ' + f));
  const ce = R.consoleErrors.filter((x) => !/Failed to load resource: the server responded with a status of (401|403|404|409|422|429)/.test(x));
  console.log(`Konsol hata/uyarı: ${ce.length}`);
  ce.slice(0, 30).forEach((x) => console.log(' ! ' + x));
  return R.fail === 0 && ce.length === 0;
};

process.on('unhandledRejection', (e) => { if (!/TargetClosed|Target page, context or browser has been closed/.test(String(e && e.message))) { R.fail++; R.fails.push('unhandledRejection: ' + (e && e.message)); } });
