<?php
/**
 * Plugin Name: APD Sites
 * Description: Adds tradie mini-websites to the aussieplatform.app directory. Reuses the helpers from zz-apd-core.php and must load after it.
 * Version: 1.0.0
 * Requires at least: 6.0
 * Requires PHP: 8.3
 * License: GPL-2.0-or-later
 */

/*
 * Switches. All off by default.
 *
 * APD_SITES_ON=false        hides every entry point and returns 404 for the routes.
 *                           Admins can still use the routes for testing.
 * APD_SITES_BILLING=false   shows no price, no payment step and no payment keys.
 * APD_SITES_INDEXING=false  marks every /site/ page noindex,follow and keeps it
 *                           out of sitemaps. robots.txt is never touched.
 */
if (!defined('APD_SITES_ON')) {
    define('APD_SITES_ON', false);
}
if (!defined('APD_SITES_BILLING')) {
    define('APD_SITES_BILLING', false);
}
if (!defined('APD_SITES_INDEXING')) {
    define('APD_SITES_INDEXING', false);
}

/*
 * This loader must run after zz-apd-core.php. MU-plugins load in alphabetical
 * order, so zz-apd-core.php runs first. If the core helpers are missing we bail
 * out quietly and load nothing.
 */
if (!function_exists('apd_get')) {
    return;
}

$apd_sites_dir = __DIR__ . '/apd-sites';

$apd_sites_includes = array(
    '/src/trade-families.php',
    '/src/sanitise.php',
    '/src/validate.php',
    '/src/render.php',
    '/src/routing.php',
    '/src/importer.php',
    '/wp/table.php',
    '/wp/uploads.php',
    '/wp/routes.php',
    '/wp/admin.php',
    '/wp/biz-link.php',
);

foreach ($apd_sites_includes as $apd_sites_include) {
    $apd_sites_path = $apd_sites_dir . $apd_sites_include;
    if (is_file($apd_sites_path)) {
        require_once $apd_sites_path;
    }
}
unset($apd_sites_include, $apd_sites_path);

if (function_exists('add_action')) {
    add_action('init', 'apd_sites_maybe_install');
    add_action('admin_init', 'apd_sites_maybe_install');
}
