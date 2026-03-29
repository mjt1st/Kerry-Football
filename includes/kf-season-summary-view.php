<?php
/**
 * Shortcode handler for the Season Summary view.
 *
 * @package Kerry_Football
 * - MODIFICATION: When a commissioner uses "View As Player", week links now point to the player-specific "My Picks" page instead of the general "Week Summary".
 * * DD DISPLAY FIX (V2.3.0): Refined award display persistence: The "DD" selection marker now persists and is only replaced by score adjustment markers (strike-through) upon finalization.
 */

function kf_season_summary_view() {
    if (!is_user_logged_in()) return '<p>You must be logged in.</p>';
    if (session_status() === PHP_SESSION_NONE) { session_start(); }
    
    global $wpdb;
    $season_id = $_SESSION['kf_active_season_id'] ?? 0;

    if (!$season_id) return '<div class="kf-container"><h1>Season Summary</h1><p>Please select a season from the main menu to begin.</p></div>';
    
    $current_user_id = get_current_user_id();
    $is_commissioner = kf_can_manage_season($season_id);

    $season = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}seasons WHERE id = %d", $season_id));
    if (!$season) {
        unset($_SESSION['kf_active_season_id']);
        return '<div class="kf-container"><p>The selected season could not be found. Please select another season.</p></div>';
    }

    $all_season_players_results = $wpdb->get_results($wpdb->prepare(
        "SELECT u.ID, u.display_name 
         FROM {$wpdb->prefix}users u 
         JOIN {$wpdb->prefix}season_players sp ON u.ID = sp.user_id 
         LEFT JOIN {$wpdb->prefix}season_player_order spo ON u.ID = spo.user_id AND sp.season_id = spo.season_id
         WHERE sp.season_id = %d AND sp.status = 'accepted' 
         ORDER BY spo.display_order ASC, u.display_name ASC", 
        $season_id
    ));
    
    $all_season_players = [];
    foreach($all_season_players_results as $player) {
        $all_season_players[$player->ID] = $player->display_name;
    }

    $view_as_user_id = null;
    $is_viewing_as_other = false;
    if ($is_commissioner && isset($_GET['view_as']) && is_numeric($_GET['view_as'])) {
        $selected_user_id = intval($_GET['view_as']);
        if (isset($all_season_players[$selected_user_id])) {
            $view_as_user_id = $selected_user_id;
            $is_viewing_as_other = true;
        }
    }
    
    $players_to_display = $is_viewing_as_other ? [$view_as_user_id => $all_season_players[$view_as_user_id]] : $all_season_players;
    
    // --- Week and Deadline Data Fetching ---
    $all_weeks_in_season = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$wpdb->prefix}weeks WHERE season_id = %d AND status != 'draft' ORDER BY week_number ASC", $season_id));
    $all_week_ids_in_season = wp_list_pluck($all_weeks_in_season, 'id');
    $all_week_numbers_map = wp_list_pluck($all_weeks_in_season, 'week_number', 'id');
    
    $current_time_gmt = current_time('mysql', 1);
    $week_deadlines_passed = [];
    foreach ($all_weeks_in_season as $week_check) {
        if (!empty($week_check->submission_deadline) && $week_check->submission_deadline !== '0000-00-00 00:00:00') {
            $week_deadlines_passed[$week_check->id] = ($current_time_gmt >= $week_check->submission_deadline);
        } else {
            $week_deadlines_passed[$week_check->id] = false;
        }
    }

    // --- Fetch DD Selections (made by player) ---
    $dd_selection_map = [];
    $dd_selections = $wpdb->get_results($wpdb->prepare("SELECT user_id, week_id FROM {$wpdb->prefix}dd_selections WHERE season_id = %d", $season_id));
    foreach ($dd_selections as $sel) {
        $dd_selection_map[$sel->user_id][$sel->week_id] = true;
    }
    
    // --- Existing DD Log (Finalized Scores) ---
    $dd_logs = [];
    if (!empty($all_week_ids_in_season)) {
        $week_id_placeholders = implode(',', array_fill(0, count($all_week_ids_in_season), '%d'));
        $dd_logs = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}score_history WHERE week_id IN ($week_id_placeholders) OR replaced_by_week_id IN ($week_id_placeholders)",
            array_merge($all_week_ids_in_season, $all_week_ids_in_season)
        ));
    }
    
    $dd_source_map = [];
    $dd_target_map = [];
    foreach ($dd_logs as $log) {
        $source_week_num = $all_week_numbers_map[$log->replaced_by_week_id] ?? '?';
        $target_week_num = $all_week_numbers_map[$log->week_id] ?? '?';

        $dd_source_map[$log->user_id][$log->replaced_by_week_id] = 'Double Down: This score replaced the score from Week ' . $target_week_num . '.';
        $dd_target_map[$log->user_id][$log->week_id] = [
            'tooltip' => 'Score Replaced: This score was replaced by the Double Down from Week ' . $source_week_num . '.',
            'original_score' => $log->original_score
        ];
    }
    
    $scores_results = $wpdb->get_results($wpdb->prepare("SELECT s.user_id, s.week_id, s.score FROM {$wpdb->prefix}scores s JOIN {$wpdb->prefix}weeks w ON s.week_id = w.id WHERE w.season_id = %d", $season_id));
    $scores_map = [];
    foreach($scores_results as $result) { $scores_map[$result->week_id][$result->user_id] = $result->score; }
    
    $season_totals = [];
    foreach ($players_to_display as $player_id => $player_name) { $season_totals[$player_id] = 0; }
    foreach ($all_weeks_in_season as $week) {
        if ($week->status !== 'finalized') continue;
        foreach ($players_to_display as $player_id => $player_name) {
            $score = $scores_map[$week->id][$player_id] ?? 0;
            $season_totals[$player_id] += $score;
        }
    }

    $sorted_totals = $season_totals;
    arsort($sorted_totals);
    $ranks = [];
    $rank = 1;
    $prev_total = null;
    $count_at_rank = 0;
    foreach ($sorted_totals as $player_id => $total) {
        if ($total !== $prev_total) {
            $rank += $count_at_rank;
            $count_at_rank = 1;
        } else {
            $count_at_rank++;
        }
        $ranks[$player_id] = ordinal($rank);
        $prev_total = $total;
    }

    ob_start();
    ?>
    <div class="kf-container">
        <div class="kf-breadcrumbs">
            <a href="<?php echo esc_url(site_url('/')); ?>">Homepage</a> &raquo;
            <span>Season Summary</span>
        </div>
        <h1>Season Summary</h1>
        <h2 class="kf-page-subtitle"><?php echo esc_html($season->name); ?></h2>
        
        <?php if ($is_commissioner): ?>
        <div class="kf-action-bar">
            <div class="kf-action-group">
                <form method="GET" class="kf-view-as-form">
                    <label for="view_as_select">View As Player:</label>
                    <select id="view_as_select" name="view_as" onchange="this.form.submit()">
                        <option value="">-- Show All Players --</option>
                        <?php foreach ($all_season_players as $player_id => $player_name): ?>
                            <option value="<?php echo esc_attr($player_id); ?>" <?php selected($view_as_user_id, $player_id); ?>><?php echo esc_html($player_name); ?></option>
                        <?php endforeach; ?>
                    </select>
                </form>
            </div>
            <div class="kf-action-group">
                <a href="<?php echo esc_url(site_url('/manage-weeks/')); ?>" class="kf-button kf-button-action">Manage All Weeks</a>
            </div>
        </div>
        <?php endif; ?>

        <div class="kf-table-wrapper">
            <table class="kf-table">
                <thead>
                    <tr>
                        <th>Week</th>
                        <th>Status</th>
                        <?php foreach ($players_to_display as $uid => $name): ?>
                            <th style="text-align:center;"><?php echo esc_html($name); ?></th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($all_weeks_in_season)): ?>
                        <tr><td colspan="<?php echo 2 + count($players_to_display); ?>">No published weeks are available to display.</td></tr>
                    <?php else: ?>
                        <?php foreach ($all_weeks_in_season as $week): ?>
                            <tr>
                                <td>
                                    <?php
                                    // MODIFICATION: If viewing as another player, link to their picks page. Otherwise, link to the general week summary.
                                    $link_args = ['week_id' => $week->id];
                                    $page_url = $is_viewing_as_other ? site_url('/my-picks/') : site_url('/week-summary/'); 
                                    if ($is_viewing_as_other) { $link_args['view_as'] = $view_as_user_id; }
                                    $link_url = add_query_arg($link_args, $page_url);
                                    ?>
                                    <a href="<?php echo esc_url($link_url); ?>">Week <?php echo $week->week_number; ?></a>
                                </td>
                                <td class="kf-status-cell">
                                    <?php echo esc_html(ucfirst($week->status)); ?>
                                </td>
                                <?php foreach ($players_to_display as $uid => $name): ?>
                                    <?php 
                                    $score = $scores_map[$week->id][$uid] ?? '-';
                                    $cell_class = '';
                                    $tooltip = '';
                                    $awards_html = '';
                                    $display_score = $score;

                                    $is_dd_selected = isset($dd_selection_map[$uid][$week->id]);
                                    $is_picks_locked = $week_deadlines_passed[$week->id] ?? false; // Is the submission deadline passed?
                                    
                                    // --- 1. HANDLE DD SELECTION/SUBMISSION STATUS (BASE) ---
                                    if ($is_dd_selected) {
                                        // DD selected, always show the marker regardless of finalization status,
                                        // UNLESS the score has been formally adjusted (handled below).
                                        $awards_html = ' <span class="kf-award-icon" title="Double Down Selected">DD</span>';
                                        $cell_class = 'kf-dd-involved';
                                        $tooltip = 'Double Down selected.';
                                    }

                                    // --- 2. HANDLE FINALIZED SCORE ADJUSTMENT (Overrides simple selection) ---
                                    if ($week->status === 'finalized') {
                                        // Check if score was replaced or was the source of a replacement
                                        if (isset($dd_source_map[$uid][$week->id])) {
                                            $cell_class = 'kf-dd-involved';
                                            $tooltip = $dd_source_map[$uid][$week->id];
                                            $awards_html = ' <span class="kf-award-icon" title="' . esc_attr($tooltip) . '">DD</span>'; // DD was applied this week
                                        } 
                                        elseif (isset($dd_target_map[$uid][$week->id])) {
                                            $cell_class = 'kf-dd-involved';
                                            $tooltip = $dd_target_map[$uid][$week->id]['tooltip'];
                                            $original_score = $dd_target_map[$uid][$week->id]['original_score'];
                                            $display_score = "<strike>$original_score</strike> $score";
                                            $awards_html = ''; // Score replaced, don't show simple DD, show strike-through/tooltip instead
                                        } else {
                                            // Ensure awards HTML is reset if neither source nor target applies, in case DD was selected but score was equal.
                                            $awards_html = '';
                                        }
                                        
                                        // --- Awards are only given on finalized weeks ---
                                        if ($week->bpow_winner_user_id == $uid) {
                                            $awards_html .= ' <span class="kf-award-icon" title="Best Player of the Week">🏆</span>';
                                        }
                                        if ($week->mwow_winner_user_id == $uid) {
                                            $awards_html .= ' <span class="kf-award-badge" title="Most Wins of the Week">MW</span>';
                                        }
                                    }
                                    
                                    // --- 3. Hide Picks if not finalized and not commissioner and deadline has not passed (redundant check, but safe) ---
                                    if (!$is_commissioner && $week->status !== 'finalized' && !$is_picks_locked) {
                                        $display_score = '-';
                                        $awards_html = '';
                                        $cell_class = '';
                                        $tooltip = 'Picks are still open.';
                                    }
                                    ?>
                                    <td style="text-align:center;" class="<?php echo esc_attr($cell_class); ?>" title="<?php echo esc_attr($tooltip); ?>">
                                        <?php echo wp_kses($display_score, ['strike' => []]); ?><?php echo $awards_html; ?>
                                    </td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                        <tr style="font-weight:bold; background-color: #f1f1f1;">
                            <td colspan="2">Total</td>
                            <?php foreach ($players_to_display as $uid => $name): ?>
                                <td style="text-align:center;">
                                    <?php echo esc_html($season_totals[$uid]); ?> <sup style="color: #FFD700;"><?php echo isset($ranks[$uid]) ? esc_html($ranks[$uid]) : '-'; ?></sup>
                                </td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
            <div class="kf-rank-legend" style="text-align: right; font-size: 0.9em; color: #555; margin-top: 10px;">
                <span style="color: #FFD700;">Gold Rank</span>: Indicates player ranking by season total score (highest to lowest).
            </div>
        </div>
    </div>
    <?php
    return ob_get_clean();
}

if (!function_exists('ordinal')) {
    function ordinal($number) {
        if (!is_numeric($number) || $number < 1) return $number;
        $ends = array('th','st','nd','rd','th','th','th','th','th','th');
        if ((($number % 100) >= 11) && (($number % 100) <= 13)) return $number . 'th';
        return $number . $ends[$number % 10];
    }
}