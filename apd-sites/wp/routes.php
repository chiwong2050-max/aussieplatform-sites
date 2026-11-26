<?php
/**
 * Request routing and the self-service website builder.
 *
 * Owner flow:  /my-site/<listing_id>/            (7 steps, one screen each)
 * Public page: /site/<slug>/                    (rendered when live + approved)
 *
 * The directory core (zz-apd-core.php) exposes a listing as a database row
 * object: $l->id, $l->user_id, $l->name, $l->trades (comma separated),
 * $l->suburb_id, $l->phone, $l->website, $l->email, $l->abn, $l->licence,
 * $l->descr, $l->hours.
 */

if (!function_exists('apd_esc')) {
    /**
     * Attribute escaper. The core only ships apd_e() for HTML text, so the
     * plugin keeps its own attribute-safe helper.
     *
     * @param mixed $value
     */
    function apd_esc($value): string
    {
        if (function_exists('esc_attr')) {
            return esc_attr((string) $value);
        }
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

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

if (!function_exists('apd_sites_step_url')) {
    function apd_sites_step_url(int $listing_id, int $step, string $template = ''): string
    {
        $args = array('step' => max(1, min(7, $step)));
        if ('' !== $template) {
            $args['template'] = $template;
        }
        return add_query_arg($args, apd_sites_owner_url($listing_id));
    }
}

if (!function_exists('apd_sites_live_url')) {
    function apd_sites_live_url(string $slug): string
    {
        return home_url('/site/' . $slug . '/');
    }
}

if (!function_exists('apd_sites_claim_url')) {
    function apd_sites_claim_url(int $listing_id): string
    {
        return home_url('/claim/' . $listing_id . '/');
    }
}

if (!function_exists('apd_sites_template_url')) {
    /**
     * Public URL of a template folder (for stock images and thumbnails).
     */
    function apd_sites_template_url(string $id): string
    {
        $id = basename($id);
        if (defined('WPMU_PLUGIN_URL')) {
            return rtrim(WPMU_PLUGIN_URL, '/') . '/apd-sites/templates/' . $id;
        }
        return '';
    }
}

if (!function_exists('apd_sites_now')) {
    function apd_sites_now(): int
    {
        if (function_exists('current_time')) {
            $t = current_time('timestamp');
            if (is_numeric($t)) {
                return (int) $t;
            }
        }
        return time();
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

if (!function_exists('apd_sites_listing_trades')) {
    /**
     * Trades for a listing row (comma separated string in the directory).
     *
     * @return string[]
     */
    function apd_sites_listing_trades(object $listing): array
    {
        $raw = isset($listing->trades) ? (string) $listing->trades : '';
        $out = array();
        foreach (explode(',', $raw) as $slug) {
            $slug = trim($slug);
            if ('' !== $slug) {
                $out[] = $slug;
            }
        }
        return $out;
    }
}

if (!function_exists('apd_sites_insert_report')) {
    /**
     * Add a row to the existing directory reports queue so the site appears
     * for review (the core admin queue lists reports where status='open').
     * Only columns that exist in the table are written.
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

        $salt = function_exists('wp_salt') ? wp_salt() : '';
        $ip   = md5((string) ($_SERVER['REMOTE_ADDR'] ?? '') . $salt);
        $now  = current_time('mysql');

        $map = array(
            'listing_id'  => $listing_id,
            'post_id'     => $listing_id,
            'reporter_id' => $user_id,
            'user_id'     => $user_id,
            'reason'      => 'Site review',
            'type'        => 'Site review',
            'details'     => 'A tradie mini-website was submitted for review.',
            'ip'          => $ip,
            'status'      => 'open',
            'created'     => $now,
            'created_at'  => $now,
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

/* ------------------------------------------------------------------------- *
 * Field schema
 * ------------------------------------------------------------------------- */

if (!function_exists('apd_sites_detail_fields')) {
    /**
     * Step 2 fields: contact and business details.
     *
     * @return array
     */
    function apd_sites_detail_fields(): array
    {
        return array(
            array('key' => 'business_name', 'label' => 'Business name',   'type' => 'text',     'required' => true,  'max' => 80),
            array('key' => 'phone',         'label' => 'Phone',           'type' => 'tel',      'required' => true,  'max' => 32),
            array('key' => 'email',         'label' => 'Email',           'type' => 'email',    'required' => false, 'max' => 120),
            array('key' => 'suburb',        'label' => 'Suburb',          'type' => 'text',     'required' => false, 'max' => 60),
            array('key' => 'state',         'label' => 'State',           'type' => 'text',     'required' => false, 'max' => 20),
            array('key' => 'service_area',  'label' => 'Service area',    'type' => 'text',     'required' => false, 'max' => 120),
            array('key' => 'abn',           'label' => 'ABN',             'type' => 'text',     'required' => false, 'max' => 20),
            array('key' => 'licence',       'label' => 'Licence number',  'type' => 'text',     'required' => false, 'max' => 60),
            array('key' => 'logo',          'label' => 'Logo URL',        'type' => 'url',      'required' => false, 'max' => 240),
            array('key' => 'hours',         'label' => 'Opening hours',   'type' => 'text',     'required' => false, 'max' => 120),
        );
    }
}

if (!function_exists('apd_sites_words_fields')) {
    /**
     * Step 4 fields: the written words.
     *
     * @return array
     */
    function apd_sites_words_fields(): array
    {
        return array(
            array('key' => 'hero_heading', 'label' => 'Headline',            'type' => 'text',     'required' => false, 'max' => 120),
            array('key' => 'hero_sub',     'label' => 'Sub-heading',         'type' => 'text',     'required' => false, 'max' => 200),
            array('key' => 'about',        'label' => 'About your business', 'type' => 'textarea', 'required' => false, 'max' => 1200),
            array('key' => 'cta_text',     'label' => 'Call to action',      'type' => 'text',     'required' => false, 'max' => 60),
        );
    }
}

if (!function_exists('apd_sites_collect')) {
    /**
     * Clean submitted values using a field definition list.
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
            if (function_exists('wp_unslash')) {
                $value = wp_unslash($value);
            }

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

if (!function_exists('apd_sites_prefill')) {
    /**
     * Prefill the details step from real listing fields only.
     */
    function apd_sites_prefill(object $listing): array
    {
        $suburb = '';
        $state  = '';
        if (function_exists('apd_sub') && !empty($listing->suburb_id)) {
            $sub = apd_sub((int) $listing->suburb_id);
            if ($sub) {
                $suburb = (string) ($sub->name ?? '');
                $state  = (string) ($sub->state ?? '');
            }
        }
        $logo = '';
        if (!empty($listing->logo_id) && function_exists('wp_get_attachment_image_url')) {
            $url = wp_get_attachment_image_url((int) $listing->logo_id, 'medium');
            if ($url) {
                $logo = (string) $url;
            }
        }

        return array(
            'business_name' => (string) ($listing->name ?? ''),
            'phone'         => (string) ($listing->phone ?? ''),
            'email'         => (string) ($listing->email ?? ''),
            'suburb'        => $suburb,
            'state'         => $state,
            'service_area'  => $suburb,
            'abn'           => (string) ($listing->abn ?? ''),
            'licence'       => (string) ($listing->licence ?? ''),
            'logo'          => $logo,
            'hours'         => (string) ($listing->hours ?? ''),
            'about'         => (string) ($listing->descr ?? ''),
        );
    }
}

if (!function_exists('apd_sites_normalise_services')) {
    /**
     * Force a services list into array( title, text, image ) rows.
     */
    function apd_sites_normalise_services($services): array
    {
        $out = array();
        foreach ((array) $services as $item) {
            if (is_array($item)) {
                $title = trim((string) ($item['title'] ?? ''));
                if ('' === $title) {
                    continue;
                }
                $out[] = array(
                    'title' => $title,
                    'text'  => (string) ($item['text'] ?? ''),
                    'image' => (string) ($item['image'] ?? ''),
                );
            } elseif (is_string($item)) {
                $title = trim($item);
                if ('' !== $title) {
                    $out[] = array('title' => $title, 'text' => '', 'image' => '');
                }
            }
        }
        return $out;
    }
}

if (!function_exists('apd_sites_render_data')) {
    /**
     * Turn stored owner data into the full set of values a template may use:
     * aliases for the simple example layouts, owner photos as the gallery, and
     * the template's stock images filling any gaps.
     */
    function apd_sites_render_data(array $meta, array $data, int $listing_id = 0): array
    {
        $out = $data;

        // Aliases so both the rich templates and the simple example templates render.
        if (empty($out['name']) && !empty($data['business_name'])) {
            $out['name'] = (string) $data['business_name'];
        }
        if (empty($out['description']) && !empty($data['about'])) {
            $out['description'] = (string) $data['about'];
        }
        $out['services'] = apd_sites_normalise_services($data['services'] ?? array());

        // Owner photos become the gallery; the template stock fills the rest.
        $photos = isset($data['photos']) && is_array($data['photos']) ? array_values(array_filter($data['photos'])) : array();
        if ($photos) {
            $out['gallery'] = array_map(static function ($url) {
                return array('image' => (string) $url, 'caption' => '');
            }, $photos);
        }

        $stock = isset($meta['stock']) && is_array($meta['stock']) ? $meta['stock'] : array();
        $base  = apd_sites_template_url((string) ($meta['id'] ?? ''));
        $out   = apd_site_stock_merge($stock, $out, $base);

        // Testimonials stay hidden unless the owner adds real ones.
        if (!isset($out['testimonials']) || !is_array($out['testimonials'])) {
            $out['testimonials'] = array();
        }

        return $out;
    }
}

/* ------------------------------------------------------------------------- *
 * Shared UI
 * ------------------------------------------------------------------------- */

if (!function_exists('apd_sites_wizard_css')) {
    function apd_sites_wizard_css(): string
    {
        return '<style>'
            . '.apd-sites-wizard{max-width:780px;margin:0 auto}'
            . '.apd-steps{display:flex;flex-wrap:wrap;gap:6px;margin:0 0 14px;padding:0;list-style:none;font-size:13px}'
            . '.apd-steps li{padding:4px 10px;border-radius:999px;background:#eef0f3;color:#4b5563}'
            . '.apd-steps li.on{background:var(--c);color:#fff;font-weight:700}'
            . '.apd-sites-wizard h1{margin:0 0 6px;font-size:24px}'
            . '.apd-sites-wizard .sub{color:#6b7280;margin:0 0 16px}'
            . '.apd-field{display:block;margin:0 0 12px}'
            . '.apd-field>span{display:block;font-weight:600;margin:0 0 4px}'
            . '.apd-field input,.apd-field textarea{width:100%;padding:12px;border:1px solid #d1d5db;border-radius:10px;font:inherit;box-sizing:border-box}'
            . '.apd-actions{display:flex;flex-wrap:wrap;gap:10px;margin:16px 0 0}'
            . '.apd-actions button,.apd-actions .btn{min-height:48px;padding:12px 20px;border-radius:10px;font:inherit;font-weight:700;cursor:pointer;border:0}'
            . '.apd-actions .apd-primary{background:var(--c);color:#fff}'
            . '.apd-designs{display:grid;gap:14px;grid-template-columns:repeat(auto-fill,minmax(200px,1fr))}'
            . '.apd-design{border:1px solid #e5e7eb;border-radius:12px;overflow:hidden;background:#fff}'
            . '.apd-design img{display:block;width:100%;height:140px;object-fit:cover;background:#f3f4f6}'
            . '.apd-design .bd{padding:10px}'
            . '.apd-design h2{font-size:16px;margin:0 0 6px}'
            . '.apd-badge{display:inline-block;font-size:12px;background:#eef2ff;color:#3730a3;border-radius:999px;padding:2px 8px}'
            . '.apd-error{color:#b91c1c;font-size:13px;font-weight:600}'
            . '.apd-errors{color:#b91c1c;margin:0 0 12px}'
            . '.apd-checks{display:grid;gap:8px;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));margin:0 0 16px}'
            . '.apd-checks label{display:flex;gap:8px;align-items:center;font-weight:500}'
            . '.apd-previews{display:grid;gap:16px}'
            . '.apd-preview{border:1px solid #e5e7eb;border-radius:12px;overflow:hidden;background:#fff}'
            . '.apd-preview .bar{padding:8px 12px;font-weight:700;background:#f9fafb;border-bottom:1px solid #e5e7eb}'
            . '.apd-preview iframe{display:block;width:100%;border:0;background:#fff;height:72vh}'
            . '.apd-preview.phone iframe{width:390px;max-width:100%;margin:0 auto}'
            . '.apd-plan{border:1px solid #e5e7eb;border-left:4px solid var(--c);border-radius:10px;padding:14px;background:#fff;margin:0 0 16px}'
            . '.apd-photos{display:flex;flex-wrap:wrap;gap:10px;margin:0 0 16px;padding:0;list-style:none}'
            . '.apd-photos img{width:96px;height:96px;object-fit:cover;border-radius:8px;background:#f3f4f6}'
            . '</style>';
    }
}

if (!function_exists('apd_sites_wizard_steps')) {
    function apd_sites_wizard_steps(): array
    {
        return array(
            1 => 'Pick a design',
            2 => 'Your details',
            3 => 'Your services',
            4 => 'Words',
            5 => 'Photos',
            6 => 'Preview',
            7 => 'Publish',
        );
    }
}

if (!function_exists('apd_sites_wizard_nav')) {
    function apd_sites_wizard_nav(int $step): string
    {
        $out = '<ul class="apd-steps">';
        foreach (apd_sites_wizard_steps() as $n => $label) {
            $out .= '<li' . ($n === $step ? ' class="on"' : '') . '>' . ($n === $step ? '' : $n . '. ') . esc_html($label) . '</li>';
        }
        return $out . '</ul>';
    }
}

if (!function_exists('apd_sites_begin')) {
    /**
     * Wrap a step's body in the wizard shell and render the page.
     */
    function apd_sites_begin(string $title, int $step, string $body, string $desc, string $back = ''): void
    {
        $html = apd_sites_wizard_css() . '<div class="apd-sites-wizard">'
            . apd_sites_wizard_nav($step)
            . '<h1>' . esc_html($title) . '</h1>'
            . $body . '</div>';
        apd_layout($title, $html, '' !== $back ? $back : '/', $desc, false);
    }
}

if (!function_exists('apd_sites_field_html')) {
    function apd_sites_field_html(array $field, $value, string $error = ''): string
    {
        $key   = (string) $field['key'];
        $label = (string) ($field['label'] ?? $key);
        $type  = (string) ($field['type'] ?? 'text');
        $value = (string) $value;
        $req   = !empty($field['required']) ? ' required' : '';

        $html = '<label class="apd-field"><span>' . esc_html($label);
        if ('' !== $error) {
            $html .= ' <em class="apd-error">' . esc_html($error) . '</em>';
        }
        $html .= '</span>';
        if ('textarea' === $type || 'lines' === $type) {
            $html .= '<textarea name="' . esc_attr($key) . '" rows="6"' . $req . '>' . esc_html($value) . '</textarea>';
        } else {
            $input_type = in_array($type, array('email', 'url', 'tel', 'number'), true) ? $type : 'text';
            $html .= '<input type="' . esc_attr($input_type) . '" name="' . esc_attr($key) . '" value="' . esc_attr($value) . '"' . $req . '>';
        }
        return $html . '</label>';
    }
}

if (!function_exists('apd_sites_errors_html')) {
    function apd_sites_errors_html(array $errors): string
    {
        if (!$errors) {
            return '';
        }
        $out = '<ul class="apd-errors">';
        foreach ($errors as $key => $message) {
            if ('form' === $key || is_int($key)) {
                $out .= '<li>' . esc_html((string) $message) . '</li>';
            }
        }
        return $out . '</ul>';
    }
}

/* ------------------------------------------------------------------------- *
 * Steps
 * ------------------------------------------------------------------------- */

if (!function_exists('apd_sites_render_step1')) {
    function apd_sites_render_step1(object $listing, array $trades, string $current = ''): void
    {
        $root   = apd_site_templates_root();
        $ranked = apd_site_templates_ranked($root, $trades);
        $id     = (int) $listing->id;

        $body = apd_sites_errors_html(array());
        $body .= '<p class="sub">Pick the design closest to your business. You can change it any time.</p>';

        if (!$ranked) {
            $body .= '<p>No designs are available yet.</p>';
            apd_sites_begin('Pick a design', 1, $body, 'Choose a design for your website.');
            return;
        }

        $body .= '<div class="apd-designs">';
        foreach ($ranked as $tid => $meta) {
            $matches = apd_site_template_matches($meta, $trades)
                && !in_array('*', isset($meta['trades']) && is_array($meta['trades']) ? $meta['trades'] : array(), true);
            $thumb   = isset($meta['thumbnail']) ? (string) $meta['thumbnail'] : '';
            $thumb_url = '' !== $thumb ? apd_sites_template_url($tid) . '/' . ltrim($thumb, '/') : '';
            $url     = apd_sites_step_url($id, 2, (string) $tid);

            $body .= '<div class="apd-design">';
            if ('' !== $thumb_url) {
                $body .= '<img loading="lazy" src="' . esc_url($thumb_url) . '" alt="">';
            }
            $body .= '<div class="bd">';
            $body .= '<h2>' . esc_html((string) ($meta['name'] ?? $tid)) . '</h2>';
            if ($matches) {
                $body .= '<span class="apd-badge">Good for your trade</span><br>';
            }
            if (!empty($current) && $current === $tid) {
                $body .= '<span class="apd-badge">Current</span><br>';
            }
            $body .= '<p><a class="btn" href="' . esc_url($url) . '">Use this design</a></p>';
            $body .= '</div></div>';
        }
        $body .= '</div>';

        apd_sites_begin('Pick a design', 1, $body, 'Choose a design for your website.');
    }
}

if (!function_exists('apd_sites_render_step2')) {
    function apd_sites_render_step2(object $listing, array $data, array $errors, string $template): void
    {
        $id     = (int) $listing->id;
        $fields = apd_sites_detail_fields();

        $body = apd_sites_errors_html($errors);
        $body .= '<p class="sub">We have filled in what we know from your listing. Check it and fix anything that is wrong.</p>';
        $body .= '<form method="post" action="' . esc_url(apd_sites_step_url($id, 2, $template)) . '">';
        $body .= apd_nonce();
        $body .= '<input type="hidden" name="step" value="2">';
        $body .= '<input type="hidden" name="template" value="' . esc_attr($template) . '">';
        foreach ($fields as $field) {
            $key = (string) $field['key'];
            $body .= apd_sites_field_html($field, $data[$key] ?? '', (string) ($errors[$key] ?? ''));
        }
        $body .= '<div class="apd-actions">'
            . '<button class="apd-primary" name="apd_action" value="next">Save and continue</button>'
            . '<a class="btn" href="' . esc_url(apd_sites_step_url($id, 1)) . '">Back to designs</a>'
            . '</div></form>';

        apd_sites_begin('Your details', 2, $body, 'Your business details.', apd_sites_step_url($id, 1));
    }
}

if (!function_exists('apd_sites_render_step3')) {
    function apd_sites_render_step3(object $listing, array $data, array $trades, array $errors, string $template): void
    {
        $id       = (int) $listing->id;
        $options  = apd_site_service_options($trades);
        $services = apd_sites_normalise_services($data['services'] ?? array());
        $chosen   = array();
        foreach ($services as $service) {
            $chosen[strtolower($service['title'])] = true;
        }
        $custom = array();
        foreach ($services as $service) {
            if (!in_array($service['title'], $options, true)) {
                $custom[] = $service['title'];
            }
        }

        $body = apd_sites_errors_html($errors);
        $body .= '<p class="sub">Tick everything you do. Add your own services if they are not listed.</p>';
        $body .= '<form method="post" action="' . esc_url(apd_sites_step_url($id, 3, $template)) . '">';
        $body .= apd_nonce();
        $body .= '<input type="hidden" name="step" value="3">';
        $body .= '<input type="hidden" name="template" value="' . esc_attr($template) . '">';

        if ($options) {
            $body .= '<div class="apd-checks">';
            foreach ($options as $option) {
                $checked = isset($chosen[strtolower($option)]) ? ' checked' : '';
                $body .= '<label><input type="checkbox" name="services[]" value="' . esc_attr($option) . '"' . $checked . '> '
                    . esc_html($option) . '</label>';
            }
            $body .= '</div>';
        }

        $body .= apd_sites_field_html(
            array('key' => 'services_extra', 'label' => 'Your own services (one per line)', 'type' => 'textarea'),
            implode("\n", $custom)
        );
        $body .= '<div class="apd-actions">'
            . '<button class="apd-primary" name="apd_action" value="next">Save and continue</button>'
            . '<a class="btn" href="' . esc_url(apd_sites_step_url($id, 2, $template)) . '">Back</a>'
            . '</div></form>';

        apd_sites_begin('Your services', 3, $body, 'Choose the services you offer.', apd_sites_step_url($id, 2, $template));
    }
}

if (!function_exists('apd_sites_render_step4')) {
    function apd_sites_render_step4(object $listing, array $data, array $errors, string $template, string $notice = ''): void
    {
        $id     = (int) $listing->id;
        $fields = apd_sites_words_fields();

        $body = apd_sites_errors_html($errors);
        if ('' !== $notice) {
            $body .= '<div class="apd-plan">' . esc_html($notice) . '</div>';
        }
        $body .= '<p class="sub">Press "Write it for me" for a friendly first draft, then change any wording you like.</p>';
        $body .= '<form method="post" action="' . esc_url(apd_sites_step_url($id, 4, $template)) . '">';
        $body .= apd_nonce();
        $body .= '<input type="hidden" name="step" value="4">';
        $body .= '<input type="hidden" name="template" value="' . esc_attr($template) . '">';
        foreach ($fields as $field) {
            $key = (string) $field['key'];
            $body .= apd_sites_field_html($field, $data[$key] ?? '', (string) ($errors[$key] ?? ''));
        }
        $body .= '<div class="apd-actions">'
            . '<button class="apd-primary" name="apd_action" value="write">Write it for me</button>'
            . '<button name="apd_action" value="next">Save and continue</button>'
            . '<a class="btn" href="' . esc_url(apd_sites_step_url($id, 3, $template)) . '">Back</a>'
            . '</div></form>';

        apd_sites_begin('The words', 4, $body, 'Your website wording.', apd_sites_step_url($id, 3, $template));
    }
}

if (!function_exists('apd_sites_render_step5')) {
    function apd_sites_render_step5(object $listing, array $data, array $errors, string $template): void
    {
        $id     = (int) $listing->id;
        $photos = isset($data['photos']) && is_array($data['photos']) ? $data['photos'] : array();
        $max    = function_exists('apd_sites_max_photos') ? apd_sites_max_photos() : 8;

        $body = apd_sites_errors_html($errors);
        $body .= '<p class="sub">Add up to ' . (int) $max . ' photos of your work. Leave this empty to keep the design\'s stock images.</p>';
        $body .= '<form method="post" enctype="multipart/form-data" action="' . esc_url(apd_sites_step_url($id, 5, $template)) . '">';
        $body .= apd_nonce();
        $body .= '<input type="hidden" name="step" value="5">';
        $body .= '<input type="hidden" name="template" value="' . esc_attr($template) . '">';

        if ($photos) {
            $body .= '<ul class="apd-photos">';
            foreach ($photos as $photo) {
                $body .= '<li><img loading="lazy" src="' . esc_url((string) $photo) . '" alt=""></li>';
            }
            $body .= '</ul>';
        }

        $body .= '<label class="apd-field"><span>Photos (jpg, png or webp, max 5 MB each)</span>'
            . '<input type="file" name="apd_photos[]" accept="image/jpeg,image/png,image/webp" multiple></label>';
        $body .= '<label class="apd-field"><span><input type="checkbox" name="own_photos" value="1"> These are my own photos</span></label>';
        $body .= '<div class="apd-actions">'
            . '<button class="apd-primary" name="apd_action" value="next">Save and continue</button>'
            . '<a class="btn" href="' . esc_url(apd_sites_step_url($id, 4, $template)) . '">Back</a>'
            . '</div></form>';

        apd_sites_begin('Photos', 5, $body, 'Add photos of your work.', apd_sites_step_url($id, 4, $template));
    }
}

if (!function_exists('apd_sites_render_step6')) {
    function apd_sites_render_step6(object $listing, string $template): void
    {
        $id   = (int) $listing->id;
        $base = apd_sites_step_url($id, 6, $template);
        $body = '<p class="sub">Here is your website on a phone and on a desktop. Nothing is public yet.</p>';
        $body .= '<div class="apd-previews">';
        $body .= '<div class="apd-preview phone"><div class="bar">Phone</div>'
            . '<iframe loading="lazy" title="Phone preview" src="' . esc_url(add_query_arg(array('step' => 6, 'template' => $template, 'frame' => 1, 'device' => 'phone'), apd_sites_owner_url($id))) . '"></iframe></div>';
        $body .= '<div class="apd-preview"><div class="bar">Desktop</div>'
            . '<iframe loading="lazy" title="Desktop preview" src="' . esc_url(add_query_arg(array('step' => 6, 'template' => $template, 'frame' => 1, 'device' => 'desktop'), apd_sites_owner_url($id))) . '"></iframe></div>';
        $body .= '</div>';

        $body .= '<form method="post" action="' . esc_url($base) . '">';
        $body .= apd_nonce();
        $body .= '<input type="hidden" name="step" value="6">';
        $body .= '<input type="hidden" name="template" value="' . esc_attr($template) . '">';
        $body .= '<div class="apd-actions">'
            . '<button class="apd-primary" name="apd_action" value="next">Looks good, continue</button>'
            . '<a class="btn" href="' . esc_url(apd_sites_step_url($id, 5, $template)) . '">Back</a>'
            . '</div></form>';

        apd_sites_begin('Preview', 6, $body, 'Preview your website.', apd_sites_step_url($id, 5, $template));
    }
}

if (!function_exists('apd_sites_render_step7')) {
    function apd_sites_render_step7(object $listing, array $row, string $template, string $notice = ''): void
    {
        $id      = (int) $listing->id;
        $status  = $row ? (string) $row['status'] : 'draft';
        $slug    = $row ? (string) $row['slug'] : '';
        $state   = apd_site_trial_state($status, $row ? (string) $row['trial_start'] : '', apd_sites_now(), apd_sites_billing_on());

        $body = '';
        if ('' !== $notice) {
            $body .= '<div class="apd-plan">' . esc_html($notice) . '</div>';
        }
        $body .= '<div class="apd-plan"><strong>' . esc_html(apd_site_plan_text()) . '</strong>'
            . '<p>Publishing starts your free trial and sends the site to our team to check. No card needed.</p></div>';

        if ('review' === $state) {
            $body .= '<p class="sub">Your website is in review. We will email you when it is live.</p>';
        } elseif ('live' === $state || 'trial' === $state || 'expired' === $state) {
            $days = $row ? apd_site_trial_days_left((string) $row['trial_start'], apd_sites_now()) : 0;
            $body .= '<p class="sub">Your website is live.';
            if ('trial' === $state) {
                $body .= ' ' . (int) $days . ' days left in your free trial.';
            }
            $body .= '</p>';
            if ('' !== $slug) {
                $body .= '<p><a class="btn" href="' . esc_url(apd_sites_live_url($slug)) . '" target="_blank" rel="noopener">View my website</a></p>';
            }
        } elseif ('rejected' === $state) {
            $body .= '<p class="sub">This website was not approved. You can edit it and publish again.</p>';
        }

        $body .= '<form method="post" action="' . esc_url(apd_sites_step_url($id, 7, $template)) . '">';
        $body .= apd_nonce();
        $body .= '<input type="hidden" name="step" value="7">';
        $body .= '<input type="hidden" name="template" value="' . esc_attr($template) . '">';
        $body .= '<div class="apd-actions">'
            . '<button class="apd-primary" name="apd_action" value="publish">Start my free trial and send for review</button>'
            . '<a class="btn" href="' . esc_url(apd_sites_step_url($id, 6, $template)) . '">Preview</a>'
            . '<a class="btn" href="' . esc_url(apd_sites_step_url($id, 1)) . '">Change design</a>'
            . '</div></form>';

        apd_sites_begin('Publish', 7, $body, 'Publish your website.', apd_sites_step_url($id, 6, $template));
    }
}

/* ------------------------------------------------------------------------- *
 * Document rendering
 * ------------------------------------------------------------------------- */

if (!function_exists('apd_sites_render_preview')) {
    /**
     * The preview frame: the site document with a "preview" banner, noindex.
     */
    function apd_sites_render_preview(object $listing, array $meta, array $data): void
    {
        $render = apd_sites_render_data($meta, $data, (int) $listing->id);
        $footer = '<footer class="apd-site-footer"><div class="apd-preview-banner">Preview &mdash; not public yet</div></footer>';
        echo apd_site_render_document(apd_site_templates_root(), (string) $meta['id'], $render, array(
            'title'       => (string) ($render['business_name'] ?? $render['name'] ?? ($meta['name'] ?? 'Preview')),
            'description' => (string) ($render['hero_sub'] ?? ''),
            'indexing'    => false,
            'footer'      => $footer,
        ));
    }
}

if (!function_exists('apd_sites_render_live')) {
    function apd_sites_render_live(object $listing, array $meta, array $data, array $row): void
    {
        $status   = (string) $row['status'];
        $indexing = ('live' === $status) && apd_sites_indexing_on();
        if (!$indexing && function_exists('header')) {
            header('X-Robots-Tag: noindex, follow');
        }

        $owner = apd_me() > 0 && apd_me() === (int) $listing->user_id;
        $state = apd_site_trial_state($status, (string) $row['trial_start'], apd_sites_now(), apd_sites_billing_on());

        $banner = '';
        if ($owner) {
            if ('trial' === $state) {
                $left   = apd_site_trial_days_left((string) $row['trial_start'], apd_sites_now());
                $banner = '<div class="apd-site-banner">Your free trial is active &mdash; ' . (int) $left . ' days left.</div>';
            } elseif ('expired' === $state) {
                $banner = '<div class="apd-site-banner">Your free trial has ended. Your website stays online and you can keep editing any time.</div>';
            } elseif ('review' === $state) {
                $banner = '<div class="apd-site-banner">Your website is still in review. Only you can see it for now.</div>';
            }
        }

        $footer = '<footer class="apd-site-footer">' . $banner
            . '<a href="' . esc_url(home_url('/')) . '">Website by aussieplatform.app</a></footer>';

        $render = apd_sites_render_data($meta, $data, (int) $listing->id);
        echo apd_site_render_document(apd_site_templates_root(), (string) $meta['id'], $render, array(
            'title'       => (string) ($render['business_name'] ?? $render['name'] ?? ($meta['name'] ?? 'Website')),
            'description' => (string) ($render['hero_sub'] ?? $render['about'] ?? ''),
            'indexing'    => $indexing,
            'footer'      => $footer,
        ));
    }
}

/* ------------------------------------------------------------------------- *
 * POST handling
 * ------------------------------------------------------------------------- */

if (!function_exists('apd_sites_write_context')) {
    function apd_sites_write_context(object $listing, array $data): array
    {
        $trades     = apd_sites_listing_trades($listing);
        $trade_slug = $trades ? (string) $trades[0] : '';
        return array(
            'business_name' => (string) ($data['business_name'] ?? ($listing->name ?? '')),
            'trade'         => $trade_slug,
            'suburb'        => (string) ($data['suburb'] ?? ''),
            'state'         => (string) ($data['state'] ?? ''),
            'service_area'  => (string) ($data['service_area'] ?? ''),
            'services'      => isset($data['services']) && is_array($data['services']) ? $data['services'] : array(),
        );
    }
}

if (!function_exists('apd_sites_merge_copy')) {
    /**
     * Merge generated copy into the stored data, keeping service images.
     */
    function apd_sites_merge_copy(array $data, array $copy): array
    {
        foreach (array('hero_heading', 'hero_sub', 'about', 'cta_text') as $key) {
            if (isset($copy[$key]) && '' !== trim((string) $copy[$key])) {
                $data[$key] = (string) $copy[$key];
            }
        }
        $images = array();
        foreach ((array) ($data['services'] ?? array()) as $service) {
            if (is_array($service) && !empty($service['title'])) {
                $images[strtolower((string) $service['title'])] = (string) ($service['image'] ?? '');
            }
        }
        $services = array();
        foreach ((array) ($copy['services'] ?? array()) as $service) {
            if (!is_array($service)) {
                continue;
            }
            $title = trim((string) ($service['title'] ?? ''));
            if ('' === $title) {
                continue;
            }
            $services[] = array(
                'title' => $title,
                'text'  => (string) ($service['text'] ?? ''),
                'image' => $images[strtolower($title)] ?? '',
            );
        }
        if ($services) {
            $data['services'] = $services;
        }
        return $data;
    }
}

if (!function_exists('apd_sites_save_draft')) {
    function apd_sites_save_draft(int $listing_id, string $template, array $data): void
    {
        apd_sites_save_site($listing_id, apd_me(), $template, $data, 'draft');
    }
}

if (!function_exists('apd_sites_handle_owner_post')) {
    function apd_sites_handle_owner_post(object $listing, string $root): void
    {
        // apd_check() verifies the nonce, the honeypot and the timing token and
        // redirects back if any of them fail.
        apd_check();

        $listing_id = (int) $listing->id;
        $step       = isset($_POST['step']) ? (int) $_POST['step'] : 1;
        $template   = isset($_POST['template']) ? apd_site_slug((string) $_POST['template']) : '';
        $action     = isset($_POST['apd_action']) ? sanitize_key((string) $_POST['apd_action']) : 'next';
        $meta       = ('' !== $template) ? apd_site_load_meta($root, $template) : null;

        if (!is_array($meta)) {
            apd_go(apd_sites_step_url($listing_id, 1));
            return;
        }

        $row  = apd_sites_get_site_by_listing($listing_id);
        $data = $row ? json_decode((string) $row['data'], true) : array();
        if (!is_array($data)) {
            $data = array();
        }

        switch ($step) {
            case 2:
                $fields = apd_sites_detail_fields();
                $clean  = apd_sites_collect($fields, $_POST);
                $errors = apd_site_validate($fields, $clean);
                if (apd_site_moderate($clean, 'apd_family_ok')) {
                    $errors['form'] = 'Please reword anything that is not family friendly.';
                }
                $data = array_merge($data, $clean);
                if ($errors) {
                    apd_sites_render_step2($listing, $data, $errors, $template);
                    return;
                }
                apd_sites_save_draft($listing_id, $template, $data);
                apd_go(apd_sites_step_url($listing_id, 3, $template));
                return;

            case 3:
                $titles = array();
                if (isset($_POST['services']) && is_array($_POST['services'])) {
                    foreach ($_POST['services'] as $title) {
                        $titles[] = sanitize_text_field((string) $title);
                    }
                }
                if (isset($_POST['services_extra'])) {
                    $extra = apd_site_clean_lines($_POST['services_extra'], 4000);
                    foreach (preg_split('/\r\n|\r|\n/', (string) $extra) as $line) {
                        $titles[] = trim((string) $line);
                    }
                }
                $old = array();
                foreach (apd_sites_normalise_services($data['services'] ?? array()) as $service) {
                    $old[strtolower($service['title'])] = (string) $service['image'];
                }
                $services = array();
                $seen     = array();
                foreach ($titles as $title) {
                    $title = trim((string) $title);
                    if ('' === $title) {
                        continue;
                    }
                    $key = strtolower($title);
                    if (isset($seen[$key])) {
                        continue;
                    }
                    $seen[$key]   = true;
                    $services[]   = array('title' => $title, 'text' => '', 'image' => $old[$key] ?? '');
                }
                if (apd_site_moderate(array('services' => wp_list_pluck($services, 'title')), 'apd_family_ok')) {
                    apd_sites_render_step3($listing, $data, apd_sites_listing_trades($listing), array('form' => 'Please reword anything that is not family friendly.'), $template);
                    return;
                }
                $data['services'] = $services;
                apd_sites_save_draft($listing_id, $template, $data);
                apd_go(apd_sites_step_url($listing_id, 4, $template));
                return;

            case 4:
                if ('write' === $action) {
                    if (!apd_sites_rate_limit('write', 30, HOUR_IN_SECONDS)) {
                        apd_sites_render_step4($listing, $data, array('form' => 'Too many requests. Please try again shortly.'), $template);
                        return;
                    }
                    $copy = apd_sites_write_copy(apd_sites_write_context($listing, $data));
                    $data = apd_sites_merge_copy($data, $copy);
                    apd_sites_save_draft($listing_id, $template, $data);
                    apd_sites_render_step4($listing, $data, array(), $template, 'Here is a first draft. Change anything you like, then continue.');
                    return;
                }
                $fields = apd_sites_words_fields();
                $clean  = apd_sites_collect($fields, $_POST);
                $errors = apd_site_validate($fields, $clean);
                if (apd_site_moderate($clean, 'apd_family_ok')) {
                    $errors['form'] = 'Please reword anything that is not family friendly.';
                }
                $data = array_merge($data, $clean);
                if ($errors) {
                    apd_sites_render_step4($listing, $data, $errors, $template);
                    return;
                }
                apd_sites_save_draft($listing_id, $template, $data);
                apd_go(apd_sites_step_url($listing_id, 5, $template));
                return;

            case 5:
                $errors = array();
                $has    = !empty($_FILES['apd_photos']['name'])
                    && is_array($_FILES['apd_photos']['name'])
                    && count(array_filter($_FILES['apd_photos']['name'], static function ($n) {
                        return '' !== trim((string) $n);
                    })) > 0;
                if ($has) {
                    if (empty($_POST['own_photos'])) {
                        $errors['photos'] = 'Please tick "These are my own photos".';
                    } elseif (!apd_sites_rate_limit('upload', 20, HOUR_IN_SECONDS)) {
                        $errors['photos'] = 'Too many uploads. Please try again later.';
                    } else {
                        $photo_errors = array();
                        $new          = apd_sites_handle_photos('apd_photos', $listing_id, $photo_errors);
                        if ($photo_errors) {
                            $errors['photos'] = implode(' ', $photo_errors);
                        }
                        $existing = isset($data['photos']) && is_array($data['photos']) ? $data['photos'] : array();
                        $photos   = array_values(array_unique(array_merge($existing, $new)));
                        if (function_exists('apd_sites_max_photos') && count($photos) > apd_sites_max_photos()) {
                            $photos           = array_slice($photos, 0, apd_sites_max_photos());
                            $errors['photos'] = 'Only the first ' . (int) apd_sites_max_photos() . ' photos were kept.';
                        }
                        $data['photos'] = $photos;
                    }
                }
                if ($errors) {
                    apd_sites_render_step5($listing, $data, $errors, $template);
                    return;
                }
                apd_sites_save_draft($listing_id, $template, $data);
                apd_go(apd_sites_step_url($listing_id, 6, $template));
                return;

            case 6:
                apd_go(apd_sites_step_url($listing_id, 7, $template));
                return;

            case 7:
            default:
                if ('publish' !== $action) {
                    apd_go(apd_sites_step_url($listing_id, 6, $template));
                    return;
                }
                if (!apd_sites_rate_limit('publish', 10, HOUR_IN_SECONDS)) {
                    apd_sites_render_step7($listing, $row, $template, 'Too many attempts. Please try again shortly.');
                    return;
                }
                $now    = apd_now();
                $fields = apd_site_publish_fields($now);
                $slug   = apd_sites_make_slug((string) ($data['business_name'] ?? ($listing->name ?? '')), $listing_id);

                // Clean hook for Stripe later. With billing off this stays false.
                $filtered = function_exists('apply_filters')
                    ? (bool) apply_filters('apd_sites_billing_required', false, $listing_id)
                    : false;
                $billing_required = apd_site_billing_required($filtered, apd_sites_billing_on());

                apd_sites_save_site($listing_id, apd_me(), $template, $data, $fields['status'], array(
                    'slug'        => $slug,
                    'trial_start' => $fields['trial_start'],
                    'note'        => $billing_required ? 'billing required' : '',
                ));
                apd_sites_insert_report($listing_id, apd_me());
                apd_flash('Your website is in review and your free trial has started.');
                apd_go(apd_sites_step_url($listing_id, 7, $template));
                return;
        }
    }
}

/* ------------------------------------------------------------------------- *
 * GET handling
 * ------------------------------------------------------------------------- */

if (!function_exists('apd_sites_handle_owner')) {
    function apd_sites_handle_owner(array $route): void
    {
        apd_need_login();
        $listing_id = (int) $route['listing_id'];
        $listing    = apd_get($listing_id);
        if (!is_object($listing)) {
            apd_sites_send_404();
            return;
        }

        $is_admin = function_exists('current_user_can') && current_user_can('manage_options');
        if (!apd_site_can_manage((int) $listing->user_id, apd_me(), $is_admin)) {
            apd_go(apd_sites_claim_url($listing_id));
        }

        if ('POST' === strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET')) {
            apd_sites_handle_owner_post($listing, apd_site_templates_root());
            return;
        }

        $root = apd_site_templates_root();
        $row  = apd_sites_get_site_by_listing($listing_id);
        $data = $row ? json_decode((string) $row['data'], true) : array();
        if (!is_array($data)) {
            $data = array();
        }

        $stored_template = $row ? (string) $row['template'] : '';
        $template        = isset($_GET['template']) ? apd_site_slug((string) $_GET['template']) : $stored_template;
        $step            = isset($_GET['step']) ? (int) $_GET['step'] : ('' !== $template ? 2 : 1);
        $step            = max(1, min(7, $step));

        $meta = ('' !== $template) ? apd_site_load_meta($root, $template) : null;
        if ($step >= 2 && !is_array($meta)) {
            apd_sites_render_step1($listing, apd_sites_listing_trades($listing), $template);
            return;
        }

        // Preview frames render the site document only.
        if (6 === $step && !empty($_GET['frame'])) {
            apd_sites_render_preview($listing, $meta, $data);
            return;
        }

        $fills = apd_sites_prefill($listing);
        foreach ($fills as $key => $value) {
            if (!isset($data[$key]) || '' === $data[$key]) {
                $data[$key] = $value;
            }
        }

        switch ($step) {
            case 1:
                apd_sites_render_step1($listing, apd_sites_listing_trades($listing), $template);
                break;
            case 2:
                apd_sites_render_step2($listing, $data, array(), (string) $meta['id']);
                break;
            case 3:
                apd_sites_render_step3($listing, $data, apd_sites_listing_trades($listing), array(), (string) $meta['id']);
                break;
            case 4:
                apd_sites_render_step4($listing, $data, array(), (string) $meta['id']);
                break;
            case 5:
                apd_sites_render_step5($listing, $data, array(), (string) $meta['id']);
                break;
            case 6:
                apd_sites_render_step6($listing, (string) $meta['id']);
                break;
            case 7:
            default:
                apd_sites_render_step7($listing, $row, (string) $meta['id'], (string) apd_flash(''));
                break;
        }
    }
}

if (!function_exists('apd_sites_handle_live')) {
    function apd_sites_handle_live(array $route): void
    {
        $slug = isset($route['slug']) ? (string) $route['slug'] : '';
        $row  = apd_sites_get_site_by_slug($slug);
        if (!$row) {
            apd_sites_send_404();
            return;
        }

        $listing = apd_get((int) $row['listing_id']);
        if (!is_object($listing)) {
            apd_sites_send_404();
            return;
        }

        $status   = (string) $row['status'];
        $is_owner = apd_me() > 0 && apd_me() === (int) $listing->user_id;
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

        apd_sites_render_live($listing, $meta, $data, $row);
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
    add_action('template_redirect', 'apd_sites_router', 20);
}
