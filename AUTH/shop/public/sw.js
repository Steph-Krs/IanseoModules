/* Service worker of the customer pages (public/): shows the "order ready" notification the
   server sends through the push service of the browser, also when the page is closed or the
   phone locked, and opens the order page when it is touched. Its texts come from the server,
   already in the customer's language. */
self.addEventListener('install', function () { self.skipWaiting(); });
self.addEventListener('activate', function (e) { e.waitUntil(self.clients.claim()); });

self.addEventListener('push', function (e) {
    var d = {};
    try { d = e.data ? e.data.json() : {}; } catch (x) { d = {}; }
    if (!d.title) return;
    e.waitUntil(self.registration.showNotification(d.title, {
        body: d.body || '', tag: d.tag || 'shp', renotify: true, requireInteraction: true,
        vibrate: [300, 150, 300, 150, 300], icon: 'icon.php?s=192', badge: 'icon.php?s=96',
        data: { url: d.url || 'index.php' }
    }));
});

self.addEventListener('notificationclick', function (e) {
    e.notification.close();
    var url = (e.notification.data && e.notification.data.url) || 'index.php';
    e.waitUntil(self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function (list) {
        for (var i = 0; i < list.length; i++) {
            if (list[i].url.indexOf('/shop/public/') >= 0 && 'focus' in list[i]) {
                if ('navigate' in list[i]) list[i].navigate(url);
                return list[i].focus();
            }
        }
        return self.clients.openWindow(url);
    }));
});
