<?php
// 1.8.28: the three numbers the week summary shows while games are being played.
//   subtotal  — banked, from games that have a result (a tie is half, floored)
//   projected — subtotal plus every in-progress game the pick currently leads
//   possible  — subtotal plus every game still without a result: the most still reachable
// The arithmetic is inline in the shortcode, so it is lifted out of the real file and run here
// rather than reimplemented — if the block moves or is rewritten, the extraction fails loudly.
// Usage: php tests/test-live-totals.php <plugin-root>
[, $ROOT] = $argv;
define('ABSPATH', __DIR__ . '/');
error_reporting(E_ALL & ~E_WARNING & ~E_NOTICE);

$fails = 0;
function check($l, $c, $d = ''){ global $fails; if ($c) echo "  PASS  $l\n"; else { $fails++; echo "  FAIL  $l" . ($d ? "\n        $d" : '') . "\n"; } }

function add_action(...$a){ return true; }
require $ROOT . '/includes/kf-team-names.php';

$src = file_get_contents($ROOT . '/includes/kf-week-summary-view.php');
preg_match('/\$live_totals = array_fill_keys.*?\$week_has_open_game = false;/s', $src, $init);
preg_match('/\/\/ === LIVE TOTALS: TIE-AWARE ===.*?\$bpow_live_totals\[\'possible\'\]\s*\+= \$bpow_live_totals\[\'subtotal\'\];/s', $src, $calc);
if (empty($init[0]) || empty($calc[0])) {
    echo "  FAIL  could not lift the live-totals block out of kf-week-summary-view.php\n1 FAILED\n";
    exit(1);
}

function game($id, $a, $b, $result, $status = 'scheduled', $home = null, $away = null) {
    return (object)['id'=>$id, 'team_a'=>$a, 'team_b'=>$b, 'result'=>$result,
                    'game_status'=>$status, 'home_score'=>$home, 'away_score'=>$away];
}
function pick($team, $points) { return (object)['pick'=>$team, 'point_value'=>$points]; }

/** Run the real block over a fixture. */
function totals($regular_matchups, $players, $std_picks_map, $bpow_picks_map, $last_week_bpow_winner_id) {
    global $init, $calc;
    eval($init[0]);
    eval($calc[0]);
    return [$live_totals, $bpow_live_totals, $week_has_live_game, $week_has_open_game];
}

$players = [7 => 'Kerry', 8 => 'Saz'];

// Two banked games (one of them a tie), two in progress (one of them level), one not kicked off.
$games = [
    game(1, 'KC Chiefs',  'IND Colts',  'KC Chiefs'),                        // decided
    game(2, 'NO Saints',  'BAL Ravens', 'tie'),                              // decided, half points
    game(3, 'SF 49ers',   'MIA Dolphins', null, 'in_progress', 21, 7),       // 49ers (home) ahead
    game(4, 'DAL Cowboys','WAS Commanders', null, 'in_progress', 10, 10),    // level
    game(5, 'SEA Seahawks','ARI Cardinals', null, 'scheduled'),              // not kicked off
];
$std = [
    1 => [7 => pick('KC Chiefs', 10),    8 => pick('IND Colts', 10)],
    2 => [7 => pick('NO Saints', 7),     8 => pick('BAL Ravens', 6)],
    3 => [7 => pick('SF 49ers', 9),      8 => pick('MIA Dolphins', 9)],
    4 => [7 => pick('DAL Cowboys', 5),   8 => pick('DAL Cowboys', 5)],
    5 => [7 => pick('SEA Seahawks', 4),  8 => pick('SEA Seahawks', 4)],
];
// The BPOW player played only three of the five: the other two must count for nothing.
$bpow = [ 1 => pick('KC Chiefs', 12), 3 => pick('SF 49ers', 8), 5 => pick('SEA Seahawks', 6) ];

list($lt, $bt, $live, $open) = totals($games, $players, $std, $bpow, 7);

echo "A week in play\n";
check('banked points are the decided games, a tie counting half',
    $lt[7]['subtotal'] === 13 && $lt[8]['subtotal'] === 3, json_encode([$lt[7]['subtotal'], $lt[8]['subtotal']]));
check('projected adds the game they are currently leading',
    $lt[7]['projected'] === 22, (string)$lt[7]['projected']);
