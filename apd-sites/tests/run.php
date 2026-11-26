<?php
/**
 * Tiny acceptance test runner. No Composer, no WordPress required.
 *
 * Run from the plugin folder:
 *   cd apd-sites && php tests/run.php
 */

$apd_sites  = dirname(__DIR__);              // .../apd-sites
$repo_root  = dirname($apd_sites);           // repo root
$src_dir    = $apd_sites . '/src';
$templates  = $apd_sites . '/templates';
$fixtures   = $apd_sites . '/tests/fixtures';
$work_dir   = $apd_sites . '/tests/tmp';

/*
 * Minimal WordPress stubs so the real reference core (reference/zz-apd-core.php)
 * can be loaded without WordPress. Only the symbols it touches at include time
 * are needed.
 */
if (!defined('ABSPATH')) {
    define('ABSPATH', $repo_root . '/');
}
if (!function_exists('add_action')) {
    function add_action($hook, $callback = null, $priority = 10, $args = 1)
    {
        return true;
    }
}
if (!function_exists('add_filter')) {
    function add_filter($hook, $callback = null, $priority = 10, $args = 1)
    {
        return true;
    }
}
if (!function_exists('apply_filters')) {
    function apply_filters($hook, $value = null, ...$args)
    {
        return $value;
    }
}
if (!function_exists('do_action')) {
    function do_action($hook, ...$args)
    {
        return null;
    }
}
if (!function_exists('add_shortcode')) {
    function add_shortcode($tag, $callback = null)
    {
        return true;
    }
}

require_once $src_dir . '/trade-families.php';
require_once $src_dir . '/sanitise.php';
require_once $src_dir . '/validate.php';
require_once $src_dir . '/render.php';
require_once $src_dir . '/routing.php';
require_once $src_dir . '/trial.php';
require_once $src_dir . '/clicksites.php';
require_once $src_dir . '/writer.php';
require_once $src_dir . '/importer.php';
require_once $repo_root . '/reference/zz-apd-core.php';

$GLOBALS['apd_pass'] = 0;
$GLOBALS['apd_fail'] = 0;

function t_ok($condition, string $message): void
{
    if ($condition) {
        $GLOBALS['apd_pass']++;
        echo "  PASS  {$message}\n";
    } else {
        $GLOBALS['apd_fail']++;
        echo "  FAIL  {$message}\n";
    }
}

function t_eq($expected, $actual, string $message): void
{
    t_ok(
        $expected === $actual,
        $message . ($expected === $actual ? '' : ' (expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . ')')
    );
}

function rrmdir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $items = scandir($dir);
    foreach ($items as $item) {
        if ('.' === $item || '..' === $item) {
            continue;
        }
        $path = $dir . '/' . $item;
        if (is_dir($path)) {
            rrmdir($path);
        } else {
            @unlink($path);
        }
    }
    @rmdir($dir);
}

function copydir(string $src, string $dst): void
{
    if (!is_dir($dst)) {
        @mkdir($dst, 0775, true);
    }
    $items = scandir($src);
    foreach ($items as $item) {
        if ('.' === $item || '..' === $item) {
            continue;
        }
        $s = $src . '/' . $item;
        $d = $dst . '/' . $item;
        if (is_dir($s)) {
            copydir($s, $d);
        } else {
            copy($s, $d);
        }
    }
}

echo "APD Sites acceptance tests\n";
echo str_repeat('-', 60) . "\n";

/* ------------------------------------------------------------------ *
 * 1. Importer
 * ------------------------------------------------------------------ */
echo "\nImporter\n";
$fixture    = $fixtures . '/clicksites-sample';
$import_out = $work_dir . '/example-import';
rrmdir($import_out);

$report = apd_site_import_clicksites($fixture, $import_out, array(
    'id'     => 'example-import',
    'name'   => 'Example Import',
    'trades' => array('*'),
));

$imported_html = is_file($import_out . '/template.html') ? (string) file_get_contents($import_out . '/template.html') : '';
$imported_css  = is_file($import_out . '/style.css') ? (string) file_get_contents($import_out . '/style.css') : '';

