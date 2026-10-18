import { api } from '../api.js';
import { state, accOptions, catOptions } from '../state.js';
import { h, mount, loading, money, date, table, errToast, toast, parseMoney, formModal, emptyState, seg, plain } from '../ui.js';
import { loadMeta } from '../state.js';

function detectDelim(line) { const c = { ';': 0, ',': 0, '\t': 0 }; let q = false; for (const ch of line) { if (ch === '"') q = !q; else if (!q && ch in c) c[ch]++; } return Object.entries(c).sort((a, b) => b[1] - a[1])[0][0]; }
export function parseCsv(text) {
  text = text.replace(/^﻿/, '');
  const first = text.split(/\r?\n/).find((l) => l.trim()) || '';
  const d = detectDelim(first); const rows = []; let row = []; let cur = ''; let q = false;
  for (let i = 0; i < text.length; i++) {
    const ch = text[i];
    if (q) { if (ch === '"') { if (text[i + 1] === '"') { cur += '"'; i++; } else q = false; } else cur += ch; }
    else if (ch === '"') q = true;
    else if (ch === d) { row.push(cur); cur = ''; }
    else if (ch === '\n' || ch === '\r') { if (ch === '\r' && text[i + 1] === '\n') i++; row.push(cur); cur = ''; if (row.some((c) => c.trim() !== '')) rows.push(row); row = []; }
    else cur += ch;
  }
  row.push(cur); if (row.some((c) => c.trim() !== '')) rows.push(row);
  return rows;
}
export function parseDate(t) {
  t = String(t || '').trim();
  let m = /^(\d{4})-(\d{2})-(\d{2})/.exec(t); if (m) return `${m[1]}-${m[2]}-${m[3]}`;
  m = /^(\d{1,2})[./](\d{1,2})[./](\d{4})/.exec(t); if (m) return `${m[3]}-${m[2].padStart(2, '0')}-${m[1].padStart(2, '0')}`;
  return null;
}