check('and adds nothing for the game they are currently losing',
    $lt[8]['projected'] === 3, (string)$lt[8]['projected']);
check('a level game counts for neither of them',
    $lt[7]['projected'] !== 27 && $lt[8]['projected'] !== 8);
check('still possible counts every undecided game as a win',
    $lt[7]['possible'] === 31 && $lt[8]['possible'] === 21, json_encode([$lt[7]['possible'], $lt[8]['possible']]));
check('which is never below what they have already banked',
    $lt[7]['possible'] >= $lt[7]['subtotal'] && $lt[8]['possible'] >= $lt[8]['subtotal']);
check('and never below what they are projected to finish on',
    $lt[7]['possible'] >= $lt[7]['projected'] && $lt[8]['possible'] >= $lt[8]['projected']);
check('wins still count decided games only', $lt[7]['wins'] === 1 && $lt[8]['wins'] === 0);
check('the week knows a game is in progress', $live === true);
check('and that something is still undecided', $open === true);

echo "\nThe BPOW column gets the same three numbers\n";
check('banked', $bt['subtotal'] === 12, (string)$bt['subtotal']);
check('projected', $bt['projected'] === 20, (string)$bt['projected']);
check('still possible', $bt['possible'] === 26, (string)$bt['possible']);
check('a game they made no pick on counts for nothing',
    $bt['possible'] === 26 && $bt['projected'] === 20);

echo "\nEvery game decided\n";
$all_done = [ game(1, 'KC Chiefs', 'IND Colts', 'KC Chiefs'), game(2, 'NO Saints', 'BAL Ravens', 'NO Saints') ];
$done_picks = [ 1 => [7 => pick('KC Chiefs', 10), 8 => pick('IND Colts', 10)],
                2 => [7 => pick('BAL Ravens', 7), 8 => pick('NO Saints', 7)] ];
list($lt2, $bt2, $live2, $open2) = totals($all_done, $players, $done_picks, [], 0);
check('nothing is in progress and nothing is open', $live2 === false && $open2 === false);
check('all three numbers agree once the week is over',
    $lt2[7]['subtotal'] === 10 && $lt2[7]['projected'] === 10 && $lt2[7]['possible'] === 10,
    json_encode($lt2[7]));

echo "\nBefore kick-off\n";
$not_started = [ game(1, 'KC Chiefs', 'IND Colts', null), game(2, 'NO Saints', 'BAL Ravens', null) ];
$ns_picks = [ 1 => [7 => pick('KC Chiefs', 10), 8 => pick('IND Colts', 10)],
              2 => [7 => pick('BAL Ravens', 7), 8 => pick('NO Saints', 7)] ];
list($lt3, $bt3, $live3, $open3) = totals($not_started, $players, $ns_picks, [], 0);
check('nothing is in progress yet, but the week is open', $live3 === false && $open3 === true);
check('everything is still on the table', $lt3[7]['possible'] === 17 && $lt3[7]['subtotal'] === 0);
check('and nobody is projected anything', $lt3[7]['projected'] === 0 && $lt3[8]['projected'] === 0);

echo "\nWhat the page shows\n";
check('"of N" is only printed while something is still reachable',
    substr_count($src, "['possible'] ?? 0) > (") === 1
    && strpos($src, "if (\$bpow_live_totals['possible'] > \$bpow_live_totals['subtotal'])") !== false);
check('the projected line appears only while a game is in progress',
    substr_count($src, '<?php if ($week_has_live_game): ?>') === 3, substr_count($src, '<?php if ($week_has_live_game): ?>') . ' uses');
check('both reach the BPOW column too', substr_count($src, "kf-live-projected") === 2);
check('the footer gets the same two rows, with a label cell and an empty Winner cell',
    strpos($src, "<td><strong>Projected</strong></td><td aria-hidden=\"true\"></td>") !== false
    && strpos($src, "<td><strong>Still Possible</strong></td><td aria-hidden=\"true\"></td>") !== false);
check('and the page says what they mean', strpos($src, 'the most still reachable') !== false);
check('none of it reaches a finished week',
    strpos($src, "<?php if (!\$is_finalized && \$week_has_open_game): ?>") !== false);

echo "\n" . ($fails ? "$fails FAILED\n" : "ALL PASS\n");
exit($fails ? 1 : 0);
