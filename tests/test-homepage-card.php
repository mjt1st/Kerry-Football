<?php
// 1.8.26: where the Season Summary / Week N Summary links sit on a homepage season card.
// They render for every viewer, but trailing the card they appeared under the "Admin View"
// heading on a commissioner's card, so they read as commissioner tools. They now sit in the
// player's own section, and only fall back to the foot of the card for a commissioner who does
// not play that league.
// Usage: php tests/test-homepage-card.php <plugin-root>
[, $ROOT] = $argv;
define('ABSPATH', __DIR__ . '/');
error_reporting(E_ALL & ~E_WARNING & ~E_NOTICE);

$fails = 0;
function check($l, $c, $d = ''){ global $fails; if ($c) echo "  PASS  $l\n"; else { $fails++; echo "  FAIL  $l" . ($d ? "\n        $d" : '') . "\n"; } }

foreach ([
 'esc_html'=>'return htmlspecialchars((string)$a[0], ENT_QUOTES, "UTF-8");',
 'esc_attr'=>'return htmlspecialchars((string)$a[0], ENT_QUOTES, "UTF-8");',
 'esc_url'=>'return (string)$a[0];',
 'site_url'=>'return "https://kf.test" . ($a[0] ?? "");',
 'add_shortcode'=>'return true;','add_action'=>'return true;','add_filter'=>'return true;',
 'is_user_logged_in'=>'return true;','wp_get_current_user'=>'return (object)["ID"=>1,"display_name"=>"Kerry"];',
 'get_current_user_id'=>'return 1;','current_user_can'=>'return false;',
 'kf_league_url'=>'return $a[0] . (strpos($a[0], "?") === false ? "?" : "&") . "season_id=" . (int)$a[1];',
 'kf_can_manage_season'=>'return false;','kf_page_season_id'=>'return 0;',
 'wp_date'=>'return "Fri, Sep 25 5:00 PM ET";','get_gmt_from_date'=>'return $a[0];',
] as $n=>$b) { if (!function_exists($n)) eval("function $n(...\$a){ $b }"); }

require $ROOT . '/includes/kf-homepage.php';

$season = (object)['id' => 3, 'name' => 'COLLEGE 2026', 'is_active' => 1];
$week   = (object)['id' => 44, 'week_number' => 4, 'status' => 'published', 'submission_deadline' => '2026-09-25 21:00:00'];

function card($is_commissioner, $is_player, $season, $week, $published = true) {
    return kf_render_season_card($season, $is_commissioner, [
        'current_week_published' => $published ? $week : null,
        'latest_week_any'        => $week,
        'summary_week'           => $week,
        'is_also_player'         => $is_player,
        'picks_submitted'        => false,
        'rank'                   => 1,
        'player_count'           => 10,
        'total_score'            => 297,
    ]);
}

$season_link = 'Season Summary';
$week_link   = 'Week 4 Summary';

echo "A player who does not run the league\n";
$html = card(false, true, $season, $week);
check('sees the season summary link', substr_count($html, $season_link) === 1, substr_count($html, $season_link) . ' found');
check('sees the week summary link', substr_count($html, $week_link) === 1);
check('and both point at this league', substr_count($html, 'season_id=3') >= 2);
check('with no admin section on the card', strpos($html, 'Admin View') === false);

echo "\nA commissioner who also plays\n";
$html = card(true, true, $season, $week);
check('gets exactly one of each link', substr_count($html, $season_link) === 1 && substr_count($html, $week_link) === 1);
check('and they sit in the player section, above Admin View',
    strpos($html, $week_link) < strpos($html, 'Admin View'),
    'links at ' . strpos($html, $week_link) . ', Admin View at ' . strpos($html, 'Admin View'));
check('below the player\'s own button, not before it',
    strpos($html, 'Make Your Picks') < strpos($html, $week_link));
check('the admin links are still their own group',
    strpos($html, 'Manage Players') !== false && strpos($html, 'Edit Season') !== false);

echo "\nA commissioner who does not play this league\n";
$html = card(true, false, $season, $week);
check('still gets one of each link', substr_count($html, $season_link) === 1 && substr_count($html, $week_link) === 1);
check('after the admin block, the only section they have',
    strpos($html, 'Admin View') === false && strpos($html, 'Manage Players') < strpos($html, $week_link));

echo "\nBefore a week is published\n";
// The player's button is itself the season summary then, so the card must not offer it twice.
$html = card(false, true, $season, $week, false);
check('the season summary is not offered twice', substr_count($html, $season_link) === 0);
check('the button is the way through', strpos($html, 'View Summary') !== false);
check('the week summary link is still there', substr_count($html, $week_link) === 1);

echo "\n" . ($fails ? "$fails FAILED\n" : "ALL PASS\n");
exit($fails ? 1 : 0);
