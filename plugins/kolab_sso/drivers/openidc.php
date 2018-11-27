<?php

/**
 * kolab_sso driver implementing OpenIDC Authorization Code Flow
 * https://openid.net/specs/openid-connect-core-1_0.html#CodeFlowSteps
 *
 * TODO: Discovery: https://openid.net/specs/openid-connect-discovery-1_0.html#ProviderConfigurationRequest
 *
 * @author Aleksander Machniak <machniak@kolabsys.com>
 *
 * Copyright (C) 2018, Kolab Systems AG <contact@kolabsys.com>
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
class kolab_sso_openidc
{
    protected $id     = 'openidc';
    protected $config = array();
    protected $plugin;


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
            'scope'         => 'openid email offline_access',
            'client_id'     => $this->config['client_id'],
            'state'         => $this->plugin->rc->get_request_token(),
            'redirect_uri'  => $this->redirect_uri(),
        );

        // TODO: Other params by config: display, prompt, max_age,

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

        $error = $this->response_error(
            rcube_utils::get_input_value('error', rcube_utils::INPUT_GET),
            rcube_utils::get_input_value('error_description', rcube_utils::INPUT_GET),
            rcube_utils::get_input_value('error_uri', rcube_utils::INPUT_GET)
        );

        if ($error) {
            // TODO: display error in UI
            return;
        }

        $state = rcube_utils::get_input_value('state', rcube_utils::INPUT_GET);
        $code  = rcube_utils::get_input_value('code', rcube_utils::INPUT_GET);

        if (!$state) {
            $this->plugin->debug("[{$this->id}][response] State missing");
            $error = $this->plugin->gettext('errorinvalidresponse');
            return;
        }

        if ($state != $this->plugin->rc->get_request_token()) {
            $this->plugin->debug("[{$this->id}][response] Invalid response state");
            $error = $this->plugin->gettext('errorinvalidresponse');
            return;
        }

        if (!$code) {
            $this->plugin->debug("[{$this->id}][response] Code missing");
            $error = $this->plugin->gettext('errorinvalidresponse');
            return;
        }

        return $this->request_token($code);
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
            'client_id'     => $this->config['client_id'],
            'client_secret' => $this->config['client_secret'],
            'grant_type'    => $refresh_token ? 'refresh_token' : 'authorization_code',
        );

        if ($refresh_token) {
            $params['refresh_token'] = $refresh_token;
            $params['scope']         = 'openid email offline_access';
        }
        else {
            $params['code']         = $code;
            $params['redirect_uri'] = $this->redirect_uri();
        }

        $post = http_build_query($params);

        $this->plugin->debug("[{$this->id}][$mode] Requesting POST $url?$post");

        try {
            // TODO: JWT-based methods of client authentication
            // https://openid.net/specs/openid-connect-core-1_0.html#rfc.section.9

            $request = $this->get_request($url, 'POST');
            $request->setAuth($this->config['client_id'], $this->config['client_secret']);
            $request->setBody($post);

            $response = $request->send();
            $status   = $response->getStatus();
            $response = $response->getBody();

            $this->plugin->debug("[{$this->id}][$mode] Response: $response");

            $response = @json_decode($response, true);

            if ($status != 200 || !is_array($response) || !empty($response['error'])) {
                $err = $this->error_message(is_array($response) ? $response['error'] : null);
                throw new Exception("OpenIDC request failed with error: $err");
            }
        }
        catch (Exception $e) {
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
            $this->plugin->debug("[{$this->id}][$mode] Error: Invalid or unsupported response");
            return;
        }

        $ttl     = $response['expires_in'] ?: 600;
        $validto = new DateTime(sprintf('+%d seconds', $ttl), new DateTimezone('UTC'));

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

        if (!empty($response['id_token'])) {
            try {
                $key = $this->config['client_secret'];

                if (!empty($this->config['pubkey'])) {
                    $pubkey = trim(preg_replace('/\r?\n[\s\t]+/', "\n", $this->config['pubkey']));

                    if (strpos($pubkey, '-----') !== 0) {
                        $pubkey = "-----BEGIN PUBLIC KEY-----\n" . trim(chunk_split($pubkey, 64, "\n")) . "\n-----END PUBLIC KEY-----";
                    }

                    if ($keyid = openssl_pkey_get_public($pubkey)) {
                        $key = $keyid;
                    }
                    else {
                        throw new Exception("Failed to extract public key");
                    }
                }

                $jwt = new Firebase\JWT\JWT;
                $jwt::$leeway = 60;

                $payload = $jwt->decode($response['id_token'], $key, array_keys(Firebase\JWT\JWT::$supported_algs));
                $email   = $this->config['debug_email'] ?: $payload->email;

                if (empty($email)) {
                    throw new Exception("No email address in JWT token");
                }

                if (!in_array($this->config['client_id'], (array) $payload->aud)) {
                    throw new Exception("Token audience does not match");
                }

                // More extended token validation
                // https://openid.net/specs/openid-connect-core-1_0.html#IDTokenValidation

                $result['email'] = $email;
            }
            catch (Exception $e) {
                rcube::raise_error(array(
                    'line' => __LINE__, 'file' => __FILE__, 'message' => $e->getMessage()),
                    true, false);
                return;
            }
        }

        return $result;
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
    protected function response_error($error, $description, $uri)
    {
        if (empty($error)) {
            return;
        }

        $msg = $this->error_message($error);

        $this->plugin->debug("[{$this->id}] Error: $msg");

        // TODO: Add URI to the message

        $label = 'error' . str_replace('_', '', $error);
        if ($this->plugin->rc->text_exists($label, 'kolab_sso')) {
            return $this->plugin->gettext($label);
        }

        return $this->plugin->gettext('responseerrorunknown');
    }

    /**
     * Returns error message for specified OpenIDC error code
     */
    protected function error_message($error)
    {
        switch ($error) {
        // OAuth2 codes
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
        // OpenIDC codes
        case 'interaction_required':
            return "End-User interaction required";
        case 'login_required':
            return "End-User authentication required";
        case 'account_selection_required':
            return "End-User account selection required";
        case 'consent_required':
            return "End-User consent required";
        case 'invalid_request_uri':
            return "Invalid request_uri";
        case 'invalid_request_object':
            return "Invalid Request Object";
        case 'request_not_supported':
            return "Request param not supported";
        case 'request_uri_not_supported':
            return "request_uri param not supported";
        case 'registration_not_supported':
            return "Registration parameter not supported";
        }

        return "Unknown error";
    }
}
