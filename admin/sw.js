/* BIER_BUILD:20261016f — Admin notifications service worker */
const API = new URL(self.location).searchParams.get('api') || '../backend/api';
const BASE = self.registration.scope;
try { importScripts('i18n-en.js'); } catch (e) {}
async function swLang() { try { const c = await caches.open('bier-lang'); const r = await c.match('/lang'); return r ? (await r.text()) : 'am'; } catch (e) { return 'am'; } }
self.addEventListener('message', e => { if (e.data && e.data.type === 'lang') e.waitUntil(caches.open('bier-lang').then(c => c.put('/lang', new Response(e.data.lang)))); });

// Pass-through fetch handler (makes the admin installable; never caches, so updates are always fresh)
self.addEventListener('fetch', () => {});

self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', e => e.waitUntil(self.clients.claim()));

self.addEventListener('push', event => {
  event.waitUntil((async () => {
    let items = [];
    try {
      const r = await fetch(API + '/feed.php?action=latest', { credentials: 'include', cache: 'no-store' });
      const j = await r.json(); if (j && j.ok) items = j.items || [];
    } catch (e) {}
    if (!items.length) items = [{ type: 'generic', id: Date.now(), title: 'Bier Creative', body: 'አዲስ እንቅስቃሴ አለ', tab: '' }];
    const en = (await swLang()) === 'en' && self.bierTr;
    for (const it of items) {
      if (en) { it.title = self.bierTr(it.title); it.body = self.bierTr(it.body); }
      await self.registration.showNotification(it.title, {
        body: it.body, tag: it.type + '-' + it.id, renotify: true, vibrate: [200, 100, 200, 100, 300],
        icon: BASE + 'bier_1.png', badge: BASE + 'bier_1.png', data: { tab: it.tab }
      });
    }
    const cl = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
    cl.forEach(c => c.postMessage({ type: 'chime' }));
  })());
});

self.addEventListener('notificationclick', event => {
  event.notification.close();
  const tab = (event.notification.data && event.notification.data.tab) || '';
  event.waitUntil((async () => {
    const cl = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
    if (cl.length) { const c = cl[0]; await c.focus(); c.postMessage({ type: 'goto', tab }); return; }
    await self.clients.openWindow(BASE + (tab ? '?tab=' + tab : ''));
  })());
});
