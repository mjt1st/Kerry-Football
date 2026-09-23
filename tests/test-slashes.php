<?php
// 1.8.24: apostrophes in team names. Saves keep the name as typed, comparisons ignore a stray
// backslash from older data, and the one-time repair cleans what is stored (snapshotting first).
// Usage: php test-slashes.php <plugin-root>
[, $ROOT] = $argv;
define('ABSPATH', __DIR__ . '/');
error_reporting(E_ALL & ~E_WARNING & ~E_NOTICE);
session_save_path(sys_get_temp_dir()); @session_start();

$W = [];
function reset_w(){ global $W; $W = ['rows'=>[], 'updates'=>[], 'snapshots'=>[], 'options'=>[], 'logs'=>[]]; }
reset_w();
foreach ([
 'esc_html'=>'return htmlspecialchars((string)$a[0], ENT_QUOTES, "UTF-8");','esc_attr'=>'return htmlspecialchars((string)$a[0], ENT_QUOTES, "UTF-8");',
 'esc_url'=>'return (string)$a[0];','wp_unslash'=>'return is_array($a[0]) ? array_map("stripslashes",$a[0]) : stripslashes((string)$a[0]);',
 'stripslashes_deep'=>'return is_array($a[0]) ? array_map("stripslashes_deep",$a[0]) : (is_string($a[0]) ? stripslashes($a[0]) : $a[0]);',
 'sanitize_text_field'=>'return trim(strip_tags((string)$a[0]));','wp_json_encode'=>'return json_encode($a[0]);',
 'current_time'=>'return "2026-09-22 12:00:00";','wp_list_pluck'=>'$o=[]; foreach($a[0] as $r){ $o[] = is_array($r) ? $r[$a[1]] : $r->{$a[1]}; } return $o;',
 'get_option'=>'global $W; return $W["options"][$a[0]] ?? ($a[1] ?? false);','update_option'=>'global $W; $W["options"][$a[0]] = $a[1]; return true;',
 'add_action'=>'return true;','add_filter'=>'return true;','error_log'=>'global $W; $W["logs"][] = $a[0]; return true;',
 'kf_snapshot_week'=>'global $W; $W["snapshots"][] = [(int)$a[0], $a[1] ?? ""]; return 1;',
 'site_url'=>'return "https://kf.test".($a[0]??"");','selected'=>'$r=((string)$a[0]===(string)($a[1]??true))?" selected":""; if($a[2]??true) echo $r; return $r;',
] as $n=>$b) { if (!function_exists($n)) eval("function $n(...\$a){ $b }"); }

class Db {
    public $prefix = 'edk_';
    function prepare($q, ...$a){ foreach ($a as $v) { $q = preg_replace('/%d|%s/', is_int($v) ? (string)$v : "'" . $v . "'", $q, 1); } return $q; }
    function get_results($q){ global $W;
        if (stripos($q, 'FROM edk_matchups') !== false) return $W['rows']['matchups'] ?? [];
        if (stripos($q, 'FROM edk_picks') !== false)    return $W['rows']['picks'] ?? [];
        if (stripos($q, 'FROM edk_pending_picks') !== false) return $W['rows']['pending'] ?? [];
        return []; }
    function get_row($q){ global $W;
        if (preg_match('/WHERE w\.id = (\d+)/', $q, $m)) return $W['rows']['weeks'][(int)$m[1]] ?? null;
        return null; }
    function get_var($q){ return null; }
    function update($t, $d, $w){ global $W; $W['updates'][] = [$t, $d, $w]; return 1; }
}
$GLOBALS['wpdb'] = new Db();
require $ROOT . '/includes/kf-team-names.php';

$fails = 0;
function check($l, $c, $d = ''){ global $fails; if ($c) echo "  PASS  $l\n"; else { $fails++; echo "  FAIL  $l" . ($d ? "\n        $d" : '') . "\n"; } }

