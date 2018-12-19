/**
 * Mattermost driver
 * Websocket code based on https://github.com/mattermost/mattermost-redux/master/client/websocket_client.js
 *
 * @author Aleksander Machniak <machniak@kolabsys.com>
 *
 * @licstart  The following is the entire license notice for the
 * JavaScript code in this file.
 *
 * Copyright (C) 2015-2018, Kolab Systems AG <contact@kolabsys.com>
 * Copyright (C) 2015-2018, Mattermost, Inc.
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

var MAX_WEBSOCKET_FAILS = 7;
var MIN_WEBSOCKET_RETRY_TIME = 3000; // 3 sec
var MAX_WEBSOCKET_RETRY_TIME = 300000; // 5 mins

function MMWebSocketClient()
{
    var Socket;

    this.conn = null;
    this.connectionUrl = null;
    this.token = null;
    this.sequence = 1;
    this.connectFailCount = 0;
    this.eventCallback = null;
    this.firstConnectCallback = null;
    this.reconnectCallback = null;
    this.errorCallback = null;
    this.closeCallback = null;
    this.connectingCallback = null;
    this.dispatch = null;
    this.getState = null;
    this.stop = false;
    this.platform = '';

    this.initialize = function(token, dispatch, getState, opts)
    {
        var forceConnection = opts.forceConnection || true,
            webSocketConnector = opts.webSocketConnector || WebSocket,
            connectionUrl = opts.connectionUrl,
            platform = opts.platform,
            self = this;

        if (platform) {
            this.platform = platform;
        }

        if (forceConnection) {
            this.stop = false;
        }

        return new Promise(function(resolve, reject) {
            if (self.conn) {
                resolve();
                return;
            }

            if (connectionUrl == null) {
                console.log('websocket must have connection url');
                reject('websocket must have connection url');
                return;
            }

            if (!dispatch) {
                console.log('websocket must have a dispatch');
                reject('websocket must have a dispatch');
                return;
            }

            if (self.connectFailCount === 0) {
                console.log('websocket connecting to ' + connectionUrl);
            }

            Socket = webSocketConnector;
            if (self.connectingCallback) {
                self.connectingCallback(dispatch, getState);
            }

            var regex = /^(?:https?|wss?):(?:\/\/)?[^/]*/;
            var captured = (regex).exec(connectionUrl);
            var origin;

            if (captured) {
                origin = captured[0];

                if (platform === 'android') {
                    // this is done cause for android having the port 80 or 443 will fail the connection
                    // the websocket will append them
                    var split = origin.split(':');
                    var port = split[2];
                    if (port === '80' || port === '443') {
                        origin = split[0] + ':' + split[1];
                    }
                }
            } else {
                // If we're unable to set the origin header, the websocket won't connect, but the URL is likely malformed anyway
                console.warn('websocket failed to parse origin from ' + connectionUrl);
            }

            self.conn = new Socket(connectionUrl, [], {headers: {origin}});
            self.connectionUrl = connectionUrl;
            self.token = token;
            self.dispatch = dispatch;
            self.getState = getState;

            self.conn.onopen = function() {
                if (token && platform !== 'android') {
                    // we check for the platform as a workaround until we fix on the server that further authentications
                    // are ignored
                    self.sendMessage('authentication_challenge', {token});
                }

                if (self.connectFailCount > 0) {
                    console.log('websocket re-established connection');
                    if (self.reconnectCallback) {
                        self.reconnectCallback(self.dispatch, self.getState);
                    }
                } else if (self.firstConnectCallback) {
                    self.firstConnectCallback(self.dispatch, self.getState);
                }

                self.connectFailCount = 0;
                resolve();
            };

            self.conn.onclose = function() {
                self.conn = null;
                self.sequence = 1;

                if (self.connectFailCount === 0) {
                    console.log('websocket closed');
                }

                self.connectFailCount++;

                if (self.closeCallback) {
                    self.closeCallback(self.connectFailCount, self.dispatch, self.getState);
                }

                var retryTime = MIN_WEBSOCKET_RETRY_TIME;

                // If we've failed a bunch of connections then start backing off
                if (self.connectFailCount > MAX_WEBSOCKET_FAILS) {
                    retryTime = MIN_WEBSOCKET_RETRY_TIME * self.connectFailCount;
                    if (retryTime > MAX_WEBSOCKET_RETRY_TIME) {
                        retryTime = MAX_WEBSOCKET_RETRY_TIME;
                    }
                }

                setTimeout(function() {
                        if (self.stop) {
                            return;
                        }
                        self.initialize(token, dispatch, getState, Object.assign({}, opts, {forceConnection: true}));
                    },
                    retryTime
                );
            };

            self.conn.onerror = function(evt) {
                if (self.connectFailCount <= 1) {
                    console.log('websocket error');
                    console.log(evt);
                }

                if (self.errorCallback) {
                    self.errorCallback(evt, self.dispatch, self.getState);
                }
            };

            self.conn.onmessage = function(evt) {
                var msg = JSON.parse(evt.data);
                if (msg.seq_reply) {
                    if (msg.error) {
                        console.warn(msg);
                    }
                } else if (self.eventCallback) {
                    self.eventCallback(msg, self.dispatch, self.getState);
                }
            };
        });
    }

    this.setConnectingCallback = function(callback)
    {
        this.connectingCallback = callback;
    }

    this.setEventCallback = function(callback)
    {
        this.eventCallback = callback;
    }

    this.setFirstConnectCallback = function(callback)
    {
        this.firstConnectCallback = callback;
    }

    this.setReconnectCallback = function(callback)
    {
        this.reconnectCallback = callback;
    }

    this.setErrorCallback = function(callback)
    {
        this.errorCallback = callback;
    }

    this.setCloseCallback = function(callback) {
        this.closeCallback = callback;
    }

    this.close = function(stop)
    {
        this.stop = stop;
        this.connectFailCount = 0;
        this.sequence = 1;

        if (this.conn && this.conn.readyState === Socket.OPEN) {
            this.conn.onclose = function(){};
            this.conn.close();
            this.conn = null;
            console.log('websocket closed');
        }
    }

    this.sendMessage = function(action, data)
    {
        var msg = {
            action,
            seq: this.sequence++,
            data
        };

        if (this.conn && this.conn.readyState === Socket.OPEN) {
            this.conn.send(JSON.stringify(msg));
        } else if (!this.conn || this.conn.readyState === Socket.CLOSED) {
            this.conn = null;
            this.initialize(this.token, this.dispatch, this.getState, {forceConnection: true, platform: this.platform});
        }
    }
}

