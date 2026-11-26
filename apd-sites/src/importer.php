<?php
/**
 * ClickSites HTML importer. Pure PHP, no WordPress dependency.
 *
 * Turns an exported ClickSites HTML folder into the plugin's template format:
 * strips unsafe and third-party markup, keeps the layout and CSS, copies local
 * images and converts obvious sample text into {{field}} placeholders.
 */

if (!function_exists('apd_site_known_fields')) {
    /**
     * Placeholder keys a human can safely fill. Anything else is reported as
     * unknown so it can be mapped by hand.
     */
    function apd_site_known_fields(): array
    {
        return array(
            'name', 'phone', 'email', 'website', 'suburb', 'abn', 'licence', 'hours',
            'description', 'services', 'title', 'price', 'this',
        );
    }
}

if (!function_exists('apd_site_default_fields')) {
    /**
     * Field definitions written into a new template's meta.json.
     */
    function apd_site_default_fields(): array
    {
        return array(
            array('key' => 'name',        'label' => 'Business name', 'type' => 'text',     'required' => true,  'max' => 80),
            array('key' => 'phone',       'label' => 'Phone',         'type' => 'tel',      'required' => true,  'max' => 32),
            array('key' => 'email',       'label' => 'Email',         'type' => 'email',    'required' => false, 'max' => 120),
            array('key' => 'website',     'label' => 'Website',       'type' => 'url',      'required' => false, 'max' => 160),
            array('key' => 'suburb',      'label' => 'Suburb',        'type' => 'text',     'required' => false, 'max' => 60),
            array('key' => 'hours',       'label' => 'Hours',         'type' => 'text',     'required' => false, 'max' => 120),
            array('key' => 'abn',         'label' => 'ABN',           'type' => 'text',     'required' => false, 'max' => 20),
            array('key' => 'licence',     'label' => 'Licence',       'type' => 'text',     'required' => false, 'max' => 40),
            array('key' => 'description', 'label' => 'Description',   'type' => 'textarea', 'required' => false, 'max' => 600),
        );
    }
}

if (!function_exists('apd_site_import_find_html')) {
    function apd_site_import_find_html(string $src_dir): ?string
    {
        $candidates = array('index.html', 'index.htm', 'home.html');
        foreach ($candidates as $name) {
            $path = rtrim($src_dir, '/\\') . '/' . $name;
            if (is_file($path)) {
                return $path;
            }
        }
        $entries = is_dir($src_dir) ? scandir($src_dir) : false;
        if (false === $entries) {
            return null;
        }
        foreach ($entries as $entry) {
            if (preg_match('/\.html?$/i', $entry)) {
                return rtrim($src_dir, '/\\') . '/' . $entry;
            }
        }
        return null;
    }
}

if (!function_exists('apd_site_import_is_external')) {
    function apd_site_import_is_external(string $url): bool
    {
        $url = trim($url);
        return (bool) preg_match('#^(?:https?:)?//#i', $url);
    }
}

if (!function_exists('apd_site_import_strip_css_imports')) {
    function apd_site_import_strip_css_imports(string $css): string
    {
        return (string) preg_replace('/@import\s+[^;]+;/i', '', $css);
    }
}

if (!function_exists('apd_site_import_token_report')) {
    function apd_site_import_token_report(string $html): array
    {
        $known   = apd_site_known_fields();
        $seen    = array();
        $unknown = array();
        if (preg_match_all('/\{\{\s*([A-Za-z0-9_]+)\s*\}\}/', $html, $m)) {
            foreach ($m[1] as $token) {
                if (in_array($token, $known, true)) {
                    $seen[$token] = true;
                } else {
                    $unknown[$token] = true;
                }
            }
        }
        return array(
            'known'   => array_keys($seen),
            'unknown' => array_keys($unknown),
        );
    }
}

if (!function_exists('apd_site_import_apply_placeholders')) {
    function apd_site_import_apply_placeholders(string $html, array &$removed): string
    {
        // Obvious sample text first.
        $html = (string) preg_replace('/\b(?:your business name|business name|company name)\b/i', '{{name}}', $html);
        $removed['placeholders'][] = 'generic business name text';

        // Lorem ipsum blocks become the description field.
        $html = (string) preg_replace('/(?:lorem ipsum)[^<]*/i', '{{description}}', $html);

        // Emails.
        $html = (string) preg_replace('/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/', '{{email}}', $html);

        // Australian phone numbers.
        $html = (string) preg_replace('/(?:\+?61|0)[\s\-]?\d{2,4}[\s\-]?\d{3}[\s\-]?\d{3}/', '{{phone}}', $html);

        // First heading becomes the business name.
        $html = (string) preg_replace_callback(
            '/<h1\b([^>]*)>(.*?)<\/h1>/is',
            static function ($m) {
                if (false !== strpos($m[2], '{{')) {
                    return $m[0];
                }
                return '<h1' . $m[1] . '>{{name}}</h1>';
            },
            $html
        );

        return $html;
    }
}

