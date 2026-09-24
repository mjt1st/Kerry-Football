<?php
// 1.8.25: two bugs.
//   1. The BPOW score swap never ran. Player IDs come back from get_col() as strings and were
//      compared to the BPOW winner's ID with ===, so a player whose second set of picks scored
//      higher kept the lower score, and the summary's BPOW column showed that lower total.
//   2. Point values were split on commas only, so a league default stored as
//      "13,12,11,10,9,8,7,6,5,4.3.2.1" ended at 4 and lost three point values.
// Usage: php tests/test-bpow-and-points.php <plugin-root>
[, $ROOT] = $argv;
define('ABSPATH', __DIR__ . '/');
error_reporting(E_ALL & ~E_WARNING & ~E_NOTICE);

$fails = 0;
function check($l, $c, $d = ''){ global $fails; if ($c) echo "  PASS  $l\n"; else { $fails++; echo "  FAIL  $l" . ($d ? "\n        $d" : '') . "\n"; } }

foreach ([
 'esc_html'=>'return htmlspecialchars((string)$a[0], ENT_QUOTES, "UTF-8");',
 'esc_attr'=>'return htmlspecialchars((string)$a[0], ENT_QUOTES, "UTF-8");',
 'wp_unslash'=>'return is_array($a[0]) ? array_map("stripslashes",$a[0]) : stripslashes((string)$a[0]);',
 'sanitize_text_field'=>'return trim(strip_tags((string)$a[0]));',
 'add_action'=>'return true;','add_filter'=>'return true;','error_log'=>'return true;',
 'get_option'=>'return false;','update_option'=>'return true;',
 'current_time'=>'return "2026-09-23 12:00:00";',
 'wp_list_pluck'=>'$o=[]; foreach($a[0] as $r){ $o[] = is_array($r) ? $r[$a[1]] : $r->{$a[1]}; } return $o;',
 // Point at a directory with no kf-double-down-engine.php, so the engine does not pull the DD
 // engine in and this test stays about scoring.
 'plugin_dir_path'=>'return sys_get_temp_dir() . "/kf-no-such-dir/";',
] as $n=>$b) { if (!function_exists($n)) eval("function $n(...\$a){ $b }"); }

require $ROOT . '/includes/kf-point-values.php';

echo "Point values\n";
$typo = '13,12,11,10,9,8,7,6,5,4.3.2.1';
check('a list typed with full stops yields every value',
    kf_parse_point_values($typo) === [13,12,11,10,9,8,7,6,5,4,3,2,1], json_encode(kf_parse_point_values($typo)));
check('and so adds up to the weekly total, not six short',
    array_sum(kf_parse_point_values($typo)) === 91, (string)array_sum(kf_parse_point_values($typo)));
// The bug itself, stated as a test: this is how every reader parsed the list until 1.8.25.
check('the OLD parsing is what dropped point values 3, 2 and 1',
    count(array_filter(array_map('intval', explode(',', $typo)))) === 10);
check('it is stored back in canonical form',
    kf_normalize_point_values($typo) === '13,12,11,10,9,8,7,6,5,4,3,2,1', kf_normalize_point_values($typo));
check('a correct list is left exactly as it is',
    kf_normalize_point_values('13,12,11,10,9,8,7,6,5,4,3,2,1') === '13,12,11,10,9,8,7,6,5,4,3,2,1');
check('normalising twice changes nothing more',
    kf_normalize_point_values(kf_normalize_point_values($typo)) === kf_normalize_point_values($typo));
check('spaces, semicolons, newlines and a trailing comma all separate values',
    kf_parse_point_values(" 3 ; 2\n1,") === [3,2,1], json_encode(kf_parse_point_values(" 3 ; 2\n1,")));
check('the order typed is kept, not sorted', kf_parse_point_values('5,1,3') === [5,1,3]);
check('duplicates are kept for the form to show', kf_parse_point_values('5,5') === [5,5]);
check('zero is dropped', kf_parse_point_values('0,4') === [4]);
check('an empty field stays empty, so the week falls back to 1..games',
    kf_parse_point_values('') === [] && kf_normalize_point_values('') === '');
check('text alone yields nothing', kf_parse_point_values('none') === []);

