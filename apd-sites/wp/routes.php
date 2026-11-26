<?php
/**
 * Request routing for tradie mini-websites.
 *
 * Owner flow:  /my-site/<listing_id>/
 * Public page: /site/<listing_id>-<slug>/
 */

if (!function_exists('apd_sites_on')) {
    function apd_sites_on(): bool
    {
        return defined('APD_SITES_ON') && APD_SITES_ON;
    }
}

if (!function_exists('apd_sites_billing_on')) {
    function apd_sites_billing_on(): bool
    {
        return defined('APD_SITES_BILLING') && APD_SITES_BILLING;
    }
}

if (!function_exists('apd_sites_indexing_on')) {
    function apd_sites_indexing_on(): bool
    {
        return defined('APD_SITES_INDEXING') && APD_SITES_INDEXING;
    }
}

if (!function_exists('apd_sites_owner_url')) {
    function apd_sites_owner_url(int $listing_id): string
    {
        return home_url('/my-site/' . $listing_id . '/');
    }
}

if (!function_exists('apd_sites_live_url')) {
    function apd_sites_live_url(int $listing_id, string $slug): string
    {
        return home_url('/site/' . $listing_id . '-' . $slug . '/');
    }
}

if (!function_exists('apd_sites_claim_url')) {
    function apd_sites_claim_url(int $listing_id): string
    {
        return home_url('/claim/' . $listing_id . '/');
    }
}

if (!function_exists('apd_sites_send_404')) {
    function apd_sites_send_404(): void
    {
        if (function_exists('status_header')) {
            status_header(404);
        }
        if (function_exists('nocache_headers')) {
            nocache_headers();
        }
        if (function_exists('header')) {
            header('Content-Type: text/html; charset=utf-8');
        }
        echo '<!DOCTYPE html><html lang="en-AU"><head><meta charset="utf-8">'
            . '<meta name="robots" content="noindex,follow"><title>Not found</title></head>'
            . '<body><h1>Not found</h1></body></html>';
    }
}

if (!function_exists('apd_sites_rate_limit')) {
    /**
     * Simple transient backed rate limit keyed by the current user.
     */
    function apd_sites_rate_limit(string $bucket, int $max, int $window): bool
    {
        $user_id = apd_me();
        if ($user_id <= 0) {
            return false;
        }
        $key   = 'apd_sites_rl_' . $bucket . '_' . $user_id;
        $count = (int) get_transient($key);
        if ($count >= $max) {
            return false;
        }
        set_transient($key, $count + 1, $window);
        return true;
    }
}

if (!function_exists('apd_sites_insert_report')) {
    /**
     * Add a row to the existing directory reports queue so the site appears
     * for review. Only columns that exist in the table are written.
     */
    function apd_sites_insert_report(int $listing_id, int $user_id): int
    {
        global $wpdb;
        if (!isset($wpdb) || $listing_id <= 0) {
            return 0;
        }
        $table  = apd_tb('reports');
        $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
        if ($exists !== $table) {
            return 0;
        }
        $cols = $wpdb->get_col("DESC {$table}", 0);
        if (!is_array($cols) || !$cols) {
            return 0;
        }

        $now  = current_time('mysql');
        $map  = array(
            'listing_id' => $listing_id,
            'post_id'    => $listing_id,
            'user_id'    => $user_id,
            'reason'     => 'Site review',
            'type'       => 'Site review',
            'status'     => 'pending',
            'created'    => $now,
            'created_at' => $now,
        );
        $data = array();
        $fmt  = array();
        foreach ($map as $column => $value) {
            if (in_array($column, $cols, true)) {
                $data[$column] = $value;
                $fmt[]         = is_int($value) ? '%d' : '%s';
            }
        }
        if (!$data) {
            return 0;
        }
        $wpdb->insert($table, $data, $fmt);
        return (int) $wpdb->insert_id;
    }
}

if (!function_exists('apd_sites_collect')) {
    /**
     * Clean submitted values using the template field definitions.
     */
    function apd_sites_collect(array $fields, array $src): array
    {
        $out = array();
        foreach ($fields as $field) {
            if (!is_array($field) || !isset($field['key'])) {
                continue;
            }
            $key   = (string) $field['key'];
            $type  = isset($field['type']) ? (string) $field['type'] : 'text';
            $max   = isset($field['max']) ? (int) $field['max'] : 0;
            $value = array_key_exists($key, $src) ? $src[$key] : '';

            switch ($type) {
                case 'email':
                    $out[$key] = apd_site_clean_email($value);
                    break;
                case 'url':
                    $out[$key] = apd_site_clean_url($value);
                    break;
                case 'tel':
                    $out[$key] = apd_site_clean_phone($value);
                    break;
                case 'textarea':
                case 'lines':
                    $out[$key] = apd_site_clean_lines($value, $max);
                    break;
                default:
                    $out[$key] = apd_site_clean_text($value, $max);
            }
        }
        return $out;
    }
}

