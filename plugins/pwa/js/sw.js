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

// Warning: cacheName and assetsToCache vars are set by the PWA plugin
//          when sending this file content to the browser

self.addEventListener('sync', function(event) {
    if (event.registration.tag == "oneTimeSync") {
        console.dir(self.registration);
        console.log("One Time Sync Fired");
    }
});

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
    // `claim()` sets this worker as the active worker for all clients that
    // match the workers scope and triggers an `oncontrollerchange` event for
    // the clients.
    return self.clients.claim();
});

self.addEventListener('fetch', function(event) {
    // Ignore non-get request like when accessing the admin panel
    // if (event.request.method !== 'GET') { return; }
    // Don't try to handle non-secure assets because fetch will fail
    if (/http:/.test(event.request.url)) {
        return;
    }

    // Here's where we cache all the things!
    event.respondWith(
        // Open the cache created when install
        caches.open(cacheName).then(function(cache) {
            // Go to the network to ask for that resource
            return fetch(event.request).then(function(networkResponse) {
                // Add a copy of the response to the cache (updating the old version)
                cache.put(event.request, networkResponse.clone());
                // Respond with it
                return networkResponse;
            }).catch(function() {
                // If there is no internet connection, try to match the request
                // to some of our cached resources
                return cache.match(event.request);
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
