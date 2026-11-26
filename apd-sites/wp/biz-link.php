<?php
/**
 * "Make a free website for this listing" link on the existing biz page.
 *
 * The directory core and its rich layer do not expose any do_action() or
 * apply_filters() hook on the biz page, so the link is attached through the
 * standard WordPress `wp_footer` action. A small inline script moves the link
 * next to the existing "Edit this listing" button; without JavaScript the link
 * still renders (it simply sits at the end of the page).
 *
 * The core files are never edited.
 */

if (!function_exists('apd_sites_biz_link')) {
    function apd_sites_biz_link(): void
    {
        if (!function_exists('apd_sites_on') || !apd_sites_on()) {
            return;
        }

        $path = isset($_SERVER['REQUEST_URI'])
            ? (string) parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_PATH)
            : '';
        if (!preg_match('~^/biz/(\d+)(?:-[a-z0-9-]*)?/?$~i', $path, $m)) {
            return;
        }

        $listing_id = (int) $m[1];
        $listing    = apd_get($listing_id);
        if (!is_object($listing)) {
            return;
        }

        $owner_id = (int) $listing->user_id;
        $is_admin = function_exists('current_user_can') && current_user_can('manage_options');
        if ($owner_id <= 0 || ($owner_id !== apd_me() && !$is_admin)) {
            return;
        }

        $url   = apd_sites_owner_url($listing_id);
        $label = 'Make a free website for this listing';

        // Server-rendered link (works without JavaScript) plus a tiny script
        // that relocates it next to the "Edit this listing" button.
        echo '<p class="apd-site-cta" id="apd-site-cta"><a class="btn alt" href="' . esc_url($url) . '">'
            . esc_html($label) . '</a></p>';
        echo '<script>(function(){var c=document.getElementById("apd-site-cta"),'
            . 'e=document.querySelector(\'a[href="/list/?id=' . $listing_id . '"]\');'
            . 'if(!c||!e||!e.parentNode)return;e.parentNode.appendChild(document.createTextNode(" "));'
            . 'e.parentNode.appendChild(c.firstChild);if(c.parentNode)c.parentNode.removeChild(c);})();</script>';
    }
}

if (function_exists('add_action')) {
    add_action('wp_footer', 'apd_sites_biz_link');
}