t_ok(false === stripos($imported_html, '<script'), 'importer removes <script> tags');
t_ok(false === stripos($imported_html, 'onclick'), 'importer removes onclick attributes');
t_ok(false === stripos($imported_html, 'onload'), 'importer removes other on* attributes');
t_ok(false === stripos($imported_html, 'javascript:'), 'importer removes javascript: URLs');
t_ok(false === stripos($imported_html, '<iframe'), 'importer removes external iframes');
t_ok(false === stripos($imported_html, 'google-analytics'), 'importer removes tracking pixels');
t_ok(false === stripos($imported_html, 'external.example.com'), 'importer removes off-site forms');
t_ok(false === stripos($imported_css, '@import'), 'importer strips @import rules');
t_ok(in_array('slogan', $report['placeholders']['unknown'], true), 'importer reports unknown placeholders (slogan)');
t_ok(in_array('images/hero.jpg', $report['images'], true), 'importer copies local images into the template');
t_ok(count($report['removed']) > 0, 'importer reports what it removed');
$imported_meta = json_decode((string) file_get_contents($import_out . '/meta.json'), true);
t_ok(is_array($imported_meta) && false === $imported_meta['example'], 'imported template meta.json has example:false');

/* ------------------------------------------------------------------ *
 * 2. Trade families
 * ------------------------------------------------------------------ */
echo "\nTrade families\n";
$family_map = apd_site_trade_family_map();
$missing    = array();
foreach (array_keys(apd_trades()) as $slug) {
    if (!isset($family_map[$slug])) {
        $missing[] = $slug;
    }
}
t_ok(!$missing, 'every apd_trades() slug maps to a family' . ($missing ? ': ' . implode(', ', $missing) : ''));

$counts = array();
foreach (apd_site_families() as $def) {
    foreach ($def['trades'] as $trade) {
        $counts[$trade] = isset($counts[$trade]) ? $counts[$trade] + 1 : 1;
    }
}
$dupes = array();
foreach ($counts as $trade => $count) {
    if ($count > 1) {
        $dupes[] = $trade;
    }
}
t_ok(!$dupes, 'each trade belongs to exactly one family' . ($dupes ? ': ' . implode(', ', $dupes) : ''));
t_eq('build', apd_site_trade_family('plumber'), 'plumber is in the build family');
t_eq('pets', apd_site_trade_family('dog-walking'), 'dog-walking is in the pets family');

/* ------------------------------------------------------------------ *
 * 3. Gallery
 * ------------------------------------------------------------------ */
echo "\nGallery\n";
$gallery      = apd_site_gallery($templates, array('plumber'));
$gallery_keys = array_keys($gallery);
t_eq(4, count($gallery), 'gallery lists the plumbing template plus the 3 Example layouts for a plumber');
t_eq('plumbing-a', $gallery_keys[0], 'the trade specific template sorts before the Example layouts');
$example_count = 0;
foreach ($gallery as $meta) {
    if (!empty($meta['example'])) {
        $example_count++;
    }
}
t_eq(3, $example_count, 'the 3 Example layouts are marked example:true');

$gallery_tmp = $work_dir . '/gallery';
rrmdir($gallery_tmp);
@mkdir($gallery_tmp, 0775, true);
foreach (array('example-classic', 'example-services', 'example-gallery') as $tid) {
    copydir($templates . '/' . $tid, $gallery_tmp . '/' . $tid);
}
@mkdir($gallery_tmp . '/plumber-special', 0775, true);
file_put_contents(
    $gallery_tmp . '/plumber-special/meta.json',
    json_encode(array('id' => 'plumber-special', 'name' => 'Plumber special', 'example' => false, 'trades' => array('plumber'), 'fields' => array()))
);
file_put_contents($gallery_tmp . '/plumber-special/template.html', '<p>{{name}}</p>');

$sorted      = apd_site_gallery($gallery_tmp, array('plumber'));
$sorted_keys = array_keys($sorted);
t_eq('plumber-special', $sorted_keys[0], 'a trades:["plumber"] template sorts before the Example layouts');

/* ------------------------------------------------------------------ *
 * 4. Renderer and validator
 * ------------------------------------------------------------------ */
