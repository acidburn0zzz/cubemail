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
    public $noajax  = true;
    public $noframe = true;

    /** @var string $version Plugin version */
    public static $version = '0.1';

    /** @var array|null $config Plugin config (from manifest.json) */
    private $config;


    /**
     * Plugin initialization
     */
    function init()
    {
        $this->add_hook('template_object_links', array($this, 'template_object_links'));
        $this->add_hook('template_object_meta', array($this, 'template_object_meta'));

        $this->include_script('js/pwa.js');
    }

    /**
     * Adds <link> elements to the HTML output
     */
    public function template_object_links($args)
    {
        $config  = $this->get_config();
        $content = '';
        $links   = array(
            array(
                'rel'  => 'manifest',
                'href' => $this->urlbase . 'assets/manifest.json',
            ),
            array(
                'rel'   => 'apple-touch-icon',
                'sizes' => '180x180',
                'href'  => $this->urlbase . 'assets/apple-touch-icon.png'
            ),
            array(
                'rel'   => 'icon',
                'type'  => 'image/png',
                'sizes' => '32x32',
                'href'  => $this->urlbase . 'assets/favicon-32x32.png'
            ),
            array(
                'rel'   => 'icon',
                'type'  => 'image/png',
                'sizes' => '16x16',
                'href'  => $this->urlbase . 'assets/favicon-16x16.png'
            ),
            array(
                'rel'   => 'mask-icon',
                'href'  => $this->urlbase . 'assets/safari-pinned-tab.svg',
                'color' => $config['theme_color'] ?: '#5bbad5',
            ),
        );

        foreach ($links as $link) {
            $content .= html::tag('link', $link) . "\n";
        }

        $args['content'] .= $content;

        // replace favicon.ico
        $args['content'] = preg_replace(
            '/(<link rel="shortcut icon" href=")([^"]+)/',
            '\\1' . $this->urlbase . 'assets/favicon.ico',
            $args['content']
        );

        return $args;
    }

    /**
     * Adds <meta> elements to the HTML output
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
            $rcube = rcube::get_instance();
            $rcube->task = 'pwa';

            if ($file = $rcube->find_asset('plugins/pwa/js/sw.js')) {
                header('Content-Type: application/javascript');

                // TODO: What assets do we want to cache?
                // TODO: assets_dir support
                $assets = array(
//                    'plugins/pwa/assets/manifest.json',
                );

                echo "var cacheName = 'v" . self::$version . "';\n";
                echo "var assetsToCache = " . json_encode($assets) . "\n";

                readfile($file);
                exit;
            }

            header('HTTP/1.0 404 PWA plugin error');
            exit;
        }
    }

    /**
     * Read configuration from manifest.json
     *
     * @return array Key-value configuration
     */
    private function get_config()
    {
        if (is_array($this->config)) {
            return $this->config;
        }

        $config   = array();
        $defaults = array(
            'tile_color'      => '#2d89ef',
            'theme_color'     => '#2e3135',
        );

        if ($file = rcube::get_instance()->find_asset('plugins/pwa/assets/manifest.json')) {
            $config = json_decode(file_get_contents(INSTALL_PATH . $file), true);
        }

        return $this->config = array_merge($defaults, $config);
    }
}

// Hijack HTTP requests to plugin assets e.g. service worker
pwa::http_request();
