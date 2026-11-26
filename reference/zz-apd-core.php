<?php
/* Plugin Name: aussieplatform directory - free tradie & sole-trader hub (listings, trade/suburb pages, owner edits, reports). Undo: /root/bk/aussieplatform-rich-20261117/UNDO.sh */
if (!defined('ABSPATH')) exit;
if (is_readable('/usr/share/submit-livewarn/livewarn.php')) require_once '/usr/share/submit-livewarn/livewarn.php';
define('APD_BRAND','aussieplatform'); define('APD_COLOR','#0f766e'); define('APD_DBV','1');
define('APD_DEV_NOINDEX', false);  // 2026-11-17: site live; thin pages still noindex in apd_layout
define('APD_FEATURED_ON', false);  // "Featured" upgrade switched OFF - no prices set
function apd_tb($n){ global $wpdb; return $wpdb->prefix.'apd_'.$n; }
function apd_subt(){ global $wpdb; return $wpdb->prefix.'ap_suburbs'; } // existing suburb table (Matthew Proctor, CC BY 4.0)
add_action('init', function(){
  if (get_option('apd_dbv') === APD_DBV) return;
  global $wpdb; require_once ABSPATH.'wp-admin/includes/upgrade.php'; $c=$wpdb->get_charset_collate();
  dbDelta("CREATE TABLE ".apd_tb('listings')." (id bigint unsigned NOT NULL AUTO_INCREMENT, user_id bigint unsigned NOT NULL, name varchar(120) NOT NULL, trades varchar(400) NOT NULL DEFAULT '', suburb_id int unsigned NOT NULL DEFAULT 0, radius smallint NOT NULL DEFAULT 25, address varchar(200) NOT NULL DEFAULT '', phone varchar(40) NOT NULL DEFAULT '', website varchar(200) NOT NULL DEFAULT '', email varchar(190) NOT NULL DEFAULT '', abn varchar(20) NOT NULL DEFAULT '', licence varchar(80) NOT NULL DEFAULT '', descr text, logo_id bigint unsigned NOT NULL DEFAULT 0, status varchar(12) NOT NULL DEFAULT 'pending', featured tinyint NOT NULL DEFAULT 0, is_test tinyint NOT NULL DEFAULT 0, created datetime NOT NULL, updated datetime NOT NULL, PRIMARY KEY  (id), KEY user_id (user_id), KEY status (status), KEY suburb_id (suburb_id)) $c;");
  dbDelta("CREATE TABLE ".apd_tb('reports')." (id bigint unsigned NOT NULL AUTO_INCREMENT, listing_id bigint unsigned NOT NULL, reporter_id bigint unsigned NOT NULL DEFAULT 0, reason varchar(80) NOT NULL, details text, ip varchar(64) NOT NULL DEFAULT '', status varchar(10) NOT NULL DEFAULT 'open', created datetime NOT NULL, PRIMARY KEY  (id)) $c;");
  update_option('apd_dbv', APD_DBV);
}, 1);
add_filter('pre_option_blog_public', function(){ return APD_DEV_NOINDEX ? '0' : false; });
add_filter('robots_txt', function($o){ if(APD_DEV_NOINDEX) return "User-agent: *\nDisallow: /\n"; return function_exists('apd_robots_live') ? apd_robots_live($o) : $o; }, 99);
add_action('send_headers', function(){ if (APD_DEV_NOINDEX) header('X-Robots-Tag: noindex, nofollow', true); });
add_filter('show_admin_bar', function($s){ return current_user_can('manage_options') ? $s : false; });
add_action('admin_init', function(){ if (!wp_doing_ajax() && !current_user_can('edit_posts')) { wp_safe_redirect(home_url('/my-listings/')); exit; } });
add_filter('login_redirect', function($to,$req,$u){ return (!is_wp_error($u) && $u && !user_can($u,'edit_posts')) ? home_url('/my-listings/') : $to; }, 10, 3);

/* ---------- trades ---------- */
function apd_trades(){ return [
 'electrician'=>'Electrician','plumber'=>'Plumber','gasfitter'=>'Gasfitter','carpenter'=>'Carpenter','builder'=>'Builder','handyman'=>'Handyman',
 'painter'=>'Painter','plasterer'=>'Plasterer','tiler'=>'Tiler','bricklayer'=>'Bricklayer','concreter'=>'Concreter','roofer'=>'Roofer','glazier'=>'Glazier',
 'cabinet-maker'=>'Cabinet maker','flooring'=>'Flooring','fencer'=>'Fencer','landscaper'=>'Landscaper','gardener'=>'Gardener','lawn-mowing'=>'Lawn mowing',
 'tree-services'=>'Tree services / arborist','pool-services'=>'Pool services','air-conditioning'=>'Air conditioning & heating','solar'=>'Solar installer',
 'locksmith'=>'Locksmith','pest-control'=>'Pest control','cleaner'=>'Cleaner','carpet-cleaning'=>'Carpet cleaning','window-cleaning'=>'Window cleaning',
 'removalist'=>'Removalist','rubbish-removal'=>'Rubbish removal','mechanic'=>'Mechanic','auto-electrician'=>'Auto electrician','car-detailing'=>'Car detailing',
 'appliance-repair'=>'Appliance repair','antenna-tv'=>'Antenna & TV install','computer-repair'=>'Computer & phone repair','pet-grooming'=>'Pet grooming','dog-walking'=>'Dog walking',
 'tutor'=>'Tutor','photographer'=>'Photographer','bookkeeper'=>'Bookkeeper','web-designer'=>'Web designer','graphic-designer'=>'Graphic designer','other'=>'Other services'];
}
function apd_trade_label($s){ return apd_trades()[$s] ?? ''; }
function apd_states(){ return ['ACT'=>'Australian Capital Territory','NSW'=>'New South Wales','NT'=>'Northern Territory','QLD'=>'Queensland','SA'=>'South Australia','TAS'=>'Tasmania','VIC'=>'Victoria','WA'=>'Western Australia']; }

/* ---------- helpers ---------- */
function apd_e($s){ return esc_html($s); }
function apd_me(){ return get_current_user_id(); }
function apd_now(){ return current_time('mysql'); }
function apd_go($u){ wp_safe_redirect($u); exit; }
function apd_is_test($u=0){ $u=$u?:apd_me(); return $u && get_user_meta($u,'apd_test',true)==='1'; }
function apd_see_test(){ return current_user_can('manage_options') || apd_is_test(); }
function apd_live_sql($a='l'){ return "$a.status='live'".(apd_see_test()?'':" AND $a.is_test=0"); }
function apd_flash($m=null){ $k='apd_fl_'.(apd_me()?:md5($_SERVER['REMOTE_ADDR']??'')); if($m!==null){ set_transient($k,$m,120); return ''; } $v=get_transient($k); if($v){ delete_transient($k); return '<div class="flash">'.apd_e($v).'</div>'; } return ''; }
function apd_nonce(){ $t=time(); return wp_nonce_field('apd_act','_apdn',true,false).'<input type="hidden" name="_apdt" value="'.$t.'.'.substr(hash_hmac('sha256',(string)$t,wp_salt()),0,12).'"><div style="position:absolute;left:-9999px" aria-hidden="true"><label>Leave empty<input type="text" name="apd_hp" tabindex="-1" autocomplete="off"></label></div>'; }
function apd_check(){ $bad=empty($_POST['_apdn'])||!wp_verify_nonce($_POST['_apdn'],'apd_act'); $p=explode('.',(string)($_POST['_apdt']??''));
  if(!$bad){ $bad = !empty($_POST['apd_hp']) || count($p)!==2 || !hash_equals(substr(hash_hmac('sha256',$p[0],wp_salt()),0,12),$p[1]) || time()-(int)$p[0]<3 || time()-(int)$p[0]>86400; }
  if($bad){ apd_flash('Your form expired or was sent too quickly. Please try again.'); apd_go($_SERVER['REQUEST_URI']); } }
