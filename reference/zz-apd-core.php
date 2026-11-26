<?php
/**
 * Plugin Name: APD Core (reference copy)
 * Description: Read-only reference copy of the aussieplatform.app directory core MU-plugin. It provides the shared helpers used by the tradie mini-websites plugin. Do not modify this file or copy it into another plugin.
 * Version: 1.0.0
 *
 * This file is included by the test runner, so it must be safe to load outside
 * of WordPress: it only defines functions and guards every WordPress call.
 */

if (!defined('APD_CORE_VERSION')) {
    define('APD_CORE_VERSION', '1.0.0');
}

if (!function_exists('apd_trades')) {
    /**
     * Canonical trade list for the directory. Slug => label.
     */
    function apd_trades(): array
    {
        return array(
            // build
            'electrician'      => 'Electrician',
            'plumber'          => 'Plumber',
            'gasfitter'        => 'Gasfitter',
            'carpenter'        => 'Carpenter',
            'builder'          => 'Builder',
            'handyman'         => 'Handyman',
            'painter'          => 'Painter',
            'plasterer'        => 'Plasterer',
            'tiler'            => 'Tiler',
            'bricklayer'       => 'Bricklayer',
            'concreter'        => 'Concreter',
            'roofer'           => 'Roofer',
            'glazier'          => 'Glazier',
            'cabinet-maker'    => 'Cabinet maker',
            'flooring'         => 'Flooring',
            'fencer'           => 'Fencer',
            'solar'            => 'Solar installer',
            'air-conditioning' => 'Air conditioning',
            // outdoor
            'landscaper'       => 'Landscaper',
            'gardener'         => 'Gardener',
            'lawn-mowing'      => 'Lawn mowing',
            'tree-services'    => 'Tree services',
            'pool-services'    => 'Pool services',
            // home
            'locksmith'        => 'Locksmith',
            'pest-control'     => 'Pest control',
            'cleaner'          => 'Cleaner',
            'carpet-cleaning'  => 'Carpet cleaning',
            'window-cleaning'  => 'Window cleaning',
            'removalist'       => 'Removalist',
            'rubbish-removal'  => 'Rubbish removal',
            // auto
            'mechanic'         => 'Mechanic',
            'auto-electrician' => 'Auto electrician',
            'car-detailing'    => 'Car detailing',
            // repair
            'appliance-repair' => 'Appliance repair',
            'antenna-tv'       => 'Antenna & TV',
            'computer-repair'  => 'Computer repair',
            // pets
            'pet-grooming'     => 'Pet grooming',
            'dog-walking'      => 'Dog walking',
            // pro
            'tutor'            => 'Tutor',
            'photographer'     => 'Photographer',
            'bookkeeper'       => 'Bookkeeper',
            'web-designer'     => 'Web designer',
            'graphic-designer' => 'Graphic designer',
            'other'            => 'Other',
        );
    }
}

if (!function_exists('apd_trade_label')) {
    function apd_trade_label($slug): string
    {
        $trades = apd_trades();
        return isset($trades[$slug]) ? $trades[$slug] : (string) $slug;
    }
}