echo "kf_team_key()\n";
check('a stray backslash is ignored', kf_team_key("Hawai\\'i Rainbow Warriors") === kf_team_key("Hawai'i Rainbow Warriors"));
check('a doubly-slashed pick matches its result', kf_team_key("Hawai\\\\\\'i") === kf_team_key("Hawai\\'i"));
check('case and spacing still ignored', kf_team_key('  TEXAS A&M  ') === kf_team_key('texas a&m'));
check('different teams still differ', kf_team_key("Hawai'i") !== kf_team_key('Hawaii Warriors'));
check('null is empty', kf_team_key(null) === '');
// The bug itself, stated as a test: this is the comparison scoring used until 1.8.24.
check('the OLD comparison is what scored a correct pick as a loss',
    strcasecmp(trim("Hawai\\\'i Rainbow Warriors"), trim("Hawai\'i Rainbow Warriors")) !== 0);

echo "\nkf_sql_team_key()\n";
$sql = kf_sql_team_key('p.pick');
check('normalises the column in SQL', $sql === "REPLACE(LOWER(TRIM(p.pick)), CHAR(92), '')", $sql);
check('uses CHAR(92), not a backslash literal (NO_BACKSLASH_ESCAPES-proof)', strpos($sql, '\\') === false, $sql);
$engine = file_get_contents($ROOT . '/includes/kf-scoring-engine.php');
check('every scoring comparison uses it', substr_count($engine, "kf_sql_team_key('p.pick')") === 4 && strpos($engine, 'LOWER(TRIM(p.pick)) = LOWER(TRIM(m.result))') === false,
    substr_count($engine, "kf_sql_team_key('p.pick')") . ' uses');

echo "\nSave paths unslash\n";
$ws = file_get_contents($ROOT . '/includes/kf-week-setup.php');
check('week setup: team names', strpos($ws, 'sanitize_text_field( wp_unslash( $teamA ) )') !== false && strpos($ws, 'sanitize_text_field( wp_unslash( $team_b_list[$index] ) )') !== false);
$pk = file_get_contents($ROOT . '/includes/kf-player-picks.php');
check('picks: both standard and BPOW', substr_count($pk, 'sanitize_text_field( wp_unslash( (string) $pick ) )') === 2);
check('picks: a saved pick is matched as a key', substr_count($pk, "selected(kf_team_key(\$saved_pick['pick'] ?? ''), kf_team_key(\$matchup->team_") === 2);
$er = file_get_contents($ROOT . '/includes/kf-enter-results-view.php');
check('results: wp_unslash, not a one-off stripslashes', strpos($er, 'sanitize_text_field( wp_unslash( $result ) )') !== false && strpos($er, 'stripslashes($result)') === false);
check('the live projection normalises too', strpos($er, 'kfTeamKey(pick) === kfTeamKey(result)') !== false && strpos($er, 'function kfTeamKey(') !== false);
$sum = file_get_contents($ROOT . '/includes/kf-week-summary-view.php');
check('week summary: no raw pick/result comparisons left', strpos($sum, 'strcasecmp(trim($pick_data->pick)') === false && strpos($sum, 'strcasecmp(trim($bpow_pick_data->pick)') === false);
check('week summary: compares keys', substr_count($sum, 'kf_team_key($pick_data->pick)') === 3 && substr_count($sum, 'kf_team_key($bpow_pick_data->pick)') === 3,
    substr_count($sum, 'kf_team_key($pick_data->pick)') . '/' . substr_count($sum, 'kf_team_key($bpow_pick_data->pick)'));