function apd_need_login(){ if(apd_me()) return; apd_flash('Please sign in or create a free account first.'); apd_go('/login/?to='.rawurlencode($_SERVER['REQUEST_URI'])); }
function apd_bad_words(){ return ['fuck','shit','cunt','bitch','nigger','faggot','porn','escort','sexy','nude','nudes','onlyfans','viagra','cocaine','casino','adult massage','happy ending']; }
function apd_family_ok($s){ if((function_exists('aw_mli_unsafe')&&aw_mli_unsafe($s))) return false; $l=' '.strtolower(preg_replace('/[^a-z0-9 ]+/i',' ',$s)).' '; foreach(apd_bad_words() as $w){ if(strpos($l,' '.$w)!==false) return false; } return true; }
function apd_bad_re(){
  $parts=[];
  foreach(apd_bad_words() as $w){ $parts[]=str_replace(' ','\s+',preg_quote($w,'/')); }
  return '/(?:^|[^\p{L}\p{N}])(?:'.implode('|',$parts).')(?![\p{L}\p{N}])/iu';
}
function apd_listrules_note(){
  if (!function_exists('aw_lw_note')) return '';
  return aw_lw_note('apd', '<strong>What can\'t be listed.</strong> Real, family-friendly trades only. Adult services, scams and abusive language are not allowed. Please read the <a href="/rules/" target="_blank" rel="noopener">Community rules</a>.', '.apd-form');
}
function apd_livewarn_script(){
  if (!function_exists('aw_lw_script') || !function_exists('aw_lw_re_js')) return '';
  return aw_lw_script(['prefix'=>'apd','form'=>'.apd-form','fields'=>['name','descr','address','website','licence'],'rules'=>[
    array_merge(aw_lw_re_js(apd_bad_re()), ['kind'=>'bad','msg'=>'Please keep your listing family-friendly. Adult services, scams and abusive language are not allowed. Please read the Community rules.']),
  ]]);
}
function apd_sub($id){ global $wpdb; static $c=[]; if(!$id) return null; if(!isset($c[$id])) $c[$id]=$wpdb->get_row($wpdb->prepare("SELECT * FROM ".apd_subt()." WHERE id=%d",$id)); return $c[$id]; }
function apd_sub_label($s){ return $s ? $s->name.' '.$s->state.' '.$s->postcode : ''; }
function apd_find_sub($txt,$fz=true){ global $wpdb; $txt=trim(preg_replace('/\s+/',' ',str_replace(',',' ',(string)$txt))); if($txt==='') return null; $T=apd_subt();
  if(preg_match('/(\d{4})\s*$/',$txt,$m)){ $name=trim(preg_replace('/\b(VIC|NSW|QLD|SA|WA|TAS|NT|ACT)\b|\d{4}\s*$/i','',$txt));
    if($name!==''){ $r=$wpdb->get_row($wpdb->prepare("SELECT * FROM $T WHERE postcode=%s AND name=%s LIMIT 1",$m[1],$name)); if($r) return $r; }
    return $wpdb->get_row($wpdb->prepare("SELECT * FROM $T WHERE postcode=%s ORDER BY id LIMIT 1",$m[1])); }
  $st=''; if(preg_match('/\b(VIC|NSW|QLD|SA|WA|TAS|NT|ACT)\b/i',$txt,$m)) $st=strtoupper($m[1]);
  $name=trim(preg_replace('/\b(VIC|NSW|QLD|SA|WA|TAS|NT|ACT)\b/i','',$txt));
  $r=$st ? $wpdb->get_row($wpdb->prepare("SELECT * FROM $T WHERE name=%s AND state=%s LIMIT 1",$name,$st)) : $wpdb->get_row($wpdb->prepare("SELECT * FROM $T WHERE name=%s ORDER BY id LIMIT 1",$name));
  if(!$r&&$fz&&function_exists('catx_pac_best')){ $b=catx_pac_best($txt,'au'); if($b){ $r=apd_find_sub($b,false); if($r) $GLOBALS['apd_pac_note']=$b; } } /* t1226 fuzzy */
  return $r; }
function apd_slug($s){ return sanitize_title($s); }
function apd_url($l){ return '/biz/'.(int)$l->id.'-'.apd_slug($l->name).'/'; }
function apd_area_url($s){ return '/area/'.strtolower($s->state).'/'.apd_slug($s->name).'-'.$s->postcode.'/'; }
function apd_tel($p){ return preg_replace('/[^\d+]/','',$p); }
function apd_get($id){ global $wpdb; return $wpdb->get_row($wpdb->prepare("SELECT * FROM ".apd_tb('listings')." WHERE id=%d",$id)); }
function apd_radius_txt($r){ return $r>=999 ? 'whole state' : 'within '.(int)$r.' km'; }
/* listings serving a suburb: base suburb distance <= radius, or whole-state in same state */
function apd_serving($s,$trade='',$lim=50){ global $wpdb; $L=apd_tb('listings'); $S=apd_subt();
  $w=apd_live_sql('l'); if($trade) $w.=$wpdb->prepare(" AND CONCAT(',',l.trades,',') LIKE %s", '%,'.$wpdb->esc_like($trade).',%');
  $sql=$wpdb->prepare("SELECT l.*, s.state AS sstate, (6371*2*ASIN(SQRT(POWER(SIN(RADIANS(s.lat-%f)/2),2)+COS(RADIANS(%f))*COS(RADIANS(s.lat))*POWER(SIN(RADIANS(s.lng-%f)/2),2)))) AS km FROM $L l JOIN $S s ON s.id=l.suburb_id WHERE $w HAVING (km<=l.radius OR (l.radius>=999 AND sstate=%s)) ORDER BY l.featured DESC, km ASC LIMIT %d", $s->lat,$s->lat,$s->lng,$s->state,$lim);
  return $wpdb->get_results($sql); }
function apd_logo($l,$sz=64){ if($l->logo_id && ($u=wp_get_attachment_image_url($l->logo_id,'thumbnail'))) return '<img class="logo" src="'.esc_url($u).'" width="'.$sz.'" height="'.$sz.'" alt="">'; return '<span class="logo ph" aria-hidden="true">'.apd_e(mb_strtoupper(mb_substr($l->name,0,1))).'</span>'; }
function apd_card($l){ $s=apd_sub($l->suburb_id); $tr=array_filter(array_map('apd_trade_label',explode(',',$l->trades)));
  return '<a class="card biz" href="'.esc_attr(apd_url($l)).'">'.apd_logo($l,52).'<span><b>'.apd_e($l->name).'</b>'.($l->is_test?' <span class="pill test">TEST</span>':'').'<br><span class="mut">'.apd_e(implode(' · ',array_slice($tr,0,3))).'</span><br><span class="mut">'.apd_e($s?$s->name.' '.$s->state:'').' · '.apd_e(apd_radius_txt($l->radius)).'</span></span></a>'; }