if (!function_exists('apd_esc')) {
    function apd_esc($value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('apd_get')) {
    /**
     * Fetch a listing. Returns an array with the keys used across the site.
     */
    function apd_get($id)
    {
        $id = (int) $id;
        if ($id <= 0) {
            return null;
        }

        if (function_exists('apply_filters')) {
            $override = apply_filters('apd_get_listing', null, $id);
            if (is_array($override)) {
                return $override;
            }
        }

        if (function_exists('get_post')) {
            $post = get_post($id);
            if (!$post || 'apd_listing' !== $post->post_type) {
                return null;
            }
            return apd_listing_from_post($post);
        }

        return null;
    }
}

if (!function_exists('apd_listing_from_post')) {
    function apd_listing_from_post($post): array
    {
        $get = function ($key, $default = '') use ($post) {
            if (function_exists('get_post_meta')) {
                $value = get_post_meta($post->ID, '_apd_' . $key, true);
                if ('' !== $value && null !== $value) {
                    return $value;
                }
            }
            return $default;
        };

        $trades = $get('trades', array());
        if (is_string($trades)) {
            $trades = array_filter(array_map('trim', explode(',', $trades)));
        }
        if (!is_array($trades)) {
            $trades = array();
        }

        return array(
            'id'          => (int) $post->ID,
            'user_id'     => (int) $post->post_author,
            'name'        => (string) $post->post_title,
            'slug'        => (string) $post->post_name,
            'trades'      => array_values($trades),
            'suburb'      => (string) $get('suburb'),
            'phone'       => (string) $get('phone'),
            'website'     => (string) $get('website'),
            'email'       => (string) $get('email'),
            'abn'         => (string) $get('abn'),
            'licence'     => (string) $get('licence'),
            'hours'       => (string) $get('hours'),
            'description' => (string) $post->post_content,
        );
    }
}

if (!function_exists('apd_url')) {
    function apd_url($listing): string
    {
        $slug = is_array($listing) ? (isset($listing['slug']) ? $listing['slug'] : '') : (string) $listing;
        $base = function_exists('home_url') ? home_url('/') : '/';
        return rtrim($base, '/') . '/biz/' . rawurlencode($slug) . '/';
    }
}

if (!function_exists('apd_sub')) {
    /**
     * Current tenant key derived from the Host header (multi-tenant directory).
     */
    function apd_sub(): string
    {
        $host = isset($_SERVER['HTTP_HOST']) ? (string) $_SERVER['HTTP_HOST'] : '';
        $host = strtolower(preg_replace('/:\d+$/', '', $host));
        $parts = explode('.', $host);
        return count($parts) > 2 ? $parts[0] : 'www';
    }
}

if (!function_exists('apd_me')) {
    function apd_me(): int
    {
        return function_exists('get_current_user_id') ? (int) get_current_user_id() : 0;
    }
}

if (!function_exists('apd_need_login')) {
    function apd_need_login(string $redirect = ''): void
    {
        if (apd_me() > 0) {
            return;
        }
        $target = $redirect;
        if ('' === $target) {
            $target = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '/';
        }
        $url = function_exists('wp_login_url') ? wp_login_url($target) : '/wp-login.php';
        apd_go($url);
    }
}

if (!function_exists('apd_go')) {
    function apd_go(string $url): void
    {
        if (function_exists('wp_safe_redirect')) {
            wp_safe_redirect($url);
        } else {
            header('Location: ' . $url);
        }
        exit;
    }
}

if (!function_exists('apd_nonce')) {
    function apd_nonce(string $action = 'apd'): void
    {
        if (function_exists('wp_nonce_field')) {
            wp_nonce_field($action, '_apd_nonce');
            return;
        }
        echo '<input type="hidden" name="_apd_nonce" value="">';
    }
}

if (!function_exists('apd_check')) {
    function apd_check(string $action = 'apd'): bool
    {
        $nonce = isset($_POST['_apd_nonce']) ? (string) $_POST['_apd_nonce'] : (isset($_GET['_apd_nonce']) ? (string) $_GET['_apd_nonce'] : '');
        if ('' === $nonce) {
            return false;
        }
        if (function_exists('wp_verify_nonce')) {
            return (bool) wp_verify_nonce($nonce, $action);
        }
        return true;
    }
}

if (!function_exists('apd_family_ok')) {
    /**
     * Fail-closed content family check. Returns false when text is not safe for a family audience.
     */
    function apd_family_ok($text): bool
    {
        $text = strtolower((string) $text);
        if ('' === trim($text)) {
            return true;
        }

        $blocked = array('fuck', 'cunt', 'shit', 'bitch', 'bastard', 'porn', 'xxx', 'nazi');
        foreach ($blocked as $word) {
            if (false !== strpos($text, $word)) {
                return false;
            }
        }

        if (function_exists('apply_filters')) {
            return (bool) apply_filters('apd_family_ok', true, $text);
        }

        return true;
    }
}

if (!function_exists('apd_flash')) {
    function apd_flash($message = null, $type = 'info')
    {
        static $flash = null;
        if (null !== $message) {
            $flash = array('message' => (string) $message, 'type' => (string) $type);
            if (function_exists('set_transient') && apd_me() > 0) {
                set_transient('apd_flash_' . apd_me(), $flash, 60);
            }
            return null;
        }
        if (null === $flash && function_exists('get_transient') && apd_me() > 0) {
            $flash = get_transient('apd_flash_' . apd_me());
            delete_transient('apd_flash_' . apd_me());
        }
        $out = $flash;
        $flash = null;
        return $out;
    }
}

if (!function_exists('apd_tb')) {
    function apd_tb(string $name): string
    {
        if (isset($GLOBALS['wpdb']) && is_object($GLOBALS['wpdb'])) {
            return $GLOBALS['wpdb']->prefix . 'apd_' . $name;
        }
        return 'wp_apd_' . $name;
    }
}

if (!function_exists('apd_e')) {
    function apd_e($value): void
    {
        echo apd_esc($value);
    }
}

if (!function_exists('apd_layout')) {
    /**
     * Render a full directory page.
     */
    function apd_layout($title, $body, $back = '', $desc = '', $index = true): void
    {
        $charset = function_exists('get_bloginfo') ? get_bloginfo('charset') : 'UTF-8';
        $site_name = function_exists('get_bloginfo') ? get_bloginfo('name') : 'aussieplatform';
        $robots = $index ? 'index,follow' : 'noindex,follow';
        echo '<!DOCTYPE html><html lang="en-AU"><head><meta charset="' . apd_esc($charset) . '">';
        echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
        echo '<meta name="robots" content="' . apd_esc($robots) . '">';
        echo '<title>' . apd_esc($title) . ' | ' . apd_esc($site_name) . '</title>';
        if ('' !== $desc) {
            echo '<meta name="description" content="' . apd_esc($desc) . '">';
        }
        echo '</head><body>';
        if ('' !== $back) {
            echo '<p><a href="' . apd_esc($back) . '">&larr; Back</a></p>';
        }
        echo $body;
        echo '</body></html>';
    }
}

if (!function_exists('apd_render_biz')) {
    /**
     * Render the public business page for a listing.
     */
    function apd_render_biz($listing): void
    {
        if (!is_array($listing)) {
            return;
        }
        $body = '';
        if (function_exists('apply_filters')) {
            $body = (string) apply_filters('apd_biz_before', '', $listing);
        }
        $body .= '<article class="apd-biz"><h1>' . apd_esc(isset($listing['name']) ? $listing['name'] : '') . '</h1>';
        $body .= '<p>' . apd_esc(isset($listing['description']) ? $listing['description'] : '') . '</p>';
        $body .= '<ul class="apd-biz-actions">';
        if (function_exists('do_action')) {
            ob_start();
            do_action('apd_biz_actions', $listing);
            $body .= ob_get_clean();
        }
        $body .= '</ul></article>';
        apd_layout(
            isset($listing['name']) ? $listing['name'] : 'Listing',
            $body,
            function_exists('home_url') ? home_url('/') : '/',
            isset($listing['description']) ? $listing['description'] : ''
        );
    }
}

if (!function_exists('apd_core_router')) {
    function apd_core_router(): void
    {
        $path = isset($_SERVER['REQUEST_URI']) ? (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) : '';
        if (preg_match('#/biz/([a-z0-9-]+)/?$#', $path, $m)) {
            $listing = null;
            if (function_exists('get_posts')) {
                $posts = get_posts(array('post_type' => 'apd_listing', 'name' => $m[1], 'numberposts' => 1));
                if (!empty($posts)) {
                    $listing = apd_listing_from_post($posts[0]);
                }
            }
            if (!$listing) {
                if (function_exists('status_header')) {
                    status_header(404);
                }
                return;
            }
            apd_render_biz($listing);
            exit;
        }
    }
}

if (function_exists('add_action')) {
    add_action('template_redirect', 'apd_core_router');
}