echo "\nOne-time repair\n";
function seed_dirty(){
    global $W; reset_w();
    $W['rows']['matchups'] = [
        (object)['id'=>1,'week_id'=>10,'team_a'=>"Hawai\\'i Rainbow Warriors",'team_b'=>'Fresno State','result'=>"Hawai\\'i Rainbow Warriors"],
        (object)['id'=>2,'week_id'=>11,'team_a'=>'Texas A&M','team_b'=>'Arizona State','result'=>'Texas A&M'],
    ];
    $W['rows']['picks'] = [
        (object)['id'=>91,'week_id'=>10,'pick'=>"Hawai\\\\\\'i Rainbow Warriors"],
        (object)['id'=>92,'week_id'=>10,'pick'=>'Fresno State'],
        (object)['id'=>93,'week_id'=>11,'pick'=>'Texas A&M'],
        (object)['id'=>95,'week_id'=>11,'pick'=>"O\'Brien State"],
    ];
    $W['rows']['pending'] = [ (object)['id'=>5,'week_id'=>10,'picks_data'=>json_encode(['picks'=>['1'=>"Hawai\\'i Rainbow Warriors"],'points'=>['1'=>3]])] ];
    $W['rows']['weeks'] = [
        10 => (object)['id'=>10,'week_number'=>3,'status'=>'finalized','season_name'=>'COLLEGE 2026'],
        11 => (object)['id'=>11,'week_number'=>4,'status'=>'published','season_name'=>'COLLEGE 2026'],
    ];
}
seed_dirty();
$r = kf_repair_slashed_team_text();
$updates = $W['updates'];
$by = function($table) use ($updates){ return array_values(array_filter($updates, fn($u) => $u[0] === $table)); };
check('the slashed matchup is cleaned', ($by('edk_matchups')[0][1]['team_a'] ?? null) === "Hawai'i Rainbow Warriors" && ($by('edk_matchups')[0][1]['result'] ?? null) === "Hawai'i Rainbow Warriors", json_encode($by('edk_matchups')));
check('a clean matchup is left alone', count($by('edk_matchups')) === 1);
check('the slashed pick is cleaned, every layer', ($by('edk_picks')[0][1]['pick'] ?? null) === "Hawai'i Rainbow Warriors", json_encode($by('edk_picks')));
check('clean picks are left alone', count($by('edk_picks')) === 2, json_encode($by('edk_picks')));
check('pending late picks are cleaned inside the JSON', ($by('edk_pending_picks')[0][1]['picks_data'] ?? '') === json_encode(['picks'=>['1'=>"Hawai'i Rainbow Warriors"],'points'=>['1'=>3]]), json_encode($by('edk_pending_picks')));
check('each affected week is snapshotted, before the change', $W['snapshots'] === [[10, 'pre_slash_repair'], [11, 'pre_slash_repair']], json_encode($W['snapshots']));
check('the report counts what changed', $r['matchups'] === 1 && $r['picks'] === 2 && $r['pending'] === 1, json_encode($r));
check('the finalized week is flagged for re-scoring', array_keys($r['finalized_weeks']) === [10] && $r['finalized_weeks'][10]['week_number'] === 3, json_encode($r['finalized_weeks']));
check('the published week is recorded but not flagged', isset($r['weeks'][11]) && !isset($r['finalized_weeks'][11]));
check('the report is stored for the dashboard', ($W['options']['kf_slash_repair_report']['picks'] ?? null) === 2);

echo "\nRepair on a league that never had one\n";
reset_w();
$W['rows']['matchups'] = [ (object)['id'=>3,'week_id'=>12,'team_a'=>'Ohio State','team_b'=>'Michigan','result'=>'Ohio State'] ];
$W['rows']['picks'] = [ (object)['id'=>94,'week_id'=>12,'pick'=>'Ohio State'] ];
$r2 = kf_repair_slashed_team_text();
check('changes nothing', $W['updates'] === [] && $W['snapshots'] === [], json_encode($W['updates']));
check('reports nothing to do', $r2['matchups'] === 0 && $r2['picks'] === 0 && $r2['weeks'] === []);

echo "\nIt runs once\n";
reset_w(); seed_dirty();
kf_maybe_repair_slashed_team_text();
$first = count($W['updates']);
kf_maybe_repair_slashed_team_text();
check('a second page load repeats nothing', count($W['updates']) === $first && $first > 0, $first . ' then ' . count($W['updates']));
check('the guard is set before the work, so a failure cannot loop', ($W['options']['kf_slash_repair_done'] ?? null) === '1');

echo "\n" . ($fails ? "$fails FAILED\n" : "ALL PASS\n");
exit($fails ? 1 : 0);
