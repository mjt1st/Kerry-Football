<?php
/**
 * Shortcode handler for the main "Player Dashboard" landing page.
 *
 * @package Kerry_Football
 * - NEW: Adds a "Commissioner Tools" section with a player dropdown to quickly access any player's picks for the upcoming week.
 * - Updates the "season complete" message to be shown only if season is_active = 0.
 * - If is_active = 1 and no published weeks, show "All weeks finalized...".
 * * TIMEZONE FIX (V2.0):
 * - MODIFIED: The "Next Up" week's deadline display now uses PHP DateTime objects to correctly format the deadline in the site's local time with the proper abbreviation (e.g., EDT).
 */

if (!function_exists('ordinal')) {
    function ordinal($number) {
        if (!is_numeric($number) || $number < 1) return $number;
        $ends = ['th','st','nd','rd','th','th','th','th','th','th'];
        if ((($number % 100) >= 11) && (($number % 100) <= 13)) return $number . 'th';
        return $number . $ends[$number % 10];
    }
}

function kf_player_dashboard_view() {
    if (!is_user_logged_in()) { return '<div class="kf-container"><p>You must be logged in to view this page.</p></div>'; }
    if (session_status() === PHP_SESSION_NONE) { session_start(); }

    global $wpdb;
    $user_id = get_current_user_id();
    $season_id = $_SESSION['kf_active_season_id'] ?? 0;

    if (!$season_id) {
        return '<div class="kf-container"><h1>Player Dashboard</h1><p>Please select a season from the main menu to view your dashboard.</p></div>';
    }

    $active_season = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}seasons WHERE id = %d", $season_id));
    if (!$active_season) {
        return '<div class="kf-container"><p>The selected season could not be found.</p></div>';
    }

    $is_commissioner = current_user_can('manage_options');
    $player_info = get_userdata($user_id);
    $is_active_player = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}season_players WHERE user_id = %d AND season_id = %d AND status = 'accepted'", $user_id, $season_id));

    // --- Data Fetching & Calculations ---
    $action_week = null;
    $has_submitted_action_week = false;
    $player_total_score = 0;
    $player_season_rank = '-';
    $player_ranks = [];
    $all_season_players = []; // For commissioner dropdown

    // Get the next active week
    $action_week = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}weeks WHERE season_id = %d AND status = 'published' ORDER BY week_number ASC LIMIT 1", $season_id));

    if ($is_commissioner) {
        $all_season_players = $wpdb->get_results($wpdb->prepare("SELECT u.ID, u.display_name FROM {$wpdb->prefix}users u JOIN {$wpdb->prefix}season_players sp ON u.ID = sp.user_id WHERE sp.season_id = %d AND sp.status = 'accepted' ORDER BY u.display_name ASC", $season_id));
    }
    
    if ($is_active_player) {
        if ($action_week) {
            $has_submitted_action_week = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}picks WHERE user_id = %d AND week_id = %d LIMIT 1", $user_id, $action_week->id));
        }

        $all_player_totals = $wpdb->get_results($wpdb->prepare("SELECT user_id, SUM(score) as total_score FROM {$wpdb->prefix}scores s JOIN {$wpdb->prefix}weeks w ON s.week_id = w.id WHERE w.season_id = %d GROUP BY user_id ORDER BY total_score DESC", $season_id));
        if ($all_player_totals) {
            $rank = 0; $last_score = -1; $players_at_rank = 1;
            foreach ($all_player_totals as $player_total) {
                if ($player_total->total_score !== $last_score) { $rank += $players_at_rank; $players_at_rank = 1; } else { $players_at_rank++; }
                if ($player_total->user_id == $user_id) {
                    $player_total_score = $player_total->total_score;
                    $player_season_rank = ordinal($rank);
                    break;
                }
                $last_score = $player_total->total_score;
            }
        }
    }
    
    $all_weeks = $wpdb->get_results($wpdb->prepare("SELECT id, week_number, status FROM {$wpdb->prefix}weeks WHERE season_id = %d AND status != 'draft' ORDER BY week_number ASC", $season_id));
    
    $weeks_with_results = $wpdb->get_col($wpdb->prepare("SELECT DISTINCT week_id FROM {$wpdb->prefix}matchups WHERE week_id IN (SELECT id FROM {$wpdb->prefix}weeks WHERE season_id = %d) AND result IS NOT NULL AND result != ''", $season_id));
    $weeks_with_results = array_flip($weeks_with_results);
    $player_scores_results = $wpdb->get_results($wpdb->prepare("SELECT week_id, score FROM {$wpdb->prefix}scores WHERE user_id = %d AND week_id IN (SELECT id FROM {$wpdb->prefix}weeks WHERE season_id = %d)", $user_id, $season_id), OBJECT_K);
    $player_scores = [];
    foreach($player_scores_results as $week_id_key => $data) { $player_scores[$week_id_key] = $data->score; }
    
    $submitted_weeks_results = $wpdb->get_col($wpdb->prepare("SELECT DISTINCT week_id FROM {$wpdb->prefix}picks WHERE user_id = %d", $user_id));
    $submitted_weeks = array_flip($submitted_weeks_results);

    $finalized_week_ids = wp_list_pluck(array_filter($all_weeks, fn($w) => $w->status === 'finalized'), 'id');
    if ($is_active_player && !empty($finalized_week_ids)) {
        $placeholders = implode(',', array_fill(0, count($finalized_week_ids), '%d'));
        $all_scores_for_ranking = $wpdb->get_results( $wpdb->prepare( "SELECT week_id, user_id, score FROM {$wpdb->prefix}scores WHERE week_id IN ($placeholders) ORDER BY week_id, score DESC", $finalized_week_ids ) );
        
        $scores_by_week = [];
        if (is_array($all_scores_for_ranking)) {
            foreach ($all_scores_for_ranking as $score_data) { $scores_by_week[$score_data->week_id][] = $score_data; }
        }

        foreach ($scores_by_week as $week_id_key => $scores) {
            $rank = 0; $last_score = -1; $players_at_rank = 1;
            foreach ($scores as $score_data) {
                if ($score_data->score !== $last_score) { $rank += $players_at_rank; $players_at_rank = 1; } else { $players_at_rank++; }
                if ($score_data->user_id == $user_id) { $player_ranks[$week_id_key] = $rank; break; }
                $last_score = $score_data->score;
            }
        }
    }

    ob_start();
    ?>
    <div class="kf-container">
        <div class="kf-dashboard-header">
            <h1><?php echo esc_html($player_info->display_name); ?>'s Dashboard</h1>
            <?php if ($is_active_player): ?>
                <div class="kf-subheader-stats">
                    <span><strong>Season Score:</strong> <?php echo $player_total_score; ?></span>
                    <span class="kf-stat-separator">|</span>
                    <span><strong>Overall Rank:</strong> <?php echo $player_season_rank; ?></span>
                </div>
            <?php endif; ?>
        </div>

        <?php // --- NEW: Commissioner Tools section --- ?>
        <?php if ($is_commissioner && !empty($all_season_players)): ?>
            <div class="kf-card" style="margin-bottom: 2em; background-color: #f0f6fc;">
                <h3 style="margin-top:0; border-bottom:1px solid #c8e1ff; padding-bottom:0.5em;">Commissioner Tools</h3>
                <?php if ($action_week): ?>
                    <form id="kf-commish-view-as-form" class="kf-view-as-form" style="display:flex; align-items:center; gap:10px; margin-top:1em;">
                        <label for="kf-commish-player-select"><strong>Edit another player's picks for Week <?php echo esc_html($action_week->week_number); ?>:</strong></label>
                        <select id="kf-commish-player-select" name="view_as">
                            <option value="">-- Select Player --</option>
                            <?php foreach ($all_season_players as $player): ?>
                                <option value="<?php echo esc_attr($player->ID); ?>"><?php echo esc_html($player->display_name); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </form>
                    <script>
                    document.getElementById('kf-commish-player-select').addEventListener('change', function() {
                        if (this.value) {
                            const weekId = <?php echo esc_js($action_week->id); ?>;
                            const userId = this.value;
                            const url = `<?php echo esc_url(site_url('/my-picks/')); ?>?week_id=${weekId}&view_as=${userId}`;
                            window.location.href = url;
                        }
                    });
                    </script>
                <?php else: ?>
                    <p>There are no open weeks to edit picks for.</p>
                <?php endif; ?>
            </div>
        <?php endif; ?>
        
        <?php if ($is_active_player): ?>
            <?php if ($active_season->is_active == 0): ?>
                <div class="kf-card"><h3 style="margin-top:0;">The season is complete!</h3></div>
            <?php elseif ($action_week): ?>
                <div class="kf-card kf-action-card">
                    <div class="kf-action-card-info">
                        <h3>Next Up: Week <?php echo esc_html($action_week->week_number); ?></h3>
                        <?php
                        // --- TIMEZONE FIX ---
                        // Convert the UTC time from the DB to the site's local timezone for display.
                        $deadline_formatted = 'N/A';
                        if ($action_week->submission_deadline) {
                            try {
                                $utc_dt = new DateTime($action_week->submission_deadline, new DateTimeZone('UTC'));
                                $site_tz = new DateTimeZone(wp_timezone_string());
                                $site_dt = $utc_dt->setTimezone($site_tz);
                                // A slightly different format for this card view
                                $deadline_formatted = $site_dt->format('l, M j @ g:i A T');
                            } catch (Exception $e) {
                                // Fallback
                                $deadline_formatted = date("l, M j @ g:i A T", strtotime($action_week->submission_deadline));
                            }
                        }
                        ?>
                        <p><strong>Deadline:</strong> <?php echo esc_html($deadline_formatted); ?></p>
                        <div class="kf-action-card-status">
                            <?php if($has_submitted_action_week): ?>
                                <span class="kf-status-submitted">&#10004; Picks Submitted</span>
                            <?php else: ?>
                                <span class="kf-status-pending">&#10008; Awaiting Picks</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="kf-action-card-button">
                       <a href="<?php echo esc_url(add_query_arg(['week_id' => $action_week->id], site_url('/my-picks/'))); ?>" class="kf-button">Make / Edit Picks</a>
                    </div>
                </div>
            <?php else: ?>
                <div class="kf-card"><h3 style="margin-top:0;">All weeks finalized. Awaiting commissioner to archive the season.</h3></div>
            <?php endif; ?>
        <?php elseif ($is_commissioner): ?>
            <div class="notice notice-info"><p><strong>Commissioner View:</strong> You are not an active player in this season.</p></div>
        <?php endif; ?>

        <h3>Season History</h3>
        <div class="kf-table-wrapper">
            <table class="kf-table">
                <thead><tr><th>Week</th><th>Your Score</th><th>Weekly Rank</th><th>Status</th></tr></thead>
                <tbody>
                    <?php if (empty($all_weeks)): ?>
                        <tr><td colspan="4">No published weeks are available to display.</td></tr>
                    <?php else: ?>
                        <?php foreach($all_weeks as $week): ?>
                            <tr>
                                <td>
                                    <?php
                                    if ($week->status !== 'draft') {
                                        echo '<a href="' . esc_url(add_query_arg(['week_id' => $week->id], site_url('/my-picks/'))) . '">Week ' . esc_html($week->week_number) . '</a>';
                                    } else {
                                        echo 'Week ' . esc_html($week->week_number);
                                    }
                                    ?>
                                </td>
                                <td class="<?php echo $week->status !== 'finalized' ? 'kf-score-pending' : ''; ?>">
                                    <?php echo $player_scores[$week->id] ?? '-'; ?>
                                </td>
                                <td><?php echo (isset($player_ranks[$week->id]) && is_numeric($player_ranks[$week->id])) ? ordinal($player_ranks[$week->id]) : '-'; ?></td>
                                <td>
                                    <?php
                                    if ($week->status === 'finalized') {
                                        echo '<span class="kf-status-finalized">Finalized</span>';
                                    } elseif (!isset($submitted_weeks[$week->id])) {
                                        echo '<span class="kf-status-pending">Awaiting Picks</span>';
                                    } elseif (isset($weeks_with_results[$week->id])) {
                                        echo '<span class="kf-status-in-progress">Results Pending</span>';
                                    } else {
                                        echo '<span class="kf-status-submitted">Picks Submitted</span>';
                                    }
                                    ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php
    return ob_get_clean();
}