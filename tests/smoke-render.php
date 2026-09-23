<?php
// Renders every Kerry Football shortcode against a permissive fake database and reports fatals,
// stray PHP, and unbalanced tags. Layout smoke test only — the numbers mean nothing.
// Usage: php smoke-render.php <plugin-root>
[, $ROOT] = $argv;
define('ABSPATH', __DIR__ . '/');
define('OBJECT_K','OBJECT_K'); define('ARRAY_A','ARRAY_A'); define('OBJECT','OBJECT'); define('ARRAY_N','ARRAY_N');
error_reporting(E_ALL & ~E_WARNING & ~E_NOTICE & ~E_DEPRECATED);
session_save_path(sys_get_temp_dir()); @session_start();
$_SESSION['kf_active_season_id'] = 4;
$_SERVER['REQUEST_METHOD'] = 'GET';

foreach ([
  'esc_html'=>'return htmlspecialchars((string)$a[0], ENT_QUOTES, "UTF-8");','esc_attr'=>'return htmlspecialchars((string)$a[0], ENT_QUOTES, "UTF-8");',
  'esc_textarea'=>'return htmlspecialchars((string)$a[0], ENT_QUOTES, "UTF-8");','esc_js'=>'return addslashes((string)$a[0]);',
  'esc_url'=>'return htmlspecialchars((string)$a[0], ENT_QUOTES, "UTF-8");','esc_url_raw'=>'return (string)$a[0];',
  'wp_kses_post'=>'return $a[0];','wp_kses'=>'return $a[0];','wp_unslash'=>'return is_array($a[0]) ? array_map("stripslashes",$a[0]) : stripslashes((string)$a[0]);',
  'stripslashes_deep'=>'return is_array($a[0]) ? array_map("stripslashes_deep",$a[0]) : (is_string($a[0]) ? stripslashes($a[0]) : $a[0]);',
  'sanitize_text_field'=>'return trim((string)$a[0]);','sanitize_key'=>'return strtolower((string)$a[0]);','sanitize_title'=>'return strtolower(preg_replace("/[^a-z0-9]+/i","-",(string)$a[0]));',
  'absint'=>'return abs((int)$a[0]);','site_url'=>'return "https://kf.test".($a[0]??"");','home_url'=>'return "https://kf.test".($a[0]??"");',
  'admin_url'=>'return "https://kf.test/wp-admin/".($a[0]??"");','get_permalink'=>'return "https://kf.test/page/";','wp_login_url'=>'return "/login";',
  'add_query_arg'=>'$k=$a[0]; $v=$a[1]??null; $u=$a[2]??null; if(is_array($k)){$u=$v; $args=$k;}else{$args=[$k=>$v];} if(!is_string($u)||$u===""){$u="https://kf.test/current/";} $p=parse_url($u); parse_str($p["query"]??"",$q); $q=array_merge($q,$args); return ($p["scheme"]??"https")."://".($p["host"]??"kf.test").($p["path"]??"/").($q?"?".http_build_query($q):"");',
  'remove_query_arg'=>'return "https://kf.test/current/";','wp_nonce_url'=>'return $a[0]."&_wpnonce=n";',
  'is_user_logged_in'=>'return true;','get_current_user_id'=>'return 101;','current_user_can'=>'return true;','user_can'=>'return true;','get_role'=>'return null;',
  'wp_get_current_user'=>'return (object)["ID"=>101,"display_name"=>"Britt","user_login"=>"britt","user_email"=>"b@x.test","roles"=>["commissioner"]];',
  'get_userdata'=>'return (object)["ID"=>$a[0],"display_name"=>"Britt","user_login"=>"britt","user_email"=>"b@x.test"];',
  'get_users'=>'$r=[]; foreach(["Britt","Saz","Kerry"] as $i=>$n) $r[]=(object)["ID"=>101+$i,"display_name"=>$n,"user_login"=>strtolower($n),"user_email"=>"x@x.test"]; return $r;',
  'get_user_meta'=>'return ($a[2]??false) ? "" : [];','update_user_meta'=>'return true;',
  'current_time'=>'return gmdate("Y-m-d H:i:s");','wp_timezone_string'=>'return "America/New_York";','get_gmt_from_date'=>'return $a[0];',
  'get_date_from_gmt'=>'return date(($a[1]??"Y-m-d H:i:s"), strtotime($a[0]));','date_i18n'=>'return date($a[0], $a[1]??time());','wp_date'=>'return date($a[0], $a[1]??time());',
  'human_time_diff'=>'return "3 hours";','wp_nonce_field'=>'echo "<input type=\"hidden\">";','wp_create_nonce'=>'return "n";','wp_verify_nonce'=>'return false;',
  'selected'=>'$r=((string)$a[0]===(string)($a[1]??true))?" selected":""; if($a[2]??true) echo $r; return $r;',
  'checked'=>'$r=((string)$a[0]===(string)($a[1]??true))?" checked":""; if($a[2]??true) echo $r; return $r;','disabled'=>'return "";',
  'get_option'=>'return $a[1] ?? false;','update_option'=>'return true;','delete_option'=>'return true;','get_transient'=>'return false;','set_transient'=>'return true;','delete_transient'=>'return true;',
  'wp_json_encode'=>'return json_encode($a[0]);','wp_list_pluck'=>'$o=[]; foreach($a[0] as $r){ $r=(object)$r; if(isset($a[2])) $o[$r->{$a[2]}]=$r->{$a[1]}; else $o[]=$r->{$a[1]}; } return $o;',
  'plugin_dir_path'=>'return dirname($a[0])."/";','plugin_dir_url'=>'return "https://kf.test/p/";','wp_next_scheduled'=>'return time()+600;',
  'wp_redirect'=>'return true;','wp_safe_redirect'=>'return true;','wp_validate_redirect'=>'return $a[0];','headers_sent'=>'return true;',
  'add_action'=>'return true;','add_filter'=>'return true;','add_shortcode'=>'return true;','shortcode_exists'=>'return false;','is_admin'=>'return false;','wp_doing_ajax'=>'return false;',
  'number_format_i18n'=>'return number_format($a[0]);','size_format'=>'return "12 KB";','nocache_headers'=>'return true;',
  'kf_can_manage_season'=>'return true;','kf_can_access_season'=>'return true;','kf_is_any_commissioner'=>'return true;','kf_enter_season_context'=>'return true;','kf_shares_managed_season'=>'return true;',
] as $n => $b) { if (!function_exists($n)) eval("function $n(...\$a){ $b }"); }