if (!function_exists('apd_sites_parse_services')) {
    function apd_sites_parse_services($text): array
    {
        $out = array();
        $lines = preg_split('/\r\n|\r|\n/', (string) $text);
        foreach ($lines as $line) {
            $line = trim((string) $line);
            if ('' !== $line) {
                $out[] = array('title' => $line);
            }
        }
        return $out;
    }
}

if (!function_exists('apd_sites_prefill')) {
    /**
     * Prefill values from real listing fields only.
     */
    function apd_sites_prefill(array $listing): array
    {
        $keys = array('name', 'phone', 'email', 'website', 'suburb', 'abn', 'licence', 'hours', 'description');
        $out  = array();
        foreach ($keys as $key) {
            $out[$key] = isset($listing[$key]) ? (string) $listing[$key] : '';
        }
        return $out;
    }
}

if (!function_exists('apd_sites_render_gallery')) {
    function apd_sites_render_gallery(array $listing, string $root, array $trades): void
    {
        $gallery   = apd_site_gallery($root, $trades);
        $listing_id = (int) $listing['id'];

        $body  = '<div class="apd-sites-gallery"><h1>Choose a layout</h1>';
        $body .= '<p>Build a free mini-website for <strong>' . apd_esc($listing['name']) . '</strong>.</p>';

        if (!$gallery) {
            $body .= '<p>No layouts are available yet.</p>';
        } else {
            $body .= '<ul class="apd-sites-list">';
            foreach ($gallery as $id => $meta) {
                $label = !empty($meta['example']) ? 'Example layout' : 'Layout';
                $url   = add_query_arg('template', $id, apd_sites_owner_url($listing_id));
                $body .= '<li class="apd-site-card"><h2>' . apd_esc($meta['name']) . '</h2>';
                $body .= '<p class="apd-site-badge">' . apd_esc($label) . '</p>';
                $body .= '<p><a class="apd-site-choose" href="' . apd_esc($url) . '">Use this layout</a></p></li>';
            }
            $body .= '</ul>';
        }
        $body .= '</div>';

        apd_layout('Choose a layout', $body, apd_url($listing), 'Pick a layout for your free tradie website.', false);
    }
}

if (!function_exists('apd_sites_render_form')) {
    function apd_sites_render_form(array $listing, array $meta, array $values = array(), array $errors = array()): void
    {
        $values = array_merge(apd_sites_prefill($listing), $values);
        $action = apd_sites_owner_url((int) $listing['id']);

        $body  = '<div class="apd-sites-form"><h1>' . apd_esc($meta['name']) . '</h1>';
        if ($errors) {
            $body .= '<ul class="apd-errors">';
            foreach ($errors as $error) {
                $body .= '<li>' . apd_esc($error) . '</li>';
            }
            $body .= '</ul>';
        }

        $body .= '<form method="post" enctype="multipart/form-data" action="' . apd_esc($action) . '">';
        ob_start();
        apd_nonce('apd_site');
        $body .= ob_get_clean();
        $body .= '<input type="hidden" name="template" value="' . apd_esc($meta['id']) . '">';

        foreach ($meta['fields'] as $field) {
            $key   = (string) $field['key'];
            $label = isset($field['label']) ? (string) $field['label'] : $key;
            $type  = isset($field['type']) ? (string) $field['type'] : 'text';
            $value = isset($values[$key]) ? (string) $values[$key] : '';
            if (isset($errors[$key])) {
                $label .= ' <span class="apd-error">' . apd_esc($errors[$key]) . '</span>';
            }
            $required = !empty($field['required']) ? ' required' : '';
            $body .= '<p class="apd-field"><label>' . apd_esc($label) . '<br>';
            if ('textarea' === $type || 'lines' === $type) {
                $body .= '<textarea name="' . apd_esc($key) . '"' . $required . '>' . apd_esc($value) . '</textarea>';
            } else {
                $body .= '<input type="' . apd_esc($type) . '" name="' . apd_esc($key) . '" value="' . apd_esc($value) . '"' . $required . '>';
            }
            $body .= '</label></p>';
        }

        $body .= '<p class="apd-field"><label>Photos (jpg, png or webp, max 5 MB each)<br>'
            . '<input type="file" name="apd_photos[]" accept="image/jpeg,image/png,image/webp" multiple></label></p>';
        $body .= '<p class="apd-field"><label><input type="checkbox" name="own_photos" value="1"> These are my own photos</label></p>';
        $body .= '<p class="apd-actions"><button type="submit" name="apd_action" value="preview">Preview</button> '
            . '<button type="submit" name="apd_action" value="submit">Submit for review</button></p>';
        $body .= '</form></div>';

        apd_layout('Build your site', $body, apd_sites_owner_url((int) $listing['id']), 'Build your free tradie website.', false);
    }
}