echo "\nRenderer and validator\n";
$classic_meta = apd_site_load_meta($templates, 'example-classic');
$render_data  = array(
    'name'        => '<b>Bob</b>',
    'phone'       => '0400 000 000',
    'description' => 'Friendly & reliable',
    'services'    => array(array('title' => 'Blocked drains')),
);
$rendered = apd_site_render($templates, 'example-classic', $render_data);
t_ok(false !== strpos($rendered, '&lt;b&gt;Bob&lt;/b&gt;'), 'renderer escapes HTML in owner text');
t_ok(false === strpos($rendered, '<b>Bob</b>'), 'renderer never outputs raw owner HTML');
t_ok(false !== strpos($rendered, 'Friendly &amp; reliable'), 'renderer escapes ampersands');

$errors = apd_site_validate($classic_meta['fields'], array());
t_ok(isset($errors['name']) && isset($errors['phone']), 'missing required fields fail validation');
$valid = apd_site_validate($classic_meta['fields'], array('name' => 'Bob', 'phone' => '0400 000 000'));
t_eq(0, count($valid), 'valid data passes validation');
$too_long = apd_site_validate($classic_meta['fields'], array('name' => str_repeat('a', 200), 'phone' => '1'));
t_ok(isset($too_long['name']), 'fields longer than max fail validation');

/* ------------------------------------------------------------------ *
 * 5. Indexing and route gating
 * ------------------------------------------------------------------ */
echo "\nIndexing and route gating\n";
$doc  = apd_site_render_document($templates, 'example-classic', array('name' => 'Bob'), array('indexing' => false));
$head = substr($doc, 0, (int) strpos($doc, '</head>'));
t_ok(false !== strpos($head, 'noindex'), 'with indexing off the rendered head contains noindex');

$doc_index = apd_site_render_document($templates, 'example-classic', array('name' => 'Bob'), array('indexing' => true));
$head_index = substr($doc_index, 0, (int) strpos($doc_index, '</head>'));
t_ok(false !== strpos($head_index, 'index,follow'), 'with indexing on the rendered head allows indexing');

t_eq(404, apd_site_route_status('/site/1-my-plumbing/', false, false), 'with APD_SITES_ON off a non-admin gets 404');
t_eq(200, apd_site_route_status('/site/1-my-plumbing/', true, false), 'admins can still test routes when off');
t_eq(200, apd_site_route_status('/site/1-my-plumbing/', false, true), 'the route runs when the switch is on');
t_eq(404, apd_site_route_status('/some/other/path/', false, true), 'unrelated paths are not handled');

/* ------------------------------------------------------------------ *
 * 6. Real ClickSites designs
 * ------------------------------------------------------------------ */
echo "\nReal ClickSites designs\n";
$real_templates = array(
    'cleaning-a'   => 'cleaning',
    'cleaning-b'   => 'cleaning',
    'electrical-a' => 'electrical',
    'plumbing-a'   => 'plumbing',
);
foreach ($real_templates as $tid => $family) {
    $meta = apd_site_load_meta($templates, $tid);
    if (!is_array($meta)) {
        t_ok(false, $tid . ': meta.json loads');
        continue;
    }
    t_ok(true, $tid . ': meta.json loads');
    t_eq(false, $meta['example'] ?? null, $tid . ': meta.json has example:false');
    t_eq($family, (string) ($meta['family'] ?? ''), $tid . ': meta family is ' . $family);

    $thumb = (string) ($meta['thumbnail'] ?? '');
    t_ok('' !== $thumb && is_file($templates . '/' . $tid . '/' . $thumb), $tid . ': thumbnail file exists');

    $html = apd_site_template_html($templates, $tid);
    t_ok(is_string($html) && '' !== $html, $tid . ': template.html exists');
    $html = (string) $html;
    t_ok(false === stripos($html, '<script'), $tid . ': no <script> left');
    t_ok(false === stripos($html, 'clicksite'), $tid . ': no ClickSites branding left');
    t_ok(false === stripos($html, '__NEXT_DATA__'), $tid . ': no __NEXT_DATA__ left');
    t_ok(false === stripos($html, 'onclick'), $tid . ': no onclick attributes left');
    t_ok(false === stripos($html, 'javascript:'), $tid . ': no javascript: URLs left');
    t_ok(false === stripos($html, 'google-analytics'), $tid . ': no analytics left');
    t_ok(false !== strpos($html, '{{business_name}}'), $tid . ': business_name placeholder present');
    t_ok(false !== strpos($html, '{{phone}}'), $tid . ': phone placeholder present');
    t_ok(false !== strpos($html, '{{#each services}}'), $tid . ': services block present');
    t_ok(false !== strpos($html, '{{#each testimonials}}'), $tid . ': testimonials block present (hidden until real)');
    t_ok(0 === preg_match('/\b\d+\s*%/', $html), $tid . ': no made-up percentage claims');
    t_ok(
        0 === preg_match('/\b(?:years?\s+(?:of\s+)?experience|award[- ]winning|established|satisfaction guaranteed)\b/i', $html),
        $tid . ': no made-up awards or experience claims'
    );

    // The four real exports must still resolve their trades from the design family.
    $expected = apd_site_design_family_trades($family);
    t_eq($expected, $meta['trades'] ?? array(), $tid . ': trades come from the ' . $family . ' family');
}