$picks = file_get_contents($ROOT . '/includes/kf-player-picks.php');
check('the picks page offers values through the shared parser, and saved picks are checked the same way',
    substr_count($picks, 'foreach (kf_parse_point_values(') === 2 && strpos($picks, "explode(',', \$point_values_raw)") === false,
    substr_count($picks, 'foreach (kf_parse_point_values(') . ' uses');
$setup = file_get_contents($ROOT . '/includes/kf-week-setup.php');
check('week setup normalises on save and when filling the field',
    strpos($setup, "kf_normalize_point_values( wp_unslash( \$_POST['point_values'] ) )") !== false
    && strpos($setup, '$point_values_val = kf_normalize_point_values($point_values_val);') !== false);
check('the running total in the form splits the same way the server does',
    strpos($setup, "pointsInput.value.split(/[^0-9]+/)") !== false);
$edit = file_get_contents($ROOT . '/includes/kf-edit-season.php');
check('league settings can correct the stored default',
    strpos($edit, 'name="default_point_values"') !== false
    && strpos($edit, "\$update_data['default_point_values'] = \$submitted_point_values;") !== false);
check('and an empty submission cannot wipe it',
    strpos($edit, "if (\$submitted_point_values !== '') {") !== false);

// ---------------------------------------------------------------------------------------------
echo "\nBPOW score swap\n";

$W = ['inserts' => [], 'bpow_stats' => ['wins' => 3, 'subtotal' => 91], 'week_update' => null];

class Db {
    public $prefix = 'edk_';
    function prepare($q, ...$a){ foreach ($a as $v) { $q = preg_replace('/%d|%s/', is_int($v) ? (string)$v : "'" . $v . "'", $q, 1); } return $q; }
    function query($q){ return true; }
    function get_var($q){
        if (stripos($q, 'SELECT status') !== false)             return 'published';
        if (stripos($q, 'bpow_winner_user_id') !== false)        return '7';   // get_var returns a string
        if (stripos($q, 'is_tiebreaker = 0') !== false && stripos($q, 'COUNT(*)') !== false) return '3';
        if (stripos($q, 'result IS NOT NULL') !== false)         return '4';
        if (stripos($q, 'SELECT p.pick') !== false)              return '50';
        return null;
    }
    function get_col($q){ return ['7', '8']; }                                 // get_col returns strings
    function get_row($q){
        global $W;
        if (stripos($q, 'is_tiebreaker = 1') !== false && stripos($q, 'SELECT id, result') !== false) {
            return (object)['id' => 99, 'result' => '52'];
        }
        if (stripos($q, 'mwow_bonus_points') !== false) {
            return (object)['id' => 10, 'week_number' => 3, 'season_id' => 1, 'mwow_bonus_points' => 5];
        }
        if (stripos($q, 'FROM edk_weeks WHERE id') !== false) {
            return (object)['id' => 10, 'week_number' => 3, 'season_id' => 1, 'status' => 'published'];
        }
        if (stripos($q, 'p.is_bpow = 1') !== false) {
            return (object)['wins' => $W['bpow_stats']['wins'], 'subtotal' => $W['bpow_stats']['subtotal']];
        }
        if (stripos($q, 'p.is_bpow = 0') !== false) {
            // Player 7 scores 85 on their regular picks; player 8 scores 87 with the most wins.
            return stripos($q, "user_id = '7'") !== false
                ? (object)['wins' => 2, 'subtotal' => 85]
                : (object)['wins' => 4, 'subtotal' => 87];
        }
        return null;
    }
    function delete($t, $w){ return 1; }
    function insert($t, $d){ global $W; $W['inserts'][(int)$d['user_id']] = $d; return 1; }
    function update($t, $d, $w){ global $W; if (isset($d['status'])) { $W['week_update'] = $d; } return 1; }
}
$GLOBALS['wpdb'] = new Db();
require $ROOT . '/includes/kf-team-names.php';
require $ROOT . '/includes/kf-scoring-engine.php';
check('the double down engine was not pulled into this test', !function_exists('kf_apply_double_down_for_week'));

$stats = _kf_calculate_player_stats_for_week(10);
check('the BPOW winner\'s second set of picks is read at all',
    !empty($stats[7]['bpow_stats_for_score']), json_encode($stats[7]['bpow_stats_for_score'] ?? null));
