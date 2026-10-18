// API istemcisi: oturum çerezi HttpOnly olduğundan JS tarafından okunamaz; CSRF jetonu yalnızca bellekte tutulur (localStorage'a yazılmaz).
let csrf = null;
let tenant = null; // yönetici müşteri görünümünde: salt okunur ?tenant=
const listeners = new Set();

export class ApiError extends Error {
  constructor(status, body) {
    const e = (body && body.error) || {};
    super(e.message || 'İşlem tamamlanamadı.');
    this.status = status;
    this.code = e.code || 'error';
    this.field = e.field || null;
    this.extra = e;
  }
}

export const api = {
  setCsrf(v) { csrf = v; },
  getCsrf() { return csrf; },
  setTenant(id) { tenant = id; },
  getTenant() { return tenant; },
  on(fn) { listeners.add(fn); return () => listeners.delete(fn); },

  async request(method, path, body, opts = {}) {
    let url = '/api' + path;
    if (tenant && method === 'GET') url += (url.includes('?') ? '&' : '?') + 'tenant=' + encodeURIComponent(tenant);
    const headers = { Accept: 'application/json' };
    if (csrf && method !== 'GET') headers['X-CSRF-Token'] = csrf;
    if (opts.idem) headers['Idempotency-Key'] = opts.idem;
    const init = { method, headers, credentials: 'same-origin', cache: 'no-store', redirect: 'error', referrerPolicy: 'no-referrer' };
    if (opts.form) {
      init.body = opts.form;
    } else if (body !== undefined && method !== 'GET') {
      headers['Content-Type'] = 'application/json';
      init.body = JSON.stringify(body);
    }
    let res;
    try {
      res = await fetch(url, init);
    } catch {
      throw new ApiError(0, { error: { code: 'network', message: 'Sunucuya ulaşılamadı. İnternet bağlantınızı kontrol edin.' } });
    }
    if (opts.raw) return res;
    let data = null;
    try { data = await res.json(); } catch { /* JSON değil */ }
    if (!res.ok || !data || data.ok === false) {
      const err = new ApiError(res.status, data);
      for (const l of listeners) l(err);
      throw err;
    }
    return data;
  },
  get(path, params) {
    let p = path;
    if (params) {
      const q = new URLSearchParams();
      for (const [k, v] of Object.entries(params)) if (v !== undefined && v !== null && v !== '') q.set(k, String(v));
      const s = q.toString();
      if (s) p += (p.includes('?') ? '&' : '?') + s;
    }
    return this.request('GET', p);
  },
  post(path, body, opts) { return this.request('POST', path, body ?? {}, opts); },
  put(path, body, opts) { return this.request('PUT', path, body ?? {}, opts); },
  patch(path, body, opts) { return this.request('PATCH', path, body ?? {}, opts); },
  del(path, opts) { return this.request('DELETE', path, undefined, opts); },
  upload(path, form) { return this.request('POST', path, undefined, { form }); },

  /** Dosya indirme (kimlikli): blob olarak alıp kaydettirir. */
  async download(path, filename) {
    let url = '/api' + path;
    if (tenant) url += (url.includes('?') ? '&' : '?') + 'tenant=' + encodeURIComponent(tenant);
    const res = await fetch(url, { credentials: 'same-origin', cache: 'no-store', referrerPolicy: 'no-referrer' });
    if (!res.ok) {
      let d = null; try { d = await res.json(); } catch { /* */ }
      throw new ApiError(res.status, d);
    }
    const blob = await res.blob();
    const a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = filename;
    document.body.appendChild(a);
    a.click();
    setTimeout(() => { URL.revokeObjectURL(a.href); a.remove(); }, 1500);
  },
};

/** Her form açılışında yeni bir Idempotency-Key üretir; çift tıklama aynı isteği iki kez işletmez. */
export function newKey() {
  const b = new Uint8Array(16);
  crypto.getRandomValues(b);
  return Array.from(b, (x) => x.toString(16).padStart(2, '0')).join('');
}