/* ------------------------------------------------------------------ *
 * 7. Importer on a Next.js export
 * ------------------------------------------------------------------ */
echo "\nImporter (Next.js)\n";
$nd_fixture = $fixtures . '/nextjs-sample';
$nd_out     = $work_dir . '/nextjs-import';
rrmdir($nd_out);
$report_nd = apd_site_import_clicksites($nd_fixture, $nd_out, array(
    'id'     => 'nextjs-import',
    'name'   => 'Next.js Import',
    'family' => 'cleaning',
));
t_eq('nextjs', $report_nd['mode'] ?? '', 'a Next.js export is rebuilt from __NEXT_DATA__');

$nd_html = is_file($nd_out . '/template.html') ? (string) file_get_contents($nd_out . '/template.html') : '';
t_ok(false === stripos($nd_html, '<script'), 'rebuilt template has no scripts');
t_ok(false === stripos($nd_html, 'clicksite'), 'rebuilt template has no ClickSites branding');
t_ok(false !== strpos($nd_html, '{{business_name}}'), 'rebuilt template keeps the business_name placeholder');
t_ok(false !== strpos($nd_html, '{{#each services}}'), 'rebuilt template keeps the services block');

$nd_meta = json_decode((string) file_get_contents($nd_out . '/meta.json'), true);
t_ok(is_array($nd_meta) && false === ($nd_meta['example'] ?? null), 'rebuilt meta.json has example:false');
t_eq('cleaning', (string) ($nd_meta['family'] ?? ''), 'rebuilt meta.json keeps the family');
t_eq(apd_site_design_family_trades('cleaning'), $nd_meta['trades'] ?? array(), 'rebuilt meta.json resolves the family trades');

$removed_blob = strtolower(implode(' | ', $report_nd['removed']));
t_ok(false !== strpos($removed_blob, 'testimonial'), 'importer drops the demo testimonials (no fake reviews)');
t_ok(false !== strpos($removed_blob, 'claim'), 'importer reports the made-up claims it removed');

// If a real export is on disk, its payload must parse too.
$real_src = $repo_root . '/import/cleaning-a/index.html';
if (is_file($real_src)) {
    $nd_real = apd_site_cs_next_data((string) file_get_contents($real_src));
    t_ok(is_array($nd_real) && !empty($nd_real['content']), 'a real ClickSites export parses from __NEXT_DATA__ (cleaning-a)');
} else {
    echo "  NOTE  import/cleaning-a not on disk, skipped the real-export parse check\n";
}

/* ------------------------------------------------------------------ *
 * 8. Rendering with missing fields
 * ------------------------------------------------------------------ */
echo "\nRendering with missing fields\n";
$empty_rendered = apd_site_render($templates, 'cleaning-a', array());
t_ok(false === strpos($empty_rendered, '{{'), 'no unrendered placeholders when every field is missing');
t_ok(false === stripos($empty_rendered, '<script'), 'missing data never introduces a script');
t_ok(false !== strpos($empty_rendered, 'Call '), 'the phone call link still renders');

