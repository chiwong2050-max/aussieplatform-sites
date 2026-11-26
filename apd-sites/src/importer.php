<?php
/**
 * ClickSites importer. Pure PHP, no WordPress dependency.
 *
 * ClickSites designs are Next.js exports: the real content lives inside the
 * __NEXT_DATA__ JSON rather than the rendered DOM. When that payload is present
 * the importer rebuilds the page from it (see src/clicksites.php) so no demo
 * copy, ClickSites branding, tracker or made-up claim survives. Plain HTML
 * exports still go through the older strip-and-placeholder pipeline.
 */

if (!function_exists('apd_site_canonical_fields')) {
    /**
     * The placeholder fields the builder and the imported templates use.
     */
    function apd_site_canonical_fields(): array
    {
        return array(
            array('key' => 'business_name', 'label' => 'Business name',  'type' => 'text',     'required' => true,  'max' => 80),
            array('key' => 'phone',         'label' => 'Phone',          'type' => 'tel',      'required' => true,  'max' => 32),
            array('key' => 'email',         'label' => 'Email',          'type' => 'email',    'required' => false, 'max' => 120),
            array('key' => 'suburb',        'label' => 'Suburb',         'type' => 'text',     'required' => false, 'max' => 60),
            array('key' => 'state',         'label' => 'State',          'type' => 'text',     'required' => false, 'max' => 20),
            array('key' => 'service_area',  'label' => 'Service area',   'type' => 'text',     'required' => false, 'max' => 120),
            array('key' => 'abn',           'label' => 'ABN',            'type' => 'text',     'required' => false, 'max' => 20),
            array('key' => 'licence',       'label' => 'Licence',        'type' => 'text',     'required' => false, 'max' => 60),
            array('key' => 'logo',          'label' => 'Logo URL',       'type' => 'url',      'required' => false, 'max' => 240),
            array('key' => 'hero_heading',  'label' => 'Headline',       'type' => 'text',     'required' => false, 'max' => 120),
            array('key' => 'hero_sub',      'label' => 'Sub-heading',    'type' => 'text',     'required' => false, 'max' => 200),
            array('key' => 'about',         'label' => 'About',          'type' => 'textarea', 'required' => false, 'max' => 1200),
            array('key' => 'cta_text',      'label' => 'Call to action', 'type' => 'text',     'required' => false, 'max' => 60),
            array('key' => 'hours',         'label' => 'Hours',          'type' => 'text',     'required' => false, 'max' => 120),
        );
    }
}

if (!function_exists('apd_site_known_fields')) {
    /**
     * Placeholder keys a human can safely fill. Anything else is reported as
     * unknown so it can be mapped by hand.
     */
    function apd_site_known_fields(): array
    {
        $keys = array();
        foreach (apd_site_canonical_fields() as $field) {
            $keys[] = (string) $field['key'];
        }
        // Legacy names plus the keys used inside {{#each}} rows.
        return array_values(array_unique(array_merge($keys, array(
            'name', 'description', 'website', 'title', 'price', 'this',
            'services', 'why_us', 'gallery', 'testimonials',
            'text', 'image', 'caption', 'author', 'hero_image', 'about_image',
        ))));
    }
}

