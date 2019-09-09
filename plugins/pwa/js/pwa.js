/**
 * PWA plugin engine
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

// Service worker (required by Android/Chrome but not iOS/Safari)
if ('serviceWorker' in navigator) {
    navigator.serviceWorker.register('?PWA=sw.js')
        .then(function(reg) {
            console.log("ServiceWorker registered", reg);
        })
        .catch(function(error) {
            console.log("Failed to register ServiceWorker", error);
        });
}

// Offline overlay
var pwa_online = true;
function updateOnlineStatus() {
    if (!navigator.onLine) {
        var overlay = document.createElement('div'),
            img_src = rcmail.assets_path('plugins/pwa/assets/wifi.svg');

        overlay.id = 'pwa-offline-overlay';
        overlay.style.cssText = 'position: absolute; top: 0; bottom: 0; width: 100%; z-index: 20000;'
            + ' display: flex; flex-direction: column; align-items: center; justify-content: center;'
            + ' opacity: .85; background-color: #000; color: #fff;';
        overlay.innerHTML = '<img src="' + img_src + '" width="100">'
            + '<div style="font-size: 12pt; margin-top: 1em"><b>No internet connection</b></div>';
        overlay.onclick = function(e) { e.stopPropagation(); };

        document.body.appendChild(overlay);

        pwa_online = false;
    }
    else {
        var overlay = document.getElementById('pwa-offline-overlay');
        if (overlay) {
            document.body.removeChild(overlay);
        }

        // When becoming online again, send keep-alive request to check if the session
        // is still valid, if not user will be redirected to the logon screen
        if (!pwa_online && rcmail.task != 'login') {
            rcmail.keep_alive();
        }

        pwa_online = true;
    }
}

window.addEventListener('load', function() {
    updateOnlineStatus();
    window.addEventListener('online',  updateOnlineStatus);
    window.addEventListener('offline', updateOnlineStatus);
});
