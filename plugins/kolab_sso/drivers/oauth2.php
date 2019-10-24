<?php

/**
 * kolab_sso driver implementing OAuth2 Authorization (RFC6749)
 * with use of JWT tokens.
 *
 * @author Aleksander Machniak <machniak@kolabsys.com>
 *
 * Copyright (C) 2018-2019, Kolab Systems AG <contact@kolabsys.com>
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
 */
class kolab_sso_oauth2
{
    protected $plugin;
    protected $id       = 'oauth2';
    protected $config   = array();
    protected $params   = array();
    protected $defaults = array(
        'scope'          => 'email',
        'token_type'     => 'access_token',
        'user_field'     => 'email',
        'validate_items' => array('aud'),
    );


    /**
     * Object constructor
     *
     * @param rcube_plugin $plugin kolab_sso plugin object
     * @param array        $config Driver configuration
     */
    public function __construct($plugin, $config)
    {
        $this->plugin = $plugin;
        $this->config = $config;

        $this->plugin->require_plugin('libkolab');
    }

    /**
     * Authentication request (redirect to SSO service)
     */
    public function authorize()
    {
        $params = array(
            'response_type' => 'code',
            'scope'         => $this->get_param('scope'),
            'client_id'     => $this->get_param('client_id'),
            'state'         => $this->plugin->rc->get_request_token(),
            'redirect_uri'  => $this->redirect_uri(),
        );

        // Add extra request parameters (don't overwrite params set above)
        if (!empty($this->config['extra_params'])) {
            $params = array_merge((array) $this->config['extra_params'], $params);
        }

        $url = $this->config['auth_uri'] ?: (unslashify($this->config['uri']) . '/authorize');
        $url .= '?' . http_build_query($params);

        $this->plugin->debug("[{$this->id}][authorize] Redirecting to $url");

        header("Location: $url");
        die;
    }

    /**
     * Authorization response validation
     */
    public function response()
    {
        $this->plugin->debug("[{$this->id}][authorize] Response: " . $_SERVER['REQUEST_URI']);

        $this->error = $this->error_message(
            rcube_utils::get_input_value('error', rcube_utils::INPUT_GET),
            rcube_utils::get_input_value('error_description', rcube_utils::INPUT_GET),
            rcube_utils::get_input_value('error_uri', rcube_utils::INPUT_GET)
        );

        if ($this->error) {
            return;
        }

        $state = rcube_utils::get_input_value('state', rcube_utils::INPUT_GET);
        $code  = rcube_utils::get_input_value('code', rcube_utils::INPUT_GET);

        if (!$state) {
            $this->plugin->debug("[{$this->id}][response] State missing");
            $this->error = $this->plugin->gettext('errorinvalidresponse');
            return;
        }

        if ($state != $this->plugin->rc->get_request_token()) {
            $this->plugin->debug("[{$this->id}][response] Invalid response state");
            $this->error = $this->plugin->gettext('errorinvalidresponse');
            return;
        }

        if (!$code) {
            $this->plugin->debug("[{$this->id}][response] Code missing");
            $this->error = $this->plugin->gettext('errorinvalidresponse');
            return;
        }

        return $this->request_token($code);
    }

    /**
     * Error message for the response handler
     */
    public function response_error()
    {
        if ($this->error) {
            return $this->plugin->rc->gettext('loginfailed') . ' ' . $this->error;
        }
    }

    /**
     * Existing session validation
     */
    public function validate_session($session)
    {
        $this->plugin->debug("[{$this->id}][validate] Session: " . json_encode($session));

        // Sanity checks
        if (empty($session) || empty($session['code']) || empty($session['validto']) || empty($session['email'])) {
            $this->plugin->debug("[{$this->id}][validate] Session invalid");
            return;
        }

        // Check expiration time
        $now     = new DateTime('now', new DateTimezone('UTC'));
        $validto = new DateTime($session['validto'], new DateTimezone('UTC'));

        // Don't refresh often than TTL/2
        $validto->sub(new DateInterval(sprintf('PT%dS', $session['ttl']/2)));
        if ($now < $validto) {
            $this->plugin->debug("[{$this->id}][validate] Token valid, skipping refresh");
            return $session;
        }

        // No refresh_token, not possible to refresh
        if (empty($session['refresh_token'])) {
            $this->plugin->debug("[{$this->id}][validate] Session cannot be refreshed");
            return;
        }

        // Renew tokens
        $info = $this->request_token($session['code'], $session['refresh_token']);

        if (!empty($info)) {
            // Make sure the email didn't change
            if (!empty($info['email']) && $info['email'] != $session['email']) {
                $this->plugin->debug("[{$this->id}][validate] Email address change");
                return;
            }

            $session = array_merge($session, $info);

            $this->plugin->debug("[{$this->id}][validate] Session valid: " . json_encode($session));
            return $session;
        }
    }

