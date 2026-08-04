/**
 * Service worker minimal : rend la page installable et survivable à une coupure
 * réseau passagère (4G en salle). Les appels API ne sont JAMAIS mis en cache —
 * une cote périmée serait pire que pas de cote du tout.
 */
const CACHE = 'prono-v1';
const SHELL = ['./', './manifest.json', './icon.svg'];

self.addEventListener('install', e => {
    e.waitUntil(caches.open(CACHE).then(c => c.addAll(SHELL)).then(() => self.skipWaiting()));
});

self.addEventListener('activate', e => {
    e.waitUntil(
        caches.keys()
            .then(ks => Promise.all(ks.filter(k => k !== CACHE).map(k => caches.delete(k))))
            .then(() => self.clients.claim())
    );
});

self.addEventListener('fetch', e => {
    const url = new URL(e.request.url);
    if (e.request.method !== 'GET' || url.pathname.endsWith('/api.php')) return;

    // Réseau d'abord : l'interface reste à jour, le cache ne sert que hors ligne.
    e.respondWith(
        fetch(e.request)
            .then(r => {
                const copy = r.clone();
                caches.open(CACHE).then(c => c.put(e.request, copy)).catch(() => {});
                return r;
            })
            .catch(() => caches.match(e.request).then(r => r || caches.match('./')))
    );
});
