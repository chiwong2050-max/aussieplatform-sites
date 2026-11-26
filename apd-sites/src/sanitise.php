<?php
/**
 * Text sanitiser. Pure PHP, no WordPress dependency.
 *
 * All owner supplied values pass through here before they are stored or
 * rendered. Values are never trusted: tags are removed, whitespace is
 * normalised and length is capped.
 */

if (!function_exists('apd_site_str')) {
    function apd_site_str($value): string
    {
        if (is_array($value) || is_object($value)) {
            return '';
        }
        return (string) $value;
    }
}

if (!function_exists('apd_site_collapse')) {
    function apd_site_collapse(string $text): string
    {
        $text = str_replace(array("\r\n", "\r"), "\n", $text);
        $text = preg_replace('/[ \t]+/', ' ', $text);
        $text = preg_replace('/\n{3,}/', "\n\n", $text);
        return trim($text);
    }
}

if (!function_exists('apd_site_truncate')) {
    function apd_site_truncate(string $text, int $max): string
    {
        if ($max <= 0) {
            return $text;
        }
        if (function_exists('mb_substr')) {
            return mb_substr($text, 0, $max);
        }
        return substr($text, 0, $max);
    }
}

if (!function_exists('apd_site_clean_text')) {
    /**
     * Single line text: strip tags, collapse whitespace, cap length.
     */
    function apd_site_clean_text($value, int $max = 0): string
    {
        $text = apd_site_str($value);
        $text = strip_tags($text);
        $text = str_replace(array("\r", "\n", "\t"), ' ', $text);
        $text = apd_site_collapse($text);
        return apd_site_truncate($text, $max);
    }
}

if (!function_exists('apd_site_clean_lines')) {
    /**
     * Multi line text: keep newlines, strip tags, cap length.
     */
    function apd_site_clean_lines($value, int $max = 0): string
    {
        $text = apd_site_str($value);
        $text = strip_tags($text);
        $text = apd_site_collapse($text);
        return apd_site_truncate($text, $max);
    }
}

if (!function_exists('apd_site_clean_url')) {
    /**
     * Only http(s) URLs survive.
     */
    function apd_site_clean_url($value): string
    {
        $url = trim(apd_site_str($value));
        if ('' === $url) {
            return '';
        }
        if (!preg_match('#^https?://#i', $url)) {
            $url = 'https://' . ltrim($url, '/');
        }
        $parts = parse_url($url);
        if (!$parts || empty($parts['host']) || !in_array(strtolower($parts['scheme'] ?? ''), array('http', 'https'), true)) {
            return '';
        }
        return apd_site_url_raw($url);
    }
}

if (!function_exists('apd_site_url_raw')) {
    function apd_site_url_raw(string $url): string
    {
        $url = preg_replace('/[\x00-\x1F\x7F]/', '', $url);
        return trim($url);
    }
}

if (!function_exists('apd_site_clean_email')) {
    function apd_site_clean_email($value): string
    {
        $email = trim(apd_site_str($value));
        return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '';
    }
}

if (!function_exists('apd_site_clean_phone')) {
    function apd_site_clean_phone($value): string
    {
        $phone = trim(apd_site_str($value));
        $phone = preg_replace('/[^0-9+()\-\s]/', '', $phone);
        return apd_site_truncate(trim($phone), 32);
    }
}

if (!function_exists('apd_site_slug')) {
    function apd_site_slug($value): string
    {
        $slug = strtolower(apd_site_str($value));
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
        $slug = trim($slug, '-');
        return apd_site_truncate($slug, 60);
    }
}

if (!function_exists('apd_site_clean_abn')) {
    function apd_site_clean_abn($value): string
    {
        $abn = preg_replace('/[^0-9 ]/', '', apd_site_str($value));
        return apd_site_truncate(trim($abn), 20);
    }
}
