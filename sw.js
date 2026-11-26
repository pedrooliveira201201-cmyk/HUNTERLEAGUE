// Hunter League: guarda só a tela de entrada para abrir mesmo sem internet.
// Páginas com dados (app, admin, api, QR) nunca são guardadas.
const CACHE = 'hl-v6';
const SHELL = ['./', './index.html', './manifest.webmanifest', './icons/icon-192.png', './icons/icon-512.png', './icons/apple-touch-icon.png', './icons/favicon-64.png', './img/crest-640.webp', './img/crest-160.webp'];
self.addEventListener('install', e => { e.waitUntil(caches.open(CACHE).then(c => c.addAll(SHELL)).then(() => self.skipWaiting())); });
self.addEventListener('activate', e => { e.waitUntil(caches.keys().then(ks => Promise.all(ks.filter(k => k !== CACHE).map(k => caches.delete(k)))).then(() => self.clients.claim())); });
self.addEventListener('fetch', e => {
  const req = e.request, url = new URL(req.url);
  if (req.method !== 'GET' || url.origin !== location.origin) return;
  if (/^\/(api|admin|i|app)(\/|$|\.php)/.test(url.pathname) || url.pathname.endsWith('.php')) return;
  const base = new URL('./', self.registration.scope).pathname;
  const isEntrada = url.pathname === base || url.pathname === base + 'index.html';
  if (req.mode === 'navigate') {
    if (isEntrada) { e.respondWith(fetch(req).then(r => { if (r.ok) { const c = r.clone(); caches.open(CACHE).then(k => k.put('./index.html', c)); } return r; }).catch(() => caches.match('./index.html'))); }
    else { e.respondWith(fetch(req).catch(() => caches.match('./index.html'))); }
    return;
  }
  // imagens e ícones: cópia guardada primeiro e guarda o que buscar
  e.respondWith(caches.match(req).then(hit => hit || fetch(req).then(r => { if (r.ok && /\.(webp|png|svg|woff2?)$/.test(url.pathname)) { const c = r.clone(); caches.open(CACHE).then(k => k.put(req, c)); } return r; })));
});