check('with its own wins and subtotal',
    ($stats[7]['bpow_stats_for_score']['subtotal'] ?? null) === 91 && ($stats[7]['bpow_stats_for_score']['wins'] ?? null) === 3);
check('nobody else gets a second set', empty($stats[8]['bpow_stats_for_score']));
// The bug itself, stated as a test: this is the comparison the engine used until 1.8.25.
check('the OLD comparison is what discarded the higher score', ('7' === 7) === false);

$r = kf_finalize_week_logic(10);
check('the week finalized', $r === true, var_export($r, true));
check('the higher BPOW subtotal becomes the week score',
    ($W['inserts'][7]['score'] ?? null) === 91, json_encode($W['inserts'][7] ?? null));
check('and is recorded as having come from the BPOW picks',
    ($W['inserts'][7]['is_bpow_score'] ?? null) === 1 && ($W['inserts'][7]['subtotal'] ?? null) === 91
    && ($W['inserts'][7]['wins'] ?? null) === 3);
check('the Most Wins bonus does not stack on top of it',
    ($W['inserts'][7]['mwow_bonus_awarded'] ?? null) === 0);
check('everyone else is scored exactly as before',
    ($W['inserts'][8]['score'] ?? null) === 92 && ($W['inserts'][8]['is_bpow_score'] ?? null) === 0,
    json_encode($W['inserts'][8] ?? null));

// A lower second set must not touch the score.
$W['inserts'] = []; $W['bpow_stats'] = ['wins' => 1, 'subtotal' => 70];
kf_finalize_week_logic(10);
check('a lower BPOW subtotal is ignored',
    ($W['inserts'][7]['score'] ?? null) === 85 && ($W['inserts'][7]['is_bpow_score'] ?? null) === 0
    && ($W['inserts'][7]['wins'] ?? null) === 2, json_encode($W['inserts'][7] ?? null));

// Equal is not higher: the regular picks keep it.
$W['inserts'] = []; $W['bpow_stats'] = ['wins' => 9, 'subtotal' => 85];
kf_finalize_week_logic(10);
check('an equal BPOW subtotal is ignored', ($W['inserts'][7]['is_bpow_score'] ?? null) === 0);

// A player with no second set of picks at all scores 0 on it, which must never replace their week.
$W['inserts'] = []; $W['bpow_stats'] = ['wins' => 0, 'subtotal' => 0];
kf_finalize_week_logic(10);
check('an unplayed second set cannot replace a real score',
    ($W['inserts'][7]['score'] ?? null) === 85 && ($W['inserts'][7]['is_bpow_score'] ?? null) === 0);

echo "\nWeek summary column\n";
$sum = file_get_contents($ROOT . '/includes/kf-week-summary-view.php');
check('no cell in the BPOW column prints the player\'s own score row any more',
    strpos($sum, "esc_html(\$finalized_scores[\$last_week_bpow_winner_id]") === false);
check('all five of them print what the BPOW picks are worth instead',
    substr_count($sum, '$bpow_column_totals[') === 5, substr_count($sum, '$bpow_column_totals[') . ' uses');
check('the stored score wins when those picks are what counted',
    strpos($sum, "\$bpow_score_counted = !empty(\$finalized_scores[\$last_week_bpow_winner_id]['is_bpow_score']);") !== false);
check('a player whose BPOW picks counted is marked as such',
    substr_count($sum, "from BPOW picks") === 2);
check('and the rule is explained on the page', strpos($sum, 'becomes their week total') !== false);

// ---------------------------------------------------------------------------------------------
echo "\nScan for weeks that were scored with the bug\n";

$S = ['weeks' => [], 'bpow' => [], 'scores' => []];
function get_userdata($id){ return (object)['display_name' => 'Player ' . $id]; }