$partial = apd_site_render($templates, 'cleaning-a', array('business_name' => 'Bob', 'phone' => '0400 000 000'));
t_ok(false !== strpos($partial, 'Bob'), 'partial data renders the business name');
t_ok(false === strpos($partial, 'apd-card'), 'no service cards render when services are empty');
t_ok(false === strpos($partial, 'apd-testimonials'), 'the testimonials section stays hidden with no real reviews');

$stock_merged = apd_site_stock_merge(
    array(
        'hero_image' => 'images/hero.webp',
        'gallery'    => array(array('image' => 'images/g1.webp', 'caption' => '')),
    ),
    array(),
    '/base'
);
t_eq('/base/images/hero.webp', $stock_merged['hero_image'], 'the stock hero image fills an empty slot');
t_eq('/base/images/g1.webp', $stock_merged['gallery'][0]['image'], 'the stock gallery gets the base URL prefix');

/* ------------------------------------------------------------------ *
 * 9. Owner access
 * ------------------------------------------------------------------ */
echo "\nOwner access\n";
t_ok(apd_site_can_manage(5, 5, false), 'the listing owner can manage their own site');
t_ok(!apd_site_can_manage(5, 7, false), 'another logged-in user cannot manage the site');
t_ok(!apd_site_can_manage(5, 0, false), 'a logged-out visitor cannot manage the site');
t_ok(apd_site_can_manage(5, 0, true), 'an admin can manage any site');

$owner_route = apd_site_match_route('/my-site/12/');
t_ok(
    is_array($owner_route) && 'owner' === $owner_route['route'] && 12 === $owner_route['listing_id'],
    '/my-site/<listing-id>/ matches the owner route'
);
$live_route = apd_site_match_route('/site/bob-plumbing/');
t_ok(
    is_array($live_route) && 'live' === $live_route['route'] && 'bob-plumbing' === $live_route['slug'],
    '/site/<slug>/ matches the live route'
);

/* ------------------------------------------------------------------ *
 * 10. The no-AI writer
 * ------------------------------------------------------------------ */
echo "\nWriter (no-AI) for every family\n";
$writer_trades = array(
    'build'   => 'electrician',
    'outdoor' => 'gardener',
    'home'    => 'cleaner',
    'auto'    => 'mechanic',
    'repair'  => 'appliance-repair',
    'pets'    => 'dog-walking',
    'pro'     => 'tutor',
);
foreach ($writer_trades as $family => $trade) {
    $ctx  = array(
        'business_name' => 'Northside ' . $trade,
        'trade'         => $trade,
        'suburb'        => 'Parramatta',
        'state'         => 'NSW',
        'service_area'  => 'Parramatta',
        'services'      => array(array('title' => 'General work')),
    );
    $copy = apd_site_write_copy($ctx);
    $ok   = '' !== trim((string) ($copy['hero_heading'] ?? ''))
        && '' !== trim((string) ($copy['hero_sub'] ?? ''))
        && '' !== trim((string) ($copy['about'] ?? ''))
        && '' !== trim((string) ($copy['cta_text'] ?? ''));
    t_ok($ok, $family . ': the writer fills every text field');
    t_ok('' !== trim((string) ($copy['services'][0]['text'] ?? '')), $family . ': each service gets its own short line');
    $blob = implode(' ', array(
        (string) ($copy['hero_heading'] ?? ''),
        (string) ($copy['hero_sub'] ?? ''),
        (string) ($copy['about'] ?? ''),
        (string) ($copy['services'][0]['text'] ?? ''),
    ));
    t_ok(false === strpos($blob, '{'), $family . ': no unfilled {placeholders} remain');
    t_ok(0 === preg_match('/\b\d+\s*%/', $blob), $family . ': no made-up percentage claims');
}

$copy_a = apd_site_write_copy(array('business_name' => 'Alpha Cleaning', 'trade' => 'cleaner', 'suburb' => 'Ryde'));
$copy_b = apd_site_write_copy(array('business_name' => 'Zeta Cleaning', 'trade' => 'cleaner', 'suburb' => 'Ryde'));
t_ok(
    $copy_a['about'] !== $copy_b['about'] || $copy_a['hero_heading'] !== $copy_b['hero_heading'],
    'different businesses get different wording'
);

