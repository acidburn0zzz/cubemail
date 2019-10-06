/**
 * PWA service worker
 *
 * @author Christian Mollekopf <mollekopf@kolabsys.com>
 *
 * @licstart  The following is the entire license notice for the
 * JavaScript code in this file.
 *
 * Copyright (C) 2019, Kolab Systems AG <contact@kolabsys.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 *
 * @licend  The above is the entire license notice
 * for the JavaScript code in this file.
 */

// Notice: cacheName and assetsToCache vars are set by the PWA plugin
//         when sending this file content to the browser

self.addEventListener('install', function(event) {
    // waitUntil() ensures that the Service Worker will not
    // install until the code inside has successfully occurred
    event.waitUntil(
        // Create cache with the name supplied above and
        // return a promise for it
        caches.open(cacheName).then(function(cache) {
            // Important to `return` the promise here to have `skipWaiting()`
            // fire after the cache has been updated.
            return cache.addAll(assetsToCache);
        }).then(function() {
            // `skipWaiting()` forces the waiting ServiceWorker to become the
            // active ServiceWorker, triggering the `onactivate` event.
            // Together with `Clients.claim()` this allows a worker to take effect
            // immediately in the client(s).
            return self.skipWaiting();
        })
    );
});

// Activate event
// Be sure to call self.clients.claim()
self.addEventListener('activate', function(event) {
    event.waitUntil(
        // Remove older version caches
        caches.keys().then(function(keyList) {
            return Promise.all(keyList.map(function(key) {
                if (cacheName.indexOf(key) === -1) {
                    return caches.delete(key);
                }
            }));
        })
        .then(
            // `claim()` sets this worker as the active worker for all clients that
            // match the workers scope and triggers an `oncontrollerchange` event for
            // the clients.
            self.clients.claim()
        )
    );
});

self.addEventListener('fetch', function(event) {
    // Don't attempt to cache non-assets
    var url = event.request.url.replace(/\?(.*)$/, '');
    if (event.request.method != 'GET' || !url.match(/\.(css|js|png|svg|jpg|jpeg|ico|woff|woff2|html|json)$/)) {
        return;
    }

    event.respondWith(
        caches.match(event.request).then(function(response) {
            if (response) {
                console.log('[Service Worker] Fetch (cache) ' + event.request.url);
                return response;
            }

            console.log('[Service Worker] Fetch (remote) ' + event.request.url);

            return fetch(event.request).then(function(response) {
                return caches.open(cacheName).then(function(cache) {
                    if (response.status === 200) {
                        cache.put(event.request, response.clone());
                    }

                    return response;
                })
            })
        })
    );
});

self.addEventListener('beforeinstallprompt', function(e) {
    e.userChoice.then(function(choiceResult) {
        if (choiceResult.outcome == 'dismissed') {
            alert('User cancelled home screen install');
        } else {
            alert('User added to home screen');
        }
    });
});
