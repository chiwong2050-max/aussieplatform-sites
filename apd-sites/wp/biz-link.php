<?php
/**
 * "Make a free website for this listing" link on the existing biz page.
 *
 * Uses the apd_biz_actions hook exposed by the core MU-plugin. The core files
 * are never edited.
 */

if (!function_exists('apd_site_biz_link')) {
    /**
     * @param mixed $listing The listing passed by the core biz page.
     */
    function apd_site_biz_link($listing): void
    {
        if (!apd_sites_on() || !is_array($listing)) {
            return;
        }
        $listing_id = isset($listing['id']) ? (int) $listing['id'] : 0;
        $owner_id   = isset($listing['user_id']) ? (int) $listing['user_id'] : 0;
        if ($listing_id <= 0 || $owner_id <= 0) {
            return;
        }
        if (apd_me() !== $owner_id) {
            return;
        }
        $url = apd_sites_owner_url($listing_id);
        echo '<li class="apd-biz-action apd-biz-action--site"><a href="' . apd_esc($url) . '">'
            . 'Make a free website for this listing</a></li>';
    }
}

if (function_exists('add_action')) {
    add_action('apd_biz_actions', 'apd_site_biz_link', 10, 1);
}
