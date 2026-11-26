<?php
/**
 * Database table for tradie mini-websites.
 */

if (!function_exists('apd_sites_db_version')) {
    function apd_sites_db_version(): string
    {
        return '1';
    }
}

if (!function_exists('apd_sites_table')) {
    function apd_sites_table(): string
    {
        return apd_tb('sites');
    }
}

if (!function_exists('apd_sites_install')) {
    function apd_sites_install(): void
    {
        global $wpdb;
        if (!isset($wpdb) || !function_exists('dbDelta')) {
            return;
        }
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $table   = apd_sites_table();
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            listing_id bigint(20) unsigned NOT NULL DEFAULT 0,
            user_id bigint(20) unsigned NOT NULL DEFAULT 0,
            template varchar(64) NOT NULL DEFAULT '',
            data longtext NOT NULL,
            status varchar(16) NOT NULL DEFAULT 'draft',
            created datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
            updated datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
            PRIMARY KEY  (id),
            KEY listing_id (listing_id),
            KEY user_id (user_id),
            KEY status (status)
        ) {$charset};";

        dbDelta($sql);
        update_option('apd_sites_db_version', apd_sites_db_version());
    }
}

if (!function_exists('apd_sites_maybe_install')) {
    function apd_sites_maybe_install(): void
    {
        if (get_option('apd_sites_db_version') !== apd_sites_db_version()) {
            apd_sites_install();
        }
    }
}

if (!function_exists('apd_sites_get_site_by_listing')) {
    /**
     * Latest site row for a listing, or null.
     */
    function apd_sites_get_site_by_listing(int $listing_id)
    {
        global $wpdb;
        if (!isset($wpdb)) {
            return null;
        }
        $table = apd_sites_table();
        $row   = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$table} WHERE listing_id = %d ORDER BY id DESC LIMIT 1", $listing_id),
            ARRAY_A
        );
        return $row ?: null;
    }
}

if (!function_exists('apd_sites_save_site')) {
    /**
     * Insert or update the site row for a listing.
     */
    function apd_sites_save_site(int $listing_id, int $user_id, string $template, array $data, string $status): int
    {
        global $wpdb;
        if (!isset($wpdb)) {
            return 0;
        }
        $table = apd_sites_table();
        $now   = current_time('mysql');
        $json  = wp_json_encode($data);
        $existing = apd_sites_get_site_by_listing($listing_id);

        if ($existing) {
            $wpdb->update(
                $table,
                array(
                    'user_id'  => $user_id,
                    'template' => $template,
                    'data'     => $json,
                    'status'   => $status,
                    'updated'  => $now,
                ),
                array('id' => (int) $existing['id']),
                array('%d', '%s', '%s', '%s', '%s'),
                array('%d')
            );
            return (int) $existing['id'];
        }

        $wpdb->insert(
            $table,
            array(
                'listing_id' => $listing_id,
                'user_id'    => $user_id,
                'template'   => $template,
                'data'       => $json,
                'status'     => $status,
                'created'    => $now,
                'updated'    => $now,
            ),
            array('%d', '%d', '%s', '%s', '%s', '%s', '%s')
        );
        return (int) $wpdb->insert_id;
    }
}