if (!function_exists('apd_site_import_images')) {
    /**
     * Copy local images next to the template and rewrite their src.
     */
    function apd_site_import_images(string $html, string $src_dir, string $dest_dir, array &$report): string
    {
        $images_dir = rtrim($dest_dir, '/\\') . '/images';
        $copies     = array();

        $html = (string) preg_replace_callback(
            '/(<img\b[^>]*\bsrc\s*=\s*)(["\'])([^"\']+)(\2)/i',
            static function ($m) use ($src_dir, $images_dir, &$copies, &$report) {
                $url = trim($m[3]);
                if ('' === $url || apd_site_import_is_external($url) || 0 === strpos($url, 'data:') || 0 === strpos($url, '#')) {
                    return $m[0];
                }
                $local = rtrim($src_dir, '/\\') . '/' . ltrim(preg_replace('#^\./#', '', $url), '/');
                if (!is_file($local)) {
                    $report['notes'][] = 'Missing local image: ' . $url;
                    return $m[0];
                }
                $name = basename($url);
                if (!isset($copies[$name])) {
                    if (!is_dir($images_dir)) {
                        @mkdir($images_dir, 0775, true);
                    }
                    if (@copy($local, $images_dir . '/' . $name)) {
                        $copies[$name] = true;
                        $report['images'][] = 'images/' . $name;
                    } else {
                        $report['notes'][] = 'Could not copy image: ' . $url;
                        return $m[0];
                    }
                }
                return $m[1] . $m[2] . 'images/' . $name . $m[4];
            },
            $html
        );

        return $html;
    }
}

