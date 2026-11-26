<?php
/**
 * Database table for tradie mini-websites.
 */

if (!function_exists('apd_sites_db_version')) {
    function apd_sites_db_version(): string
    {
        return '2';
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
            slug varchar(80) NOT NULL DEFAULT '',
            data longtext NOT NULL,
            status varchar(16) NOT NULL DEFAULT 'draft',
            trial_start datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
            note varchar(255) NOT NULL DEFAULT '',
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

if (!function_exists('apd_sites_get_site_by_slug')) {
    /**
     * Site row for a public slug, or null.
     */
    function apd_sites_get_site_by_slug(string $slug)
    {
        global $wpdb;
        if (!isset($wpdb) || '' === $slug) {
            return null;
        }
        $table = apd_sites_table();
        $row   = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$table} WHERE slug = %s ORDER BY id DESC LIMIT 1", $slug),
            ARRAY_A
        );
        return $row ?: null;
    }
}

if (!function_exists('apd_sites_all_sites')) {
    /**
     * Every site row, newest first (for the admin screen).
     */
    function apd_sites_all_sites(int $limit = 200): array
    {
        global $wpdb;
        if (!isset($wpdb)) {
            return array();
        }
        $table = apd_sites_table();
        $limit = max(1, min(1000, $limit));
        $rows  = $wpdb->get_results("SELECT * FROM {$table} ORDER BY id DESC LIMIT {$limit}", ARRAY_A);
        return is_array($rows) ? $rows : array();
    }
}

if (!function_exists('apd_sites_make_slug')) {
    /**
     * A unique public slug for a listing's site. Based on the business name,
     * de-duplicated with the listing id (and then a counter) when taken.
     */
    function apd_sites_make_slug(string $name, int $listing_id): string
    {
        $slug = function_exists('sanitize_title')
            ? sanitize_title($name)
            : strtolower(preg_replace('/[^a-z0-9]+/i', '-', $name));
        $slug = trim((string) $slug, '-');
        if ('' === $slug) {
            $slug = 'tradie';
        }
        $slug = substr($slug, 0, 60);

        $taken = apd_sites_get_site_by_slug($slug);
        if (!$taken || (int) $taken['listing_id'] === $listing_id) {
            return $slug;
        }

        $base = $slug . '-' . $listing_id;
        $try  = $base;
        $n    = 1;
        while (($taken = apd_sites_get_site_by_slug($try)) && (int) $taken['listing_id'] !== $listing_id) {
            $n++;
            $try = $base . '-' . $n;
            if ($n > 50) {
                break;
            }
        }
        return $try;
    }
}

if (!function_exists('apd_sites_save_site')) {
    /**
     * Insert or update the site row for a listing.
     *
     * @param array $extra Optional slug, trial_start, note columns.
     */
    function apd_sites_save_site(int $listing_id, int $user_id, string $template, array $data, string $status, array $extra = array()): int
    {
        global $wpdb;
        if (!isset($wpdb)) {
            return 0;
        }
        $table = apd_sites_table();
        $now   = current_time('mysql');
        $json  = wp_json_encode($data);
        $existing = apd_sites_get_site_by_listing($listing_id);

        $fields = array(
            'user_id'  => $user_id,
            'template' => $template,
            'data'     => $json,
            'status'   => $status,
            'updated'  => $now,
        );
        $formats = array('%d', '%s', '%s', '%s', '%s');
        foreach (array('slug', 'trial_start', 'note') as $column) {
            if (array_key_exists($column, $extra)) {
                $fields[$column]  = (string) $extra[$column];
                $formats[]        = '%s';
            }
        }

        if ($existing) {
            $wpdb->update($table, $fields, array('id' => (int) $existing['id']), $formats, array('%d'));
            return (int) $existing['id'];
        }

        $fields['listing_id'] = $listing_id;
        $fields['created']    = $now;
        $formats[]            = '%d';
        $formats[]            = '%s';

        $wpdb->insert($table, $fields, $formats);
        return (int) $wpdb->insert_id;
    }
}
