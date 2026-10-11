// Service worker for WhatsApp message alerts (Web Push). The engine pushes each new incoming message;
// this shows it even when no panel tab is open. Lives at the site root so its scope covers the whole panel.
self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', (e) => e.waitUntil(self.clients.claim()));

self.addEventListener('push', (e) => {
    let d = {};
    try { d = e.data ? e.data.json() : {}; } catch (x) {}
    e.waitUntil(self.clients.matchAll({type: 'window', includeUncontrolled: true}).then((tabs) => {
        // a panel tab on screen shows its own alert (toast + sound)
        if (tabs.some((t) => t.visibilityState === 'visible' && new URL(t.url).pathname.startsWith('/app'))) return;
        return self.registration.showNotification(d.title || 'নতুন WhatsApp মেসেজ', {
            body: d.body || '', tag: d.tag || 'wa', renotify: true, icon: '/favicon.ico', data: {url: d.url || '/app'},
        });
    }));
});

self.addEventListener('notificationclick', (e) => {
    e.notification.close();
    const url = new URL((e.notification.data && e.notification.data.url) || '/app', self.location.origin).href;
    e.waitUntil(self.clients.matchAll({type: 'window', includeUncontrolled: true}).then((tabs) => {
        const tab = tabs.find((t) => new URL(t.url).pathname.startsWith('/app'));
        if (tab) return tab.focus().then((t) => (t || tab).navigate(url));
        return self.clients.openWindow(url);
    }));
});