function apd_empty($where=''){ return '<div class="card empty"><p><b>No businesses listed here yet'.($where?' for '.apd_e($where):'').'.</b></p><p class="mut">Are you a local tradie or sole trader? Listing is free, with no lead fees or commission.</p><a class="btn" href="/list/">List your business free</a></div>'; }
function apd_trade_select($name,$sel=[],$multi=false,$any=false){ $sel=(array)$sel;
  if($multi){ $h='<div class="tgrid">'; foreach(apd_trades() as $k=>$v) $h.='<label class="chk"><input type="checkbox" name="'.$name.'[]" value="'.$k.'"'.(in_array($k,$sel,true)?' checked':'').'> '.apd_e($v).'</label>'; return $h.'</div>'; }
  $h='<select name="'.$name.'" id="'.$name.'">'.($any?'<option value="">Any trade</option>':'<option value="">Choose a trade…</option>'); foreach(apd_trades() as $k=>$v) $h.='<option value="'.$k.'"'.(in_array($k,$sel,true)?' selected':'').'>'.apd_e($v).'</option>'; return $h.'</select>'; }

/* ---------- layout ---------- */
function apd_layout($title,$body,$back='/',$desc='',$index=true){
  status_header($GLOBALS["apd_code"]??200);
  $p=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH) ?: '/';
  $priv=['/list/','/login/','/signup/','/my-listings/','/claim/','/report/','/featured/'];
  $pp=rtrim($p?:'/','/').'/';
  if($pp==='//') $pp='/';
  foreach($priv as $x){ if($pp===$x || ($x!=='/' && strpos($pp,$x)===0)) $index=false; }
  $index = $index && !APD_DEV_NOINDEX && (($GLOBALS["apd_code"]??200)==200);
  if(APD_DEV_NOINDEX){ nocache_headers(); header('X-Robots-Tag: noindex, nofollow', true); }
  else {
    if(!$index) header('X-Robots-Tag: noindex, follow', true);
    $fk='apd_fl_'.(apd_me()?:md5($_SERVER['REMOTE_ADDR']??''));
    if(is_user_logged_in() || get_transient($fk) || (($_SERVER['REQUEST_METHOD']??'GET')!=='GET')) nocache_headers();
    else header('Cache-Control: public, max-age=60, s-maxage=300');
  }
  $tabs=[['/','⌂','Home'],['/find/','⌕','Find'],['/list/','＋','List free'],['/my-listings/','☺','My listings']];
  ?><!doctype html><html lang="en-AU"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover"><?php
    if(APD_DEV_NOINDEX) echo '<meta name="robots" content="noindex,nofollow">';
    elseif(!$index) echo '<meta name="robots" content="noindex,follow">';
  ?><title><?php echo apd_e($title.' · '.APD_BRAND); ?></title><?php if($desc) echo '<meta name="description" content="'.esc_attr($desc).'">'; ?>
