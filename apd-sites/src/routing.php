<?php
/**
 * Route matching and gating. Pure PHP, no WordPress dependency.
 *
 * The WordPress side (wp/routes.php) uses these helpers so the security
 * decision can be unit tested without a running WordPress.
 */

if (!function_exists('apd_site_request_path')) {
    function apd_site_request_path(string $uri): string
    {
        $path = parse_url($uri, PHP_URL_PATH);
        return is_string($path) ? $path : '';
    }
}

if (!function_exists('apd_site_match_route')) {
    /**
     * Match a request path against the plugin's routes.
     *
     * @return array|null array(route, listing_id, slug) or null.
     */
    function apd_site_match_route(string $path)
    {
        $path = '/' . ltrim((string) $path, '/');
        if (preg_match('#^/my-site/(\d+)/?$#', $path, $m)) {
            return array('route' => 'owner', 'listing_id' => (int) $m[1], 'slug' => '');
        }
        if (preg_match('#^/site/([a-z0-9-]+)/?$#', $path, $m)) {
            return array('route' => 'live', 'listing_id' => 0, 'slug' => $m[1]);
        }
        return null;
    }
}

if (!function_exists('apd_site_route_gate')) {
    /**
     * Decide whether a matched route may run for the current viewer.
     *
     * With APD_SITES_ON disabled every route returns 404, except for admins
     * who can still use them for testing.
     *
     * @return int HTTP status: 200 to proceed, 404 to deny.
     */
    function apd_site_route_gate($route, bool $is_admin, bool $sites_on): int
    {
        if (!is_array($route)) {
            return 404;
        }
        if (!$sites_on && !$is_admin) {
            return 404;
        }
        return 200;
    }
}

if (!function_exists('apd_site_can_manage')) {
    /**
     * Only the listing owner or an admin may open the website builder.
     *
     * @param int  $listing_user_id The user_id on the listing row.
     * @param int  $current_user_id The logged-in user, 0 when logged out.
     * @param bool $is_admin        Does the viewer have manage_options?
     */
    function apd_site_can_manage(int $listing_user_id, int $current_user_id, bool $is_admin): bool
    {
        if ($is_admin) {
            return true;
        }
        if ($current_user_id <= 0) {
            return false;
        }
        return $listing_user_id === $current_user_id;
    }
}

if (!function_exists('apd_site_indexing_allowed')) {
    /**
     * Whether /site/ pages may be indexed. APD_SITES_INDEXING alone controls it.
     */
    function apd_site_indexing_allowed(bool $sites_indexing): bool
    {
        return $sites_indexing;
    }
}

if (!function_exists('apd_site_route_status')) {
    /**
     * Convenience wrapper used by the router and its tests: match a path and
     * return the HTTP status the handler should use (200 proceed, 404 deny).
     */
    function apd_site_route_status(string $path, bool $is_admin, bool $sites_on): int
    {
        return apd_site_route_gate(apd_site_match_route($path), $is_admin, $sites_on);
    }
}
