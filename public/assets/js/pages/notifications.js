import { api } from '../api.js';
import { h, mount, loading, money, date, emptyState } from '../ui.js';

const ROUTE = { entry: 'plan', card: 'cards', invoice: 'invoices', account: 'accounts', reconcile: 'reconcile', stock: 'stock' };

export default async function render(ctx) {
  const { el } = ctx;
  mount(el, ctx.head('Bildirim Merkezi', 'Vade, gecikme, tahsilat, kart ve fatura hatırlatmaları gerçek kayıtlarınızdan üretilir. Tercihleri Ayarlar sayfasından değiştirebilirsiniz.'), loading());
  const r = await api.get('/reports/notifications');
  const list = r.notifications;
  mount(el, ctx.head('Bildirim Merkezi', 'Vade, gecikme, tahsilat, kart ve fatura hatırlatmaları gerçek kayıtlarınızdan üretilir.'),
    list.length ? h('div', { class: 'card' }, h('div', { class: 'list' }, list.map((n) => h('div', { class: 'list-item' },
      h('span', { class: 'dot ' + ({ danger: 'danger', warn: 'warn', info: 'info' })[n.level] }),
      h('div', { class: 'grow' }, h('a', { href: ctx.href(ROUTE[n.ref.type] || 'dashboard') }, n.title), h('div', { class: 'xs muted' }, date(n.date))),
      n.amount_minor ? h('b', null, money(n.amount_minor, n.currency)) : null)))) : emptyState({ icon: '🎉', title: 'Her şey yolunda', text: 'Şu an dikkat gerektiren bir bildirim yok.' }));
}
