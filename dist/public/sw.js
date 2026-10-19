// Servis çalışanı: yalnızca statik uygulama kabuğunu önbelleğe alır. /api/ yanıtları ASLA önbelleğe alınmaz (finansal veri cihazda kalmasın).
const VERSION = 'fb-v3';
const SHELL = ['/', '/index.html', '/assets/css/app.css', '/assets/js/app.js', '/assets/js/theme-init.js', '/manifest.webmanifest'];
self.addEventListener('install', (e) => { e.waitUntil(caches.open(VERSION).then((c) => c.addAll(SHELL)).then(() => self.skipWaiting())); });
self.addEventListener('activate', (e) => { e.waitUntil(caches.keys().then((ks) => Promise.all(ks.filter((k) => k !== VERSION).map((k) => caches.delete(k)))).then(() => self.clients.claim())); });
self.addEventListener('fetch', (e) => {
  const req = e.request; const url = new URL(req.url);
  if (req.method !== 'GET' || url.origin !== location.origin || url.pathname.startsWith('/api/') || url.pathname.startsWith('/install')) return;
  if (req.mode === 'navigate') {
    e.respondWith(fetch(req).catch(() => caches.match('/index.html')));
    return;
  }
  // stale-while-revalidate (yalnızca statik dosyalar)
  e.respondWith(caches.open(VERSION).then(async (c) => {
    const hit = await c.match(req);
    const net = fetch(req).then((r) => { if (r.ok && r.type === 'basic') c.put(req, r.clone()); return r; }).catch(() => hit);
    return hit || net;
  }));
});
