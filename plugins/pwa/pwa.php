<?php

/**
 * "Converts" Roundcube into a Progressive Web Application
 *
 * @author Aleksander Machniak <machniak@kolabsys.com>
 * @author Christian Mollekopf <mollekopf@kolabsys.com>
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
 */
class pwa extends rcube_plugin
{
    /** @var string $version Plugin version */
    public static $version = '0.2';

    /** @var array $config Plugin config */
    private static $config;


    /**
     * Plugin initialization
     */
    function init()
    {
        $this->add_hook('template_object_links', array($this, 'template_object_links'));
        $this->add_hook('template_object_meta', array($this, 'template_object_meta'));

        $this->include_script('js/pwa.js');

        // Set the skin for PWA mode
        if (!empty($_GET['PWAMODE']) || !empty($_SESSION['PWAMODE'])) {
            $rcube = rcube::get_instance();
            $skin  = $_SESSION['PWAMODE'];

            if (!$skin) {
                $config = self::get_config();
                $skin   = $config['skin'];
            }

            // Reset the skin to the responsive one
            if ($rcube->config->get('skin') != $skin) {
                if ($rcube->output->type == 'html') {
                    $rcube->output->set_skin($skin);
                }

                $rcube->config->set('skin', $skin, true);

                // Reset default skin, otherwise it will be reset to default in rcmail::kill_session()
                // TODO: This could be done better
                $rcube->default_skin = $skin;
            }

            // Disable skin switch as this wouldn't have any effect
            // It also makes sure that user skin is not applied
            // TODO: Allow skin selection if there's more than one responsive skin available
            $dont_override = (array) $rcube->config->get('dont_override');
            if (!in_array('skin', $dont_override)) {
                $dont_override[] = 'skin';
                $rcube->config->set('dont_override', $dont_override, true);
            }

            // Set the mode for the client environment
            $rcube->output->set_env('PWAMODE', true);

            // Remember the mode in session
            $rcube->add_shutdown_function(function() use ($skin) {
                $_SESSION['PWAMODE'] = $skin;
            });
        }
    }

    /**
     * Adds <link> elements to the HTML output (handler for 'template_object_links' hook)
     */
    public function template_object_links($args)
    {
        $rcube   = rcube::get_instance();
        $config  = $this->get_config();
        $content = '';
        $links   = array(
            array(
                'rel'  => 'manifest',
                'href' => '?PWA=manifest.json',
            ),
            array(
                'rel'   => 'apple-touch-icon',
                'sizes' => '180x180',
                'href'  => 'apple-touch-icon.png'
            ),
            array(
                'rel'   => 'icon',
                'type'  => 'image/png',
                'sizes' => '32x32',
                'href'  => 'favicon-32x32.png'
            ),
            array(
                'rel'   => 'icon',
                'type'  => 'image/png',
                'sizes' => '16x16',
                'href'  => 'favicon-16x16.png'
            ),
            array(
                'rel'   => 'mask-icon',
                'href'  => 'safari-pinned-tab.svg',
                'color' => $config['pinned_tab_color'] ?: $config['theme_color'],
            ),
        );

        // Check if the skin contains /pwa directory
        $root_url = $rcube->find_asset('skins/' . $config['skin'] . '/pwa') ?: ($this->urlbase . 'assets');

        foreach ($links as $link) {
            if ($link['href'][0] != '?') {
                $link['href'] = $root_url . '/' . $link['href'];
            }

            $content .= html::tag('link', $link) . "\n";
        }

        // replace favicon.ico
        $icon            = $root_url . '/favicon.ico';
        $args['content'] = preg_replace('/(<link rel="shortcut icon" href=")([^"]+)/', '\\1' . $icon, $args['content']);

        $args['content'] .= $content;

        return $args;
    }

    /**
     * Adds <meta> elements to the HTML output (handler for 'template_object_meta' hook)
     */
    public function template_object_meta($args)
    {
        $config       = $this->get_config();
        $meta_content = '';
        $meta_list    = array(
            'apple-mobile-web-app-title' => 'name',
            'application-name'           => 'name',
            'msapplication-TileColor'    => 'tile_color',
            // todo: theme-color meta is already added by the skin, overwrite?
            'theme-color'                => 'theme_color',
        );

        foreach ($meta_list as $name => $opt_name) {
            if ($content = $config[$opt_name]) {
                $meta_content .= html::tag('meta', array('name' => $name, 'content' => $content)) . "\n";
            }
        }

        $args['content'] .= $meta_content;

        return $args;
    }

