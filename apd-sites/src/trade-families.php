<?php
/**
 * Trade families. Pure PHP, no WordPress dependency.
 *
 * Every slug returned by apd_trades() must belong to exactly one family.
 * Later templates can target a whole family instead of naming every trade.
 */

if (!function_exists('apd_site_families')) {
    /**
     * Family slug => array( label, trades[] ).
     */
    function apd_site_families(): array
    {
        return array(
            'build' => array(
                'label'  => 'Building & trades',
                'trades' => array(
                    'electrician', 'plumber', 'gasfitter', 'carpenter', 'builder', 'handyman',
                    'painter', 'plasterer', 'tiler', 'bricklayer', 'concreter', 'roofer',
                    'glazier', 'cabinet-maker', 'flooring', 'fencer', 'solar', 'air-conditioning',
                ),
            ),
            'outdoor' => array(
                'label'  => 'Outdoor & garden',
                'trades' => array('landscaper', 'gardener', 'lawn-mowing', 'tree-services', 'pool-services'),
            ),
            'home' => array(
                'label'  => 'Home services',
                'trades' => array(
                    'locksmith', 'pest-control', 'cleaner', 'carpet-cleaning',
                    'window-cleaning', 'removalist', 'rubbish-removal',
                ),
            ),
            'auto' => array(
                'label'  => 'Automotive',
                'trades' => array('mechanic', 'auto-electrician', 'car-detailing'),
            ),
            'repair' => array(
                'label'  => 'Repairs & tech',
                'trades' => array('appliance-repair', 'antenna-tv', 'computer-repair'),
            ),
            'pets' => array(
                'label'  => 'Pets',
                'trades' => array('pet-grooming', 'dog-walking'),
            ),
            'pro' => array(
                'label'  => 'Professional services',
                'trades' => array('tutor', 'photographer', 'bookkeeper', 'web-designer', 'graphic-designer', 'other'),
            ),
        );
    }
}

if (!function_exists('apd_site_design_families')) {
    /**
     * Design families used by the ClickSites importer (--family=…). These are
     * narrower than apd_site_families(): a cleaning design only belongs to the
     * cleaning trades, not every home service.
     *
     * @return array family slug => array( label, trades[] )
     */
    function apd_site_design_families(): array
    {
        return array(
            'cleaning'   => array(
                'label'  => 'Cleaning',
                'trades' => array('cleaner', 'carpet-cleaning', 'window-cleaning'),
            ),
            'electrical' => array(
                'label'  => 'Electrical',
                'trades' => array('electrician', 'air-conditioning', 'solar'),
            ),
            'plumbing'   => array(
                'label'  => 'Plumbing & gas',
                'trades' => array('plumber', 'gasfitter'),
            ),
        );
    }
}

if (!function_exists('apd_site_design_family_trades')) {
    /**
     * Trade slugs for a design family, or an empty array when unknown.
     */
    function apd_site_design_family_trades(string $family): array
    {
        $families = apd_site_design_families();
        return isset($families[$family]['trades']) ? $families[$family]['trades'] : array();
    }
}

if (!function_exists('apd_site_trade_family_map')) {
    /**
     * Flat map of trade slug => family slug.
     */
    function apd_site_trade_family_map(): array
    {
        $map = array();
        foreach (apd_site_families() as $family => $def) {
            foreach ($def['trades'] as $trade) {
                $map[$trade] = $family;
            }
        }
        return $map;
    }
}

if (!function_exists('apd_site_trade_family')) {
    /**
     * Family slug for a trade, or null when the trade is unknown.
     */
    function apd_site_trade_family(?string $slug): ?string
    {
        $slug = (string) $slug;
        $map = apd_site_trade_family_map();
        return isset($map[$slug]) ? $map[$slug] : null;
    }
}

if (!function_exists('apd_site_family_label')) {
    function apd_site_family_label(string $family): string
    {
        $families = apd_site_families();
        return isset($families[$family]['label']) ? $families[$family]['label'] : $family;
    }
}

if (!function_exists('apd_site_family_trades')) {
    /**
     * All trade slugs inside a family.
     */
    function apd_site_family_trades(string $family): array
    {
        $families = apd_site_families();
        return isset($families[$family]['trades']) ? $families[$family]['trades'] : array();
    }
}

if (!function_exists('apd_site_families_for_trades')) {
    /**
     * Unique family slugs that cover the given trades, in canonical order.
     */
    function apd_site_families_for_trades(array $trades): array
    {
        $found = array();
        foreach (apd_site_families() as $family => $def) {
            foreach ($trades as $trade) {
                if (in_array($trade, $def['trades'], true)) {
                    $found[$family] = true;
                    break;
                }
            }
        }
        return array_keys($found);
    }
}