class ScanDb {
    public $prefix = 'edk_';
    function prepare($q, ...$a){ foreach ($a as $v) { $q = preg_replace('/%d|%s/', is_int($v) ? (string)$v : "'" . $v . "'", $q, 1); } return $q; }
    function get_results($q){ global $S; return $S['weeks']; }
    function get_row($q){
        global $S;
        preg_match('/week_id = (\d+)/', $q, $m);
        $wid = (int)($m[1] ?? 0);
        if (stripos($q, 'is_bpow = 1') !== false) {
            return (object)['subtotal' => $S['bpow'][$wid] ?? null];
        }
        if (stripos($q, 'FROM edk_scores') !== false) {
            return isset($S['scores'][$wid]) ? (object)$S['scores'][$wid] : null;
        }
        return null;
    }
}
$GLOBALS['wpdb'] = new ScanDb();

// Two leagues. In league 1, week 2's BPOW player (the week 1 winner) scored 85 with a second set
// worth 91 — the week that needs rescoring. Week 3 already counted its BPOW set, week 4's player
// played no second set, and week 5's was worth less than their regular picks.
$S['weeks'] = [
    (object)['id'=>11,'season_id'=>1,'week_number'=>1,'bpow_winner_user_id'=>7,'season_name'=>'COLLEGE 2026'],
    (object)['id'=>12,'season_id'=>1,'week_number'=>2,'bpow_winner_user_id'=>8,'season_name'=>'COLLEGE 2026'],
    (object)['id'=>13,'season_id'=>1,'week_number'=>3,'bpow_winner_user_id'=>8,'season_name'=>'COLLEGE 2026'],
    (object)['id'=>14,'season_id'=>1,'week_number'=>4,'bpow_winner_user_id'=>8,'season_name'=>'COLLEGE 2026'],
    (object)['id'=>15,'season_id'=>1,'week_number'=>5,'bpow_winner_user_id'=>8,'season_name'=>'COLLEGE 2026'],
    (object)['id'=>21,'season_id'=>2,'week_number'=>1,'bpow_winner_user_id'=>9,'season_name'=>'PRO 2026'],
];
$S['bpow']   = [12 => 91, 13 => 80, 14 => null, 15 => 40, 21 => 99];
$S['scores'] = [
    12 => ['score' => 85, 'is_bpow_score' => 0],
    13 => ['score' => 80, 'is_bpow_score' => 1],
    14 => ['score' => 70, 'is_bpow_score' => 0],
    15 => ['score' => 88, 'is_bpow_score' => 0],
    21 => ['score' => 60, 'is_bpow_score' => 0],
];
$scan = kf_find_weeks_with_discarded_bpow_scores();
check('the week whose higher BPOW set was thrown away is listed',
    array_keys($scan['weeks']) === [12], json_encode(array_keys($scan['weeks'])));
check('with both numbers, so the change can be checked before rescoring',
    ($scan['weeks'][12]['stored'] ?? null) === 85 && ($scan['weeks'][12]['bpow'] ?? null) === 91
    && ($scan['weeks'][12]['week_number'] ?? null) === 2 && ($scan['weeks'][12]['season'] ?? null) === 'COLLEGE 2026',
    json_encode($scan['weeks'][12] ?? null));
check('a week already scored from its BPOW set is left alone', !isset($scan['weeks'][13]));
check('a player who played no second set is not flagged', !isset($scan['weeks'][14]));
check('a lower second set is not flagged', !isset($scan['weeks'][15]));
check('the first week of a league has no second set to miss', !isset($scan['weeks'][11]));
check('one league does not inherit another league\'s BPOW winner', !isset($scan['weeks'][21]));

$dash = file_get_contents($ROOT . '/includes/kf-commissioner-dashboard.php');
check('the dashboard shows the list, with its own dismiss',
    strpos($dash, "get_option( 'kf_bpow_rescore_report', [] )") !== false
    && substr_count($dash, "wp_nonce_field( 'kf_dismiss_bpow_report', 'kf_dismiss_bpow_nonce' )") === 1
    && strpos($dash, "wp_verify_nonce( sanitize_text_field( wp_unslash( \$_POST['kf_dismiss_bpow_nonce'] ) ), 'kf_dismiss_bpow_report' )") !== false);
check('nothing is rescored behind the commissioner\'s back',
    strpos(file_get_contents($ROOT . '/includes/kf-scoring-engine.php'), 'kf_finalize_week_logic( (int) $week->id') === false);

echo "\n" . ($fails ? "$fails FAILED\n" : "ALL PASS\n");
exit($fails ? 1 : 0);