export default async function render(ctx) {
  const { el, readonly } = ctx;
  const accounts = state.meta.accounts.filter((a) => !a.archived);
  let accId = Number(ctx.query.account || (accounts[0] && accounts[0].id) || 0);
  const out = h('div', { class: 'stack' });
  let status = 'unmatched';

  const accSel = h('select', { class: 'input', onchange: (e) => { accId = Number(e.target.value); load(); } }, accounts.map((a) => h('option', { value: a.id, selected: a.id === accId }, `${a.workspace_name} › ${a.name} (${a.currency})`)));
  const file = h('input', { type: 'file', accept: '.csv,.txt', class: 'hide', onchange: () => file.files[0] && importFile(file.files[0]) });
  mount(el, ctx.head('Banka Mutabakatı', 'Banka ekstresini yükleyin, hareketleri sistemdeki kayıtlarla eşleştirin. Aynı ekstre tekrar yüklenirse mükerrer kayıt oluşmaz.',
    readonly ? null : [h('button', { class: 'btn primary', onclick: () => file.click() }, '⬆ Ekstre yükle (CSV)'), file]),
    accounts.length ? h('div', { class: 'filters' }, h('div', { class: 'field' }, h('label', null, 'Hesap'), accSel)) : null, out);
  if (!accounts.length) return mount(out, emptyState({ icon: '🏦', title: 'Hesap yok', text: 'Önce bir banka hesabı ekleyin.' }));

  function importFile(f) {
    const reader = new FileReader();
    reader.onload = () => {
      const rows = parseCsv(String(reader.result));
      if (rows.length < 2) return toast('Dosyada veri bulunamadı.', 'err');
      const head = rows[0].map((x) => x.trim());
      const guess = (re, def) => { const i = head.findIndex((x) => re.test(x.toLowerCase())); return i >= 0 ? i : def; };
      const opts = head.map((x, i) => [i, `${i + 1}. ${x || '(boş)'}`]);
      const opt0 = [['-1', '— yok —'], ...opts];
      const m = formModal({
        title: 'Sütunları eşleştirin', wide: true, submit: 'İçe aktar',
        note: `${rows.length - 1} satır bulundu. Tutar tek sütunda işaretli ise “Tutar”ı, ayrı sütunlarda ise “Borç/Alacak” sütunlarını seçin. Tarih biçimleri: GG.AA.YYYY, GG/AA/YYYY, YYYY-AA-GG.`,
        fields: [
          { name: 'dcol', label: 'Tarih sütunu', type: 'select', options: opts, value: guess(/tarih|date/, 0), required: true },
          { name: 'tcol', label: 'Açıklama sütunu', type: 'select', options: opts, value: guess(/açıklama|aciklama|desc|detay/, 1), required: true },
          { name: 'acol', label: 'Tutar sütunu (işaretli)', type: 'select', options: opt0, value: guess(/^tutar|amount|miktar/, -1) },
          { name: 'ocol', label: 'Çıkış / Borç sütunu', type: 'select', options: opt0, value: guess(/borç|borc|çıkış|cikis|debit/, -1) },
          { name: 'icol', label: 'Giriş / Alacak sütunu', type: 'select', options: opt0, value: guess(/alacak|giriş|giris|credit/, -1) },
        ],
        extra: h('div', { class: 'small muted' }, 'Örnek ilk satır: ' + (rows[1] || []).join(' | ')),
        onSubmit: async (v) => {
          const di = Number(v.dcol); const ti = Number(v.tcol); const ai = Number(v.acol); const oi = Number(v.ocol); const ii = Number(v.icol);
          const lines = []; let bad = 0;
          for (const r of rows.slice(1)) {
            const d = parseDate(r[di]); let a = null;
            if (ai >= 0) a = parseMoney(r[ai]);
            else { const o = oi >= 0 ? parseMoney(r[oi]) : null; const i = ii >= 0 ? parseMoney(r[ii]) : null; if (o || i) a = (i || 0) - Math.abs(o || 0); }
            if (!d || a === null || a === 0) { bad++; continue; }
            lines.push({ date: d, description: (r[ti] || '').trim().slice(0, 300), amount_minor: a });
          }
          if (!lines.length) throw new Error('Geçerli satır bulunamadı; sütun seçimlerini kontrol edin.');
          const res = await api.post(`/accounts/${accId}/statement`, { lines });
          toast(`${res.added} yeni satır eklendi, ${res.duplicates} tekrar atlandı${bad ? `, ${bad} geçersiz satır yok sayıldı` : ''}.`, 'ok');
          load();
        },
      });
    };
    reader.readAsText(f, 'utf-8');
    file.value = '';
  }

  async function load() {
    mount(out, loading());
    try {
      const [s, l] = await Promise.all([api.get(`/accounts/${accId}/reconciliation`), api.get('/statement-lines', { account_id: accId, status })]);
      const cnt = Object.fromEntries(s.counts.map((c) => [c.status, c]));
      const cur = state.meta.accounts.find((a) => a.id === accId).currency;
      mount(out,
        h('div', { class: 'auto-grid' },
          h('div', { class: 'card stat' }, h('div', { class: 'label' }, 'Eşleşmemiş'), h('div', { class: 'value' }, String((cnt.unmatched || { n: 0 }).n)), h('div', { class: 'sub' }, money((cnt.unmatched || { s: 0 }).s, cur))),
          h('div', { class: 'card stat' }, h('div', { class: 'label' }, 'Eşleşmiş'), h('div', { class: 'value pos' }, String((cnt.matched || { n: 0 }).n))),
          h('div', { class: 'card stat' }, h('div', { class: 'label' }, 'Yok sayılan'), h('div', { class: 'value' }, String((cnt.ignored || { n: 0 }).n)))),
        seg([['unmatched', 'Eşleşmemiş'], ['matched', 'Eşleşmiş'], ['ignored', 'Yok sayılan']], status, (v) => { status = v; load(); }),
        table({
          cols: [{ key: 'line_date', label: 'Tarih', render: (x) => date(x.line_date) }, { key: 'description', label: 'Ekstre açıklaması' },
            { key: 'amount_minor', label: 'Tutar', num: true, render: (x) => h('span', { class: x.amount_minor > 0 ? 'pos' : 'neg' }, money(x.amount_minor, cur, { sign: true })) },
            { label: 'İşlem', act: true, sortable: false, render: (x) => (readonly ? null : x.status === 'unmatched' ? h('div', { class: 'row tight' },
              ...x.candidates.map((c) => h('button', { class: 'btn sm', title: c.description || '', onclick: async () => { try { await api.post(`/statement-lines/${x.id}/match`, { transaction_id: c.id }); toast('Eşleşti', 'ok'); load(); } catch (e) { errToast(e); } } }, `✓ ${date(c.txn_date)} kaydıyla eşleştir`)),
              h('button', { class: 'btn sm primary', onclick: () => createFrom(x, cur) }, x.amount_minor > 0 ? '＋ Gelir olarak kaydet' : '＋ Gider olarak kaydet'),
              h('button', { class: 'btn sm ghost', onclick: async () => { await api.post(`/statement-lines/${x.id}/ignore`); load(); } }, 'Yok say'))
              : h('button', { class: 'btn sm ghost', onclick: async () => { try { await api.post(`/statement-lines/${x.id}/unmatch`); load(); } catch (e) { errToast(e); } } }, 'Eşleşmeyi kaldır')) }],
          rows: l.lines, empty: { icon: '✅', title: status === 'unmatched' ? 'Eşleşmemiş satır yok' : 'Kayıt yok', text: 'Ekstre yüklemek için “Ekstre yükle (CSV)” düğmesini kullanın.' },
        }),
        s.unreconciled_transactions.length ? h('div', { class: 'card' }, h('div', { class: 'card-title' }, 'Ekstrede karşılığı bulunmayan sistem hareketleri'), h('div', { class: 'muted small' }, 'Ekstre tarih aralığında olup henüz bir ekstre satırıyla eşleşmemiş kayıtlar:'),
          h('div', { class: 'list' }, s.unreconciled_transactions.map((t) => h('div', { class: 'list-item' }, h('span', null, date(t.txn_date)), h('div', { class: 'grow' }, t.description || t.kind), h('b', { class: t.direction === 'in' ? 'pos' : 'neg' }, money(t.direction === 'in' ? t.amount_minor : -t.amount_minor, cur, { sign: true })))))) : null);
    } catch (e) { mount(out, h('div', { class: 'alert err' }, e.message)); }
  }
  function createFrom(x, cur) {
    const kind = x.amount_minor > 0 ? 'income' : 'expense';
    formModal({
      title: 'Ekstre satırından kayıt oluştur', note: `${date(x.line_date)} · ${money(x.amount_minor, cur)} · ${x.description || ''}`,
      fields: [{ name: 'category_id', label: 'Kategori', type: 'select', options: catOptions(kind) }, { name: 'description', label: 'Açıklama', value: x.description, full: true }],
      onSubmit: async (v, key) => { await api.post(`/statement-lines/${x.id}/create`, { category_id: v.category_id ? Number(v.category_id) : null, description: v.description }, { idem: key }); await loadMeta(); toast('Hareket oluşturuldu ve eşleşti', 'ok'); load(); },
    });
  }
  load();
}
