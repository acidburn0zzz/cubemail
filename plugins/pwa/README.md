The plugin "converts" Roundcube into a Progressive Web Application
which can be "installed" into user device's operating system.

This is proof-of-concept.


CONFIGURATION
-------------

1. Default plugin configuration works with the Elastic skin. Any external skin that
wants to use skin-specific images, app names, etc. need to contain `/pwa` sub-directory
with images as in plugin's `/assets` directory.

Colors and other metadata is configurable via skin's meta.json file. For example:
```
    "config": {
        "pwa_name": "Roundcube",
        "pwa_theme_color": "#f4f4f4"
    }
```

https://realfavicongenerator.net/ will help you with creating images automatically
and choosing some colors.

2. Configure the plugin.

Copy config.inc.php.dist to config.inc.php and change config options to your liking.
Any option set in the plugin config will have a preference over skin configuration
described above.

3. Enable the plugin in Roundcube configuration file.
