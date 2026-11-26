<?php
/**
 * Admin screen to review, approve or reject submitted mini-websites.
 */

if (!function_exists('apd_sites_set_status')) {
    function apd_sites_set_status(int $site_id, string $status): bool
    {
        global $wpdb;
        if (!isset($wpdb) || $site_id <= 0) {
            return false;
        }
        $allowed = array('draft', 'pending', 'live', 'rejected');
        if (!in_array($status, $allowed, true)) {
            return false;
        }
        $wpdb->update(
            apd_sites_table(),
            array('status' => $status, 'updated' => current_time('mysql')),
            array('id' => $site_id),
            array('%s', '%s'),
            array('%d')
        );
        return true;
    }
}

if (!function_exists('apd_sites_resolve_reports')) {
    /**
     * Close the matching rows in the existing reports queue.
     */
    function apd_sites_resolve_reports(int $listing_id): void
    {
        global $wpdb;
        if (!isset($wpdb) || $listing_id <= 0) {
            return;
        }
        $table = apd_tb('reports');
        $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
        if ($exists !== $table) {
            return;
        }
        $cols = $wpdb->get_col("DESC {$table}", 0);
        if (!is_array($cols) || !in_array('status', $cols, true)) {
            return;
        }
        $key = in_array('listing_id', $cols, true) ? 'listing_id' : (in_array('post_id', $cols, true) ? 'post_id' : '');
        if ('' === $key) {
            return;
        }
        $wpdb->update($table, array('status' => 'closed'), array($key => $listing_id, 'reason' => 'Site review'), array('%s'), array('%d', '%s'));
    }
}

if (!function_exists('apd_sites_admin_menu')) {
    function apd_sites_admin_menu(): void
    {
        add_menu_page(
            'Tradie sites',
            'Tradie sites',
            'manage_options',
            'apd-sites',
            'apd_sites_admin_page',
            'dashicons-admin-site-alt3',
            58
        );
    }
}

if (!function_exists('apd_sites_admin_page')) {
    function apd_sites_admin_page(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('You are not allowed to review sites.');
        }

        if ('POST' === strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') && isset($_POST['apd_sites_action'])) {
            check_admin_referer('apd_sites_admin');
            $site_id = isset($_POST['site_id']) ? (int) $_POST['site_id'] : 0;
            $action  = sanitize_key((string) $_POST['apd_sites_action']);
            $status  = 'approve' === $action ? 'live' : ('reject' === $action ? 'rejected' : '');
            if ($site_id > 0 && '' !== $status) {
                apd_sites_set_status($site_id, $status);
                $site = apd_sites_get_site((int) $site_id);
                if ($site) {
                    apd_sites_resolve_reports((int) $site['listing_id']);
                }
                echo '<div class="notice notice-success"><p>Site updated.</p></div>';
            }
        }

        global $wpdb;
        $table = apd_sites_table();
        $rows  = $wpdb->get_results("SELECT * FROM {$table} ORDER BY id DESC LIMIT 200", ARRAY_A);
        if (!is_array($rows)) {
            $rows = array();
        }

        echo '<div class="wrap"><h1>Tradie mini-websites</h1>';
        echo '<p>The site queue mirrors the directory reports queue (reason: Site review).</p>';
        echo '<table class="widefat striped"><thead><tr>';
        echo '<th>ID</th><th>Listing</th><th>Template</th><th>Status</th><th>Updated</th><th>Preview</th><th>Action</th>';
        echo '</tr></thead><tbody>';

        foreach ($rows as $row) {
            $site_id    = (int) $row['id'];
            $listing_id = (int) $row['listing_id'];
            $preview    = add_query_arg(array('apd_preview' => $site_id), apd_sites_owner_url($listing_id));
            echo '<tr>';
            echo '<td>' . esc_html($site_id) . '</td>';
            echo '<td>#' . esc_html($listing_id) . '</td>';
            echo '<td>' . esc_html($row['template']) . '</td>';
            echo '<td>' . esc_html($row['status']) . '</td>';
            echo '<td>' . esc_html($row['updated']) . '</td>';
            echo '<td><a href="' . esc_url($preview) . '">Open</a></td>';
            echo '<td>';
            echo '<form method="post" style="display:inline">';
            wp_nonce_field('apd_sites_admin');
            echo '<input type="hidden" name="site_id" value="' . esc_attr($site_id) . '">';
            echo '<button class="button button-primary" name="apd_sites_action" value="approve">Approve</button> ';
            echo '<button class="button" name="apd_sites_action" value="reject">Reject</button>';
            echo '</form>';
            echo '</td>';
            echo '</tr>';
        }

        echo '</tbody></table></div>';
    }
}

if (!function_exists('apd_sites_get_site')) {
    function apd_sites_get_site(int $site_id)
    {
        global $wpdb;
        if (!isset($wpdb) || $site_id <= 0) {
            return null;
        }
        $table = apd_sites_table();
        $row   = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $site_id), ARRAY_A);
        return $row ?: null;
    }
}

if (function_exists('add_action')) {
    add_action('admin_menu', 'apd_sites_admin_menu');
}
