<?php
/**
 * Plugin Name: APD Rich (reference copy)
 * Description: Read-only reference copy of the aussieplatform.app rich-content MU-plugin. Provides presentation helpers layered on top of APD Core.
 * Version: 1.0.0
 *
 * This file is safe to include outside WordPress: it defines functions and
 * guards its one WordPress hook registration.
 */

if (!function_exists('apd_rich_services')) {
    /**
     * Normalise a list of services into title/description rows.
     */
    function apd_rich_services($services): array
    {
        $out = array();
        if (!is_array($services)) {
            return $out;
        }
        foreach ($services as $service) {
            if (is_string($service)) {
                $out[] = array('title' => $service, 'description' => '');
                continue;
            }
            if (is_array($service)) {
                $out[] = array(
                    'title'       => isset($service['title']) ? (string) $service['title'] : '',
                    'description' => isset($service['description']) ? (string) $service['description'] : '',
                );
            }
        }
        return $out;
    }
}

if (!function_exists('apd_rich_hours')) {
    function apd_rich_hours($hours): string
    {
        if (is_array($hours)) {
            $hours = implode(', ', array_map('strval', $hours));
        }
        return trim((string) $hours);
    }
}

if (!function_exists('apd_rich_badge')) {
    function apd_rich_badge(string $label, string $tone = 'neutral'): string
    {
        return '<span class="apd-badge apd-badge--' . apd_esc($tone) . '">' . apd_esc($label) . '</span>';
    }
}

if (!function_exists('apd_rich_register')) {
    function apd_rich_register(): void
    {
        // Rich content registers nothing by itself in this reference copy.
    }
}

if (function_exists('add_action')) {
    add_action('init', 'apd_rich_register');
}
