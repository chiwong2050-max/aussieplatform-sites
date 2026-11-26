<?php
/**
 * Template renderer. Pure PHP, no WordPress dependency.
 *
 * Templates use {{field}} placeholders and {{#each services}}...{{/each}}
 * blocks. Every value is HTML escaped when rendered, so owner supplied text
 * can never inject markup.
 */

if (!function_exists('apd_site_templates_root')) {
    /**
     * Default templates directory (this file lives in <plugin>/src).
     */
    function apd_site_templates_root(): string
    {
        return dirname(__DIR__) . '/templates';
    }
}

if (!function_exists('apd_site_escape')) {
    function apd_site_escape($value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('apd_site_load_meta')) {
    /**
     * Read and decode templates/<id>/meta.json.
     *
     * @return array|null
     */
    function apd_site_load_meta(string $root, string $id)
    {
        $id = basename($id);
        $file = rtrim($root, '/\\') . '/' . $id . '/meta.json';
        if (!is_file($file)) {
            return null;
        }
        $json = file_get_contents($file);
        if (false === $json) {
            return null;
        }
        $meta = json_decode($json, true);
        return is_array($meta) ? $meta : null;
    }
}

if (!function_exists('apd_site_template_file')) {
    function apd_site_template_file(string $root, string $id, string $file): ?string
    {
        $id = basename($id);
        $path = rtrim($root, '/\\') . '/' . $id . '/' . $file;
        if (!is_file($path)) {
            return null;
        }
        $content = file_get_contents($path);
        return false === $content ? null : $content;
    }
}

if (!function_exists('apd_site_template_html')) {
    function apd_site_template_html(string $root, string $id): ?string
    {
        return apd_site_template_file($root, $id, 'template.html');
    }
}

if (!function_exists('apd_site_template_css')) {
    function apd_site_template_css(string $root, string $id): ?string
    {
        return apd_site_template_file($root, $id, 'style.css');
    }
}

if (!function_exists('apd_site_render_each')) {
    /**
     * Expand {{#each key}}...{{/each}} blocks.
     */
    function apd_site_render_each(string $html, array $data): string
    {
        return (string) preg_replace_callback(
            '/\{\{#each\s+([A-Za-z0-9_]+)\s*\}\}(.*?)\{\{\/each\}\}/s',
            static function ($m) use ($data) {
                $key   = $m[1];
                $inner = $m[2];
                $items = (isset($data[$key]) && is_array($data[$key])) ? $data[$key] : array();
                $out   = '';
                foreach ($items as $item) {
                    $row = is_array($item) ? $item : array('this' => $item);
                    $out .= apd_site_render_fields($inner, $row);
                }
                return $out;
            },
            $html
        );
    }
}

if (!function_exists('apd_site_render_fields')) {
    /**
     * Replace {{field}} tokens with escaped values. Unknown keys become empty.
     */
    function apd_site_render_fields(string $html, array $data): string
    {
        return (string) preg_replace_callback(
            '/\{\{\s*([A-Za-z0-9_]+)\s*\}\}/',
            static function ($m) use ($data) {
                $key = $m[1];
                if (!array_key_exists($key, $data)) {
                    return '';
                }
                $value = $data[$key];
                if (is_array($value)) {
                    return '';
                }
                return apd_site_escape($value);
            },
            $html
        );
    }
}

if (!function_exists('apd_site_render')) {
    /**
     * Render the template body with escaping.
     */
    function apd_site_render(string $root, string $id, array $data): string
    {
        $html = apd_site_template_html($root, $id);
        if (null === $html) {
            return '';
        }
        $html = apd_site_render_each($html, $data);
        $html = apd_site_render_fields($html, $data);
        return trim($html);
    }
}

if (!function_exists('apd_site_robots')) {
    function apd_site_robots(bool $indexing): string
    {
        return $indexing ? 'index,follow' : 'noindex,follow';
    }
}

if (!function_exists('apd_site_render_document')) {
    /**
     * Render a full HTML document around the template body.
     *
     * Options: title, description, indexing (bool), footer (html), lang.
     */
    function apd_site_render_document(string $root, string $id, array $data, array $opts = array()): string
    {
        $meta = apd_site_load_meta($root, $id);
        $meta = is_array($meta) ? $meta : array();

        $body = apd_site_render($root, $id, $data);
        $css  = apd_site_template_css($root, $id);
        $css  = null === $css ? '' : $css;

        $title    = isset($opts['title']) ? (string) $opts['title'] : (isset($meta['name']) ? (string) $meta['name'] : 'Website');
        $desc     = isset($opts['description']) ? (string) $opts['description'] : '';
        $lang     = isset($opts['lang']) ? (string) $opts['lang'] : 'en-AU';
        $footer   = isset($opts['footer']) ? (string) $opts['footer'] : '';
        $indexing = array_key_exists('indexing', $opts) ? (bool) $opts['indexing'] : true;

        $head   = array();
        $head[] = '<meta charset="utf-8">';
        $head[] = '<meta name="viewport" content="width=device-width, initial-scale=1">';
        $head[] = '<meta name="robots" content="' . apd_site_escape(apd_site_robots($indexing)) . '">';
        $head[] = '<title>' . apd_site_escape($title) . '</title>';
        if ('' !== $desc) {
            $head[] = '<meta name="description" content="' . apd_site_escape($desc) . '">';
        }
        if ('' !== $css) {
            $head[] = '<style>' . $css . '</style>';
        }

        return "<!DOCTYPE html>\n<html lang=\"" . apd_site_escape($lang) . "\">\n<head>\n"
            . implode("\n", $head) . "\n</head>\n<body>\n" . $body . "\n" . $footer . "\n</body>\n</html>\n";
    }
}

if (!function_exists('apd_site_list_templates')) {
    /**
     * All templates found in a root directory. id => meta.
     */
    function apd_site_list_templates(string $root): array
    {
        $out = array();
        if (!is_dir($root)) {
            return $out;
        }
        $entries = scandir($root);
        if (false === $entries) {
            return $out;
        }
        foreach ($entries as $entry) {
            if ('.' === $entry || '..' === $entry) {
                continue;
            }
            if (!is_dir(rtrim($root, '/\\') . '/' . $entry)) {
                continue;
            }
            $meta = apd_site_load_meta($root, $entry);
            if (is_array($meta) && isset($meta['id'])) {
                $out[$entry] = $meta;
            }
        }
        return $out;
    }
}

if (!function_exists('apd_site_template_matches')) {
    /**
     * Does a template serve any of the listing's trades? '*' matches anything.
     */
    function apd_site_template_matches(array $meta, array $trades): bool
    {
        $template_trades = (isset($meta['trades']) && is_array($meta['trades'])) ? $meta['trades'] : array();
        if (in_array('*', $template_trades, true)) {
            return true;
        }
        foreach ($template_trades as $trade) {
            if (in_array($trade, $trades, true)) {
                return true;
            }
        }
        return false;
    }
}

if (!function_exists('apd_site_gallery')) {
    /**
     * Templates available to a listing, trade specific ones first, then '*'.
     */
    function apd_site_gallery(string $root, array $trades): array
    {
        $templates = apd_site_list_templates($root);
        $matched   = array();
        $wildcard  = array();
        foreach ($templates as $id => $meta) {
            if (!apd_site_template_matches($meta, $trades)) {
                continue;
            }
            $template_trades = (isset($meta['trades']) && is_array($meta['trades'])) ? $meta['trades'] : array();
            if (in_array('*', $template_trades, true)) {
                $wildcard[$id] = $meta;
            } else {
                $matched[$id] = $meta;
            }
        }
        ksort($matched);
        ksort($wildcard);
        return array_merge($matched, $wildcard);
    }
}
