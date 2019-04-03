<?php

/**
 * kolab_sso driver implementing Abraxas SEAS Portal OAuth2/JWT flow
 *
 * @author Aleksander Machniak <machniak@kolabsys.com>
 *         Beat Rubischon <beat.rubischon@adfinis-sygroup.ch>
 *
 * Copyright (C) 2018, Kolab Systems AG <contact@kolabsys.com>
 * Copyright (C) 2019, Adfinis SyGroup AG
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


class kolab_sso_seas extends kolab_sso_oauth2
{
    protected $id       = 'seas';
    protected $defaults = array(
        'scope'          => 'USER',
        'token_type'     => 'access_token',
        'user_field'     => 'user_name',
        'validate_items' => array(),
    );
}
