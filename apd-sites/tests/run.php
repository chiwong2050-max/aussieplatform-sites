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

require_once $src_dir . '/trade-families.php';
require_once $src_dir . '/sanitise.php';
require_once $src_dir . '/validate.php';
require_once $src_dir . '/render.php';
require_once $src_dir . '/routing.php';
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
$gallery = apd_site_gallery($templates, array('plumber'));
t_eq(3, count($gallery), 'gallery lists the 3 Example layout templates for a plumber listing');
$all_example = true;
foreach ($gallery as $meta) {
    if (empty($meta['example'])) {
        $all_example = false;
    }
}
t_ok($all_example, 'the 3 listed templates are marked as example layouts');

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
 * 6. php -l on every file
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