$ai_ctx = array(
    'business_name' => 'Fallback Co',
    'trade'         => 'plumber',
    'suburb'        => 'Bondi',
    'services'      => array(array('title' => 'Blocked drains')),
);
t_ok(!apd_sites_ai_enabled(), 'the AI writer is disabled while APD_SITES_AI is off');
t_ok(null === apd_sites_ai_write($ai_ctx), 'the AI writer returns null when no key is available');
t_eq(apd_site_write_copy($ai_ctx), apd_sites_write_copy($ai_ctx), 'apd_sites_write_copy falls back to the no-AI writer');

/* ------------------------------------------------------------------ *
 * 11. Trial and publish
 * ------------------------------------------------------------------ */
echo "\nTrial and publish\n";
t_eq('Free 14-day trial, then A$9/month or A$49/year (GST included)', apd_site_plan_text(), 'the plan text matches the offer');
t_eq(14, apd_site_trial_days(), 'the free trial is 14 days');

$pub = apd_site_publish_fields('2026-01-01 09:00:00');
t_eq('pending', $pub['status'], 'publishing sends the site to the review queue');
t_eq('2026-01-01 09:00:00', $pub['trial_start'], 'publishing stores the trial start time');

$start = '2026-01-01 09:00:00';
$t0    = (int) strtotime($start);
t_eq(14, apd_site_trial_days_left($start, $t0), 'a fresh trial has 14 days left');
t_eq(1, apd_site_trial_days_left($start, $t0 + 13 * DAY_IN_SECONDS), 'the last trial day still shows 1 day left');
t_eq(0, apd_site_trial_days_left($start, $t0 + 14 * DAY_IN_SECONDS), 'an ended trial shows 0 days left');

t_eq('review', apd_site_trial_state('pending', $start, $t0, false), 'a pending site reads as in review');
t_eq('trial', apd_site_trial_state('live', $start, $t0, false), 'an approved site during its trial reads as trial');
t_eq('expired', apd_site_trial_state('live', $start, $t0 + 15 * DAY_IN_SECONDS, false), 'an ended trial with billing off reads as expired');
t_eq('live', apd_site_trial_state('live', $start, $t0 + 15 * DAY_IN_SECONDS, true), 'with billing on an ended trial stays live');
t_eq('rejected', apd_site_trial_state('rejected', $start, $t0, false), 'a rejected site reads as rejected');

t_ok(!apd_site_billing_required(true, false), 'with billing off nothing is ever billing-required');
t_ok(apd_site_billing_required(true, true), 'with billing on the filter decides');
t_ok(!apd_site_billing_required(false, true), 'with billing on and an empty filter, nothing is required');

/* ------------------------------------------------------------------ *
 * 12. php -l on every file
 * ------------------------------------------------------------------ */
echo "\nphp -l\n";
$php_files = array($repo_root . '/zz-apd-sites.php');
$scan_dirs = array($apd_sites, $repo_root . '/reference');
foreach ($scan_dirs as $dir) {
    if (!is_dir($dir)) {
        continue;
    }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if ($file->isFile() && 'php' === strtolower($file->getExtension())) {
            $php_files[] = $file->getPathname();
        }
    }
}
$php_binary = PHP_BINARY;
$lint_fail  = array();
foreach (array_unique($php_files) as $file) {
    $output = array();
    $rc     = 0;
    exec(escapeshellarg($php_binary) . ' -l ' . escapeshellarg($file) . ' 2>&1', $output, $rc);
    if (0 !== $rc) {
        $lint_fail[] = $file . ' :: ' . implode(' ', $output);
    }
}
t_ok(!$lint_fail, 'php -l passes on every PHP file (' . count(array_unique($php_files)) . ' files)');
foreach ($lint_fail as $fail) {
    echo '        ' . $fail . "\n";
}

/* ------------------------------------------------------------------ */
echo "\n" . str_repeat('-', 60) . "\n";
echo 'Passed: ' . $GLOBALS['apd_pass'] . '  Failed: ' . $GLOBALS['apd_fail'] . "\n";
exit($GLOBALS['apd_fail'] > 0 ? 1 : 0);
