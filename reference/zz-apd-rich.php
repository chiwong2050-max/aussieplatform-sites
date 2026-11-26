<?php
/* Plugin Name: aussieplatform richer pages, sitemaps and careful indexing (2026-11-17).
 * Guides, trade+state and trade+suburb pages, claim flow, about/contact, OSM map pins.
 * Undo: /root/bk/aussieplatform-rich-20261117/UNDO.sh
 */
if (!defined('ABSPATH')) exit;

add_action('init', function () {
  global $wpdb;
  $t = apd_tb('listings');
  $cols = $wpdb->get_col("SHOW COLUMNS FROM `$t`", 0);
  if (!in_array('lat', $cols, true)) {
    $wpdb->query("ALTER TABLE `$t`
      ADD COLUMN lat decimal(9,6) NULL DEFAULT NULL,
      ADD COLUMN lng decimal(9,6) NULL DEFAULT NULL,
      ADD COLUMN hours varchar(240) NOT NULL DEFAULT ''");
  }
}, 2);

/* ---------- helpers ---------- */
function apd_guides_data() {
  static $d = null;
  if ($d !== null) return $d;
  $f = __DIR__ . '/zz-apd-guides.json';
  $j = is_readable($f) ? json_decode((string) file_get_contents($f), true) : null;
  $d = is_array($j) ? $j : ['shared_faq' => [], 'guides' => []];
  return $d;
}
function apd_listing_indexable($l) {
  if (!$l) return false;
  $contact = (trim((string) $l->phone) !== '' || trim((string) $l->website) !== '');
  return $contact && trim((string) $l->address) !== '';
}
function apd_min_list_index() { return 3; }
function apd_trade_state_url($trade, $st) {
  return '/trade/' . $trade . '/' . strtolower($st) . '/';
}
function apd_trade_area_url($trade, $s) {
  return '/trade/' . $trade . '/' . strtolower($s->state) . '/' . apd_slug($s->name) . '-' . $s->postcode . '/';
}
function apd_trade_plural($slug) {
  $special = [
    'air-conditioning' => 'Air conditioning and heating services',
    'tree-services' => 'Tree services / arborists',
    'pool-services' => 'Pool services',
    'pest-control' => 'Pest control services',
    'carpet-cleaning' => 'Carpet cleaning services',
    'window-cleaning' => 'Window cleaning services',
    'appliance-repair' => 'Appliance repair services',
    'computer-repair' => 'Computer and phone repair services',
    'pet-grooming' => 'Pet groomers',
    'dog-walking' => 'Dog walkers',
    'antenna-tv' => 'Antenna and TV installers',
    'flooring' => 'Flooring specialists',
    'lawn-mowing' => 'Lawn mowing services',
    'rubbish-removal' => 'Rubbish removal services',
    'other' => 'Other local services',
  ];
  if (isset($special[$slug])) return $special[$slug];
  $lb = apd_trade_label($slug);
  if ($lb === '') return '';
  return preg_match('/s$/i', $lb) ? $lb : $lb . 's';
}
function apd_crumbs($parts) {
  $out = [];
  $n = count($parts);
  foreach ($parts as $i => $p) {
    if ($i === $n - 1) $out[] = '<span>' . apd_e($p[1]) . '</span>';
    else $out[] = '<a href="' . esc_attr($p[0]) . '">' . apd_e($p[1]) . '</a>';
  }
  return '<nav class="crumbs" aria-label="Breadcrumb">' . implode(' <span aria-hidden="true">/</span> ', $out) . '</nav>';
}
function apd_claim_cta($l = null) {
  if ($l && (int) $l->user_id === 0) {
    return '<div class="card cta"><b>Is this your business?</b><p class="mut" style="margin:.3em 0 .6em">This listing was added from public OpenStreetMap data. Claim it free to add opening hours, a description, a licence number or a logo. We review claims before you can edit.</p><a class="btn" href="/claim/' . (int) $l->id . '/">Claim this listing</a></div>';
  }
  return '<div class="card cta"><b>Do you run a local business?</b><p class="mut" style="margin:.3em 0 .6em">List it free, or claim an existing listing if it is already here. Customers contact you directly — no lead fees.</p><a class="btn" href="/list/">List your business free</a> <a class="btn alt" href="/find/">Find a listing to claim</a></div>';
}
function apd_is_osm_descr($d) {
  $d = trim((string) $d);
  return $d !== '' && strncmp($d, 'Source: OpenStreetMap', 21) === 0;
}
function apd_osm_url($l) {
  if (!$l || empty($l->osm_id)) return '';
  $w = ['n' => 'node', 'w' => 'way', 'r' => 'relation'];
  $t = $w[$l->osm_type ?? ''] ?? 'node';
  return 'https://www.openstreetmap.org/' . $t . '/' . (int) $l->osm_id;
}
function apd_osm_note($l = null) {
  $u = $l ? apd_osm_url($l) : '';
  $h = '<p class="mut osm">Location data from OpenStreetMap, © OpenStreetMap contributors (ODbL).';
  if ($u) $h .= ' <a href="' . esc_url($u) . '" rel="noopener nofollow" target="_blank">View this place on OpenStreetMap</a>.';
  return $h . ' Details may be incomplete until the owner claims the listing.</p>';
}
function apd_has_geo($lat, $lng) {
  $lat = (float) $lat; $lng = (float) $lng;
  return ($lat !== 0.0 || $lng !== 0.0) && $lat >= -45 && $lat <= -8 && $lng >= 110 && $lng <= 155;
}
function apd_map($lat, $lng, $label = '') {
  if (!apd_has_geo($lat, $lng)) return '';
  $lat = (float) $lat; $lng = (float) $lng;
  $pad = 0.012;
  $src = 'https://www.openstreetmap.org/export/embed.html?bbox='
    . rawurlencode(($lng - $pad) . ',' . ($lat - $pad) . ',' . ($lng + $pad) . ',' . ($lat + $pad))
    . '&layer=mapnik&marker=' . rawurlencode($lat . ',' . $lng);
  $ext = 'https://www.openstreetmap.org/?mlat=' . rawurlencode((string) $lat) . '&mlon=' . rawurlencode((string) $lng) . '#map=16/' . rawurlencode((string) $lat) . '/' . rawurlencode((string) $lng);
  $cap = $label !== '' ? '<p class="mut" style="margin:.4em 0 0">Map of ' . apd_e($label) . ' · <a href="' . esc_url($ext) . '" target="_blank" rel="noopener">Open a larger map</a></p>' : '';
  return '<div class="mapwrap"><iframe title="Map" loading="lazy" src="' . esc_url($src) . '"></iframe></div>' . $cap;
}
function apd_guide_block($trade, $place = '') {
  $g = apd_guides_data()['guides'][$trade] ?? null;
  if (!$g || empty($g['how'])) return '';
  $lb = apd_trade_label($trade);
  $h = '<section class="card guide"><h2>How to choose a ' . apd_e(strtolower($lb)) . ($place !== '' ? ' in ' . apd_e($place) : '') . '</h2>';
  foreach (preg_split('/\n+/', trim($g['how'])) as $p) {
    $p = trim($p);
    if ($p !== '') $h .= '<p>' . apd_e($p) . '</p>';
  }
  $h .= '<p class="mut">This is general information, not a recommendation of any business on this page.</p></section>';
  return $h;
}
function apd_faq_block($trade, $place = '') {
  $data = apd_guides_data();
  $items = array_merge($data['guides'][$trade]['faq'] ?? [], $data['shared_faq'] ?? []);
  if (!$items) return '';
  $lb = apd_trade_label($trade) ?: 'this service';
  $h = '<section class="card faq"><h2>Questions about hiring a ' . apd_e(strtolower($lb)) . ($place !== '' ? ' in ' . apd_e($place) : '') . '</h2>';
  $ld = [];
  foreach ($items as $it) {
    $q = (string) ($it['q'] ?? ''); $a = (string) ($it['a'] ?? '');
    if ($q === '' || $a === '') continue;
    $h .= '<details><summary>' . apd_e($q) . '</summary><p>' . apd_e($a) . '</p></details>';
    $ld[] = ['@type' => 'Question', 'name' => $q, 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $a]];
  }
  if ($ld) $h .= '<script type="application/ld+json">' . wp_json_encode(['@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => $ld], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . '</script>';
  return $h . '</section>';
}
function apd_related_map() {
  return [
    'electrician' => ['plumber', 'air-conditioning', 'solar', 'antenna-tv'],
    'plumber' => ['electrician', 'gasfitter', 'builder', 'appliance-repair'],
    'gasfitter' => ['plumber', 'appliance-repair'],
    'carpenter' => ['builder', 'cabinet-maker', 'painter', 'handyman'],
    'builder' => ['carpenter', 'electrician', 'plumber', 'painter', 'roofer'],
    'handyman' => ['carpenter', 'painter', 'gardener'],
    'painter' => ['plasterer', 'carpenter', 'builder'],
    'plasterer' => ['painter', 'builder'],
    'tiler' => ['plumber', 'builder', 'flooring'],
    'bricklayer' => ['builder', 'concreter', 'landscaper'],
    'concreter' => ['builder', 'bricklayer', 'landscaper'],
    'roofer' => ['builder', 'electrician'],
    'glazier' => ['builder', 'carpenter'],
    'cabinet-maker' => ['carpenter', 'builder'],
    'flooring' => ['tiler', 'carpenter', 'carpet-cleaning'],
    'fencer' => ['landscaper', 'gardener', 'builder'],
    'landscaper' => ['gardener', 'fencer', 'concreter', 'tree-services'],
    'gardener' => ['landscaper', 'lawn-mowing', 'tree-services'],
    'lawn-mowing' => ['gardener', 'landscaper'],
    'tree-services' => ['gardener', 'landscaper'],
    'pool-services' => ['electrician', 'landscaper'],
    'air-conditioning' => ['electrician', 'solar'],
    'solar' => ['electrician', 'air-conditioning', 'roofer'],
    'locksmith' => ['carpenter', 'handyman'],
    'pest-control' => ['cleaner', 'builder'],
    'cleaner' => ['carpet-cleaning', 'window-cleaning', 'pest-control'],
    'carpet-cleaning' => ['cleaner', 'flooring'],
    'window-cleaning' => ['cleaner', 'glazier'],
    'removalist' => ['rubbish-removal'],
    'rubbish-removal' => ['removalist', 'gardener'],
    'mechanic' => ['auto-electrician', 'car-detailing'],
    'auto-electrician' => ['mechanic'],
    'car-detailing' => ['mechanic'],
    'appliance-repair' => ['electrician', 'plumber', 'gasfitter'],
    'antenna-tv' => ['electrician', 'computer-repair'],
    'computer-repair' => ['antenna-tv', 'web-designer'],
    'pet-grooming' => ['dog-walking'],
    'dog-walking' => ['pet-grooming'],
    'tutor' => [],
    'photographer' => ['graphic-designer', 'web-designer'],
    'bookkeeper' => [],
    'web-designer' => ['graphic-designer', 'computer-repair'],
    'graphic-designer' => ['web-designer', 'photographer'],
    'other' => [],
  ];
}
function apd_trade_where($alias, $trade) {
  global $wpdb;
  return $wpdb->prepare(" AND FIND_IN_SET(%s, $alias.trades)", $trade);
}
function apd_count_trade($trade, $state = null, $suburb_id = 0) {
  global $wpdb;
  $L = apd_tb('listings'); $S = apd_subt();
  $w = apd_live_sql('l') . apd_trade_where('l', $trade);
  if ($suburb_id) {
    return (int) $wpdb->get_var("SELECT COUNT(*) FROM $L l WHERE $w AND l.suburb_id=" . (int) $suburb_id);
  }
  if ($state) {
    return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $L l JOIN $S s ON s.id=l.suburb_id WHERE $w AND s.state=%s", strtoupper($state)));
  }
  return (int) $wpdb->get_var("SELECT COUNT(*) FROM $L l WHERE $w");
}
function apd_states_for_trade($trade) {
  global $wpdb;
  $L = apd_tb('listings'); $S = apd_subt();
  $w = apd_live_sql('l') . apd_trade_where('l', $trade);
  return $wpdb->get_results("SELECT s.state AS st, COUNT(*) c FROM $L l JOIN $S s ON s.id=l.suburb_id WHERE $w GROUP BY s.state ORDER BY c DESC, s.state ASC");
}
function apd_suburbs_for_trade($trade, $state, $lim = 200) {
  global $wpdb;
  $L = apd_tb('listings'); $S = apd_subt();
  $w = apd_live_sql('l') . apd_trade_where('l', $trade);
  return $wpdb->get_results($wpdb->prepare("SELECT s.*, COUNT(*) c FROM $L l JOIN $S s ON s.id=l.suburb_id WHERE $w AND s.state=%s GROUP BY s.id ORDER BY c DESC, s.name ASC LIMIT %d", strtoupper($state), $lim));
}
function apd_top_trade_areas($lim = 12) {
  global $wpdb;
  $L = apd_tb('listings'); $S = apd_subt();
  $w = apd_live_sql('l');
  return $wpdb->get_results($wpdb->prepare("SELECT s.*, SUBSTRING_INDEX(l.trades, ',', 1) AS trade, COUNT(*) c FROM $L l JOIN $S s ON s.id=l.suburb_id WHERE $w GROUP BY l.suburb_id, trade HAVING c>=%d ORDER BY c DESC, s.name ASC LIMIT %d", apd_min_list_index(), $lim));
}
function apd_listings_in($trade, $suburb_id, $lim = 80) {
  global $wpdb;
  $L = apd_tb('listings');
  $w = apd_live_sql('l') . apd_trade_where('l', $trade);
  return $wpdb->get_results($wpdb->prepare("SELECT l.* FROM $L l WHERE $w AND l.suburb_id=%d ORDER BY l.featured DESC, l.name ASC LIMIT %d", $suburb_id, $lim));
}
function apd_nearby_suburbs($s, $trade, $lim = 8) {
  global $wpdb;
  if (!$s || !apd_has_geo($s->lat, $s->lng)) return [];
  $L = apd_tb('listings'); $S = apd_subt();
  $w = apd_live_sql('l') . apd_trade_where('l', $trade);
  $sql = $wpdb->prepare("SELECT s2.*, cnt.c, (6371*2*ASIN(SQRT(POWER(SIN(RADIANS(s2.lat-%f)/2),2)+COS(RADIANS(%f))*COS(RADIANS(s2.lat))*POWER(SIN(RADIANS(s2.lng-%f)/2),2)))) AS km
    FROM $S s2
    JOIN (SELECT l.suburb_id, COUNT(*) c FROM $L l WHERE $w GROUP BY l.suburb_id) cnt ON cnt.suburb_id=s2.id
    WHERE s2.id<>%d
    HAVING km < 35
    ORDER BY km ASC LIMIT %d", $s->lat, $s->lat, $s->lng, $s->id, $lim);
  return $wpdb->get_results($sql);
}
function apd_related_links($trade, $suburb = null, $state = '') {
  $want = array_values(array_filter(apd_related_map()[$trade] ?? [], 'apd_trade_label'));
  $dynamic = [];
  if ($suburb) {
    global $wpdb;
    $L = apd_tb('listings');
    $w = apd_live_sql('l');
    $rows = $wpdb->get_results($wpdb->prepare("SELECT l.trades FROM $L l WHERE $w AND l.suburb_id=%d LIMIT 200", $suburb->id));
    $c = [];
    foreach ($rows as $r) {
      foreach (explode(',', $r->trades) as $t) {
        $t = trim($t);
        if ($t && $t !== $trade && apd_trade_label($t)) $c[$t] = ($c[$t] ?? 0) + 1;
      }
    }
    arsort($c);
    $dynamic = array_slice(array_keys($c), 0, 6);
  }
  $slugs = [];
  foreach (array_merge($dynamic, $want) as $t) {
    if ($t !== $trade && apd_trade_label($t) && !in_array($t, $slugs, true)) $slugs[] = $t;
  }
  $slugs = array_slice($slugs, 0, 8);
  if (!$slugs) return '';
  $h = '<section><h2>Related trades</h2><div class="grid">';
  foreach ($slugs as $t) {
    $url = $suburb ? apd_trade_area_url($t, $suburb) : ($state ? apd_trade_state_url($t, $state) : '/trade/' . $t . '/');
    $h .= '<a href="' . esc_attr($url) . '">' . apd_e(apd_trade_label($t)) . '</a>';
  }
  return $h . '</div></section>';
}
function apd_link_grid($items) {
  if (!$items) return '';
  $h = '<div class="grid">';
  foreach ($items as $it) $h .= '<a href="' . esc_attr($it[0]) . '">' . $it[1] . '</a>';
  return $h . '</div>';
}
function apd_count_label($n, $unit) {
  $n = (int) $n;
  return $n . ' ' . $unit . ($n === 1 ? '' : 's');
}
function apd_ld_localbusiness($l, $s) {
  $d = ['@context' => 'https://schema.org', '@type' => 'LocalBusiness', 'name' => $l->name, 'url' => home_url(apd_url($l))];
  $tr = array_values(array_filter(array_map('apd_trade_label', explode(',', $l->trades))));
  if ($tr) $d['description'] = implode(', ', $tr) . ($s ? ' in ' . $s->name . ' ' . $s->state : '');
  if (trim($l->phone)) $d['telephone'] = $l->phone;
  if (trim($l->website)) $d['sameAs'] = [$l->website];
  $u = apd_osm_url($l);
  if ($u) { $d['sameAs'] = array_values(array_unique(array_merge($d['sameAs'] ?? [], [$u]))); }
  if ($s || trim($l->address)) {
    $addr = ['@type' => 'PostalAddress', 'addressCountry' => 'AU'];
    if (trim($l->address)) $addr['streetAddress'] = $l->address;
    if ($s) { $addr['addressLocality'] = $s->name; $addr['addressRegion'] = $s->state; $addr['postalCode'] = $s->postcode; }
    $d['address'] = $addr;
  }
  $lat = $l->lat ?? null; $lng = $l->lng ?? null;
  if (!apd_has_geo($lat, $lng) && $s) { $lat = $s->lat; $lng = $s->lng; }
  if (apd_has_geo($lat, $lng)) $d['geo'] = ['@type' => 'GeoCoordinates', 'latitude' => (float) $lat, 'longitude' => (float) $lng];
  if (trim($l->hours ?? '')) $d['openingHours'] = $l->hours;
  if (trim($l->email)) $d['email'] = $l->email;
  return '<script type="application/ld+json">' . wp_json_encode($d, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . '</script>';
}
function apd_extra_css() {
  echo '.crumbs{font-size:.85rem;color:var(--mut);margin:0 0 12px}.crumbs a{color:var(--mut)}'
    . '.guide p,.faq p{margin:.6em 0}.faq details{border-top:1px solid var(--ln);padding:8px 0}.faq details:first-of-type{border-top:0}.faq summary{cursor:pointer;font-weight:600}'
    . '.mapwrap{border-radius:14px;overflow:hidden;border:1px solid var(--ln);margin:10px 0;height:220px;background:#e5e7eb}.mapwrap iframe{border:0;width:100%;height:220px}'
    . '.cta .btn{margin:4px 6px 0 0;display:inline-block;width:auto}.osm{margin:1em 0}.stat{font-weight:700;color:var(--c)}';
}

/* ---------- robots + sitemap ---------- */
function apd_robots_live($o) {
  $sm = home_url('/sitemap.xml');
  return "User-agent: *\n"
    . "Allow: /\n"
    . "Disallow: /login/\nDisallow: /signup/\nDisallow: /list/\nDisallow: /my-listings/\n"
    . "Disallow: /report/\nDisallow: /claim/\nDisallow: /find/\n"
    . "Disallow: /wp-admin/\nDisallow: /wp-login.php\n\n"
    . "Sitemap: $sm\n";
}
function apd_sitemap_path() {
  return rtrim(ABSPATH, '/') . '/sitemap.xml';
}
function apd_sitemap_urls() {
  global $wpdb;
  $urls = [];
  $add = function ($loc, $prio, $freq = 'weekly') use (&$urls) {
    $urls[] = [$loc, $prio, $freq];
  };
  $add(home_url('/'), '1.0', 'daily');
  foreach (['/trades/', '/about/', '/contact/', '/privacy/', '/terms/', '/rules/'] as $p) $add(home_url($p), '0.5', 'monthly');
  $L = apd_tb('listings'); $S = apd_subt();
  $live = apd_live_sql('l');
  foreach (apd_trades() as $slug => $lb) {
    $n = apd_count_trade($slug);
    if ($n >= apd_min_list_index()) $add(home_url('/trade/' . $slug . '/'), '0.7', 'daily');
  }
  $strows = $wpdb->get_results("SELECT s.state AS st, l.trades, COUNT(*) c FROM $L l JOIN $S s ON s.id=l.suburb_id WHERE $live GROUP BY s.state, l.trades");
  $stateC = []; $areaC = [];
  // more accurate with FIND_IN_SET per trade — do one query per trade (few dozen)
  foreach (apd_trades() as $slug => $lb) {
    $w = $live . apd_trade_where('l', $slug);
    $rows = $wpdb->get_results("SELECT s.state AS st, COUNT(*) c FROM $L l JOIN $S s ON s.id=l.suburb_id WHERE $w GROUP BY s.state");
    foreach ($rows as $r) {
      if ((int) $r->c >= apd_min_list_index()) $add(home_url(apd_trade_state_url($slug, $r->st)), '0.7', 'daily');
    }
    $rows = $wpdb->get_results("SELECT s.id, s.name, s.state, s.postcode, COUNT(*) c FROM $L l JOIN $S s ON s.id=l.suburb_id WHERE $w GROUP BY s.id HAVING c>=" . apd_min_list_index());
    foreach ($rows as $r) $add(home_url(apd_trade_area_url($slug, $r)), '0.8', 'daily');
  }
  $areas = $wpdb->get_results("SELECT s.* FROM $S s JOIN (SELECT suburb_id, COUNT(*) c FROM $L l WHERE $live GROUP BY suburb_id HAVING c>=" . apd_min_list_index() . ") x ON x.suburb_id=s.id");
  foreach ($areas as $s) $add(home_url(apd_area_url($s)), '0.5', 'weekly');
  $biz = $wpdb->get_results("SELECT l.* FROM $L l WHERE $live");
  foreach ($biz as $l) {
    if (apd_listing_indexable($l)) $add(home_url(apd_url($l)), '0.6', 'weekly');
  }
  // unique
  $seen = []; $out = [];
  foreach ($urls as $u) {
    if (isset($seen[$u[0]])) continue;
    $seen[$u[0]] = 1;
    $out[] = $u;
  }
  return $out;
}
function apd_write_sitemap() {
  $urls = apd_sitemap_urls();
  $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
    . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
  $now = gmdate('Y-m-d');
  foreach ($urls as $u) {
    $xml .= '  <url><loc>' . esc_url($u[0]) . '</loc><lastmod>' . $now . '</lastmod><changefreq>' . $u[2] . '</changefreq><priority>' . $u[1] . '</priority></url>' . "\n";
  }
  $xml .= '</urlset>';
  $ok = @file_put_contents(apd_sitemap_path(), $xml);
  return [$ok ? count($urls) : 0, $ok];
}
function apd_sitemap_page() {
  $path = apd_sitemap_path();
  if (!is_readable($path) || (time() - filemtime($path)) > 21600) apd_write_sitemap();
  if (!is_readable($path)) { status_header(500); exit; }
  header('Content-Type: application/xml; charset=UTF-8');
  header('Cache-Control: public, max-age=3600');
  readfile($path);
  exit;
}

/* ---------- routes ---------- */
add_action('template_redirect', function () {
  $p = rtrim((string) parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH), '/') . '/';
  $post = (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST');
  if ($p === '/sitemap.xml/' || $p === '/sitemap.xml') apd_sitemap_page();
  if ($p === '/about/') apd_about();
  if ($p === '/contact/') apd_contact();
  if (preg_match('~^/claim/(\d+)/$~', $p, $m)) apd_claim_page((int) $m[1], $post);
  if (preg_match('~^/trade/([a-z0-9-]+)/([a-z]{2,3})/([a-z0-9-]+)-(\d{4})/$~', $p, $m)) apd_trade_area_page($m[1], $m[2], $m[3], $m[4]);
  if (preg_match('~^/trade/([a-z0-9-]+)/([a-z]{2,3})/$~', $p, $m)) apd_trade_state_page($m[1], $m[2]);
}, 4);

/* ---------- pages ---------- */
function apd_home_rich() {
  global $wpdb;
  $n = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . apd_tb('listings') . ' l WHERE ' . apd_live_sql('l'));
  $h = apd_crumbs([['/', 'Home']]);
  $h .= '<h1>Find a local tradie, free</h1><p class="mut">A free Australian directory of tradies and sole traders. Contact them directly: no lead fees, no commission, no middleman. ' . ($n ? '<span class="stat">' . number_format($n) . '</span> businesses are listed so far, many first added from public OpenStreetMap data.' : 'Businesses can list themselves, and some listings were first added from public OpenStreetMap data.') . '</p>';
  $h .= apd_search_form();
  $h .= '<div class="card"><b>Tradie or sole trader?</b><p class="mut" style="margin:.3em 0 .6em">List your business free, or claim a listing that is already here. Customers call or email you directly.</p><a href="/list/">List your business free ›</a> · <a href="/about/">How this directory works ›</a></div>';
  $h .= '<h2>Popular trades</h2><div class="grid">';
  $shown = 0;
  foreach (apd_trades() as $k => $v) {
    $c = apd_count_trade($k);
    if ($c < 1) continue;
    $h .= '<a href="/trade/' . $k . '/">' . apd_e($v) . ' <span class="mut">(' . (int) $c . ')</span></a>';
    if (++$shown >= 12) break;
  }
  $h .= '</div><p><a href="/trades/">All trades ›</a></p>';
  $areas = apd_top_trade_areas(12);
  if ($areas) {
    $h .= '<h2>Places with several listings</h2><div class="grid">';
    foreach ($areas as $a) {
      $lb = apd_trade_label($a->trade);
      if (!$lb) continue;
      $h .= '<a href="' . esc_attr(apd_trade_area_url($a->trade, $a)) . '">' . apd_e($lb . 's in ' . $a->name) . ' <span class="mut">(' . (int) $a->c . ')</span></a>';
    }
    $h .= '</div>';
  }
  $h .= apd_claim_cta();
  $rows = $wpdb->get_results('SELECT l.* FROM ' . apd_tb('listings') . ' l WHERE ' . apd_live_sql('l') . " AND l.address<>'' AND (l.phone<>'' OR l.website<>'') ORDER BY l.id DESC LIMIT 6");
  if ($rows) { $h .= '<h2>Recently listed</h2>'; foreach ($rows as $r) $h .= apd_card($r); }
  apd_layout('Free directory of local tradies', $h, '/', 'Find local Australian tradies and sole traders and contact them directly. Free to list, no lead fees.', true);
}
function apd_find_rich() {
  $t = sanitize_key($_GET['t'] ?? '');
  if (!apd_trade_label($t)) $t = '';
  $w = sanitize_text_field(wp_unslash($_GET['w'] ?? ''));
  if ($w !== '') {
    $s = apd_find_sub($w);
    if ($s && $t) { wp_safe_redirect(apd_trade_area_url($t, $s), 302); exit; }
    if ($s && !$t) { wp_safe_redirect(apd_area_url($s), 302); exit; }
  } elseif ($t) {
    wp_safe_redirect('/trade/' . $t . '/', 302); exit;
  }
  $h = '<h1>Find a tradie</h1>' . apd_search_form($t, $w);
  if ($w !== '') $h .= '<p class="note">We couldn\'t find that suburb. Try picking one from the list, like "Richmond VIC 3121".</p>';
  $h .= apd_claim_cta();
  apd_layout('Find a tradie', $h, '/find/', 'Search for a local tradie by trade and suburb.', false);
}
function apd_trades_page_rich() {
  $h = apd_crumbs([['/', 'Home'], ['/trades/', 'All trades']]) . '<h1>All trades</h1><p class="mut">Browse every trade we list. Pages with only a few businesses are kept off search engines until they have more detail.</p><div class="grid">';
  foreach (apd_trades() as $k => $v) {
    $c = apd_count_trade($k);
    $h .= '<a href="/trade/' . $k . '/">' . apd_e($v) . ($c ? ' <span class="mut">(' . $c . ')</span>' : '') . '</a>';
  }
  apd_layout('All trades', $h . '</div>' . apd_claim_cta(), '/', 'Browse Australian trades and find a local business to contact directly.', true);
}
function apd_trade_page_rich($slug) {
  $lb = apd_trade_label($slug);
  if (!$lb) { $GLOBALS['apd_code'] = 404; apd_layout('Not found', '<h1>Trade not found</h1><p><a href="/trades/">See all trades</a></p>', '/trades/', '', false); }
  $n = apd_count_trade($slug);
  $index = $n >= apd_min_list_index();
  $pl = apd_trade_plural($slug);
  $h = apd_crumbs([['/', 'Home'], ['/trades/', 'All trades'], ['/trade/' . $slug . '/', $pl]]);
  $h .= '<h1>' . apd_e($pl) . ' in Australia</h1>';
  $h .= '<p class="mut"><span class="stat">' . number_format($n) . '</span> ' . ($n === 1 ? 'listing' : 'listings') . ' on aussieplatform. Contact businesses directly — we don\'t take a fee or book the job.</p>';
  $h .= apd_search_form($slug);
  $states = apd_states_for_trade($slug);
  if ($states) {
    $h .= '<h2>Browse by state</h2><div class="grid">';
    $names = apd_states();
    foreach ($states as $r) {
      $nm = $names[$r->st] ?? $r->st;
      $h .= '<a href="' . esc_attr(apd_trade_state_url($slug, $r->st)) . '">' . apd_e($nm) . ' <span class="mut">(' . (int) $r->c . ')</span></a>';
    }
    $h .= '</div>';
  }
  $h .= apd_guide_block($slug);
  $h .= apd_faq_block($slug);
  $h .= apd_related_links($slug);
  $h .= apd_claim_cta();
  if ($n) {
    global $wpdb;
    $L = apd_tb('listings');
    $rows = $wpdb->get_results($wpdb->prepare('SELECT l.* FROM ' . $L . ' l WHERE ' . apd_live_sql('l') . apd_trade_where('l', $slug) . " AND l.address<>'' AND (l.phone<>'' OR l.website<>'') ORDER BY l.featured DESC, l.id DESC LIMIT 8"));
    if ($rows) { $h .= '<h2>Some listings with contact details</h2>'; foreach ($rows as $r) $h .= apd_card($r); }
  } else {
    $h .= apd_empty(strtolower($lb));
  }
  apd_layout($pl . ' in Australia', $h, '/trades/', 'Find ' . strtolower($pl) . ' in Australia and contact them directly. Free, no lead fees. ' . $n . ' listings.', $index);
}
function apd_trade_state_page($slug, $st) {
  $lb = apd_trade_label($slug);
  $st = strtoupper($st);
  $names = apd_states();
  if (!$lb || !isset($names[$st])) { $GLOBALS['apd_code'] = 404; apd_layout('Not found', '<h1>Page not found</h1><p><a href="/trades/">See all trades</a></p>', '/trades/', '', false); }
  $n = apd_count_trade($slug, $st);
  $index = $n >= apd_min_list_index();
  $pl = apd_trade_plural($slug);
  $place = $names[$st];
  $h = apd_crumbs([['/', 'Home'], ['/trade/' . $slug . '/', $pl], [apd_trade_state_url($slug, $st), $place]]);
  $h .= '<h1>' . apd_e($pl) . ' in ' . apd_e($place) . '</h1>';
  $h .= '<p class="mut"><span class="stat">' . number_format($n) . '</span> ' . ($n === 1 ? 'listing' : 'listings') . ' based in ' . apd_e($place) . '. Contact them directly — we don\'t take a fee or arrange the work.</p>';
  $h .= apd_search_form($slug);
  $subs = apd_suburbs_for_trade($slug, $st);
  if ($subs) {
    $h .= '<h2>Suburbs and towns</h2><div class="grid">';
    foreach ($subs as $s) {
      $h .= '<a href="' . esc_attr(apd_trade_area_url($slug, $s)) . '">' . apd_e($s->name) . ' ' . apd_e($s->postcode) . ' <span class="mut">(' . (int) $s->c . ')</span></a>';
    }
    $h .= '</div>';
  } else {
    $h .= apd_empty(strtolower($lb) . ' in ' . $place);
  }
  $h .= apd_guide_block($slug, $place);
  $h .= apd_faq_block($slug, $place);
  $h .= apd_related_links($slug, null, $st);
  $h .= apd_claim_cta();
  apd_layout($pl . ' in ' . $place, $h, '/trade/' . $slug . '/', 'Find ' . strtolower($pl) . ' in ' . $place . ' and contact them directly. ' . $n . ' listings. Free, no lead fees.', $index);
}
function apd_resolve_suburb($st, $slug, $pc) {
  global $wpdb;
  $S = apd_subt();
  $cands = $wpdb->get_results($wpdb->prepare("SELECT * FROM $S WHERE postcode=%s AND state=%s", $pc, strtoupper($st)));
  foreach ($cands as $c) if (apd_slug($c->name) === $slug) return $c;
  return null;
}
function apd_trade_area_page($slug, $st, $subslug, $pc) {
  $lb = apd_trade_label($slug);
  $s = $lb ? apd_resolve_suburb($st, $subslug, $pc) : null;
  if (!$lb || !$s) { $GLOBALS['apd_code'] = 404; apd_layout('Not found', '<h1>Page not found</h1><p><a href="/trades/">See all trades</a></p>', '/trades/', '', false); }
  $n = apd_count_trade($slug, null, $s->id);
  $index = $n >= apd_min_list_index();
  $pl = apd_trade_plural($slug);
  $place = $s->name . ' ' . $s->state . ' ' . $s->postcode;
  $h = apd_crumbs([
    ['/', 'Home'],
    ['/trade/' . $slug . '/', $pl],
    [apd_trade_state_url($slug, $s->state), apd_states()[$s->state] ?? $s->state],
    [apd_trade_area_url($slug, $s), $s->name],
  ]);
  $h .= '<h1>' . apd_e($pl) . ' in ' . apd_e($s->name) . ' ' . apd_e($s->state) . '</h1>';
  $h .= '<p class="mut"><span class="stat">' . number_format($n) . '</span> ' . ($n === 1 ? 'listing' : 'listings') . ' based in ' . apd_e($place) . '. Call or email them directly — aussieplatform does not take a booking or a fee.</p>';
  $h .= apd_search_form($slug, apd_sub_label($s));
  $h .= apd_map($s->lat, $s->lng, $place);
  $rows = apd_listings_in($slug, $s->id, 80);
  if ($rows) { $h .= '<h2>Businesses in ' . apd_e($s->name) . '</h2>'; foreach ($rows as $r) $h .= apd_card($r); }
  else $h .= apd_empty(strtolower($lb) . ' in ' . $s->name);
  $near = apd_nearby_suburbs($s, $slug, 8);
  if ($near) {
    $h .= '<h2>Nearby suburbs</h2><div class="grid">';
    foreach ($near as $nsub) {
      $km = isset($nsub->km) ? ' · ' . number_format((float) $nsub->km, 0) . ' km' : '';
      $h .= '<a href="' . esc_attr(apd_trade_area_url($slug, $nsub)) . '">' . apd_e($nsub->name) . ' ' . apd_e($nsub->state) . ' <span class="mut">(' . (int) $nsub->c . $km . ')</span></a>';
    }
    $h .= '</div>';
  }
  $h .= apd_related_links($slug, $s);
  $h .= apd_guide_block($slug, $s->name);
  $h .= apd_faq_block($slug, $s->name);
  $h .= apd_claim_cta();
  $h .= '<p><a href="' . esc_attr(apd_area_url($s)) . '">All trades in ' . apd_e($s->name) . ' ›</a></p>';
  apd_layout($pl . ' in ' . $s->name . ' ' . $s->state, $h, apd_trade_state_url($slug, $s->state), 'Find ' . strtolower($pl) . ' in ' . $place . ' and contact them directly. ' . $n . ' listings. Free, no lead fees.', $index);
}
function apd_area_page_rich($st, $slug, $pc) {
  $s = apd_resolve_suburb($st, $slug, $pc);
  if (!$s) { $GLOBALS['apd_code'] = 404; apd_layout('Not found', '<h1>Suburb not found</h1>', '/find/', '', false); }
  global $wpdb;
  $L = apd_tb('listings');
  $based = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . $L . ' l WHERE ' . apd_live_sql('l') . ' AND l.suburb_id=%d', $s->id));
  $index = $based >= apd_min_list_index();
  $place = apd_sub_label($s);
  $h = apd_crumbs([['/', 'Home'], [apd_area_url($s), $s->name . ' ' . $s->state]]);
  $h .= '<h1>Tradies in ' . apd_e($place) . '</h1>';
  $h .= '<p class="mut"><span class="stat">' . number_format($based) . '</span> ' . ($based === 1 ? 'business is' : 'businesses are') . ' listed as based in this suburb. Contact them directly — we don\'t take a fee.</p>';
  $h .= apd_search_form('', $place);
  $h .= apd_map($s->lat, $s->lng, $place);
  $trades = $wpdb->get_results($wpdb->prepare('SELECT l.trades FROM ' . $L . ' l WHERE ' . apd_live_sql('l') . ' AND l.suburb_id=%d', $s->id));
  $c = [];
  foreach ($trades as $r) {
    foreach (explode(',', $r->trades) as $t) {
      $t = trim($t);
      if ($t && apd_trade_label($t)) $c[$t] = ($c[$t] ?? 0) + 1;
    }
  }
  arsort($c);
  if ($c) {
    $h .= '<h2>Trades in ' . apd_e($s->name) . '</h2><div class="grid">';
    foreach ($c as $t => $n) $h .= '<a href="' . esc_attr(apd_trade_area_url($t, $s)) . '">' . apd_e(apd_trade_label($t)) . ' <span class="mut">(' . $n . ')</span></a>';
    $h .= '</div>';
  }
  $rows = $wpdb->get_results($wpdb->prepare('SELECT l.* FROM ' . $L . ' l WHERE ' . apd_live_sql('l') . ' AND l.suburb_id=%d ORDER BY l.featured DESC, l.name ASC LIMIT 80', $s->id));
  if ($rows) { $h .= '<h2>Businesses based here</h2>'; foreach ($rows as $r) $h .= apd_card($r); }
  else $h .= apd_empty($s->name);
  $h .= apd_claim_cta();
  apd_layout('Local tradies in ' . $s->name . ' ' . $s->state, $h, '/find/', 'Find local tradies based in ' . $place . ' and contact them directly. Free, no lead fees.', $index);
}
function apd_biz_rich($id) {
  $l = apd_get($id);
  $mine = $l && $l->user_id == apd_me();
  if (!$l || (!$mine && !current_user_can('manage_options') && ($l->status !== 'live' || ($l->is_test && !apd_see_test())))) {
    $GLOBALS['apd_code'] = 404;
    apd_layout('Not found', '<h1>Listing not found</h1><p>It may be waiting for review or has been removed.</p>' . apd_empty(), '/find/', '', false);
  }
  $s = apd_sub($l->suburb_id);
  $tr = array_values(array_filter(explode(',', $l->trades)));
  $mainTrade = $tr[0] ?? '';
  $index = ($l->status === 'live' && !$l->is_test && apd_listing_indexable($l));
  $h = '';
  $crumbs = [['/', 'Home']];
  if ($mainTrade && apd_trade_label($mainTrade)) {
    $crumbs[] = ['/trade/' . $mainTrade . '/', apd_trade_plural($mainTrade)];
    if ($s) {
      $crumbs[] = [apd_trade_state_url($mainTrade, $s->state), apd_states()[$s->state] ?? $s->state];
      $crumbs[] = [apd_trade_area_url($mainTrade, $s), $s->name];
    }
  } elseif ($s) {
    $crumbs[] = [apd_area_url($s), $s->name];
  }
  $crumbs[] = [apd_url($l), $l->name];
  $h .= apd_crumbs($crumbs);
  if ($l->status !== 'live') $h .= '<p class="note">This listing is <b>' . apd_e($l->status) . '</b> and only you can see it. We review new listings and edits before they go public.</p>';
  $h .= '<div class="row">' . apd_logo($l, 72) . '<div><h1 style="margin:0">' . apd_e($l->name) . '</h1>' . ($l->is_test ? '<span class="pill test">TEST</span>' : '') . '<div class="mut">' . apd_e(apd_sub_label($s)) . ' · serves ' . apd_e(apd_radius_txt($l->radius)) . '</div></div></div>';
  $h .= '<p>';
  foreach ($tr as $t) if (apd_trade_label($t)) $h .= '<a class="pill" href="' . esc_attr($s ? apd_trade_area_url($t, $s) : '/trade/' . $t . '/') . '">' . apd_e(apd_trade_label($t)) . '</a>';
  $h .= '</p>';
  $main = ''; $alt = [];
  if ($l->phone) $main = '<a class="btn big" href="tel:' . esc_attr(apd_tel($l->phone)) . '">📞 Call ' . apd_e($l->phone) . '</a>';
  if ($l->website) {
    $b = '<a class="btn alt" href="' . esc_url($l->website) . '" target="_blank" rel="nofollow noopener ugc">🌐 Website</a>';
    if ($main) $alt[] = $b; else $main = str_replace('btn alt', 'btn big', $b);
  }
  if ($l->email) {
    $b = '<a class="btn alt" href="mailto:' . esc_attr($l->email) . '">✉ Email</a>';
    if ($main) $alt[] = $b; else $main = str_replace('btn alt', 'btn big', $b);
  }
  $lat = $l->lat ?? null; $lng = $l->lng ?? null;
  if (!apd_has_geo($lat, $lng) && $s) { $lat = $s->lat; $lng = $s->lng; }
  if (apd_has_geo($lat, $lng)) $dest = $lat . ',' . $lng;
  else $dest = $l->address ?: apd_sub_label($s) . ' Australia';
  $alt[] = '<a class="btn alt" href="https://www.google.com/maps/dir/?api=1&destination=' . rawurlencode($dest) . '" target="_blank" rel="noopener">➜ Directions</a>';
  $h .= $main . '<div class="acts">' . implode('', $alt) . '</div>';
  if (trim((string) $l->descr) && !apd_is_osm_descr($l->descr)) $h .= '<div class="card">' . wpautop(apd_e($l->descr)) . '</div>';
  $h .= '<dl class="kv card">';
  if (trim($l->address)) $h .= '<dt>Address</dt><dd>' . apd_e($l->address) . '</dd>';
  $h .= '<dt>Based in</dt><dd>' . ($s ? '<a href="' . esc_attr(apd_area_url($s)) . '">' . apd_e(apd_sub_label($s)) . '</a>' : '') . '</dd>';
  $h .= '<dt>Service area</dt><dd>' . apd_e(ucfirst(apd_radius_txt($l->radius))) . '</dd>';
  if (trim($l->phone)) $h .= '<dt>Phone</dt><dd><a href="tel:' . esc_attr(apd_tel($l->phone)) . '">' . apd_e($l->phone) . '</a></dd>';
  if (trim($l->website)) $h .= '<dt>Website</dt><dd><a href="' . esc_url($l->website) . '" target="_blank" rel="nofollow noopener ugc">' . apd_e($l->website) . '</a></dd>';
  if (trim($l->email)) $h .= '<dt>Email</dt><dd><a href="mailto:' . esc_attr($l->email) . '">' . apd_e($l->email) . '</a></dd>';
  if (trim($l->hours ?? '')) $h .= '<dt>Opening hours</dt><dd>' . apd_e($l->hours) . '</dd>';
  if ($l->abn) $h .= '<dt>ABN</dt><dd>' . apd_e($l->abn) . ' <span class="mut">(as entered by the business – not verified by us. <a href="https://abr.business.gov.au/ABN/View?abn=' . rawurlencode(preg_replace('/\D/', '', $l->abn)) . '" target="_blank" rel="noopener">Check on ABN Lookup</a>)</span></dd>';
  if ($l->licence) $h .= '<dt>Licence number</dt><dd>' . apd_e($l->licence) . ' <span class="mut">(as entered by the business – not verified by us. Check it with your state licensing body before hiring.)</span></dd>';
  $h .= '</dl>';
  $h .= apd_map($lat, $lng, $l->name);
  $h .= apd_osm_note($l);
  $h .= '<p class="mut">Always check licences, insurance and quotes yourself before hiring. ' . apd_e(APD_BRAND) . ' doesn\'t check businesses or take part in any job.</p>';
  $h .= apd_ld_localbusiness($l, $s);
  if ((int) $l->user_id === 0) $h .= apd_claim_cta($l);
  if ($mine) $h .= '<p><a class="btn alt" href="/list/?id=' . (int) $l->id . '">Edit this listing</a></p>';
  if ($s && $mainTrade) {
    $near = apd_listings_in($mainTrade, $s->id, 8);
    $near = array_values(array_filter($near, function ($x) use ($l) { return (int) $x->id !== (int) $l->id; }));
    if ($near) {
      $h .= '<h2>Other ' . apd_e(strtolower(apd_trade_plural($mainTrade))) . ' in ' . apd_e($s->name) . '</h2>';
      foreach (array_slice($near, 0, 6) as $r) $h .= apd_card($r);
      $h .= '<p><a href="' . esc_attr(apd_trade_area_url($mainTrade, $s)) . '">All ' . apd_e(strtolower(apd_trade_plural($mainTrade))) . ' in ' . apd_e($s->name) . ' ›</a></p>';
    } else {
      $h .= '<p><a href="' . esc_attr(apd_area_url($s)) . '">More tradies near ' . apd_e($s->name) . ' ›</a></p>';
    }
  }
  $h .= '<details class="card"><summary style="cursor:pointer;color:#b91c1c">Report this listing</summary><form method="post" action="/report/">' . apd_nonce() . '<input type="hidden" name="id" value="' . (int) $l->id . '"><label for="rr">Reason</label><select id="rr" name="reason"><option>Wrong or out-of-date details</option><option>Not a real business / spam</option><option>Scam or fraud</option><option>Offensive or not family-safe</option><option>Fake licence or ABN</option><option>This is my business and I didn\'t list it</option><option>Other</option></select><label for="rd">Details (optional)</label><textarea id="rd" name="details" maxlength="1000" style="min-height:70px"></textarea><p><button type="submit">Send report</button></p></form></details>';
  $title = $l->name . ' – ' . implode(', ', array_map('apd_trade_label', array_slice($tr, 0, 2))) . ($s ? ' in ' . $s->name : '');
  $desc = $l->name . ($s ? ' in ' . $s->name . ' ' . $s->state : '');
  $labs = array_filter(array_map('apd_trade_label', $tr));
  if ($labs) $desc .= ' is listed as a ' . strtolower(implode(', ', $labs));
  $desc .= '. Contact them directly. Details from public data or the owner — not verified by us.';
  apd_layout($title, $h, $s && $mainTrade ? apd_trade_area_url($mainTrade, $s) : '/find/', wp_trim_words($desc, 32, '…'), $index);
}
function apd_about() {
  $h = apd_crumbs([['/', 'Home'], ['/about/', 'About']]) . '<h1>About ' . apd_e(APD_BRAND) . '</h1>';
  $h .= '<p>' . apd_e(APD_BRAND) . ' is a free directory of Australian tradies and sole traders. The aim is simple: help you find a local business and contact them directly, with no lead fees, no commission and no middleman.</p>';
  $h .= '<h2>Where the listings come from</h2><p>Many listings were first added from public OpenStreetMap data (© OpenStreetMap contributors, licensed under the Open Database Licence). We only keep fields that were in that data or later added by the business — typically a name, trade, suburb, and sometimes an address, phone number, website or email. We do not invent reviews, ratings or business facts.</p>';
  $h .= '<h2>What we don\'t do</h2><p>We don\'t check, endorse or guarantee any business. We don\'t arrange, supervise or take part in any job. Licence numbers and insurance, if shown, are unverified. Always check those yourself before you hire anyone.</p>';
  $h .= '<h2>Claim or list a business</h2><p>If you run a business listed here, you can <a href="/find/">find it</a> and claim it. If it is not here yet, you can <a href="/list/">list it free</a>. We review claims and new listings before an owner can edit a public page.</p>';
  $h .= '<p><a href="/contact/">Contact us</a> · <a href="/rules/">Community rules</a> · <a href="/privacy/">Privacy</a> · <a href="/terms/">Terms</a></p>';
  apd_layout('About ' . APD_BRAND, $h, '/', 'How the ' . APD_BRAND . ' free Australian tradie directory works, where listings come from, and how to claim or list a business.', true);
}
function apd_contact() {
  $h = apd_crumbs([['/', 'Home'], ['/contact/', 'Contact']]) . '<h1>Contact ' . apd_e(APD_BRAND) . '</h1>';
  $h .= '<p>For a problem or a suggestion about the website, use the feedback button on any page. For listing claims, open the listing and choose “Claim this listing”.</p>';
  $h .= '<p>You can also email <a href="mailto:support@aussieplatform.app">support@aussieplatform.app</a>. We read messages as soon as we can, but we cannot book a tradie or settle a dispute about a job.</p>';
  $h .= '<p>If a listing is wrong, offensive or not a real business, use “Report this listing” on that page.</p>';
  $h .= '<p><a href="/about/">About this directory</a> · <a href="/privacy/">Privacy</a> · <a href="/terms/">Terms</a></p>';
  apd_layout('Contact ' . APD_BRAND, $h, '/', 'Contact ' . APD_BRAND . ' about the directory, a listing, or a claim.', true);
}
function apd_claim_page($id, $post) {
  $l = apd_get($id);
  if (!$l || $l->status !== 'live') { $GLOBALS['apd_code'] = 404; apd_layout('Not found', '<h1>Listing not found</h1>', '/find/', '', false); }
  apd_need_login();
  $s = apd_sub($l->suburb_id);
  if ((int) $l->user_id === apd_me()) { apd_flash('This listing is already on your account.'); apd_go(apd_url($l)); }
  if ((int) $l->user_id > 0) {
    apd_layout('Already claimed', apd_crumbs([['/', 'Home'], [apd_url($l), $l->name], ['#', 'Claim']]) . '<h1>This listing already has an owner account</h1><p>If you believe that is wrong, open the listing and use “Report this listing”.</p><p><a href="' . esc_attr(apd_url($l)) . '">Back to the listing</a></p>', apd_url($l), '', false);
  }
  if ($post) {
    apd_check();
    global $wpdb;
    $note = sanitize_textarea_field(wp_unslash($_POST['details'] ?? ''));
    $wpdb->insert(apd_tb('reports'), [
      'listing_id' => (int) $l->id,
      'reporter_id' => apd_me(),
      'reason' => 'Owner claim',
      'details' => $note,
      'ip' => substr(hash('sha256', (string) ($_SERVER['REMOTE_ADDR'] ?? '') . wp_salt()), 0, 16),
      'status' => 'open',
      'created' => apd_now(),
    ]);
    apd_flash('Thanks, we have received your claim. We will review it before you can edit this listing.');
    apd_go(apd_url($l));
  }
  $h = apd_crumbs([['/', 'Home'], [apd_url($l), $l->name], ['#', 'Claim']]);
  $h .= '<h1>Claim ' . apd_e($l->name) . '</h1>';
  $h .= '<p>This listing was added from public data' . ($s ? ' and is shown as based in ' . apd_e(apd_sub_label($s)) : '') . '. If you run this business, you can ask to claim it. We review claims before you can edit the page. There is no fee.</p>';
  $h .= '<form method="post" class="card">' . apd_nonce() . '<label for="details">Anything we should know? (optional)</label><textarea id="details" name="details" maxlength="1000" placeholder="For example, how we can recognise this is your business"></textarea><p><button class="btn big" type="submit">Submit claim</button></p></form>';
  $h .= '<p class="mut">Misuse of the claim form may lead to the request being refused.</p>';
  apd_layout('Claim ' . $l->name, $h, apd_url($l), '', false);
}