if (!function_exists('apd_sites_render_preview')) {
    function apd_sites_render_preview(array $meta, array $data): void
    {
        $root   = apd_site_templates_root();
        $footer = '<footer class="apd-site-footer"><div class="apd-preview-banner">Preview &mdash; not public yet</div></footer>';
        echo apd_site_render_document($root, (string) $meta['id'], $data, array(
            'title'       => isset($data['name']) ? (string) $data['name'] : (string) $meta['name'],
            'description' => isset($data['description']) ? (string) $data['description'] : '',
            'indexing'    => false,
            'footer'      => $footer,
        ));
    }
}

if (!function_exists('apd_sites_render_live')) {
    function apd_sites_render_live(array $listing, array $meta, array $data, string $status): void
    {
        $indexing = ('live' === $status) && apd_sites_indexing_on();
        if (!$indexing && function_exists('header')) {
            header('X-Robots-Tag: noindex, follow');
        }
        $footer = '<footer class="apd-site-footer"><a href="' . apd_esc(apd_url($listing)) . '">Listed on aussieplatform</a></footer>';
        echo apd_site_render_document(apd_site_templates_root(), (string) $meta['id'], $data, array(
            'title'       => isset($data['name']) ? (string) $data['name'] : (string) $meta['name'],
            'description' => isset($data['description']) ? (string) $data['description'] : '',
            'indexing'    => $indexing,
            'footer'      => $footer,
        ));
    }
}

if (!function_exists('apd_sites_handle_owner_post')) {
    function apd_sites_handle_owner_post(array $listing, string $root): void
    {
        if (!apd_check('apd_site')) {
            apd_sites_send_404();
            return;
        }

        $action   = isset($_POST['apd_action']) ? sanitize_key((string) $_POST['apd_action']) : '';
        $template = isset($_POST['template']) ? apd_site_slug((string) $_POST['template']) : '';
        $meta     = apd_site_load_meta($root, $template);
        if (!is_array($meta) || !in_array($action, array('preview', 'submit'), true)) {
            apd_sites_send_404();
            return;
        }

        $fields  = $meta['fields'];
        $clean   = apd_sites_collect($fields, $_POST);
        $errors  = apd_site_validate($fields, $clean);
        $failed  = apd_site_moderate($clean, 'apd_family_ok');
        $hold    = !empty($failed);

        // Photos.
        $photos = array();
        $has_upload = !empty($_FILES['apd_photos']['name']) && is_array($_FILES['apd_photos']['name'])
            ? array_filter($_FILES['apd_photos']['name'], static function ($n) {
                return '' !== trim((string) $n);
            })
            : array();

        if ($has_upload) {
            if (empty($_POST['own_photos'])) {
                $errors['photos'] = 'Please tick "These are my own photos".';
            } elseif (!apd_sites_rate_limit('upload', 20, HOUR_IN_SECONDS)) {
                $errors['photos'] = 'Too many uploads. Please try again later.';
            } else {
                $photo_errors = array();
                $photos       = apd_sites_handle_photos('apd_photos', (int) $listing['id'], $photo_errors);
                if ($photo_errors) {
                    $errors['photos'] = implode(' ', $photo_errors);
                }
            }
        }

        $existing      = apd_sites_get_site_by_listing((int) $listing['id']);
        $existing_data = $existing ? json_decode((string) $existing['data'], true) : array();
        if (!is_array($existing_data)) {
            $existing_data = array();
        }
        if (!$photos && !empty($existing_data['photos']) && is_array($existing_data['photos'])) {
            $photos = $existing_data['photos'];
        }
        $clean['photos']   = $photos;
        $clean['services'] = apd_sites_parse_services(isset($clean['services']) ? $clean['services'] : '');
        if ($hold) {
            $clean['_hold'] = 1;
        }

        if ($errors) {
            $clean['services'] = isset($_POST['services']) ? apd_site_clean_lines($_POST['services'], 600) : '';
            apd_sites_render_form($listing, $meta, $clean, $errors);
            return;
        }

        if ('preview' === $action) {
            apd_sites_render_preview($meta, $clean);
            return;
        }

        // Submit for review.
        if (!apd_sites_rate_limit('submit', 5, HOUR_IN_SECONDS)) {
            apd_sites_render_form($listing, $meta, $clean, array('form' => 'Too many submissions. Please try again later.'));
            return;
        }

        apd_sites_save_site((int) $listing['id'], apd_me(), (string) $meta['id'], $clean, 'pending');
        apd_sites_insert_report((int) $listing['id'], apd_me());
        apd_flash('Your website was sent for review.', 'success');
        apd_go(apd_sites_owner_url((int) $listing['id']));
    }
}