class FakeRow {
    public $i;
    function __construct($i){ $this->i = $i; }
    function __isset($k){ return true; }
    function __get($k){
        $i = $this->i;
        $names = ['Britt','Saz','Kerry','Mike O','Jenny','Big Tom'];
        // A team with an apostrophe on purpose: this is the case 1.8.24 is about.
        $teams = [["Hawai'i Rainbow Warriors",'Fresno State Bulldogs'],['Texas A&M Aggies','Arizona State Sun Devils'],['USC Trojans','San José State Spartans'],['Ohio State Buckeyes','Michigan Wolverines'],['LSU Tigers','Alabama Crimson Tide'],['Notre Dame','Rice Owls']];
        switch (true) {
            case $k === 'ID' || $k === 'id': return (string)(101 + $i);
            case $k === 'display_name' || $k === 'player_name': return $names[$i % 6];
            case $k === 'user_login': return strtolower(str_replace(' ','',$names[$i % 6]));
            case $k === 'user_email': return 'x@example.test';
            case $k === 'name' || $k === 'season_name': return ['COLLEGE 2026','PRO 2026','NFL SURVIVOR'][$i % 3];
            case $k === 'team_a': return $teams[$i % 6][0];
            case $k === 'team_b': return $teams[$i % 6][1];
            case $k === 'pick' || $k === 'result': return $teams[$i % 6][0];
            case $k === 'week_number': return (string)($i + 1);
            case $k === 'status': return ['published','finalized','draft','accepted','invited','pending'][$i % 6];
            case $k === 'is_active' || $k === 'is_commissioner': return $i === 0 ? '1' : '0';
            case $k === 'is_tiebreaker' || $k === 'is_bpow': return '0';
            case $k === 'sport_type': return 'college-football';
            case $k === 'game_status': return ['final','in_progress','scheduled'][$i % 3];
            case strpos($k,'datetime')!==false || strpos($k,'deadline')!==false || strpos($k,'_at')!==false || $k==='date': return '2026-09-26 19:30:00';
            case strpos($k,'score')!==false || strpos($k,'total')!==false || strpos($k,'points')!==false || $k==='point_value' || $k==='wins' || $k==='subtotal': return (string)(10 + ($i*7)%20);
            case strpos($k,'spread')!==false: return '-3.5';
            case strpos($k,'count')!==false || strpos($k,'max')!==false || strpos($k,'week')!==false: return '3';
            case strpos($k,'_id')!==false: return (string)(101 + $i);
            case $k==='picks_data' || $k==='tie_data' || $k==='data': return json_encode(['picks'=>[]]);
            case $k==='reason': return 'finalize';
            case $k==='created_by': return '101';
            default: return '0';
        }
    }
}
class FakeDb {
    public $prefix='edk_'; public $insert_id=1; public $last_error=''; public $rows_affected=0;
    function prepare($q, ...$a){ return $q; }
    function get_results($q,$o=null){ $r=[]; for($i=0;$i<6;$i++) $r[]=new FakeRow($i); return $r; }
    function get_row($q,$o=null){ return new FakeRow(0); }
    function get_var($q){ return '3'; }
    function get_col($q){ return ['101','102','103']; }
    function query($q){ return 0; } function insert(...$a){ return 1; } function update(...$a){ return 1; } function delete(...$a){ return 1; }
    function esc_like($s){ return $s; }
}
$GLOBALS['wpdb'] = new FakeDb();

