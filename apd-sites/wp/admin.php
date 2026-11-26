<?php
/**
 * Admin screen to review, approve or reject submitted mini-websites, and to
 * keep an eye on optional AI usage.
 */

if (!function_exists('apd_sites_set_status')) {
    /**
     * Update a site's stored status. Only the base statuses are allowed; the
     * trial/live/expired distinction is derived from the status and trial date.
     */
    function apd_sites_set_status(int $site_id, string $status, string $note = ''): bool
    {
        global $wpdb;
        if (!isset($wpdb) || $site_id <= 0) {
            return false;
        }
        $allowed = array('draft', 'pending', 'live', 'rejected');
        if (!in_array($status, $allowed, true)) {
            return false;
        }
        $fields  = array('status' => $status, 'updated' => current_time('mysql'));
        $formats = array('%s', '%s');
        if ('' !== $note) {
            $fields['note'] = mb_substr($note, 0, 255);
            $formats[]      = '%s';
        }
        $wpdb->update(apd_sites_table(), $fields, array('id' => $site_id), $formats, array('%d'));
        return true;
    }
}

if (!function_exists('apd_sites_status_label')) {
    /**
     * Human label for a site's derived state.
     */
    function apd_sites_status_label(string $state): string
    {
        switch ($state) {
            case 'review':
                return 'In review';
            case 'trial':
                return 'Trial';
            case 'live':
                return 'Live';
            case 'expired':
                return 'Expired';
            case 'rejected':
                return 'Rejected';
            default:
                return 'Draft';
        }
    }
}

if (!function_exists('apd_sites_row_state')) {
    function apd_sites_row_state(array $row): string
    {
        return apd_site_trial_state(
            isset($row['status']) ? (string) $row['status'] : 'draft',
            isset($row['trial_start']) ? (string) $row['trial_start'] : '',
            apd_sites_now(),
            apd_sites_billing_on()
        );
    }
}

if (!function_exists('apd_sites_ai_log')) {
    /**
     * AI call counts for the last N days, newest first.
     *
     * @return array date => int
     */
    function apd_sites_ai_log(int $days = 7): array
    {
        $out = array();
        if (!function_exists('get_option')) {
            return $out;
        }
        for ($i = 0; $i < max(1, $days); $i++) {
            $date        = gmdate('Y-m-d', time() - $i * DAY_IN_SECONDS);
            $out[$date]  = (int) get_option('apd_sites_ai_calls_' . $date, 0);
        }
        return $out;
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

if (!function_exists('apd_sites_admin_handle_post')) {
    function apd_sites_admin_handle_post(): void
    {
        if ('POST' !== strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') || !isset($_POST['apd_sites_action'])) {
            return;
        }
        check_admin_referer('apd_sites_admin');

        $site_id = isset($_POST['site_id']) ? (int) $_POST['site_id'] : 0;
        $action  = sanitize_key((string) $_POST['apd_sites_action']);
        $reason  = isset($_POST['reason']) ? sanitize_text_field(wp_unslash((string) $_POST['reason'])) : '';

        $status = '';
        if ('approve' === $action) {
            $status = 'live';
        } elseif ('reject' === $action) {
            $status = 'rejected';
        } elseif ('review' === $action) {
            $status = 'pending';
        }

        if ($site_id > 0 && '' !== $status) {
            apd_sites_set_status($site_id, $status, 'rejected' === $status ? $reason : '');
            $site = apd_sites_get_site($site_id);
            if ($site && 'pending' !== $status) {
                apd_sites_resolve_reports((int) $site['listing_id']);
            }
            echo '<div class="notice notice-success"><p>Site updated.</p></div>';
        }
    }
}

if (!function_exists('apd_sites_admin_page')) {
    function apd_sites_admin_page(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('You are not allowed to review sites.');
        }

        apd_sites_admin_handle_post();

        $rows = apd_sites_all_sites(200);

        echo '<div class="wrap"><h1>Tradie mini-websites</h1>';
        echo '<p>The site queue mirrors the directory reports queue (reason: Site review). '
            . 'Plan: ' . esc_html(apd_site_plan_text()) . '.</p>';

        echo '<table class="widefat striped"><thead><tr>';
        echo '<th>ID</th><th>Listing</th><th>Template</th><th>Status</th><th>Trial left</th><th>Updated</th><th>Preview</th><th>Action</th>';
        echo '</tr></thead><tbody>';

        foreach ($rows as $row) {
            $site_id    = (int) $row['id'];
            $listing_id = (int) $row['listing_id'];
            $template   = (string) $row['template'];
            $state      = apd_sites_row_state($row);
            $days       = apd_site_trial_days_left(isset($row['trial_start']) ? (string) $row['trial_start'] : '', apd_sites_now());
            $preview    = apd_sites_step_url($listing_id, 6, $template);
            $live       = '' !== (string) $row['slug'] ? apd_sites_live_url((string) $row['slug']) : '';

            echo '<tr>';
            echo '<td>' . esc_html($site_id) . '</td>';
            echo '<td>#' . esc_html($listing_id) . '</td>';
            echo '<td>' . esc_html($template) . '</td>';
            echo '<td>' . esc_html(apd_sites_status_label($state));
            if ('rejected' === $state && '' !== (string) $row['note']) {
                echo '<br><small>' . esc_html((string) $row['note']) . '</small>';
            }
            echo '</td>';
            echo '<td>' . ('trial' === $state ? esc_html($days . ' d') : '&mdash;') . '</td>';
            echo '<td>' . esc_html((string) $row['updated']) . '</td>';
            echo '<td><a href="' . esc_url($preview) . '">Preview</a>';
            if ('' !== $live) {
                echo ' · <a href="' . esc_url($live) . '" target="_blank" rel="noopener">Open</a>';
            }
            echo '</td>';
            echo '<td>';
            echo '<form method="post" style="display:flex;flex-wrap:wrap;gap:4px;align-items:center">';
            wp_nonce_field('apd_sites_admin');
            echo '<input type="hidden" name="site_id" value="' . esc_attr($site_id) . '">';
            echo '<button class="button button-primary" name="apd_sites_action" value="approve">Approve</button> ';
            echo '<button class="button" name="apd_sites_action" value="review">Back to review</button> ';
            echo '<input type="text" name="reason" placeholder="Reject reason" value="">';
            echo '<button class="button" name="apd_sites_action" value="reject">Reject</button>';
            echo '</form>';
            echo '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';

        // Optional AI usage.
        $cap = apd_sites_ai_daily_cap();
        $log = apd_sites_ai_log(7);
        echo '<h2>AI calls</h2>';
        if (!apd_sites_ai_enabled()) {
            echo '<p>AI writing is off. The built-in writer is used instead.</p>';
        } else {
            echo '<p>Daily cap: ' . esc_html($cap) . ' calls. Today: ' . esc_html(apd_sites_ai_calls_today()) . '.</p>';
        }
        echo '<table class="widefat striped" style="max-width:320px"><thead><tr><th>Day</th><th>Calls</th></tr></thead><tbody>';
        foreach ($log as $date => $count) {
            echo '<tr><td>' . esc_html($date) . '</td><td>' . esc_html($count) . '</td></tr>';
        }
        echo '</tbody></table></div>';
    }
}

if (function_exists('add_action')) {
    add_action('admin_menu', 'apd_sites_admin_menu');
}
