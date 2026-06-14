/**
 * Equine Event Manager — Service Worker (PWA).
 *
 * Network-first strategy for all requests. The SW enables "Add to Home Screen"
 * without aggressive caching that could serve stale admin data.
 */

const CACHE_NAME = 'eem-v1';

self.addEventListener('install', function (event) {
	self.skipWaiting();
});

self.addEventListener('activate', function (event) {
	event.waitUntil(
		caches.keys().then(function (names) {
			return Promise.all(
				names.filter(function (name) { return name !== CACHE_NAME; })
					.map(function (name) { return caches.delete(name); })
			);
		}).then(function () { return self.clients.claim(); })
	);
});

self.addEventListener('fetch', function (event) {
	if (event.request.method !== 'GET') {
		return;
	}

	event.respondWith(
		fetch(event.request).then(function (response) {
			if (response.ok && event.request.url.match(/\.(css|js|png|jpg|svg|woff2?)(\?|$)/)) {
				var clone = response.clone();
				caches.open(CACHE_NAME).then(function (cache) {
					cache.put(event.request, clone);
				});
			}
			return response;
		}).catch(function () {
			return caches.match(event.request);
		})
	);
});