$tmpdir = sys_get_temp_dir() . '/kf-smoke'; @mkdir($tmpdir);
foreach (glob($ROOT . '/includes/*.php') as $inc) {
    $src = file_get_contents($inc);
    // The real permission helpers would collide with the stubs above; everything else runs as shipped.
    foreach (['kf_can_manage_season','kf_can_access_season','kf_is_any_commissioner','kf_enter_season_context','kf_shares_managed_season'] as $fnName) {
        $src = str_replace('function ' . $fnName . '(', 'function ' . $fnName . '_real(', $src);
    }
    $copy = $tmpdir . '/' . basename($inc);
    file_put_contents($copy, $src);
    require_once $copy;
}

$pages = [
  'homepage'               => ['kf_homepage_shortcode', []],
  'week summary'           => ['kf_week_summary_view', ['week_id'=>'117']],
  'picks'                  => ['kf_my_picks_shortcode', ['week_id'=>'117']],
  'season summary'         => ['kf_season_summary_view', []],
  'player dashboard'       => ['kf_player_dashboard_view', []],
  'manage weeks'           => ['kf_manage_weeks_view_shortcode', []],
  'week setup (new)'       => ['kf_week_setup_form', []],
  'week setup (edit)'      => ['kf_week_setup_form', ['week_id'=>'117']],
  'enter results'          => ['kf_enter_results_shortcode', ['week_id'=>'117']],
  'manage players'         => ['kf_player_management_shortcode', []],
  'edit season'            => ['kf_edit_season_form_shortcode', []],
  'review late picks'      => ['kf_review_late_picks_view', []],
  'commissioner dashboard' => ['kf_commissioner_dashboard_shortcode', []],
  'create season'          => ['kf_create_season_shortcode', []],
  'notification settings'  => ['kf_notification_settings_view', []],
  'player stats'           => ['kf_player_stats_shortcode', []],
  'admin dashboard'        => ['kf_admin_dashboard_shortcode', []],
];

$fails = 0;
foreach ($pages as $label => [$fn, $get]) {
    if (!function_exists($fn)) { echo str_pad($label, 24) . " MISSING $fn\n"; $fails++; continue; }
    $_GET = $get;
    ob_start();
    try { $html = (string) $fn(); } catch (Throwable $e) { $html = 'FATAL: ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine(); }
    $html = ob_get_clean() . $html;
    $clean = preg_replace('#<script[\s\S]*?</script>#i', '', preg_replace('#<style[\s\S]*?</style>#i', '', $html));
    $problems = [];
    if (strpos($html, 'FATAL:') !== false) $problems[] = substr($html, strpos($html, 'FATAL:'), 120);
    if (strpos($clean, '<?php') !== false) $problems[] = 'stray PHP';
    foreach ([['div','</div>'], ['table','</table>'], ['form','</form>']] as [$tag, $close]) {
        $o = preg_match_all('#<' . $tag . '\b#i', $clean); $c = substr_count(strtolower($clean), $close);
        if ($o !== $c) $problems[] = "$tag $o/$c";
    }
    if ($problems) { $fails++; echo str_pad($label, 24) . ' FAIL  ' . implode('; ', $problems) . "\n"; }
    else { echo str_pad($label, 24) . ' ok    ' . strlen($html) . " bytes\n"; }
}
echo "\n" . ($fails ? "$fails page(s) with problems\n" : "all pages render clean\n");
exit($fails ? 1 : 0);