    /**
     * Hijack HTTP requests to plugin assets e.g. service worker
     */
    public static function http_request()
    {
        // We register service worker file from location specified
        // as ?PWA=sw.js. This way we don't need to put it in Roundcube root
        // and we can set some javascript variables like cache version, etc.
        if ($_GET['PWA'] === 'sw.js') {
            $rcube         = rcube::get_instance();
            $rcube->task   = 'pwa';
            $rcube->action = 'sw.js';
            if ($file = $rcube->find_asset('plugins/pwa/js/sw.js')) {
                // TODO: use caching headers?
                header('Content-Type: application/javascript');

                // TODO: What assets do we want to cache?
                // TODO: assets_dir support
                $assets = array(
                    $rcube->find_asset('plugins/pwa/assets/wifi.svg'),
                );

                echo "var cacheName = 'v" . self::$version . "';\n";
                echo "var assetsToCache = " . json_encode($assets) . "\n";

                readfile($file);
                exit;
            }

            header('HTTP/1.0 404 PWA plugin error');
            exit;
        }

        // We genarate manifest.json file from skin/plugin config
        if ($_GET['PWA'] === 'manifest.json') {
            $rcube         = rcube::get_instance();
            $rcube->task   = 'pwa';
            $rcube->action = 'manifest.json';

            // Read skin/plugin config
            $config = self::get_config();

            // HTTP scope
            $scope = preg_replace('|/*\?.*$|', '', $_SERVER['REQUEST_URI']);
            $scope = strlen($scope) ? $scope : '';

            // Check if the skin contains /pwa directory
            $root_url = $rcube->find_asset('skins/' . $config['skin'] . '/pwa') ?: ('plugins/pwa/assets');

            // Manifest defaults
            $defaults = array(
                'name'              => null,
                'short_name'        => $config['name'],
                'description'       => 'Free and Open Source Webmail',
                'lang'              => 'en-US',
                'theme_color'       => null,
                'background_color'  => null,
                'pinned_tab_color'  => null,
                'icons'             => array(
                    array(
                        'src'   => $root_url . '/android-chrome-192x192.png',
                        'sizes' => '192x192',
                        'type'  => 'image/png',
                    ),
                    array(
                        'src'   => $root_url . '/android-chrome-512x512.png',
                        'sizes' => '512x512',
                        'type'  => 'image/png',
                    ),
                ),
            );

            $manifest = array(
                'scope'       => $scope,
                'start_url'   => '?PWAMODE=1',
                'display'     => 'standalone',
/*
                'permissions' => array(
                    'desktop-notification' => array(
                        'description' => "Needed for notifying you of any changes to your account."
                    ),
                ),
*/
            );

            // Build manifest data from config and defaults
            foreach ($defaults as $name => $value) {
                if (isset($config[$name])) {
                    $value = $config[$name];
                }

                $manifest[$name] = $value;
            }

            // Send manifest.json to the browser
            // TODO: use caching headers?
            header('Content-Type: application/json');
            echo rcube_output::json_serialize($manifest, (bool) $rcube->config->get('devel_mode'));
            exit;
        }
    }

    /**
     * Load plugin and skin configuration
     *
     * @return array Key-value configuration
     */
    private static function get_config()
    {
        if (is_array(self::$config)) {
            return self::$config;
        }

        $rcube        = rcube::get_instance();
        $config       = array();
        self::$config = array();
        $defaults     = array(
            'tile_color'        => '#2d89ef',
            'theme_color'       => '#f4f4f4',
            'pinned_tab_color'  => '#37beff',
            'background_color'  => '#ffffff',
            'skin'              => $rcube->config->get('skin') ?: 'elastic',
            'name'              => $rcube->config->get('product_name') ?: 'Roundcube',
        );

        // Load plugin config into $config var
        $fpath = __DIR__ . '/config.inc.php';
        if (is_file($fpath) && is_readable($fpath)) {
            ob_start();
            include($fpath);
            ob_end_clean();
        }

        // Load skin config
        $meta = @file_get_contents(RCUBE_INSTALL_PATH . '/skins/' . $defaults['skin'] . '/meta.json');
        $meta = @json_decode($meta, true);

        if ($meta && $meta['extends']) {
            // Merge with parent skin config
            $root_meta = @file_get_contents(RCUBE_INSTALL_PATH . '/skins/' . $meta['extends'] . '/meta.json');
            $root_meta = @json_decode($meta, true);

            if ($root_meta && !empty($root_meta['config'])) {
                $meta['config'] = array_merge((array) $root_meta['config'], (array) $meta['config']);
            }
        }

        foreach ((array) $meta['config'] as $name => $value) {
            if (strpos($name, 'pwa_') === 0 && !isset($config[$name])) {
                $config[$name] = $value;
            }
        }

        foreach ($config as $name => $value) {
            $name = preg_replace('/^pwa_/', '', $name);
            if ($value !== null) {
                self::$config[$name] = $value;
            }
        }

        foreach ($defaults as $name => $value) {
            if (!array_key_exists($name, self::$config)) {
                self::$config[$name] = $value;
            }
        }

        return self::$config;
    }
}

// Hijack HTTP requests to special plugin assets e.g. sw.js, manifest.json
pwa::http_request();
