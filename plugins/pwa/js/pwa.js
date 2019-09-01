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

/* SERVICE WORKER - REQUIRED */
if ('serviceWorker' in navigator) {
    navigator.serviceWorker
    .register('?PWA=sw.js')
    .then(function(reg) {
        console.log("ServiceWorker registered", reg);
    })
    .catch(function(error) {
        console.log("Failed to register ServiceWorker", error);
    });
}

function registerOneTimeSync() {
    if (navigator.serviceWorker.controller) {
        navigator.serviceWorker.ready.then(function(reg) {
        if (reg.sync) {
            reg.sync.register({
                    tag: 'oneTimeSync'
                })
                .then(function(event) {
                    console.log('Sync registration successful', event);
                })
                .catch(function(error) {
                    console.log('Sync registration failed', error);
                });
        } else {
            console.log("One time Sync not supported");
        }
        });
    } else {
        console.log("No active ServiceWorker");
    }
}

/* OFFLINE BANNER */
function updateOnlineStatus() {
    // FIXME fill in something that makes sense in roundcube
    // var d = document.body;
    // d.className = d.className.replace(/\ offline\b/,'');
    // if (!navigator.onLine) {
    //     d.className += " offline";
    // }
}
updateOnlineStatus();

window.addEventListener('load', function() {
    window.addEventListener('online',  updateOnlineStatus);
    window.addEventListener('offline', updateOnlineStatus);
});

/* CHANGE PAGE TITLE BASED ON PAGE VISIBILITY */
function handleVisibilityChange() {
    if (document.visibilityState == "hidden") {
        document.title = "Hey! Come back!";
    } else {
        document.title = original_title;
    }
}

var original_title = document.title;
document.addEventListener('visibilitychange', handleVisibilityChange, false);

/* NOTIFICATIONS */
window.addEventListener('load', function () {
    // At first, let's check if we have permission for notification
    // If not, let's ask for it
    if (window.Notification && Notification.permission !== "granted") {
        Notification.requestPermission(function (status) {
            if (Notification.permission !== status) {
                Notification.permission = status;
            }
        });
    }
});