/**
 * Initializes and starts websocket connection with Mattermost
 */
function mattermost_websocket_init(url, token)
{
    var api = new MMWebSocketClient();

    api.setEventCallback(function(e) {
        mattermost_event_handler(e);
    });

    api.initialize(token, {}, {}, {connectionUrl: url});
}

/**
 * Handles websocket events
 */
function mattermost_event_handler(event)
{
    var msg, type;

    // Direct message notification
    if (event.event == 'posted' && event.data.channel_type == 'D') {
        msg = rcmail.gettext('kolab_chat.directmessage');
        type = 'user';
    }
    // Mention notification
    else if (event.event == 'posted' && String(event.data.mentions).indexOf(rcmail.env.mattermost_user) > 0) {
        msg = rcmail.gettext('kolab_chat.mentionmessage');
        type = 'channel';
    }

    if (msg) {
        var link = $('<a>').text(rcmail.gettext('kolab_chat.openchat')),
            user = event.data.sender_name,
            channel = event.data.channel_display_name,
            channel_id = event.broadcast.channel_id,
            msg_id = 'chat-' + type + '-' + (type == 'channel' ? channel_id : user),
            href = '?_task=kolab-chat&_channel=' + urlencode(channel_id);

        msg = msg.replace('$u', user).replace('$c', channel);

        if (rcmail.env.kolab_chat_extwin) {
            link.attr('target', '_blank');
            href += '&redirect=1';
        }

        if (event.data.team_id) {
            href += '&_team=' + urlencode(event.data.team_id);
        }

        link.attr('href', href);
        msg = $('<p>').text(msg + ' ').append(link).html();

        // FIXME: Should we display it indefinitely?
        rcmail.display_message(msg, 'notice chat', 10 * 60 * 1000, msg_id);
    }
}


window.WebSocket && window.rcmail && rcmail.addEventListener('init', function() {
    // Use ajax to get the token for websocket connection
    $.ajax({
        type: 'GET',
        url: '?_task=kolab-chat&_action=action&_get=token',
        success: function(data) {
            data = JSON.parse(data);
            if (data && data.token) {
                rcmail.set_env({mattermost_url: data.url, mattermost_token: data.token, mattermost_user: data.user_id});
                mattermost_websocket_init(data.url, data.token);
            }
        }
    });
});