if (!function_exists('apd_sites_handle_owner')) {
    function apd_sites_handle_owner(array $route): void
    {
        apd_need_login();
        $listing_id = (int) $route['listing_id'];
        $listing    = apd_get($listing_id);
        if (!is_array($listing)) {
            apd_sites_send_404();
            return;
        }

        $is_admin = function_exists('current_user_can') && current_user_can('manage_options');
        if ((int) $listing['user_id'] !== apd_me() && !$is_admin) {
            apd_go(apd_sites_claim_url($listing_id));
        }

        $root   = apd_site_templates_root();
        $trades = (isset($listing['trades']) && is_array($listing['trades'])) ? $listing['trades'] : array();

        if ('POST' === strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET')) {
            apd_sites_handle_owner_post($listing, $root);
            return;
        }

        $template = isset($_GET['template']) ? apd_site_slug((string) $_GET['template']) : '';
        if ('' !== $template) {
            $meta    = apd_site_load_meta($root, $template);
            $current = apd_sites_get_site_by_listing($listing_id);
            $values  = array();
            if (is_array($meta) && $current) {
                $stored = json_decode((string) $current['data'], true);
                if (is_array($stored)) {
                    $values = $stored;
                    if (isset($stored['services']) && is_array($stored['services'])) {
                        $values['services'] = implode("\n", array_map(static function ($s) {
                            return is_array($s) ? (string) ($s['title'] ?? '') : (string) $s;
                        }, $stored['services']));
                    }
                }
            }
            if (is_array($meta)) {
                apd_sites_render_form($listing, $meta, $values);
                return;
            }
        }

        apd_sites_render_gallery($listing, $root, $trades);
    }
}

if (!function_exists('apd_sites_handle_live')) {
    function apd_sites_handle_live(array $route): void
    {
        $listing_id = (int) $route['listing_id'];
        $listing    = apd_get($listing_id);
        if (!is_array($listing)) {
            apd_sites_send_404();
            return;
        }

        $row = apd_sites_get_site_by_listing($listing_id);
        if (!$row) {
            apd_sites_send_404();
            return;
        }

        $status   = (string) $row['status'];
        $is_owner = apd_me() > 0 && apd_me() === (int) $listing['user_id'];
        $is_admin = function_exists('current_user_can') && current_user_can('manage_options');

        if ('live' !== $status && !$is_owner && !$is_admin) {
            apd_sites_send_404();
            return;
        }

        $meta = apd_site_load_meta(apd_site_templates_root(), (string) $row['template']);
        if (!is_array($meta)) {
            apd_sites_send_404();
            return;
        }

        $data = json_decode((string) $row['data'], true);
        if (!is_array($data)) {
            $data = array();
        }

        apd_sites_render_live($listing, $meta, $data, $status);
    }
}

if (!function_exists('apd_sites_router')) {
    function apd_sites_router(): void
    {
        $uri   = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
        $route = apd_site_match_route(apd_site_request_path($uri));
        if (!$route) {
            return;
        }

        $is_admin = function_exists('current_user_can') && current_user_can('manage_options');
        if (404 === apd_site_route_gate($route, $is_admin, apd_sites_on())) {
            apd_sites_send_404();
            return;
        }

        if ('owner' === $route['route']) {
            apd_sites_handle_owner($route);
        } else {
            apd_sites_handle_live($route);
        }
        exit;
    }
}

if (function_exists('add_action')) {
    add_action('template_redirect', 'apd_sites_router');
}