if (!function_exists('apd_site_import_clicksites')) {
    /**
     * Import a ClickSites HTML folder into the template format.
     *
     * @param string $src_dir  Folder holding the exported site.
     * @param string $dest_dir Destination templates/<id> folder.
     * @param array  $opts     id, name, trades[], family.
     * @return array Report of removals, images and placeholders.
     */
    function apd_site_import_clicksites(string $src_dir, string $dest_dir, array $opts = array()): array
    {
        $report = array(
            'source'       => $src_dir,
            'destination'  => $dest_dir,
            'removed'      => array(),
            'images'       => array(),
            'placeholders' => array('known' => array(), 'unknown' => array()),
            'notes'        => array(),
        );

        if (!is_dir($src_dir)) {
            $report['notes'][] = 'Source folder not found: ' . $src_dir;
            return $report;
        }
        $index = apd_site_import_find_html($src_dir);
        if (null === $index) {
            $report['notes'][] = 'No HTML file found in ' . $src_dir;
            return $report;
        }

        $html = (string) file_get_contents($index);
        $css  = '';

        // 1. Scripts.
        $html = (string) preg_replace('/<script\b[^>]*>.*?<\/script>/is', '', $html, -1, $c1);
        $html = (string) preg_replace('/<script\b[^>]*\/?>/i', '', $html, -1, $c2);
        if ($c1 + $c2 > 0) {
            $report['removed'][] = ($c1 + $c2) . ' script tag(s)';
        }

        // 2. Analytics / tracking pixels in noscript blocks.
        $html = (string) preg_replace_callback(
            '/<noscript\b[^>]*>.*?<\/noscript>/is',
            static function ($m) use (&$report) {
                if (preg_match('/googletagmanager|google-analytics|gtag|doubleclick|facebook/i', $m[0])) {
                    $report['removed'][] = 'tracking noscript block';
                    return '';
                }
                return $m[0];
            },
            $html
        );

        // 3. External iframes.
        $html = (string) preg_replace_callback(
            '/<iframe\b[^>]*>.*?<\/iframe>/is',
            static function ($m) use (&$report) {
                if (preg_match('/\bsrc\s*=\s*(["\'])\s*(?:https?:)?\/\//i', $m[0])) {
                    $report['removed'][] = 'external iframe';
                    return '';
                }
                return $m[0];
            },
            $html
        );
        $html = (string) preg_replace_callback(
            '/<iframe\b[^>]*\/?>/i',
            static function ($m) use (&$report) {
                if (preg_match('/\bsrc\s*=\s*(["\'])\s*(?:https?:)?\/\//i', $m[0])) {
                    $report['removed'][] = 'external iframe';
                    return '';
                }
                return '';
            },
            $html
        );

        // 4. External stylesheet links; keep local ones for CSS collection.
        $html = (string) preg_replace_callback(
            '/<link\b[^>]*>/i',
            static function ($m) use (&$report, $src_dir, &$css) {
                if (!preg_match('/\bhref\s*=\s*(["\'])([^"\']+)\1/i', $m[0], $h)) {
                    return $m[0];
                }
                $href = $h[2];
                if (apd_site_import_is_external($href)) {
                    $report['removed'][] = 'external stylesheet: ' . $href;
                    return '';
                }
                if (preg_match('/stylesheet/i', $m[0])) {
                    $local = rtrim($src_dir, '/\\') . '/' . ltrim($href, '/');
                    if (is_file($local)) {
                        $css .= "\n" . (string) file_get_contents($local);
                        $report['notes'][] = 'Inlined local stylesheet: ' . $href;
                        return '';
                    }
                }
                return $m[0];
            },
            $html
        );

        // 5. Tracking pixels.
        $html = (string) preg_replace_callback(
            '/<img\b[^>]*>/i',
            static function ($m) use (&$report) {
                if (preg_match('#\bsrc\s*=\s*(["\'])[^"\']*(google-analytics|googletagmanager|doubleclick|facebook|hotjar|segment|mixpanel|matomo|googleadservices|googlesyndication|/pixel)#i', $m[0])) {
                    $report['removed'][] = 'tracking pixel';
                    return '';
                }
                return $m[0];
            },
            $html
        );

        // 6. Forms posting off-site.
        $html = (string) preg_replace_callback(
            '/<form\b[^>]*>.*?<\/form>/is',
            static function ($m) use (&$report) {
                if (preg_match('/\baction\s*=\s*(["\'])\s*(?:https?:)?\/\//i', $m[0])) {
                    $report['removed'][] = 'off-site form';
                    return '';
                }
                return $m[0];
            },
            $html
        );

        // 7. Inline event handlers.
        $html = (string) preg_replace('/\son[a-z]+\s*=\s*"[^"]*"/i', '', $html, -1, $c3);
        $html = (string) preg_replace("/\son[a-z]+\s*=\s*'[^']*'/i", '', $html, -1, $c4);
        $html = (string) preg_replace('/\son[a-z]+\s*=\s*[^\s>]+/i', '', $html, -1, $c5);
        if ($c3 + $c4 + $c5 > 0) {
            $report['removed'][] = ($c3 + $c4 + $c5) . ' inline event handler(s)';
        }

        // 8. javascript: URLs.
        $html = (string) preg_replace('/(href|src)\s*=\s*(["\'])\s*javascript:[^"\']*\2/i', '$1="#"', $html, -1, $c6);
        if ($c6 > 0) {
            $report['removed'][] = $c6 . ' javascript: URL(s)';
        }

        // 9. Collect inline <style> and drop it from the markup.
        $html = (string) preg_replace_callback(
            '/<style\b[^>]*>(.*?)<\/style>/is',
            static function ($m) use (&$css) {
                $css .= "\n" . $m[1];
                return '';
            },
            $html
        );

        $css = apd_site_import_strip_css_imports($css);

        // 10. Copy local images and rewrite their src.
        $html = apd_site_import_images($html, $src_dir, $dest_dir, $report);

        // 11. Sample text to placeholders.
        $html = apd_site_import_apply_placeholders($html, $report);

        // 12. Placeholder inventory.
        $report['placeholders'] = apd_site_import_token_report($html);

        // Write the template out.
        if (!is_dir($dest_dir)) {
            @mkdir($dest_dir, 0775, true);
        }
        $id     = isset($opts['id']) && '' !== $opts['id'] ? (string) $opts['id'] : basename($dest_dir);
        $name   = isset($opts['name']) && '' !== $opts['name'] ? (string) $opts['name'] : $id;
        $trades = (isset($opts['trades']) && is_array($opts['trades']) && $opts['trades']) ? array_values($opts['trades']) : array('*');
        if (!empty($opts['family']) && function_exists('apd_site_family_trades')) {
            $family_trades = apd_site_family_trades((string) $opts['family']);
            if ($family_trades) {
                $trades = $family_trades;
            }
        }

        $meta = array(
            'id'      => $id,
            'name'    => $name,
            'example' => false,
            'trades'  => $trades,
            'fields'  => apd_site_default_fields(),
        );

        file_put_contents(rtrim($dest_dir, '/\\') . '/template.html', trim($html) . "\n");
        file_put_contents(rtrim($dest_dir, '/\\') . '/style.css', trim($css) . "\n");
        file_put_contents(
            rtrim($dest_dir, '/\\') . '/meta.json',
            json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"
        );

        return $report;
    }
}
