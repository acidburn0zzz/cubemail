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

require_once __DIR__ . '/oauth2.php';

class kolab_sso_openidc extends kolab_sso_oauth2
{
    protected $id     = 'openidc';
    protected $params = array(
        'scope'      => 'openid email offline_access',
        'token_type' => 'id_token',
    );

    /**
     * Returns error text for specified OpenIDC error code
     */
    protected function error_text($error)
    {
        // OpenIDC-specific codes
        switch ($error) {
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
            return "Request not supported";
        case 'request_uri_not_supported':
            return "request_uri param not supported";
        case 'registration_not_supported':
            return "Registration not supported";
        }

        // Fallback to OAuth2-specific codes
        return parent::error_text($error);
    }
}