if (!function_exists('apd_site_default_fields')) {
    /**
     * Field definitions written into a new template's meta.json.
     */
    function apd_site_default_fields(): array
    {
        return apd_site_canonical_fields();
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
        $html = (string) preg_replace('/\b(?:your business name|business name|company name)\b/i', '{{business_name}}', $html);
        $removed['placeholders'][] = 'generic business name text';

        // Lorem ipsum blocks become the about field.
        $html = (string) preg_replace('/(?:lorem ipsum)[^<]*/i', '{{about}}', $html);

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
                return '<h1' . $m[1] . '>{{business_name}}</h1>';
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

/* ------------------------------------------------------------------------- *
 * Image handling (stock copies, WebP, thumbnails)
 * ------------------------------------------------------------------------- */

if (!function_exists('apd_site_import_gd_convert')) {
    /**
     * Resize and re-encode one image file to WebP with GD.
     */
    function apd_site_import_gd_convert(string $src, string $dest, int $max_w, int $quality): bool
    {
        if (!function_exists('imagecreatetruecolor') || !function_exists('imagewebp')) {
            return false;
        }
        $info = @getimagesize($src);
        if (!$info || empty($info[0]) || empty($info[1])) {
            return false;
        }
        $mime = isset($info['mime']) ? strtolower((string) $info['mime']) : '';
        $make = null;
        switch ($mime) {
            case 'image/jpeg':
                $make = function_exists('imagecreatefromjpeg') ? 'imagecreatefromjpeg' : null;
                break;
            case 'image/png':
                $make = function_exists('imagecreatefrompng') ? 'imagecreatefrompng' : null;
                break;
            case 'image/webp':
                $make = function_exists('imagecreatefromwebp') ? 'imagecreatefromwebp' : null;
                break;
            case 'image/gif':
                $make = function_exists('imagecreatefromgif') ? 'imagecreatefromgif' : null;
                break;
        }
        if (!$make) {
            return false;
        }
        $img = @$make($src);
        if (!$img) {
            return false;
        }

        $w = (int) $info[0];
        $h = (int) $info[1];
        $scale = ($max_w > 0 && $w > $max_w) ? ($max_w / $w) : 1.0;
        $nw = (int) max(1, round($w * $scale));
        $nh = (int) max(1, round($h * $scale));

        $dst = imagecreatetruecolor($nw, $nh);
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        $transparent = imagecolorallocatealpha($dst, 0, 0, 0, 127);
        imagefilledrectangle($dst, 0, 0, $nw, $nh, $transparent);
        imagealphablending($dst, true);
        imagecopyresampled($dst, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);

        $ok = @imagewebp($dst, $dest, max(1, min(100, $quality)));
        imagedestroy($img);
        imagedestroy($dst);
        return (bool) $ok;
    }
}

if (!function_exists('apd_site_import_media_copy')) {
    /**
     * Copy one image into $dest_dir, converting to WebP when GD allows.
     *
     * @return string|null The saved file name (not the folder).
     */
    function apd_site_import_media_copy(string $src, string $dest_dir, int $max_w, int $quality)
    {
        if (!is_file($src)) {
            return null;
        }
        if (!is_dir($dest_dir)) {
            @mkdir($dest_dir, 0775, true);
        }
        $stem = preg_replace('/[^A-Za-z0-9._-]+/', '-', (string) pathinfo($src, PATHINFO_FILENAME));
        $stem = trim((string) $stem, '-');
        if ('' === $stem) {
            $stem = 'image';
        }
        $stem = substr($stem, 0, 60);

        $webp = $dest_dir . '/' . $stem . '.webp';
        if (apd_site_import_gd_convert($src, $webp, $max_w, $quality)) {
            return $stem . '.webp';
        }

        $ext  = strtolower((string) pathinfo($src, PATHINFO_EXTENSION));
        $ext  = preg_match('/^[a-z0-9]{2,4}$/', $ext) ? $ext : 'jpg';
        $copy = $dest_dir . '/' . $stem . '.' . $ext;
        return @copy($src, $copy) ? $stem . '.' . $ext : null;
    }
}

/* ------------------------------------------------------------------------- *
 * Next.js / ClickSites rebuild
 * ------------------------------------------------------------------------- */

if (!function_exists('apd_site_import_branding_scan')) {
    /**
     * Record the third-party pieces the rebuild deliberately drops.
     */
    function apd_site_import_branding_scan(string $html, array &$report): void
    {
        if (preg_match_all('/<script\b/i', $html, $m) && $m[0]) {
            $report['removed'][] = count($m[0]) . ' Next.js / ClickSites script tag(s)';
        }
        if (preg_match_all('/clicksites/i', $html, $m2) && $m2[0]) {
            $report['removed'][] = count($m2[0]) . ' ClickSites branding reference(s)';
        }
        if (preg_match_all('#googletagmanager|google-analytics|gtag\(|hotjar|facebook\.net|mixpanel|segment\.|doubleclick#i', $html, $m3) && $m3[0]) {
            $report['removed'][] = count($m3[0]) . ' tracker reference(s)';
        }
        if (preg_match_all('/<form\b/i', $html, $m4) && $m4[0]) {
            $report['removed'][] = count($m4[0]) . ' ClickSites form(s)';
        }
    }
}

if (!function_exists('apd_site_import_resolve_trades')) {
    /**
     * Trades for a template: design family first, then the broad family, else '*'.
     */
    function apd_site_import_resolve_trades(array $opts): array
    {
        if (isset($opts['trades']) && is_array($opts['trades']) && $opts['trades']) {
            return array_values($opts['trades']);
        }
        $family = isset($opts['family']) ? (string) $opts['family'] : '';
        if ('' !== $family) {
            if (function_exists('apd_site_design_family_trades')) {
                $trades = apd_site_design_family_trades($family);
                if ($trades) {
                    return $trades;
                }
            }
            if (function_exists('apd_site_family_trades')) {
                $trades = apd_site_family_trades($family);
                if ($trades) {
                    return $trades;
                }
            }
        }
        return array('*');
    }
}

if (!function_exists('apd_site_import_nextjs')) {
    /**
     * Rebuild a next.js export into the plugin's template format.
     *
     * @return array The import report.
     */
    function apd_site_import_nextjs(array $nd, string $dest_dir, array $opts, array $report): array
    {
        $build  = apd_site_cs_build($nd);
        $id     = isset($opts['id']) && '' !== $opts['id'] ? (string) $opts['id'] : basename($dest_dir);
        $name   = isset($opts['name']) && '' !== $opts['name'] ? (string) $opts['name'] : ucwords(str_replace('-', ' ', $id));
        $family = isset($opts['family']) ? (string) $opts['family'] : '';
        $trades = apd_site_import_resolve_trades($opts);

        apd_site_import_branding_scan((string) $nd['rendered'], $report);
        foreach ($build['removed'] as $item) {
            $report['removed'][] = $item;
        }
        foreach ($build['claims'] as $claim) {
            $report['removed'][] = 'made-up claim: "' . $claim . '"';
        }

        if (!is_dir($dest_dir)) {
            @mkdir($dest_dir, 0775, true);
        }
        $images_dir = rtrim($dest_dir, '/\\') . '/images';
        $map        = array();
        $copy       = static function ($rel) use ($images_dir, &$map, &$report) {
            $rel = (string) $rel;
            if ('' === $rel) {
                return '';
            }
            if (preg_match('#^(?:https?:)?//#i', $rel)) {
                return ''; // external stock images are dropped, not hot-linked
            }
            if (isset($map[$rel])) {
                return 'images/' . $map[$rel];
            }
            $src  = $GLOBALS['apd_site_import_src'] . '/' . ltrim($rel, '/');
            $file = apd_site_import_media_copy($src, $images_dir, 1600, 80);
            if (null === $file) {
                $report['notes'][] = 'Missing stock image: ' . $rel;
                return '';
            }
            $map[$rel] = $file;
            $report['images'][] = 'images/' . $file;
            return 'images/' . $file;
        };

        $stock = is_array($build['stock']) ? $build['stock'] : array();
        $stock['hero_image']  = $copy($stock['hero_image'] ?? '');
        $stock['about_image'] = $copy($stock['about_image'] ?? '');

        $gallery = array();
        foreach (($stock['gallery'] ?? array()) as $g) {
            $img = $copy(is_array($g) ? ($g['image'] ?? '') : '');
            if ('' !== $img) {
                $gallery[] = array('image' => $img, 'caption' => '');
            }
        }
        $stock['gallery'] = $gallery;

        foreach (array('services', 'why_us') as $field) {
            $rows = array();
            foreach (($stock[$field] ?? array()) as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $row['image'] = $copy($row['image'] ?? '');
                $rows[]       = $row;
            }
            $stock[$field] = $rows;
        }

        // Thumbnail: hero first, else the first gallery image.
        $thumb_src = '';
        if ('' !== ($stock['hero_image'] ?? '')) {
            $thumb_src = $images_dir . '/' . basename($stock['hero_image']);
        } elseif ($gallery) {
            $thumb_src = $images_dir . '/' . basename($gallery[0]['image']);
        }
        $thumbnail = '';
        if ('' !== $thumb_src && is_file($thumb_src)) {
            $thumb_file = apd_site_import_media_copy($thumb_src, $images_dir, 640, 78);
            if (null !== $thumb_file) {
                $thumbnail = 'images/' . $thumb_file;
            }
        }

        $meta = array(
            'id'        => $id,
            'name'      => $name,
            'example'   => false,
            'family'    => $family,
            'trades'    => $trades,
            'thumbnail' => $thumbnail,
            'fonts'     => $build['fonts'],
            'colors'    => $build['palette'],
            'fields'    => apd_site_canonical_fields(),
            'stock'     => $stock,
        );

        file_put_contents(rtrim($dest_dir, '/\\') . '/template.html', trim($build['template_html']) . "\n");
        file_put_contents(rtrim($dest_dir, '/\\') . '/style.css', trim($build['style_css']) . "\n");
        file_put_contents(
            rtrim($dest_dir, '/\\') . '/meta.json',
            json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"
        );

        $report['placeholders'] = apd_site_import_token_report($build['template_html']);
        $report['notes'][]      = 'Rebuilt from __NEXT_DATA__; rendered HTML used to detect sections.';
        $report['notes'][]      = 'Kept Google Fonts only: ' . ($build['fonts'] ? implode(', ', $build['fonts']) : 'none');

        return $report;
    }
}

/* ------------------------------------------------------------------------- *
 * Entry point
 * ------------------------------------------------------------------------- */

if (!function_exists('apd_site_import_clicksites')) {
    /**
     * Import a ClickSites export into the template format.
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
            'mode'         => 'html',
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

        // Next.js export: rebuild from the JSON payload.
        if (function_exists('apd_site_cs_next_data')) {
            $nd = apd_site_cs_next_data($html);
            if (is_array($nd) && !empty($nd['content'])) {
                $report['mode'] = 'nextjs';

                // The stock copy helper reads this path.
                $GLOBALS['apd_site_import_src'] = rtrim($src_dir, '/\\');
                $result = apd_site_import_nextjs($nd, $dest_dir, $opts, $report);
                unset($GLOBALS['apd_site_import_src']);
                return $result;
            }
        }

        $css = '';

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
        //    Google Fonts are the only external font source kept.
        $html = (string) preg_replace_callback(
            '/<link\b[^>]*>/i',
            static function ($m) use (&$report, $src_dir, &$css) {
                if (!preg_match('/\bhref\s*=\s*(["\'])([^"\']+)\1/i', $m[0], $h)) {
                    return $m[0];
                }
                $href = $h[2];
                if (preg_match('#fonts\.googleapis\.com|fonts\.gstatic\.com#i', $href)) {
                    return $m[0];
                }
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
        $trades = apd_site_import_resolve_trades($opts);

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
