<?php
/**
 * Import a ClickSites HTML export into a template.
 *
 * Usage:
 *   php bin/import-clicksites.php import/<folder> <template-id> --trades=plumber,electrician
 *   php bin/import-clicksites.php import/<folder> <template-id> --family=build
 *
 * The folder is the exported ClickSites site. The result is written to
 * apd-sites/templates/<template-id>/ with meta.json set to example:false.
 */

if ('cli' !== PHP_SAPI) {
    fwrite(STDERR, "This script must be run from the command line.\n");
    exit(1);
}

$root = dirname(__DIR__);
require_once $root . '/src/trade-families.php';
require_once $root . '/src/sanitise.php';
require_once $root . '/src/render.php';
require_once $root . '/src/clicksites.php';
require_once $root . '/src/importer.php';

/**
 * Resolve a folder argument relative to the current directory, the repo root
 * and the templates directory, tolerating a leading "import/".
 */
function apd_sites_cli_resolve_dir(string $arg, string $root): string
{
    $arg   = rtrim($arg, '/\\');
    $tries = array(
        $arg,
        getcwd() . '/' . $arg,
        $root . '/' . $arg,
        $root . '/../' . $arg,
    );
    if (0 === strpos($arg, 'import/')) {
        $stripped = substr($arg, strlen('import/'));
        $tries[]  = getcwd() . '/' . $stripped;
        $tries[]  = $root . '/' . $stripped;
    }
    foreach ($tries as $try) {
        if ('' !== $try && is_dir($try)) {
            return $try;
        }
    }
    return '';
}

$args = array_slice($argv, 1);
if (count($args) < 2) {
    fwrite(STDERR, "Usage: php bin/import-clicksites.php import/<folder> <template-id> [--trades=a,b | --family=build] [--name=\"Label\"]\n");
    exit(1);
}

$source_arg = (string) array_shift($args);
$template_id = sanitize_placeholder_id((string) array_shift($args));

$trades = array();
$family = '';
$name   = '';
foreach ($args as $arg) {
    if (0 === strpos($arg, '--trades=')) {
        $trades = array_values(array_filter(array_map('trim', explode(',', substr($arg, 9)))));
    } elseif (0 === strpos($arg, '--family=')) {
        $family = trim(substr($arg, 9));
    } elseif (0 === strpos($arg, '--name=')) {
        $name = trim(substr($arg, 7), "\"'");
    }
}

function sanitize_placeholder_id(string $id): string
{
    $id = strtolower(trim($id));
    $id = preg_replace('/[^a-z0-9]+/', '-', $id);
    return trim((string) $id, '-');
}

if ('' === $template_id) {
    fwrite(STDERR, "A template id is required.\n");
    exit(1);
}

$source = apd_sites_cli_resolve_dir($source_arg, $root);
if ('' === $source) {
    fwrite(STDERR, "Source folder not found: {$source_arg}\n");
    exit(1);
}

if ('' === $name) {
    $name = ucwords(str_replace('-', ' ', $template_id));
}

$destination = $root . '/templates/' . $template_id;

$report = apd_site_import_clicksites($source, $destination, array(
    'id'     => $template_id,
    'name'   => $name,
    'trades' => $trades, // empty: resolved from --family by the importer
    'family' => $family,
));

echo "Imported ClickSites export\n";
echo "  source:      {$source}\n";
echo "  destination: {$destination}\n";
echo "  template id: {$template_id}\n\n";

echo "Removed:\n";
if (!$report['removed']) {
    echo "  (nothing)\n";
} else {
    foreach ($report['removed'] as $item) {
        echo '  - ' . $item . "\n";
    }
}

echo "\nImages copied:\n";
if (!$report['images']) {
    echo "  (none)\n";
} else {
    foreach ($report['images'] as $item) {
        echo '  - ' . $item . "\n";
    }
}

echo "\nPlaceholders found (known):\n";
echo '  ' . ($report['placeholders']['known'] ? implode(', ', $report['placeholders']['known']) : '(none)') . "\n";

echo "\nPlaceholders left for a human to map (unknown):\n";
if (!$report['placeholders']['unknown']) {
    echo "  (none)\n";
} else {
    foreach ($report['placeholders']['unknown'] as $token) {
        echo '  - {{' . $token . "}}\n";
    }
}

if ($report['notes']) {
    echo "\nNotes:\n";
    foreach ($report['notes'] as $note) {
        echo '  - ' . $note . "\n";
    }
}

echo "\nDone.\n";