<link rel="canonical" href="<?php echo esc_url(home_url($p)); ?>">
<?php wp_head(); ?>
<style>:root{--c:<?php echo APD_COLOR; ?>;--bg:#f6f7f9;--ink:#1f2937;--mut:#6b7280;--ln:#e5e7eb}
*{box-sizing:border-box}html,body{margin:0}body.apd{background:var(--bg);color:var(--ink);font:16px/1.45 system-ui,-apple-system,Segoe UI,Roboto,sans-serif;padding-bottom:calc(80px + env(safe-area-inset-bottom))}
#apd-top{position:sticky;top:0;z-index:50;display:flex;align-items:center;gap:10px;padding:calc(6px + env(safe-area-inset-top)) 10px 6px;background:#fff;border-bottom:1px solid var(--ln);min-height:56px}
#apd-top a.bk{display:inline-flex;align-items:center;min-height:40px;padding:4px 12px;border-radius:999px;color:var(--ink);border:1px solid var(--ln);font-weight:700;text-decoration:none}
#apd-top b{font-size:17px;color:var(--c)}
#apd-nav{position:fixed;left:0;right:0;bottom:0;z-index:60;display:flex;background:rgba(255,255,255,.97);border-top:1px solid var(--ln);padding-bottom:env(safe-area-inset-bottom)}
#apd-nav a{flex:1;display:flex;flex-direction:column;align-items:center;padding:7px 2px 8px;min-height:58px;color:var(--mut);text-decoration:none;font-size:11.5px;font-weight:600}#apd-nav a i{font-style:normal;font-size:21px;line-height:24px}#apd-nav a.on{color:var(--c)}
main.m{max-width:760px;margin:0 auto;padding:14px}h1{font-size:1.45rem;margin:.3em 0 .5em}h2{font-size:1.1rem;margin:1.2em 0 .4em}
.card{display:block;background:#fff;border:1px solid var(--ln);border-radius:14px;padding:14px;margin:10px 0;color:var(--ink);text-decoration:none}.card.biz{display:flex;gap:12px;align-items:flex-start}
.logo{width:52px;height:52px;border-radius:12px;object-fit:cover;flex:none}.logo.ph{display:inline-flex;align-items:center;justify-content:center;background:#ccfbf1;color:var(--c);font-weight:800;font-size:22px}
.mut{color:var(--mut);font-size:.9rem}.pill{display:inline-block;background:#eef2f7;border-radius:999px;padding:2px 9px;font-size:.8rem;margin:2px 2px 2px 0;color:var(--ink);text-decoration:none}.pill.test{background:#fef3c7;color:#92400e;font-weight:700}.pill.pend{background:#fef9c3}.pill.live{background:#dcfce7;color:#166534}
label{display:block;font-weight:600;margin:12px 0 4px}input[type=text],input[type=email],input[type=password],input[type=url],input[type=tel],select,textarea{width:100%;padding:11px;border:1px solid #cbd5e1;border-radius:10px;font:inherit;background:#fff}textarea{min-height:110px}
.btn,button{display:inline-block;background:var(--c);color:#fff;border:0;border-radius:10px;padding:12px 18px;font:600 16px system-ui,sans-serif;text-decoration:none;cursor:pointer;min-height:46px;text-align:center}.btn.big{display:block;width:100%;font-size:17px}.btn.alt{background:#fff;color:var(--c);border:1px solid var(--c)}.btn.sm{padding:6px 10px;min-height:0;font-size:.85rem}
.flash{background:#ecfeff;border:1px solid #a5f3fc;border-radius:10px;padding:10px;margin:8px 0}.note{background:#fffbeb;border:1px solid #fde68a;border-radius:10px;padding:10px;font-size:.9rem}
.chk{display:flex;gap:8px;align-items:center;font-weight:500;margin:4px 0}.chk input{width:20px;height:20px;flex:none}.tgrid{display:grid;grid-template-columns:repeat(auto-fill,minmax(170px,1fr));gap:2px 8px;max-height:260px;overflow:auto;border:1px solid var(--ln);border-radius:10px;padding:8px;background:#fff}
.grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:8px}.grid a{background:#fff;border:1px solid var(--ln);border-radius:12px;padding:10px;color:var(--ink);text-decoration:none;font-weight:600;font-size:.95rem}
.acts{display:grid;grid-template-columns:repeat(auto-fit,minmax(120px,1fr));gap:8px;margin:12px 0}.row{display:flex;gap:8px;flex-wrap:wrap;align-items:center}
dl.kv dt{font-weight:600;margin-top:8px}dl.kv dd{margin:0}
#wpadminbar{display:none}#fb-btn{bottom:calc(70px + env(safe-area-inset-bottom))!important}footer.f{text-align:center;color:var(--mut);font-size:.8rem;padding:20px 10px}footer.f a{color:var(--mut)}<?php if(function_exists("apd_extra_css")) apd_extra_css(); ?></style></head>
<body class="apd"><header id="apd-top" class="appbar"><?php if($p!=='/') echo '<a class="bk" href="'.esc_url($back).'" onclick="if(document.referrer.indexOf(location.host)>-1&&history.length>1){history.back();return false}" aria-label="Back">‹ Back</a>'; ?><b><?php echo apd_e(APD_BRAND); ?></b></header>
<main class="m"><?php echo apd_flash(); echo $body; ?></main>
<footer class="f"><?php echo apd_e(APD_BRAND); ?> is a free directory. We don't check, endorse or guarantee any business. Customers contact businesses directly.<br><a href="/trades/">All trades</a> · <a href="/about/">About</a> · <a href="/contact/">Contact</a> · <a href="/rules/">Community rules</a> · <a href="/terms/">Terms</a> · <a href="/privacy/">Privacy</a> · <a href="/featured/">Featured</a><br><b>More free apps:</b> <a href="https://localaus.xyz/">LocalAus</a> · <a href="https://localusa.app/">LocalUSA</a> · <a href="https://australiaweb.net/">AustraliaWeb</a> · <a href="https://groopza.com/">Groopza</a> · <a href="https://aussiefinder.app/">AussieFinder</a><br><small>Suburb data: Matthew Proctor, Australian Postcodes (CC BY 4.0), built on ABS ASGS (CC BY 4.0). Maps © OpenStreetMap contributors.</small></footer>
<nav id="apd-nav" aria-label="Main"><?php foreach($tabs as $t){ $on=($t[0]==='/'?$p==='/':strpos($p,$t[0])===0)?' class="on"':''; echo '<a href="'.esc_url($t[0]).'"'.$on.'><i aria-hidden="true">'.$t[1].'</i>'.apd_e($t[2]).'</a>'; } ?></nav>
<script>document.querySelectorAll('input[data-sub]').forEach(function(i){var l=document.createElement('datalist');l.id='dl'+Math.random().toString(36).slice(2);document.body.appendChild(l);i.setAttribute('list',l.id);var t;i.addEventListener('input',function(){clearTimeout(t);t=setTimeout(function(){if(i.value.length<2)return;fetch('/?apd_sub='+encodeURIComponent(i.value)).then(function(r){return r.json()}).then(function(a){l.innerHTML='';a.forEach(function(s){var o=document.createElement('option');o.value=s;l.appendChild(o)})})},200)})});</script>
<?php wp_footer(); ?></body></html><?php exit; }

/* suburb autocomplete */
add_action('init', function(){ if(!isset($_GET['apd_sub'])) return; global $wpdb; $q=trim((string)$_GET['apd_sub']); header('Content-Type: application/json'); header('X-Robots-Tag: noindex');
  if(strlen($q)<2){ echo '[]'; exit; } $T=apd_subt();
  $r=ctype_digit($q) ? $wpdb->get_results($wpdb->prepare("SELECT name,state,postcode FROM $T WHERE postcode LIKE %s ORDER BY name LIMIT 10",$wpdb->esc_like($q).'%')) : $wpdb->get_results($wpdb->prepare("SELECT name,state,postcode FROM $T WHERE name LIKE %s ORDER BY name LIMIT 10",$wpdb->esc_like($q).'%'));
  echo wp_json_encode(array_map(function($x){ return $x->name.' '.$x->state.' '.$x->postcode; },$r)); exit; }, 5);

/* ---------- router ---------- */
add_action('template_redirect', function(){
  $p=rtrim(parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH),'/').'/'; $post=($_SERVER['REQUEST_METHOD']??'GET')==='POST';
  if($p==='/') apd_home();
  if($p==='/find/') apd_find();
  if($p==='/trades/') apd_trades_page();
  if(preg_match('~^/trade/([a-z0-9-]+)/$~',$p,$m)) apd_trade_page($m[1]);
  if(preg_match('~^/area/([a-z]{2,3})/([a-z0-9-]+)-(\d{4})/$~',$p,$m)) apd_area_page($m[1],$m[2],$m[3]);
  if(preg_match('~^/biz/(\d+)(-[a-z0-9-]*)?/$~',$p,$m)) apd_biz((int)$m[1]);
  if($p==='/list/') apd_form($post);
  if($p==='/my-listings/') apd_mine($post);
  if($p==='/login/') apd_login($post);
  if($p==='/signup/') apd_signup($post);
  if($p==='/logout/'){ if(!empty($_GET['n']) && wp_verify_nonce($_GET['n'],'apd_out')) wp_logout(); apd_go('/'); }
  if($p==='/report/' && $post) apd_report();
  if($p==='/terms/') apd_terms(); if($p==='/privacy/') apd_privacy(); if($p==='/rules/') apd_rules(); if($p==='/featured/') apd_featured();
}, 5);

/* ---------- public pages ---------- */
function apd_search_form($trade='',$where=''){ return '<form method="get" action="/find/" class="card"><label for="t">What do you need?</label>'.apd_trade_select('t',$trade,false,true).'<label for="w">Where? (suburb or postcode)</label><input type="text" id="w" name="w" data-sub autocomplete="off" placeholder="e.g. Richmond VIC 3121" value="'.esc_attr($where).'"><p><button class="btn big" type="submit">Search</button></p></form>'; }
function apd_home(){ if(function_exists('apd_home_rich')) return apd_home_rich(); global $wpdb; $n=(int)$wpdb->get_var("SELECT COUNT(*) FROM ".apd_tb('listings')." l WHERE ".apd_live_sql('l'));
  $h='<h1>Find a local tradie, free</h1><p class="mut">Australian tradies and sole traders. Contact them directly: no lead fees, no commission, no middleman.</p>'.apd_search_form();
  $h.='<div class="card"><b>Tradie or sole trader?</b><p class="mut" style="margin:.3em 0 .6em">List your business free. Customers call or email you directly, and you never pay per lead.</p><a href="/list/">List your business free ›</a></div>';
  $h.='<h2>Popular trades</h2><div class="grid">'; foreach(array_slice(apd_trades(),0,12,true) as $k=>$v) $h.='<a href="/trade/'.$k.'/">'.apd_e($v).'</a>'; $h.='</div><p><a href="/trades/">All trades ›</a></p>';
  if($n){ $rows=$wpdb->get_results("SELECT l.* FROM ".apd_tb('listings')." l WHERE ".apd_live_sql('l')." ORDER BY l.id DESC LIMIT 6"); $h.='<h2>Recently listed</h2>'; foreach($rows as $r) $h.=apd_card($r); }
  apd_layout('Free directory of local tradies',$h,'/','Find local Australian tradies and sole traders and contact them directly. Free to list, no lead fees.'); }
function apd_find(){ if(function_exists('apd_find_rich')) return apd_find_rich(); $t=sanitize_key($_GET['t']??''); if(!apd_trade_label($t)) $t=''; $w=sanitize_text_field(wp_unslash($_GET['w']??''));
  $h='<h1>Find a tradie</h1>'.apd_search_form($t,$w);
  if($w===''){ if($t) apd_go('/trade/'.$t.'/'); apd_layout('Find a tradie',$h); }
  $s=apd_find_sub($w); if(!$s){ $h.='<p class="note">We couldn\'t find that suburb. Try picking one from the list, like "Richmond VIC 3121".</p>'; apd_layout('Find a tradie',$h); }
  $rows=apd_serving($s,$t); $lbl=($t?apd_trade_label($t).' – ':'').apd_sub_label($s);
  $h.='<h2>'.apd_e($lbl).'</h2>'; if(!$rows) $h.=apd_empty($lbl); foreach($rows as $r) $h.=apd_card($r);
  apd_layout('Find a tradie',$h,'/find/'); }
function apd_trades_page(){ if(function_exists('apd_trades_page_rich')) return apd_trades_page_rich(); $h='<h1>All trades</h1><div class="grid">'; foreach(apd_trades() as $k=>$v) $h.='<a href="/trade/'.$k.'/">'.apd_e($v).'</a>'; apd_layout('All trades',$h.'</div>'); }
function apd_trade_page($slug){ if(function_exists('apd_trade_page_rich')) return apd_trade_page_rich($slug); $lb=apd_trade_label($slug); if(!$lb){ $GLOBALS["apd_code"]=404; apd_layout('Not found','<h1>Trade not found</h1><p><a href="/trades/">See all trades</a></p>'); }
  global $wpdb; $L=apd_tb('listings'); $rows=$wpdb->get_results($wpdb->prepare("SELECT l.* FROM $L l WHERE ".apd_live_sql('l')." AND CONCAT(',',l.trades,',') LIKE %s ORDER BY l.featured DESC, l.id DESC LIMIT 100",'%,'.$wpdb->esc_like($slug).',%'));
  $h='<h1>'.apd_e($lb).'s in Australia</h1>'.apd_search_form($slug); if(!$rows) $h.=apd_empty(strtolower($lb)); foreach($rows as $r) $h.=apd_card($r);
  apd_layout($lb.' – find a local '.$lb,$h,'/trades/','Find a local '.strtolower($lb).' and contact them directly. Free, no lead fees.'); }
function apd_area_page($st,$slug,$pc){ if(function_exists('apd_area_page_rich')) return apd_area_page_rich($st,$slug,$pc); global $wpdb; $S=apd_subt(); $cands=$wpdb->get_results($wpdb->prepare("SELECT * FROM $S WHERE postcode=%s AND state=%s",$pc,strtoupper($st))); $s=null;
  foreach($cands as $c) if(apd_slug($c->name)===$slug){ $s=$c; break; }
  if(!$s){ $GLOBALS["apd_code"]=404; apd_layout('Not found','<h1>Suburb not found</h1>'); }
  $rows=apd_serving($s,'',100); $h='<h1>Tradies serving '.apd_e(apd_sub_label($s)).'</h1>'.apd_search_form('',apd_sub_label($s)); if(!$rows) $h.=apd_empty($s->name); foreach($rows as $r) $h.=apd_card($r);
  apd_layout('Local tradies in '.$s->name.' '.$s->state,$h,'/find/'); }
function apd_biz($id){ if(function_exists('apd_biz_rich')) return apd_biz_rich($id); $l=apd_get($id); $mine=$l && $l->user_id==apd_me();
  if(!$l || (!$mine && !current_user_can('manage_options') && ($l->status!=='live' || ($l->is_test && !apd_see_test())))){ $GLOBALS["apd_code"]=404; apd_layout('Not found','<h1>Listing not found</h1><p>It may be waiting for review or has been removed.</p>'.apd_empty()); }
  $s=apd_sub($l->suburb_id); $tr=array_filter(explode(',',$l->trades));
  $h=($l->status!=='live'?'<p class="note">This listing is <b>'.apd_e($l->status).'</b> and only you can see it. We review new listings and edits before they go public.</p>':'');
  $h.='<div class="row">'.apd_logo($l,72).'<div><h1 style="margin:0">'.apd_e($l->name).'</h1>'.($l->is_test?'<span class="pill test">TEST</span>':'').'<div class="mut">'.apd_e(apd_sub_label($s)).' · serves '.apd_e(apd_radius_txt($l->radius)).'</div></div></div>';
  $h.='<p>'; foreach($tr as $t) if(apd_trade_label($t)) $h.='<a class="pill" href="/trade/'.$t.'/">'.apd_e(apd_trade_label($t)).'</a>'; $h.='</p>';
  $main=''; $alt=[];
  if($l->phone){ $main='<a class="btn big" href="tel:'.esc_attr(apd_tel($l->phone)).'">📞 Call '.apd_e($l->phone).'</a>'; }
  if($l->website){ $b='<a class="btn alt" href="'.esc_url($l->website).'" target="_blank" rel="nofollow noopener ugc">🌐 Website</a>'; if($main) $alt[]=$b; else $main=str_replace('btn alt','btn big',$b); }
  if($l->email){ $b='<a class="btn alt" href="mailto:'.esc_attr($l->email).'">✉ Email</a>'; if($main) $alt[]=$b; else $main=str_replace('btn alt','btn big',$b); }
  $dest=$l->address?:apd_sub_label($s).' Australia'; $alt[]='<a class="btn alt" href="https://www.google.com/maps/dir/?api=1&destination='.rawurlencode($dest).'" target="_blank" rel="noopener">➜ Directions</a>';
  $h.=$main.'<div class="acts">'.implode('',$alt).'</div>';
  if($l->descr) $h.='<div class="card">'.wpautop(apd_e($l->descr)).'</div>';
  $h.='<dl class="kv card">'.($l->address?'<dt>Address</dt><dd>'.apd_e($l->address).'</dd>':'').'<dt>Based in</dt><dd>'.($s?'<a href="'.esc_attr(apd_area_url($s)).'">'.apd_e(apd_sub_label($s)).'</a>':'').'</dd><dt>Service area</dt><dd>'.apd_e(ucfirst(apd_radius_txt($l->radius))).'</dd>';
  if($l->abn) $h.='<dt>ABN</dt><dd>'.apd_e($l->abn).' <span class="mut">(as entered by the business – not verified by us. <a href="https://abr.business.gov.au/ABN/View?abn='.rawurlencode(preg_replace('/\D/','',$l->abn)).'" target="_blank" rel="noopener">Check on ABN Lookup</a>)</span></dd>';
  if($l->licence) $h.='<dt>Licence number</dt><dd>'.apd_e($l->licence).' <span class="mut">(as entered by the business – not verified by us. Check it with your state licensing body before hiring.)</span></dd>';
  $h.='</dl><p class="mut">Always check licences, insurance and quotes yourself before hiring. '.apd_e(APD_BRAND).' doesn\'t check businesses or take part in any job.</p>';
  if($s) $h.='<p><a href="'.esc_attr(apd_area_url($s)).'">More tradies near '.apd_e($s->name).' ›</a></p>';
  $h.='<details class="card"><summary style="cursor:pointer;color:#b91c1c">Report this listing</summary><form method="post" action="/report/">'.apd_nonce().'<input type="hidden" name="id" value="'.(int)$l->id.'"><label for="rr">Reason</label><select id="rr" name="reason"><option>Wrong or out-of-date details</option><option>Not a real business / spam</option><option>Scam or fraud</option><option>Offensive or not family-safe</option><option>Fake licence or ABN</option><option>This is my business and I didn\'t list it</option><option>Other</option></select><label for="rd">Details (optional)</label><textarea id="rd" name="details" maxlength="1000" style="min-height:70px"></textarea><p><button type="submit">Send report</button></p></form></details>';
  if($mine) $h.='<p><a class="btn alt" href="/list/?id='.(int)$l->id.'">Edit this listing</a></p>';
  apd_layout($l->name.' – '.implode(', ',array_map('apd_trade_label',array_slice($tr,0,2))).($s?' in '.$s->name:''),$h,'/find/',wp_trim_words($l->descr,25,'…')); }
function apd_report(){ apd_check(); global $wpdb; $id=(int)($_POST['id']??0); $l=apd_get($id); if(!$l) apd_go('/');
  $ip=md5(($_SERVER['REMOTE_ADDR']??'').wp_salt()); $recent=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM ".apd_tb('reports')." WHERE ip=%s AND created>DATE_SUB(%s,INTERVAL 1 HOUR)",$ip,apd_now()));
  if($recent<10) $wpdb->insert(apd_tb('reports'),['listing_id'=>$id,'reporter_id'=>apd_me(),'reason'=>substr(sanitize_text_field(wp_unslash($_POST['reason']??'Other')),0,80),'details'=>substr(sanitize_textarea_field(wp_unslash($_POST['details']??'')),0,1000),'ip'=>$ip,'created'=>apd_now()]);
  apd_flash('Thanks, your report has been sent to our team.'); apd_go(apd_url($l)); }

/* ---------- owner: add / edit ---------- */
function apd_form($post){ apd_need_login(); global $wpdb; $L=apd_tb('listings'); $me=apd_me(); $id=(int)($_REQUEST['id']??0); $l=null;
  if($id){ $l=apd_get($id); if(!$l || $l->user_id!=$me) apd_go('/my-listings/'); }
  $err=[]; $v=$l?(array)$l:['name'=>'','trades'=>'','suburb_id'=>0,'radius'=>25,'address'=>'','phone'=>'','website'=>'','email'=>wp_get_current_user()->user_email,'abn'=>'','licence'=>'','descr'=>'','logo_id'=>0];
  if($post){ apd_check();
    $in=function($k,$n=200){ return mb_substr(sanitize_text_field(wp_unslash($_POST[$k]??'')),0,$n); };
    $v['name']=$in('name',120); $tr=array_values(array_filter(array_map('sanitize_key',(array)($_POST['trades']??[])),'apd_trade_label')); $v['trades']=implode(',',array_slice($tr,0,5));
    $sub=apd_find_sub($in('suburb')); $v['suburb_id']=$sub?$sub->id:0; $r=(int)($_POST['radius']??25); $v['radius']=in_array($r,[5,10,25,50,100,999],true)?$r:25;
    $v['address']=$in('address'); $v['phone']=$in('phone',40); $w=trim($in('website')); if($w!=='' && !preg_match('~^https?://~i',$w)) $w='https://'.$w; $v['website']=$w?esc_url_raw($w):'';
    $v['email']=sanitize_email(wp_unslash($_POST['email']??'')); $v['abn']=$in('abn',20); $v['licence']=$in('licence',80); $v['descr']=mb_substr(sanitize_textarea_field(wp_unslash($_POST['descr']??'')),0,2000);
    if(mb_strlen($v['name'])<2) $err[]='Please enter your business name.';
    if(!$tr) $err[]='Please pick at least one trade.'; if(count($tr)>5) $err[]='Please pick up to 5 trades.';
    if(!$sub) $err[]='Please choose your base suburb from the list.';
    if(!$v['phone'] && !$v['website'] && !$v['email']) $err[]='Please give at least one way to contact you (phone, website or email).';
    if($v['phone'] && strlen(preg_replace('/\D/','',$v['phone']))<8) $err[]='That phone number looks too short.';
    if(!empty($_POST['email']) && !$v['email']) $err[]='That email address doesn\'t look right.';
    if($v['website'] && !filter_var($v['website'],FILTER_VALIDATE_URL)) $err[]='That website address doesn\'t look right.';
    if($v['abn'] && strlen(preg_replace('/\D/','',$v['abn']))!==11) $err[]='An ABN has 11 digits (or leave it empty).';
    foreach(['name','descr','address'] as $k) if(!apd_family_ok($v[$k])) { $err[]='Please keep your listing family-friendly. Adult services, scams and abusive language are not allowed. Please read the Community rules.'; break; }
    if(empty($_POST['agree'])) $err[]='Please tick that you agree to the terms and community rules.';
    if(!$l){ $cnt=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $L WHERE user_id=%d",$me)); if($cnt>=10) $err[]='You can have up to 10 listings. Contact us if you need more.'; }
    if(!$err && !empty($_FILES['logo']['name'])){
      $f=$_FILES['logo']; $ok=wp_check_filetype_and_ext($f['tmp_name'],$f['name'],['jpg|jpeg'=>'image/jpeg','png'=>'image/png','webp'=>'image/webp']);
      if(empty($ok['type']) || $f['size']>2*1024*1024 || $f['error']) $err[]='Logo must be a JPG, PNG or WebP under 2 MB.';
      else { require_once ABSPATH.'wp-admin/includes/file.php'; require_once ABSPATH.'wp-admin/includes/media.php'; require_once ABSPATH.'wp-admin/includes/image.php';
        $aid=media_handle_upload('logo',0,[],['test_form'=>false,'mimes'=>['jpg|jpeg'=>'image/jpeg','png'=>'image/png','webp'=>'image/webp']]);
        if(is_wp_error($aid)) $err[]='Logo upload failed, please try another image.'; else { if($v['logo_id'] && (int)get_post_field('post_author',$v['logo_id'])===$me) wp_delete_attachment($v['logo_id'],true); $v['logo_id']=$aid; } } }
    if(!$err && $l && !empty($_POST['rmlogo']) && $v['logo_id']){ wp_delete_attachment($v['logo_id'],true); $v['logo_id']=0; }
    if(!$err){ $row=['name'=>$v['name'],'trades'=>$v['trades'],'suburb_id'=>$v['suburb_id'],'radius'=>$v['radius'],'address'=>$v['address'],'phone'=>$v['phone'],'website'=>$v['website'],'email'=>$v['email'],'abn'=>$v['abn'],'licence'=>$v['licence'],'descr'=>$v['descr'],'logo_id'=>$v['logo_id'],'status'=>'pending','updated'=>apd_now()];
      if($l){ $wpdb->update($L,$row,['id'=>$l->id]); $nid=$l->id; apd_flash('Saved. Your changes will show after a quick review.'); }
      else { $row+=['user_id'=>$me,'is_test'=>apd_is_test()?1:0,'created'=>apd_now()]; $wpdb->insert($L,$row); $nid=$wpdb->insert_id; apd_flash('Thanks! Your listing has been sent for review. We usually check new listings within a day or two.'); }
      if(!apd_is_test()) wp_mail(get_option('admin_email'),'['.APD_BRAND.'] Listing to review: '.$v['name'],"A listing was ".($l?'edited':'added')." and is waiting for review:\n".admin_url('admin.php?page=apd-review'));
      apd_go('/my-listings/'); } }
  $s=apd_sub($v['suburb_id']); $o=function($k) use($v){ return esc_attr($v[$k]); };
  $h='<h1>'.($l?'Edit your listing':'List your business free').'</h1>'.($l?'<p class="note">Edits are checked before they go public, so your listing will be hidden for a short time after you save.</p>':'<p class="mut">Free forever for a basic listing. No lead fees, no commission. Customers contact you directly.</p>');
  if($err) $h.='<div class="note"><b>Please fix:</b><br>'.implode('<br>',array_map('apd_e',$err)).'</div>';
  $h.='<form method="post" enctype="multipart/form-data" class="apd-form" action="/list/'.($l?'?id='.(int)$l->id:'').'">'.apd_nonce().apd_listrules_note().'
<label for="name">Business name *</label><input type="text" id="name" name="name" maxlength="120" required value="'.$o('name').'">
<label>Your trade(s) * <span class="mut">(up to 5)</span></label>'.apd_trade_select('trades',explode(',',$v['trades']),true).'
<label for="suburb">Base suburb *</label><input type="text" id="suburb" name="suburb" data-sub autocomplete="off" required placeholder="Start typing, e.g. Geelong" value="'.esc_attr(apd_sub_label($s)).'">
<label for="radius">How far do you travel?</label><select id="radius" name="radius">'; foreach([5,10,25,50,100,999] as $r) $h.='<option value="'.$r.'"'.((int)$v['radius']===$r?' selected':'').'>'.apd_e(ucfirst(apd_radius_txt($r))).'</option>'; $h.='</select>
<label for="phone">Phone</label><input type="tel" id="phone" name="phone" maxlength="40" value="'.$o('phone').'">
<label for="website">Website</label><input type="text" id="website" name="website" maxlength="200" placeholder="example.com.au" value="'.$o('website').'">
<label for="email">Email for customers</label><input type="email" id="email" name="email" maxlength="190" value="'.$o('email').'">
<p class="mut">Give at least one of phone, website or email. These are shown publicly.</p>
<label for="address">Shop or workshop address <span class="mut">(optional, shown publicly – leave empty if you work from home)</span></label><input type="text" id="address" name="address" maxlength="200" value="'.$o('address').'">
<label for="abn">ABN <span class="mut">(optional)</span></label><input type="text" id="abn" name="abn" inputmode="numeric" maxlength="20" value="'.$o('abn').'">
<label for="licence">Licence number <span class="mut">(optional – shown as you enter it, marked "not verified")</span></label><input type="text" id="licence" name="licence" maxlength="80" value="'.$o('licence').'">
<label for="descr">About your business</label><textarea id="descr" name="descr" maxlength="2000" placeholder="What you do, areas you cover, hours…">'.esc_textarea($v['descr']).'</textarea>
<label for="logo">Logo <span class="mut">(optional, JPG/PNG/WebP, max 2 MB)</span></label><input type="file" id="logo" name="logo" accept="image/jpeg,image/png,image/webp">'.($l && $l->logo_id?'<label class="chk"><input type="checkbox" name="rmlogo" value="1"> Remove current logo</label>':'').'
<label class="chk" style="margin-top:14px"><input type="checkbox" name="agree" value="1" required> I run this business and agree to the <a href="/terms/" target="_blank">terms</a> and <a href="/rules/" target="_blank">community rules</a>.</label>
<p><button class="btn big" type="submit">'.($l?'Save changes':'Send for review').'</button></p></form>'.apd_livewarn_script();
  apd_layout($l?'Edit listing':'List your business free',$h,'/my-listings/'); }
function apd_mine($post){ apd_need_login(); global $wpdb; $L=apd_tb('listings'); $me=apd_me();
  if($post){ apd_check(); $id=(int)($_POST['del']??0); $l=apd_get($id); if($l && $l->user_id==$me){ if($l->logo_id) wp_delete_attachment($l->logo_id,true); $wpdb->delete($L,['id'=>$id]); apd_flash('Listing deleted.'); } apd_go('/my-listings/'); }
  $rows=$wpdb->get_results($wpdb->prepare("SELECT * FROM $L WHERE user_id=%d ORDER BY id DESC",$me)); $u=wp_get_current_user();
  $h='<h1>My listings</h1>';
  if(!$rows) $h.='<div class="card empty"><p><b>You haven\'t listed a business yet.</b></p><p class="mut">It\'s free and takes about 3 minutes.</p><a class="btn big" href="/list/">List your business free</a></div>';
  else { foreach($rows as $r){ $st=['pending'=>['pend','Waiting for review'],'live'=>['live','Live'],'rejected'=>['','Not approved – please edit and resend'],'hidden'=>['','Hidden by our team']][$r->status]??['',$r->status];
      $h.='<div class="card"><div class="row">'.apd_logo($r,44).'<div style="flex:1"><b>'.apd_e($r->name).'</b><br><span class="pill '.$st[0].'">'.apd_e($st[1]).'</span>'.($r->featured?' <span class="pill">Featured</span>':'').'</div></div><div class="row" style="margin-top:8px"><a class="btn sm" href="/list/?id='.(int)$r->id.'">Edit</a><a class="btn sm alt" href="'.esc_attr(apd_url($r)).'">View</a><form method="post" onsubmit="return confirm(\'Delete this listing for good?\')" style="margin:0">'.apd_nonce().'<button class="btn sm alt" name="del" value="'.(int)$r->id.'" style="color:#b91c1c;border-color:#b91c1c">Delete</button></form></div></div>'; }
    $h.='<p><a href="/list/">+ Add another business</a></p>'; }
  $h.='<p class="mut">Signed in as '.apd_e($u->user_email).' · <a href="/logout/?n='.wp_create_nonce('apd_out').'">Sign out</a></p>';
  apd_layout('My listings',$h); }

/* ---------- accounts ---------- */
function apd_login($post){ if(apd_me()) apd_go('/my-listings/'); $to=wp_validate_redirect(wp_unslash($_REQUEST['to']??'/my-listings/'),'/my-listings/'); $err='';
  if($post){ apd_check(); $u=wp_signon(['user_login'=>sanitize_text_field(wp_unslash($_POST['email']??'')),'user_password'=>(string)wp_unslash($_POST['pw']??''),'remember'=>true],is_ssl()); if(!is_wp_error($u)) apd_go($to); $err='That email and password don\'t match.'; }
  $h='<h1>Sign in</h1>'.($err?'<p class="note">'.apd_e($err).'</p>':'').'<form method="post" class="card">'.apd_nonce().'<input type="hidden" name="to" value="'.esc_attr($to).'"><label for="em">Email</label><input type="email" id="em" name="email" required autocomplete="email"><label for="pw">Password</label><input type="password" id="pw" name="pw" required autocomplete="current-password"><p><button class="btn big" type="submit">Sign in</button></p></form><p>New here? <a href="/signup/?to='.rawurlencode($to).'">Create a free account</a> · <a href="'.esc_url(wp_lostpassword_url()).'">Forgot password?</a></p>';
  apd_layout('Sign in',$h); }
function apd_signup($post){ if(apd_me()) apd_go('/my-listings/'); $to=wp_validate_redirect(wp_unslash($_REQUEST['to']??'/list/'),'/list/'); $err=''; $em=''; $nm='';
  if($post){ apd_check(); $em=sanitize_email(wp_unslash($_POST['email']??'')); $nm=mb_substr(sanitize_text_field(wp_unslash($_POST['name']??'')),0,60); $pw=(string)wp_unslash($_POST['pw']??'');
    if(!is_email($em)) $err='Please enter a valid email.'; elseif(email_exists($em)) $err='That email already has an account. Please sign in.'; elseif(strlen($pw)<10) $err='Please use a password of at least 10 characters.'; elseif(mb_strlen($nm)<2) $err='Please enter your name.'; elseif(empty($_POST['agree'])) $err='Please agree to the terms.';
    if(!$err){ $base=sanitize_user(strstr($em,'@',true),true)?:'user'; $lg=$base; $i=1; while(username_exists($lg)) $lg=$base.(++$i);
      $id=wp_insert_user(['user_login'=>$lg,'user_email'=>$em,'user_pass'=>$pw,'display_name'=>$nm,'first_name'=>$nm,'role'=>'subscriber']);
      if(is_wp_error($id)) $err='Sorry, we couldn\'t create your account.'; else { if(substr($em,-8)==='.invalid') update_user_meta($id,'apd_test','1'); wp_set_current_user($id); wp_set_auth_cookie($id,true,is_ssl()); apd_go($to); } } }
  $h='<h1>Create a free account</h1><p class="mut">You need an account so only you can edit your listings.</p>'.($err?'<p class="note">'.apd_e($err).'</p>':'').'<form method="post" class="card">'.apd_nonce().'<input type="hidden" name="to" value="'.esc_attr($to).'"><label for="nm">Your name</label><input type="text" id="nm" name="name" required value="'.esc_attr($nm).'"><label for="em">Email</label><input type="email" id="em" name="email" required autocomplete="email" value="'.esc_attr($em).'"><label for="pw">Password <span class="mut">(10+ characters)</span></label><input type="password" id="pw" name="pw" required minlength="10" autocomplete="new-password"><label class="chk"><input type="checkbox" name="agree" value="1" required> I agree to the <a href="/terms/" target="_blank">terms</a> and <a href="/privacy/" target="_blank">privacy policy</a>.</label><p><button class="btn big" type="submit">Create account</button></p></form><p>Already have an account? <a href="/login/?to='.rawurlencode($to).'">Sign in</a></p>';
  apd_layout('Create account',$h); }

/* ---------- info pages (original text) ---------- */
function apd_upd(){ return '<p class="mut">Last updated 13 November 2026.</p>'; }
function apd_terms(){ $b=apd_e(APD_BRAND); apd_layout('Terms','<h1>Terms of use</h1>'.apd_upd().'<p>'.$b.' is a free online directory where Australian businesses describe their own services, and anyone can find them and contact them directly.</p><h2>What we do and don\'t do</h2><p>We only show listings. We don\'t provide, arrange, supervise or guarantee any work, and we\'re not part of any agreement between a customer and a business. We don\'t take a commission or charge lead fees.</p><h2>Businesses</h2><p>If you list a business, you confirm you run it or are allowed to list it, that the details are true and current, and that you hold any licences, registrations and insurance the law requires for your work. Licence numbers and ABNs are shown as you enter them and are not checked by us. We review listings before they go public and may edit, hide or remove any listing at any time.</p><h2>Customers</h2><p>Check licences, insurance, reviews elsewhere, written quotes and references yourself before you hire anyone. Any job is between you and the business.</p><h2>Acceptable use</h2><p>Follow our <a href="/rules/">community rules</a>. Don\'t scrape the site, send spam to listed businesses, or use it for anything unlawful.</p><h2>Liability</h2><p>To the extent the law allows, we are not liable for any loss arising from use of the site or from dealings between users. Nothing in these terms limits rights you have under the Australian Consumer Law.</p><h2>Changes</h2><p>We may update these terms. The date at the top shows the latest version.</p>','/'); }
function apd_privacy(){ $b=apd_e(APD_BRAND); apd_layout('Privacy','<h1>Privacy policy</h1>'.apd_upd().'<p>We collect as little as we need and follow the Australian Privacy Principles.</p><h2>What we collect</h2><p><b>Account:</b> your name, email and a securely hashed password, or your Google account name and email if you sign in with Google.<br><b>Listings:</b> the business details you enter. Phone, website, email, address, ABN, licence number, description and logo are <b>shown publicly</b> on your listing.<br><b>Reports:</b> what you tell us, and a one-way scrambled version of your IP address to stop abuse.<br><b>Analytics:</b> cookie-free, anonymous visit counts (self-hosted Umami).</p><h2>How we use it</h2><p>To run the directory, review listings, handle reports and keep the site safe. We don\'t sell your information and we don\'t show ads based on it.</p><h2>Your choices</h2><p>You can edit or delete your listings any time from My listings. To delete your account, use the feedback button or email us and we\'ll remove it.</p><h2>Storage</h2><p>Data is stored on our own server. We use reasonable security measures, but no system is perfectly secure.</p>','/'); }
function apd_rules(){ apd_layout('Community rules','<h1>Community rules</h1>'.apd_upd().'<ol><li><b>Real businesses only.</b> List a business you actually run. No fake, duplicate or placeholder listings.</li><li><b>Be honest.</b> Describe only services you offer and qualifications you hold. Don\'t enter a licence number or ABN that isn\'t yours.</li><li><b>Family-safe.</b> No adult, offensive, hateful or violent content, and nothing illegal.</li><li><b>No spam.</b> No keyword stuffing, unrelated links or listing the same business many times.</li><li><b>Respect privacy.</b> Don\'t post anyone else\'s personal details.</li><li><b>Report problems.</b> Use the "Report this listing" link on any listing. Our team reviews every report.</li></ol><p>We may hide or remove listings or accounts that break these rules.</p>','/'); }
function apd_featured(){ $h='<h1>Featured listings</h1>'; if(!APD_FEATURED_ON) $h.='<div class="card"><p><b>Not available yet.</b></p><p class="mut">Basic listings are free and always will be. An optional "Featured" upgrade may come later. Nothing can be bought right now.</p><a class="btn big" href="/list/">List your business free</a></div>'; apd_layout('Featured',$h,'/'); }

/* ---------- admin review ---------- */
add_action('admin_menu', function(){ add_menu_page('Listings to review','AP Listings','manage_options','apd-review','apd_admin','dashicons-store',2); });
function apd_admin(){ global $wpdb; $L=apd_tb('listings'); $R=apd_tb('reports');
  if(!empty($_POST['apd_a']) && check_admin_referer('apd_admin')){ $id=(int)$_POST['id']; $a=sanitize_key($_POST['apd_a']);
    if(in_array($a,['live','rejected','hidden','pending'],true)) $wpdb->update($L,['status'=>$a],['id'=>$id]);
    if($a==='closerep') $wpdb->update($R,['status'=>'closed'],['id'=>$id]);
    echo '<div class="updated"><p>Done.</p></div>'; }
  $btn=function($a,$id,$l,$p=false){ return '<form method="post" style="display:inline">'.wp_nonce_field('apd_admin','_wpnonce',true,false).'<input type="hidden" name="id" value="'.(int)$id.'"><button class="button'.($p?' button-primary':'').'" name="apd_a" value="'.$a.'">'.$l.'</button></form> '; };
  echo '<div class="wrap"><h1>Listings waiting for review</h1>';
  $rows=$wpdb->get_results("SELECT * FROM $L WHERE status='pending' ORDER BY updated ASC LIMIT 100"); if(!$rows) echo '<p>Nothing waiting.</p>';
  foreach($rows as $r){ $s=apd_sub($r->suburb_id); echo '<div style="background:#fff;border:1px solid #ddd;padding:10px;margin:8px 0"><b>'.apd_e($r->name).'</b>'.($r->is_test?' [TEST]':'').' – '.apd_e(implode(', ',array_map('apd_trade_label',explode(',',$r->trades)))).' – '.apd_e(apd_sub_label($s)).'<br>Phone '.apd_e($r->phone).' · Web '.apd_e($r->website).' · Email '.apd_e($r->email).' · ABN '.apd_e($r->abn).' · Licence '.apd_e($r->licence).'<br><i>'.apd_e(wp_trim_words($r->descr,60)).'</i><br><a href="'.esc_url(home_url(apd_url($r))).'" target="_blank">Preview</a> '.$btn('live',$r->id,'Approve',true).$btn('rejected',$r->id,'Reject').'</div>'; }
  echo '<h2>Open reports</h2>'; $reps=$wpdb->get_results("SELECT * FROM $R WHERE status='open' ORDER BY id DESC LIMIT 100"); if(!$reps) echo '<p>No open reports.</p>';
  foreach($reps as $p){ $l=apd_get($p->listing_id); echo '<div style="background:#fff;border:1px solid #ddd;padding:10px;margin:8px 0">'.apd_e($p->reason).' – '.($l?'<a href="'.esc_url(home_url(apd_url($l))).'" target="_blank">'.apd_e($l->name).'</a> ('.apd_e($l->status).')':'deleted listing').'<br>'.apd_e($p->details).'<br>'.($l?$btn('hidden',$l->id,'Hide listing').$btn('live',$l->id,'Keep live'):'').$btn('closerep',$p->id,'Close report').'</div>'; }
  echo '<h2>Live listings</h2>'; foreach($wpdb->get_results("SELECT * FROM $L WHERE status='live' ORDER BY id DESC LIMIT 200") as $r) echo '<p><a href="'.esc_url(home_url(apd_url($r))).'" target="_blank">'.apd_e($r->name).'</a> '.$btn('hidden',$r->id,'Hide').'</p>';
  echo '</div>'; }


