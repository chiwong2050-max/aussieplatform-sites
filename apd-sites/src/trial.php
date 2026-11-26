<?php
/**
 * Trial and publishing rules for tradie mini-websites. Pure PHP, no WordPress
 * dependency, so the decisions can be unit tested.
 *
 * With APD_SITES_BILLING off, publishing a site simply starts a free trial and
 * sends it to the review queue. When the trial runs out the site stays live and
 * the owner only sees a friendly banner.
 */

if (!defined('DAY_IN_SECONDS')) {
    define('DAY_IN_SECONDS', 86400);
}

if (!function_exists('apd_site_trial_days')) {
    /**
     * Length of the free trial in days.
     */
    function apd_site_trial_days(): int
    {
        return 14;
    }
}

if (!function_exists('apd_site_plan_text')) {
    /**
     * The plan shown on the publish step.
     */
    function apd_site_plan_text(): string
    {
        return 'Free 14-day trial, then A$9/month or A$49/year (GST included)';
    }
}

if (!function_exists('apd_site_trial_end')) {
    /**
     * Unix timestamp the trial ends, or 0 when there is no trial.
     */
    function apd_site_trial_end(string $trial_start): int
    {
        $trial_start = trim($trial_start);
        if ('' === $trial_start || '0000-00-00 00:00:00' === $trial_start) {
            return 0;
        }
        $ts = strtotime($trial_start);
        if (false === $ts) {
            return 0;
        }
        return $ts + apd_site_trial_days() * DAY_IN_SECONDS;
    }
}

if (!function_exists('apd_site_trial_days_left')) {
    /**
     * Whole days left in the trial (0 once it has ended).
     */
    function apd_site_trial_days_left(string $trial_start, int $now): int
    {
        $end = apd_site_trial_end($trial_start);
        if ($end <= 0 || $now >= $end) {
            return 0;
        }
        return (int) ceil(($end - $now) / DAY_IN_SECONDS);
    }
}

if (!function_exists('apd_site_trial_state')) {
    /**
     * The display state for a site row.
     *
     * @param string $status      Stored status: draft|pending|live|rejected.
     * @param string $trial_start Trial start datetime (mysql format).
     * @param int    $now         Current unix timestamp.
     * @param bool   $billing     Whether billing is switched on.
     * @return string draft|review|trial|live|expired|rejected
     */
    function apd_site_trial_state(string $status, string $trial_start, int $now, bool $billing): string
    {
        $status = strtolower(trim($status));
        if ('draft' === $status) {
            return 'draft';
        }
        if ('pending' === $status) {
            return 'review';
        }
        if ('rejected' === $status) {
            return 'rejected';
        }
        if ('live' !== $status) {
            return '' !== $status ? $status : 'draft';
        }
        $end = apd_site_trial_end($trial_start);
        if ($end <= 0) {
            return $billing ? 'live' : 'trial';
        }
        if ($now <= $end) {
            return 'trial';
        }
        return $billing ? 'live' : 'expired';
    }
}

if (!function_exists('apd_site_publish_fields')) {
    /**
     * The columns set when an owner presses Publish. Publishing starts the free
     * trial and moves the site into the existing review queue.
     *
     * @return array status + trial_start for apd_sites_save_site().
     */
    function apd_site_publish_fields(string $now): array
    {
        return array(
            'status'      => 'pending',
            'trial_start' => $now,
        );
    }
}

if (!function_exists('apd_site_billing_required')) {
    /**
     * Whether the site needs a paid plan before it can go live.
     *
     * The real hook is the WordPress filter apd_sites_billing_required, added by
     * wp/routes.php. This pure helper keeps the default decision testable: with
     * billing off nothing is ever required.
     */
    function apd_site_billing_required(bool $filter_value, bool $billing_on): bool
    {
        if (!$billing_on) {
            return false;
        }
        return (bool) $filter_value;
    }
}