    /**
     * Authentication Token request (or token refresh)
     */
    protected function request_token($code, $refresh_token = null)
    {
        $mode   = $refresh_token ? 'token-refresh' : 'token';
        $url    = $this->config['token_uri'] ?: ($this->config['uri'] . '/token');
        $params = array(
            'client_id'     => $this->get_param('client_id'),
            'client_secret' => $this->get_param('client_secret'),
            'grant_type'    => $refresh_token ? 'refresh_token' : 'authorization_code',
        );

        if ($refresh_token) {
            $params['refresh_token'] = $refresh_token;
            $params['scope']         = $this->get_param('scope');
        }
        else {
            $params['code']         = $code;
            $params['redirect_uri'] = $this->redirect_uri();
        }

        // Add extra request parameters (don't overwrite params set above)
        if (!empty($this->config['extra_params'])) {
            $params = array_merge((array) $this->config['extra_params'], $params);
        }

        $post = http_build_query($params);

        $this->plugin->debug("[{$this->id}][$mode] Requesting POST $url?$post");

        try {
            // TODO: JWT-based methods of client authentication
            // https://openid.net/specs/openid-connect-core-1_0.html#rfc.section.9

            $request = $this->get_request($url, 'POST');
            $request->setAuth($params['client_id'], $params['client_secret']);
            $request->setBody($post);

            $response = $request->send();
            $status   = $response->getStatus();
            $response = $response->getBody();

            $this->plugin->debug("[{$this->id}][$mode] Response: $response");

            $response = @json_decode($response, true);

            if ($status != 200 || !is_array($response) || !empty($response['error'])) {
                $err = $this->error_text(is_array($response) ? $response['error'] : null);
                throw new Exception("OpenIDC request failed with error: $err");
            }
        }
        catch (Exception $e) {
            $this->error = $this->plugin->gettext('errorunknown');
            rcube::raise_error(array(
                'line' => __LINE__, 'file' => __FILE__, 'message' => $e->getMessage()),
                true, false);
            return;
        }

        // Example response: {
        //   "access_token":"ACCESS_TOKEN",
        //   "token_type":"bearer",
        //   "expires_in":2592000,
        //   "refresh_token":"REFRESH_TOKEN",
        //   "scope":"read",
        //   "uid":100101,
        //   "info":{"name":"Mark E. Mark","email":"mark@example.com"}
        // }

        if (empty($response['access_token']) || empty($response['token_type'])
            || strtolower($response['token_type']) != 'bearer'
        ) {
            $this->error = $this->plugin->gettext('errorinvalidresponse');
            $this->plugin->debug("[{$this->id}][$mode] Error: Invalid or unsupported response");
            return;
        }

        $ttl     = $response['expires_in'] ?: 600;
        $validto = new DateTime(sprintf('+%d seconds', $ttl), new DateTimezone('UTC'));
        $token   = $response[$this->get_param('token_type')];

        $result = array(
            'code'          => $code,
            'access_token'  => $response['access_token'],
            // 'token_type'    => $response['token_type'],
            'validto'       => $validto->format(DateTime::ISO8601),
            'ttl'           => $ttl,
        );

        if (!empty($response['refresh_token'])) {
            $result['refresh_token'] = $response['refresh_token'];
        }

        if (!empty($token)) {
            try {
                $key = $params['client_secret'];

                if (!empty($this->config['pubkey'])) {
                    $pubkey = trim(preg_replace('/\r?\n[\s\t]+/', "\n", $this->config['pubkey']));

                    if (strpos($pubkey, '-----') !== 0) {
                        $pubkey = "-----BEGIN PUBLIC KEY-----\n" . trim(chunk_split($pubkey, 64, "\n")) . "\n-----END PUBLIC KEY-----";
                    }

                    if ($keyid = openssl_pkey_get_public($pubkey)) {
                        $key = $keyid;
                    }
                    else {
                        throw new Exception("Failed to extract public key. " . openssl_error_string());
                    }
                }

                $jwt = new Firebase\JWT\JWT;
                $jwt::$leeway = 60;

                $payload = $jwt->decode($token, $key, array_keys(Firebase\JWT\JWT::$supported_algs));

                $result['email'] = $this->validate_token_payload($payload);
            }
            catch (Exception $e) {
                $this->error = $this->plugin->gettext('errorinvalidtoken');
                rcube::raise_error(array(
                    'line' => __LINE__, 'file' => __FILE__, 'message' => $e->getMessage()),
                    true, false);
                return;
            }
        }

        return $result;
    }

    /**
     * Validates JWT token payload and returns user/email
     */
    protected function validate_token_payload($payload)
    {
        $items = $this->get_param('validate_items');
        $email = $this->config['debug_email'] ?: $payload->{$this->get_param('user_field')};

        if (empty($email)) {
            throw new Exception("No email address in JWT token");
        }

        foreach ((array) $items as $item_name) {
            // More extended token validation
            // https://openid.net/specs/openid-connect-core-1_0.html#IDTokenValidation
            switch (strtolower($item_name)) {
            case 'aud':
                if (!in_array($this->get_param('client_id'), (array) $payload->aud)) {
                    throw new Exception("Token audience does not match");
                }
                break;
            }
        }

        return $email;
    }

    /**
     * The URL to use when redirecting the user from SSO back to Roundcube
     */
    protected function redirect_uri()
    {
        // response_uri is useful when the Provider does not allow
        // URIs with parameters. In such case set response_uri = '/sso'
        // and define a redirect in http server, example for Apache:
        // RewriteRule "^sso" "/roundcubemail/?_task=login&_action=sso" [L,QSA]

        $redirect_params = empty($this->config['response_uri']) ? array('_action' => 'sso') : array();

        $url = $this->plugin->rc->url($redirect_params, false, true);

        if (!empty($this->config['response_uri'])) {
            $url = unslashify(preg_replace('/\?.*$/', '', $url)) . '/' . ltrim($this->config['response_uri'], '/');
        }

        return $url;
    }

    /**
     * Get HTTP/Request2 object
     */
    protected function get_request($url, $type)
    {
        $config = array_intersect_key($this->config, array_flip(array(
                'ssl_verify_peer',
                'ssl_verify_host',
                'ssl_cafile',
                'ssl_capath',
                'ssl_local_cert',
                'ssl_passphrase',
                'follow_redirects',
        )));

        return libkolab::http_request($url, $type, $config);
    }

    /**
     * Returns (localized) user-friendly error message
     */
    protected function error_message($error, $description, $uri)
    {
        if (empty($error)) {
            return;
        }

        $msg = $this->error_text($error);

        rcube::raise_error(array(
            'message' => "[SSO] $msg." . ($description ? " $description" : '') . ($uri ? " ($uri)" : '')
            ), true, false);

        $label = 'error' . str_replace('_', '', $error);
        if (!$this->plugin->rc->text_exists($label, 'kolab_sso')) {
            $label = 'errorunknown';
        }

        return $this->plugin->gettext($label);
    }

    /**
     * Returns error text for specified OpenIDC error code
     */
    protected function error_text($error)
    {
        switch ($error) {
        case 'invalid_request':
            return "Request malformed";
        case 'unauthorized_client':
            return "The client is not authorized";
        case 'invalid_client':
            return "Client authentication failed";
        case 'access_denied':
            return "Request denied";
        case 'unsupported_response_type':
            return "Unsupported response type";
        case 'invalid_grant':
            return "Invalid authorization grant";
        case 'unsupported_grant_type':
            return "Unsupported authorization grant";
        case 'invalid_scope':
            return "Invalid scope";
        case 'server_error':
            return "Server error";
        case 'temporarily_unavailable':
            return "Service temporarily unavailable";
        }

        return "Unknown error";
    }

    /**
     * Returns (hardcoded/configured/default) value of a configuration param
     */
    protected function get_param($name)
    {
        return $this->params[$name] ?: ($this->config[$name] ?: $this->defaults[$name]);
    }
}
