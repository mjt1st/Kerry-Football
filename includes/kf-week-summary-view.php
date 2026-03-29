<?php
/**
 * Shortcode handler for the Week Summary view.
 *
 * @package Kerry_Football
 * - NEW: Adds "Print" and "Export to CSV" buttons to the action bar.
 * - NEW: Includes custom print styles to format the table for printing.
 * - NEW: Adds JavaScript to handle the CSV export functionality.
 * * TIMEZONE FIX (V2.0):
 * - MODIFIED: The deadline display now correctly shows the site's local time.
 * * UX FIX (V2.0):
 * - MODIFIED: The tiebreaker matchup row is now always displayed last in the table.
 * * TIE SUPPORT (V2.1):
 * - Tie results award floor(points/2) and do NOT add wins.
 * - Tie cells render with light-blue styling and a dash.
 * * DD DISPLAY FIX (V2.3.4): Corrected DD display check and fixed Fatal PHP Error in query by enforcing full column notation.
 * * STABILITY FIX (V2.3.10): Final structural fix for the live scoring JS block to eliminate Parse Errors caused by mixing PHP conditionals and JS loops.
 */

function kf_week_summary_view() {
    if (!is_user_logged_in()) return '<p>You must be logged in.</p>';
    global $wpdb;

    $current_user_id = get_current_user_id();
    $is_commissioner = current_user_can('manage_options');
    $week_id = isset($_GET['week_id']) ? intval($_GET['week_id']) : 0;
    
    if (!$week_id) {
        return '<p>Invalid week. Please select a valid week from the Season Summary.</p>';
    }
    
    // --- MODIFICATION: Join with seasons table to get season name for export/print titles ---
    $weeks_table = $wpdb->prefix . 'weeks';
    $seasons_table = $wpdb->prefix . 'seasons';
    $week = $wpdb->get_row($wpdb->prepare(
        "SELECT w.*, s.name as season_name FROM $weeks_table w JOIN $seasons_table s ON w.season_id = s.id WHERE w.id = %d", 
        $week_id
    ));
    
    if (!$week || $week->season_id != ($_SESSION['kf_active_season_id'] ?? 0)) {
        return '<p>Week not found or does not belong to the active season. Please select a valid week from the Season Summary.</p>';
    }

    // --- Deadline and Pick Visibility Logic ---
    $deadline_passed = false;
    if (!empty($week->submission_deadline) && $week->submission_deadline !== '0000-00-00 00:00:00') {
        $current_time_gmt = current_time('mysql', 1);
        if ($current_time_gmt >= $week->submission_deadline) {
            $deadline_passed = true;
        }
    }
    // This is the controlling flag for pick visibility
    $picks_are_hidden = (!$is_commissioner && !$deadline_passed && $week->status !== 'finalized');
    
    $error_message = '';

    // --- Action Handler ---
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $is_commissioner && isset($_POST['kf_finalize_week_nonce_field']) && wp_verify_nonce($_POST['kf_finalize_week_nonce_field'], 'kf_finalize_week_nonce')) {
        if (isset($_POST['action']) && ($_POST['action'] === 'finalize_week' || $_POST['action'] === 'resolve_and_finalize')) {
            if (file_exists(plugin_dir_path(__FILE__) . 'kf-scoring-engine.php')) {
                require_once plugin_dir_path(__FILE__) . 'kf-scoring-engine.php';
            }
            
            $manual_winners = [
                'mwow' => isset($_POST['manual_mwow_winner']) ? intval($_POST['manual_mwow_winner']) : null,
                'bpow' => isset($_POST['manual_bpow_winner']) ? intval($_POST['manual_bpow_winner']) : null,
            ];

            $redirect_url = esc_url_raw(add_query_arg('week_id', $week_id, get_permalink()));
            try {
                // Ensure kf_finalize_week_logic is defined if it's supposed to be required above
                if (function_exists('kf_finalize_week_logic')) {
                    $result = kf_finalize_week_logic($week_id, $manual_winners);
                    if ($result === true) {
                        wp_redirect($redirect_url); 
                        exit;
                    } else {
                        $error_message = is_string($result) ? $result : 'An unknown error occurred.';
                    }
                } else {
                    $error_message = 'Scoring engine logic missing for finalization.';
                }
            } catch (Throwable $e) {
                $error_message = "A critical error occurred: " . $e->getMessage();
            }
        }
    }
    
    // --- Data Fetching ---
    $season_id = $week->season_id;
    
    // FETCH DD SELECTIONS: Check the dd_selections table
    $dd_selections = $wpdb->get_col($wpdb->prepare("SELECT user_id FROM {$wpdb->prefix}dd_selections WHERE week_id = %d", $week_id));
    $dd_source_users = $wpdb->get_col($wpdb->prepare("SELECT user_id FROM {$wpdb->prefix}score_history WHERE replaced_by_week_id = %d", $week_id));
    
    $dd_target_history = $wpdb->get_results($wpdb->prepare("SELECT user_id, original_score, replaced_by_week_id FROM {$wpdb->prefix}score_history WHERE week_id = %d", $week_id), OBJECT_K);
    $replaced_by_week_num = null;
    if (isset($dd_target_history[$current_user_id])) {
        $replaced_by_week_num = $wpdb->get_var($wpdb->prepare("SELECT week_number FROM {$wpdb->prefix}weeks WHERE id = %d", $dd_target_history[$current_user_id]->replaced_by_week_id));
    }

    $prev_week = $wpdb->get_row($wpdb->prepare("SELECT id FROM $weeks_table WHERE season_id = %d AND week_number < %d AND status != 'draft' ORDER BY week_number DESC LIMIT 1", $season_id, $week->week_number));
    $next_week = $wpdb->get_row($wpdb->prepare("SELECT id FROM $weeks_table WHERE season_id = %d AND week_number > %d AND status != 'draft' ORDER BY week_number ASC LIMIT 1", $season_id, $week->week_number));
    
    // --- CRITICAL FIX: Enforce correct query syntax with fully qualified column names ---
    $players_results = $wpdb->get_results($wpdb->prepare(
        "SELECT u.ID, u.display_name FROM {$wpdb->prefix}users u 
         JOIN {$wpdb->prefix}season_players sp ON u.ID = sp.user_id 
         LEFT JOIN {$wpdb->prefix}season_player_order spo ON u.ID = spo.user_id AND sp.season_id = spo.season_id 
         WHERE sp.season_id = %d AND sp.status = 'accepted' 
         ORDER BY spo.display_order ASC, u.display_name ASC", 
        $season_id
    ));
    $players = [];
    foreach ($players_results as $player) { $players[$player->ID] = $player->display_name; }
    // --- END CRITICAL FIX ---
    
    $live_totals = array_fill_keys(array_keys($players), ['wins' => 0, 'subtotal' => 0]);
    $bpow_live_totals = ['wins' => 0, 'subtotal' => 0];

    $is_finalized = ($week->status === 'finalized');
    $finalized_scores = [];
    if ($is_finalized) {
        $scores_results = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$wpdb->prefix}scores WHERE week_id = %d", $week_id));
        foreach ($scores_results as $score) { $finalized_scores[$score->user_id] = (array)$score; }
    }

    $week_totals = [];
    if ($is_finalized) {
        foreach ($players as $player_id => $name) {
            $week_totals[$player_id] = $finalized_scores[$player_id]['score'] ?? 0;
        }
    }

    $sorted_week_totals = $week_totals;
    arsort($sorted_week_totals);
    $week_ranks = [];
    $rank = 1;
    $prev_total = null;
    $count_at_rank = 0;
    foreach ($sorted_week_totals as $player_id => $total) {
        if ($total !== $prev_total) {
            $rank += $count_at_rank;
            $count_at_rank = 1;
        } else {
            $count_at_rank++;
        }
        $week_ranks[$player_id] = ordinal($rank);
        $prev_total = $total;
    }

    $season_totals = [];
    $finalized_weeks = $wpdb->get_results($wpdb->prepare("SELECT id FROM $weeks_table WHERE season_id = %d AND status = 'finalized'", $season_id));
    if (!empty($finalized_weeks)) {
        $week_ids = wp_list_pluck($finalized_weeks, 'id');
        $placeholders = implode(',', array_fill(0, count($week_ids), '%d'));
        $season_scores_results = $wpdb->get_results($wpdb->prepare(
            "SELECT user_id, SUM(score) as total FROM {$wpdb->prefix}scores WHERE week_id IN ($placeholders) GROUP BY user_id",
            $week_ids
        ));
        foreach ($season_scores_results as $score) {
            $season_totals[$score->user_id] = $score->total;
        }
    }
    
    $sorted_season_totals = $season_totals;
    arsort($sorted_season_totals);
    $season_ranks = [];
    $rank = 1;
    $prev_total = null;
    $count_at_rank = 0;
    foreach ($sorted_season_totals as $player_id => $total) {
        if ($total !== $prev_total) {
            $rank += $count_at_rank;
            $count_at_rank = 1;
        } else {
            $count_at_rank++;
        }
        $season_ranks[$player_id] = ordinal($rank);
        $prev_total = $total;
    }
    
    // --- UX CHANGE: Separate matchups to ensure tiebreaker is last ---
    $all_matchups_results = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$wpdb->prefix}matchups WHERE week_id = %d ORDER BY id ASC", $week_id));
    $regular_matchups = [];
    $tiebreaker_matchup = null;
    foreach ($all_matchups_results as $m) {
        if ($m->is_tiebreaker == 1) {
            $tiebreaker_matchup = $m;
        } else {
            $regular_matchups[] = $m;
        }
    }

    $tiebreaker_actual_score = $wpdb->get_var($wpdb->prepare("SELECT result FROM {$wpdb->prefix}matchups WHERE week_id = %d AND is_tiebreaker = 1", $week_id));

    // *************** IMPORTANT FIX: build maps separately ***************
    // Standard picks map (ONLY is_bpow = 0; includes tiebreaker)
    $std_picks = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}picks WHERE week_id = %d AND is_bpow = 0",
        $week_id
    ));
    $std_picks_map = [];
    foreach ($std_picks as $pick) {
        $std_picks_map[$pick->matchup_id][$pick->user_id] = $pick;
    }
    // ********************************************************************

    // BPOW map for last week's BPOW winner (no tiebreaker saved for BPOW)
    $bpow_picks_map = []; $last_week_bpow_winner_id = null; $bpow_winner_name = '';
    if ($week->week_number > 1) {
        $previous_week = $wpdb->get_row($wpdb->prepare("SELECT bpow_winner_user_id FROM $weeks_table WHERE season_id = %d AND week_number < %d AND status = 'finalized' ORDER BY week_number DESC LIMIT 1", $season_id, $week->week_number));
        if ($previous_week && $previous_week->bpow_winner_user_id) {
            $last_week_bpow_winner_id = $previous_week->bpow_winner_user_id;
            $bpow_picks = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$wpdb->prefix}picks WHERE week_id = %d AND user_id = %d AND is_bpow = 1", $week_id, $last_week_bpow_winner_id));
            foreach ($bpow_picks as $bpow_pick) { $bpow_picks_map[$bpow_pick->matchup_id] = $bpow_pick; }
            $bpow_winner_name = get_userdata($last_week_bpow_winner_id)->display_name;
        }
    }
    
    // === LIVE TOTALS: TIE-AWARE ===
    foreach ($regular_matchups as $matchup) {
        $is_tie = $matchup->result && in_array(strtolower(trim((string)$matchup->result)), ['tie','t','draw'], true);

        foreach ($players as $player_id => $player_name) {
            $pick_data = $std_picks_map[$matchup->id][$player_id] ?? null;
            if ($pick_data && $matchup->result) {
                if ($is_tie) {
                    $live_totals[$player_id]['subtotal'] += (int)floor(((int)$pick_data->point_value) / 2);
                } elseif (strcasecmp(trim($pick_data->pick), trim($matchup->result)) == 0) {
                    $live_totals[$player_id]['wins']++;
                    $live_totals[$player_id]['subtotal'] += (int)$pick_data->point_value;
                }
            }
        }

        if ($last_week_bpow_winner_id) {
            $bpow_pick_data = $bpow_picks_map[$matchup->id] ?? null;
            if ($bpow_pick_data && $matchup->result) {
                if ($is_tie) {
                    $bpow_live_totals['subtotal'] += (int)floor(((int)$bpow_pick_data->point_value) / 2);
                } elseif (strcasecmp(trim($bpow_pick_data->pick), trim($matchup->result)) == 0) {
                    $bpow_live_totals['wins']++;
                    $bpow_live_totals['subtotal'] += (int)$bpow_pick_data->point_value;
                }
            }
        }
    }

    if (!$is_finalized) {
        foreach ($players as $player_id => $name) {
            $week_totals[$player_id] = $live_totals[$player_id]['subtotal'];
        }
    }

    $tie_check_results = [];
    $needs_resolution = false;
    if ($week->status === 'published' && $is_commissioner) {
        $total_matchups = count($regular_matchups);
        $matchups_with_results = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}matchups WHERE week_id = %d AND result IS NOT NULL AND result != ''", $week_id));
        // FIX: The original check was >= ($total_matchups + 1), which assumes a tiebreaker is included. If only regular matchups are counted, the check should be >= $total_matchups.
        if ($matchups_with_results >= $total_matchups && file_exists(plugin_dir_path(__FILE__) . 'kf-scoring-engine.php')) {
            if (!function_exists('kf_check_for_ties')) { require_once plugin_dir_path(__FILE__) . 'kf-scoring-engine.php'; }
            $tie_check_results = kf_check_for_ties($week_id);
            $needs_resolution = ($tie_check_results['mwow_requires_resolution'] ?? false) || ($tie_check_results['bpow_requires_resolution'] ?? false);
        }
    } elseif ($week->status === 'tie_resolution_needed') {
        $needs_resolution = true;
        $tie_data = json_decode($week->tie_data, true);
        $tie_check_results['mwow_requires_resolution'] = !empty($tie_data['mwow']);
        $tie_check_results['mwow_tied_players'] = $tie_data['mwow'] ?? [];
        $tie_check_results['bpow_requires_resolution'] = !empty($tie_data['bpow']);
        $tie_check_results['bpow_tied_players'] = $tie_data['bpow'] ?? [];
    }

    ob_start();
    ?>
    
    <?php // --- NEW: Print-specific styles + Tie style --- ?>
    <style>
        @media print {
            body * { visibility: hidden; }
            #kf-printable-content, #kf-printable-content * { visibility: visible; }
            #kf-printable-content {
                position: absolute;
                left: 0;
                top: 0;
                width: 100%;
            }
            .kf-table { font-size: 10px; } /* Smaller font for printing */
        }
        /* Tie styling */
        .kf-tie { background: #e6f2ff !important; color: #043755 !important; }
        .kf-tie.kf-pick-cell, .kf-tie.kf-points-cell { background: #e6f2ff !important; }
    </style>

    <div class="kf-container">
       <div class="kf-breadcrumbs kf-no-print">
            <a href="<?php echo esc_url(site_url('/season-summary/')); ?>">Season Summary</a> &raquo;
            <span>Week <?php echo esc_html($week->week_number); ?> Summary</span>
        </div>

        <div class="kf-page-header kf-no-print">
            <h1>Week <?php echo esc_html($week->week_number); ?> Summary</h1>
            <div class="kf-week-nav">
                <?php if ($prev_week): ?>
                    <a href="<?php echo esc_url(add_query_arg('week_id', $prev_week->id, get_permalink())); ?>" class="kf-button">&laquo; Prev Week</a>
                <?php endif; ?>
                <?php if ($next_week): ?>
                    <a href="<?php echo esc_url(add_query_arg('week_id', $next_week->id, get_permalink())); ?>" class="kf-button">Next Week &raquo;</a>
                <?php endif; ?>
            </div>
        </div>
        
        <?php
        // --- TIMEZONE FIX ---
        $deadline_formatted = 'Not Set';
        if ($week->submission_deadline) {
            try {
                $utc_dt = new DateTime($week->submission_deadline, new DateTimeZone('UTC'));
                $site_tz = new DateTimeZone(wp_timezone_string());
                $site_dt = $utc_dt->setTimezone($site_tz);
                $deadline_formatted = $site_dt->format('l, F jS @ g:i A T');
            } catch (Exception $e) {
                $deadline_formatted = date("l, F jS @ g:i A T", strtotime($week->submission_deadline));
            }
        }
        ?>
        <h2 class="kf-page-subtitle kf-no-print">Submission Deadline: <?php echo esc_html($deadline_formatted); ?></h2>

        <?php if (!empty($error_message)): ?>
            <div class="notice notice-error is-dismissible kf-no-print" style="margin-bottom: 1em;"><p><strong>Error:</strong> <?php echo wp_kses_post($error_message); ?></p></div>
        <?php endif; ?>

        <?php if ($picks_are_hidden): ?>
            <div class="kf-card kf-no-print" style="border-left: 5px solid #0d47a1; background: #e3f2fd; margin-bottom: 1.5em;">
                <h3 style="margin-top:0;">Picks Are Hidden</h3>
                <p>To keep things fair, other players' picks will be revealed after the submission deadline has passed.</p>
            </div>
        <?php endif; ?>
        
        <?php if ($needs_resolution && $is_commissioner): ?>
            <div class="kf-card kf-no-print" style="border-left: 5px solid #d63638; background: #fbeaea; margin-bottom: 1.5em;">
                <h3 style="margin: 0 0 0.5em 0; color: #d63638;">Tie Resolution Required</h3>
                <p>An unbreakable tie was detected. Please manually select the winner(s) to finalize the week.</p>
                <form method="post" action="" class="kf-tie-resolution-form">
                    <?php wp_nonce_field('kf_finalize_week_nonce', 'kf_finalize_week_nonce_field'); ?>
                    <input type="hidden" name="action" value="resolve_and_finalize">
                    <?php if (!empty($tie_check_results['mwow_requires_resolution'])): ?>
                        <h4>Most Wins of the Week (MWOW)</h4>
                        <?php foreach ($tie_check_results['mwow_tied_players'] as $player_id): ?>
                            <div style="margin-bottom: 5px;"><input type="radio" name="manual_mwow_winner" value="<?php echo intval($player_id); ?>" required> <?php echo esc_html($players[$player_id]); ?></div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    <?php if (!empty($tie_check_results['bpow_requires_resolution'])): ?>
                        <h4 style="margin-top:1em;">Best Player of the Week (BPOW)</h4>
                        <?php foreach ($tie_check_results['bpow_tied_players'] as $player_id): ?>
                            <div style="margin-bottom: 5px;"><input type="radio" name="manual_bpow_winner" value="<?php echo intval($player_id); ?>" required> <?php echo esc_html($players[$player_id]); ?></div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    <button type="submit" class="kf-button kf-button-action" style="margin-top: 1em;">Resolve Ties &amp; Finalize</button>
                </form>
            </div>
        <?php endif; ?>

        <div class="kf-action-bar kf-no-print">
            <div class="kf-action-group kf-view-controls">
                <button id="kf-zoom-in" class="kf-button" title="Zoom In">+</button>
                <button id="kf-zoom-out" class="kf-button" title="Zoom Out">-</button>
                <button id="kf-fit-view" class="kf-button" title="Fit to Screen">Fit</button>
                <button id="kf-reset-view" class="kf-button" title="Reset View">Reset</button>
                <span class="kf-action-separator">|</span>
                <button id="kf-print-button" class="kf-button">Print</button>
                <button id="kf-export-csv" class="kf-button">Export to CSV</button>
            </div>
            
            <?php if ($is_commissioner): ?>
                <div class="kf-action-group kf-commissioner-controls">
                    <?php if ($week->status === 'published'): ?>
                        <a href="<?php echo esc_url(add_query_arg(['week_id' => $week->id], site_url('/enter-results/'))); ?>" class="kf-button kf-button-primary">Enter Results</a>
                    <?php endif; ?>
                    <?php if ($week->status === 'draft'): ?>
                        <a href="<?php echo esc_url(add_query_arg(['week_id' => $week->id], site_url('/week-setup/'))); ?>" class="kf-button">Edit Week</a>
                    <?php endif; ?>
                    <?php if ($week->status === 'published' && !$needs_resolution):
                        // Guard: only show Finalize button if all non-tiebreaker matchups have a result
                        $total_matchup_count  = count($regular_matchups) + ($tiebreaker_matchup ? 1 : 0);
                        $results_entered      = 0;
                        foreach ($regular_matchups as $m) {
                            if ($m->result !== null && $m->result !== '') $results_entered++;
                        }
                        if ($tiebreaker_matchup && $tiebreaker_matchup->result !== null && $tiebreaker_matchup->result !== '') {
                            $results_entered++;
                        }
                        $all_results_done = ($results_entered >= $total_matchup_count && $total_matchup_count > 0);
                    ?>
                        <div id="kf-finalize-wrapper" data-results-complete="<?php echo $all_results_done ? '1' : '0'; ?>">
                            <form id="kf-finalize-form" method="post" action="" style="display:inline-block;">
                                <?php wp_nonce_field('kf_finalize_week_nonce', 'kf_finalize_week_nonce_field'); ?>
                                <button type="submit" name="action" value="finalize_week" class="kf-button kf-button-action">Finalize Week</button>
                            </form>
                        </div>
                    <?php elseif ($week->status === 'tie_resolution_needed'): ?>
                        <div class="kf-finalized-controls">
                            <span class="kf-status-tie-resolution">Tie Resolution Needed - Please resolve ties above</span>
                        </div>
                    <?php elseif ($week->status === 'finalized'): ?>
                        <div class="kf-finalized-controls">
                            <span class="kf-status-finalized">Week Finalized</span>
                            <form id="kf-reverse-form" style="display: inline-block;">
                                <?php wp_nonce_field('kf_reverse_week_nonce', 'kf_reverse_nonce_field'); ?>
                                <button type="button" id="kf-reverse-finalize-btn" class="kf-button" data-week-id="<?php echo esc_attr($week->id); ?>">Reverse</button>
                            </form>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
        
        <div id="kf-printable-content"> 
            <div class="kf-print-header">
                <h1><?php echo esc_html($week->season_name); ?> - Week <?php echo esc_html($week->week_number); ?> Summary</h1>
            </div>
            <div class="kf-table-wrapper">
                <div class="kf-zoom-container">
                    <table class="kf-table" id="kf-summary-table">
                        <thead>
                            <tr>
                                <th rowspan="2">Game</th>
                                <th rowspan="2">Winner</th>
                                <?php foreach ($players as $player_id => $player_name): ?>
                                    <?php 
                                        // NEW DD CHECK: Check if DD was selected for this player
                                        $is_dd_user = in_array($player_id, $dd_selections);
                                        // DD BADGE: Only show if selected AND picks are NOT hidden
                                        $dd_badge = ($is_dd_user && !$picks_are_hidden) ? ' <span class="kf-award-badge" title="Double Down Used">DD</span>' : '';
                                    ?>
                                    <th colspan="2" class="<?php if ($player_id == $current_user_id) echo 'kf-current-player-col'; ?>">
                                        <div class="kf-header-cell-content">
                                            <?php 
                                            $winner_icon = '';
                                            if ($is_finalized) {
                                                if ($player_id == $week->bpow_winner_user_id) { $winner_icon .= ' <span class="kf-winner-icon kf-bpow-winner" title="Best Player of the Week">🏆</span>'; }
                                                if ($player_id == $week->mwow_winner_user_id) { $winner_icon .= ' <span class="kf-winner-icon kf-mwow-winner" title="Most Wins of the Week">MW</span>'; }
                                            }
                                            ?>
                                            <span><?php echo esc_html($player_name) . $winner_icon . $dd_badge; ?></span>
                                            <?php if ($is_finalized && isset($finalized_scores[$player_id])): ?>
                                                <small class="kf-final-score">Week Total: <?php echo esc_html($finalized_scores[$player_id]['score']); ?></small>
                                            <?php elseif (!$is_finalized): ?>
                                                <small class="kf-live-score">Live Subtotal: <span id="live-subtotal-<?php echo esc_attr($player_id); ?>">0</span></small>
                                            <?php endif; ?>
                                        </div>
                                    </th>
                                <?php endforeach; ?>
                                <?php if ($last_week_bpow_winner_id && !$picks_are_hidden): ?>
                                    <th colspan="2" class="kf-bpow-column <?php if ($last_week_bpow_winner_id == $current_user_id) echo 'kf-current-player-col'; ?>">
                                        <div class="kf-header-cell-content">
                                            <span><?php echo esc_html($bpow_winner_name . ' (BPOW)'); ?></span>
                                            <?php if ($is_finalized && isset($finalized_scores[$last_week_bpow_winner_id])): ?>
                                                <small class="kf-final-score">Week Total: <?php echo esc_html($finalized_scores[$last_week_bpow_winner_id]['score']); ?></small>
                                            <?php elseif (!$is_finalized): ?>
                                                <small class="kf-live-score">Live Subtotal: <span id="live-subtotal-bpow-<?php echo esc_attr($last_week_bpow_winner_id); ?>">0</span></small>
                                            <?php endif; ?>
                                        </div>
                                    </th>
                                <?php endif; ?>
                            </tr>
                            <tr>
                                <?php foreach ($players as $player_id => $player_name): ?>
                                    <th class="kf-pick-cell <?php if ($player_id == $current_user_id) echo 'kf-current-player-col'; ?>">Pick</th>
                                    <th class="kf-points-cell <?php if ($player_id == $current_user_id) echo 'kf-current-player-col'; ?>">Points</th>
                                <?php endforeach; ?>
                                <?php if ($last_week_bpow_winner_id && !$picks_are_hidden): ?>
                                    <th class="kf-pick-cell kf-bpow-column <?php if ($last_week_bpow_winner_id == $current_user_id) echo 'kf-current-player-col'; ?>">Pick</th>
                                    <th class="kf-points-cell kf-bpow-column <?php if ($last_week_bpow_winner_id == $current_user_id) echo 'kf-current-player-col'; ?>">Points</th>
                                <?php endif; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($regular_matchups as $matchup): ?>
                                <tr>
                                    <td><?php echo esc_html($matchup->team_b . ' @ ' . $matchup->team_a); ?></td>
                                    <td><?php echo esc_html($matchup->result); ?></td>
                                    <?php foreach ($players as $player_id => $player_name): ?>
                                        <?php 
                                        $highlight_class = ($player_id == $current_user_id) ? 'kf-current-player-col' : '';
                                        
                                        if ($picks_are_hidden && $player_id !== $current_user_id) {
                                            ?>
                                            <td class="kf-pick-cell kf-cell-hidden <?php echo $highlight_class; ?>" colspan="2" style="text-align:center; font-style:italic; color:#999;">Hidden</td>
                                            <?php
                                            continue;
                                        }

                                        // STANDARD picks
                                        $pick_data = $std_picks_map[$matchup->id][$player_id] ?? null;
                                        $pick = $pick_data ? esc_html($pick_data->pick) : '-';
                                        $points = $pick_data ? (int)$pick_data->point_value : null;

                                        $is_tie = $matchup->result && in_array(strtolower(trim((string)$matchup->result)), ['tie','t','draw'], true);
                                        $is_win = (!$is_tie) && $pick_data && $matchup->result && (strcasecmp(trim($pick_data->pick), trim($matchup->result)) == 0);

                                        if ($is_tie) {
                                            $class = 'kf-tie';
                                            $display_points = is_null($points) ? '-' : (string)floor($points / 2);
                                            $pick_with_mark = $pick !== '-' ? '— ' . $pick : '—';
                                        } else {
                                            $class = $is_win ? 'kf-win' : ($matchup->result ? 'kf-loss' : '');
                                            if ($pick_data) {
                                                $display_points = $is_finalized ? ($is_win ? (string)$points : '0') : (string)$points;
                                            } else {
                                                $display_points = '-';
                                            }
                                            $pick_with_mark = $pick;
                                        }
                                        ?>
                                        <td class="<?php echo $class; ?> kf-pick-cell <?php echo $highlight_class; ?>"><?php echo $pick_with_mark; ?></td>
                                        <td class="<?php echo $class; ?> kf-points-cell <?php echo $highlight_class; ?>"><?php echo $display_points; ?></td>
                                    <?php endforeach; ?>
                                    <?php if ($last_week_bpow_winner_id && !$picks_are_hidden): 
                                        $highlight_class = ($last_week_bpow_winner_id == $current_user_id) ? 'kf-current-player-col' : '';
                                        $bpow_pick_data = $bpow_picks_map[$matchup->id] ?? null;
                                        $bpow_pick = $bpow_pick_data ? esc_html($bpow_pick_data->pick) : '-';
                                        $bpow_points = $bpow_pick_data ? (int)$bpow_pick_data->point_value : null;

                                        $is_tie = $matchup->result && in_array(strtolower(trim((string)$matchup->result)), ['tie','t','draw'], true);
                                        $bpow_is_win = (!$is_tie) && $bpow_pick_data && $matchup->result && (strcasecmp(trim($bpow_pick_data->pick), trim($matchup->result)) == 0);

                                        if ($is_tie) {
                                            $bpow_class = 'kf-tie';
                                            $bpow_display_points = is_null($bpow_points) ? '-' : (string)floor($bpow_points / 2);
                                            $bpow_pick_with_mark = $bpow_pick !== '-' ? '— ' . $bpow_pick : '—';
                                        } else {
                                            $bpow_class = $bpow_is_win ? 'kf-win' : ($matchup->result ? 'kf-loss' : '');
                                            if ($bpow_pick_data) {
                                                $bpow_display_points = $is_finalized ? ($bpow_is_win ? (string)$bpow_points : '0') : (string)$bpow_points;
                                            } else {
                                                $bpow_display_points = '-';
                                            }
                                            $bpow_pick_with_mark = $bpow_pick;
                                        }
                                    ?>
                                        <td class="<?php echo $bpow_class; ?> kf-pick-cell <?php echo $highlight_class; ?>"><?php echo $bpow_pick_with_mark; ?></td>
                                        <td class="<?php echo $bpow_class; ?> kf-points-cell <?php echo $highlight_class; ?>"><?php echo $bpow_display_points; ?></td>
                                    <?php endif; ?>
                                </tr>
                            <?php endforeach; ?>
                            
                            <?php if ($tiebreaker_matchup): ?>
                                <tr>
                                    <td><?php echo esc_html($tiebreaker_matchup->team_b . ' @ ' . $tiebreaker_matchup->team_a); ?> <strong>(Tiebreaker)</strong></td>
                                    <td><?php echo esc_html($tiebreaker_matchup->result); ?></td>
                                    <?php foreach ($players as $player_id => $player_name): ?>
                                        <?php 
                                        $highlight_class = ($player_id == $current_user_id) ? 'kf-current-player-col' : '';
                                        
                                        if ($picks_are_hidden && $player_id !== $current_user_id) {
                                            ?>
                                            <td class="kf-pick-cell kf-cell-hidden <?php echo $highlight_class; ?>" colspan="2" style="text-align:center; font-style:italic; color:#999;">Hidden</td>
                                            <?php
                                            continue;
                                        }

                                        // tiebreaker is STANDARD-only
                                        $pick_data = $std_picks_map[$tiebreaker_matchup->id][$player_id] ?? null;
                                        $pick = $pick_data ? esc_html($pick_data->pick) : '-';
                                        $diff = '-';
                                        if (isset($finalized_scores[$player_id])) {
                                            $diff = $finalized_scores[$player_id]['tiebreaker_diff'];
                                        } elseif (is_numeric($tiebreaker_actual_score) && $pick_data && is_numeric($pick_data->pick)) {
                                            $diff = number_format(abs(floatval($tiebreaker_actual_score) - floatval($pick_data->pick)), 1);
                                        }
                                        ?>
                                        <td class="kf-pick-cell <?php echo $highlight_class; ?>"><?php echo $pick; ?></td>
                                        <td class="kf-points-cell <?php echo $highlight_class; ?>"><?php echo $diff; ?></td>
                                    <?php endforeach; ?>
                                    <?php if ($last_week_bpow_winner_id && !$picks_are_hidden): 
                                        $highlight_class = ($last_week_bpow_winner_id == $current_user_id) ? 'kf-current-player-col' : '';
                                        // BPOW players do not make a tiebreaker score guess
                                    ?>
                                        <td class="kf-pick-cell <?php echo $highlight_class; ?>">-</td>
                                        <td class="kf-points-cell <?php echo $highlight_class; ?>">-</td>
                                    <?php endif; ?>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                        <tfoot class="kf-table-footer">
                             <?php if ($is_finalized): ?>
                                 <tr class="kf-footer-header">
                                     <th colspan="2">Player Totals</th>
                                     <?php foreach ($players as $player_id => $player_name): ?>
                                         <th colspan="2" class="<?php if ($player_id == $current_user_id) echo 'kf-current-player-col'; ?>" style="text-align: center;">
                                             <div class="kf-header-cell-content">
                                                 <?php 
                                                 $winner_icon = '';
                                                 if ($player_id == $week->bpow_winner_user_id) { $winner_icon .= ' <span class="kf-winner-icon kf-bpow-winner" title="Best Player of the Week">🏆</span>'; }
                                                 if ($player_id == $week->mwow_winner_user_id) { $winner_icon .= ' <span class="kf-winner-icon kf-mwow-winner" title="Most Wins of the Week">MW</span>'; }
                                                 ?>
                                                 <span><?php echo esc_html($player_name) . $winner_icon; ?></span>
                                                 <small class="kf-final-score">Week Total: <?php echo esc_html($finalized_scores[$player_id]['score']); ?></small>
                                             </div>
                                         </th>
                                     <?php endforeach; ?>
                                     <?php if ($last_week_bpow_winner_id && !$picks_are_hidden): ?>
                                         <th colspan="2" class="kf-bpow-column <?php if ($last_week_bpow_winner_id == $current_user_id) echo 'kf-current-player-col'; ?>" style="text-align: center;">
                                             <div class="kf-header-cell-content">
                                                 <span><?php echo esc_html($bpow_winner_name . ' (BPOW)'); ?></span>
                                                 <small class="kf-final-score">Week Total: <?php echo esc_html($finalized_scores[$last_week_bpow_winner_id]['score']); ?></small>
                                             </div>
                                         </th>
                                     <?php endif; ?>
                                 </tr>
                                 <tr>
                                     <td colspan="2"><strong>Subtotal</strong></td>
                                     <?php foreach ($players as $player_id => $player_name): ?>
                                         <td colspan="2" class="<?php if ($player_id == $current_user_id) echo 'kf-current-player-col'; ?>" style="text-align: center;">
                                             <?php echo isset($finalized_scores[$player_id]) ? esc_html($finalized_scores[$player_id]['subtotal']) : '-'; ?>
                                         </td>
                                     <?php endforeach; ?>
                                     <?php if ($last_week_bpow_winner_id && !$picks_are_hidden): ?>
                                         <td colspan="2" class="kf-bpow-column <?php if ($last_week_bpow_winner_id == $current_user_id) echo 'kf-current-player-col'; ?>">
                                             <?php echo isset($finalized_scores[$last_week_bpow_winner_id]) ? esc_html($finalized_scores[$last_week_bpow_winner_id]['subtotal']) : '-'; ?>
                                         </td>
                                     <?php endif; ?>
                                 </tr>
                                 <tr>
                                     <td colspan="2"><strong>Wins</strong></td>
                                     <?php foreach ($players as $player_id => $player_name): ?>
                                         <td colspan="2" class="<?php if ($player_id == $current_user_id) echo 'kf-current-player-col'; ?>" style="text-align: center;">
                                             <?php echo isset($finalized_scores[$player_id]) ? esc_html($finalized_scores[$player_id]['wins']) : '-'; ?>
                                         </td>
                                     <?php endforeach; ?>
                                     <?php if ($last_week_bpow_winner_id && !$picks_are_hidden): ?>
                                          <td colspan="2" class="kf-bpow-column <?php if ($last_week_bpow_winner_id == $current_user_id) echo 'kf-current-player-col'; ?>">
                                             <?php echo isset($finalized_scores[$last_week_bpow_winner_id]) ? esc_html($finalized_scores[$last_week_bpow_winner_id]['wins']) : '-'; ?>
                                         </td>
                                     <?php endif; ?>
                                 </tr>
                                 <tr>
                                     <td colspan="2"><strong>MWOW Bonus</strong></td>
                                     <?php foreach ($players as $player_id => $player_name): ?>
                                         <td colspan="2" class="<?php if ($player_id == $current_user_id) echo 'kf-current-player-col'; ?>" style="text-align: center;">
                                             <?php echo isset($finalized_scores[$player_id]) ? esc_html($finalized_scores[$player_id]['mwow_bonus_awarded']) : '-'; ?>
                                         </td>
                                     <?php endforeach; ?>
                                     <?php if ($last_week_bpow_winner_id && !$picks_are_hidden): ?>
                                         <td colspan="2" class="kf-bpow-column <?php if ($last_week_bpow_winner_id == $current_user_id) echo 'kf-current-player-col'; ?>">-</td>
                                     <?php endif; ?>
                                 </tr>
                                 <tr class="kf-total-row">
                                     <td colspan="2"><strong>Week Total</strong></td>
                                     <?php foreach ($players as $player_id => $player_name): ?>
                                         <td colspan="2" class="<?php if ($player_id == $current_user_id) echo 'kf-current-player-col'; ?>" style="text-align: center;">
                                             <?php 
                                             $display_final_score = esc_html($week_totals[$player_id] ?? '-');
                                             if (isset($dd_target_history[$player_id])) {
                                                 $original_score = $dd_target_history[$player_id]->original_score;
                                                 $current_score = $week_totals[$player_id] ?? '-';
                                                 $display_final_score = "<strike>" . esc_html($original_score) . "</strike> " . esc_html($current_score);
                                             }
                                             echo wp_kses($display_final_score, ['strike' => []]);
                                             ?>
                                             <sup style="color: #FFD700;"><?php echo isset($week_ranks[$player_id]) ? esc_html($week_ranks[$player_id]) : '-'; ?></sup>
                                         </td>
                                     <?php endforeach; ?>
                                     <?php if ($last_week_bpow_winner_id && !$picks_are_hidden): ?>
                                         <td colspan="2" class="kf-bpow-column <?php if ($last_week_bpow_winner_id == $current_user_id) echo 'kf-current-player-col'; ?>">
                                             <?php echo isset($finalized_scores[$last_week_bpow_winner_id]) ? esc_html($finalized_scores[$last_week_bpow_winner_id]['score']) : esc_html($bpow_live_totals['subtotal']); ?>
                                         </td>
                                     <?php endif; ?>
                                 </tr>
                                 <tr class="kf-season-total-row">
                                     <td colspan="2"><strong>Season Total</strong></td>
                                     <?php foreach ($players as $player_id => $player_name): ?>
                                         <td colspan="2" class="<?php if ($player_id == $current_user_id) echo 'kf-current-player-col'; ?>" style="text-align: center;">
                                             <?php echo isset($season_totals[$player_id]) ? esc_html($season_totals[$player_id]) : '-'; ?> <sup style="color: #FFD700;"><?php echo isset($season_ranks[$player_id]) ? esc_html($season_ranks[$player_id]) : '-'; ?></sup>
                                         </td>
                                     <?php endforeach; ?>
                                     <?php if ($last_week_bpow_winner_id && !$picks_are_hidden): ?>
                                         <td colspan="2" class="kf-bpow-column <?php if ($last_week_bpow_winner_id == $current_user_id) echo 'kf-current-player-col'; ?>">
                                             <?php echo isset($season_totals[$last_week_bpow_winner_id]) ? esc_html($season_totals[$last_week_bpow_winner_id]) : '-'; ?>
                                         </td>
                                     <?php endif; ?>
                                 </tr>
                            <?php else: // NOT FINALIZED FOOTER ?>
                                <tr class="kf-total-row">
                                     <td colspan="2"><strong>Live Subtotal</strong></td>
                                     <?php foreach ($players as $player_id => $player_name): ?>
                                         <td colspan="2" class="<?php if ($player_id == $current_user_id) echo 'kf-current-player-col'; ?>" style="text-align: center;">
                                             <?php echo esc_html($week_totals[$player_id] ?? '-'); ?>
                                         </td>
                                     <?php endforeach; ?>
                                     <?php if ($last_week_bpow_winner_id && !$picks_are_hidden): ?>
                                         <td colspan="2" class="kf-bpow-column <?php if ($last_week_bpow_winner_id == $current_user_id) echo 'kf-current-player-col'; ?>">
                                             <?php echo esc_html($bpow_live_totals['subtotal'] ?? '-'); ?>
                                         </td>
                                     <?php endif; ?>
                                 </tr>
                            <?php endif; ?>
                        </tfoot>
                    </table>
                    <div class="kf-rank-legend kf-no-print" style="text-align: right; font-size: 0.9em; color: #555; margin-top: 10px;">
                        <span style="color: #FFD700;">Gold Rank</span>: Indicates player ranking by week/season total score (highest to lowest).
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php if (!$is_finalized && (!$picks_are_hidden || $is_commissioner) && !empty($regular_matchups)):
        // Build JSON data for the scenario simulator
        $js_sim_players = [];
        foreach ($players as $pid => $pname) {
            $js_sim_players[(string)$pid] = $pname;
        }
        $js_sim_matchups = [];
        foreach ($regular_matchups as $m) {
            $js_sim_matchups[] = [
                'id'     => (int)$m->id,
                'label'  => $m->team_b . ' @ ' . $m->team_a,
                'team_a' => $m->team_a,
                'team_b' => $m->team_b,
                'result' => ($m->result !== null && $m->result !== '') ? $m->result : null,
            ];
        }
        $js_sim_picks = [];
        foreach ($regular_matchups as $m) {
            $mid = (string)$m->id;
            $js_sim_picks[$mid] = [];
            foreach ($players as $pid => $pname) {
                $pick_obj = $std_picks_map[$m->id][$pid] ?? null;
                if ($pick_obj) {
                    $js_sim_picks[$mid][(string)$pid] = [
                        'pick'   => $pick_obj->pick,
                        'points' => (int)$pick_obj->point_value,
                    ];
                }
            }
        }
        $unresolved_count = 0;
        foreach ($regular_matchups as $m) {
            if ($m->result === null || $m->result === '') $unresolved_count++;
        }
        $js_sim_current = [];
        foreach ($players as $pid => $pname) {
            $js_sim_current[(string)$pid] = $week_totals[$pid] ?? 0;
        }
    ?>
    <div class="kf-scenario-panel kf-no-print" id="kf-scenario-panel">
        <div class="kf-scenario-header" id="kf-scenario-toggle" role="button" tabindex="0"
             onclick="(document.getElementById('kf-scenario-body').style.display==='none')?kfScenarioOpen():kfScenarioClose()"
             onkeydown="if(event.key==='Enter'||event.key===' '){this.click();}">
            <span>&#128302; What If? Scenario Simulator</span>
            <?php if ($unresolved_count > 0): ?>
                <span class="kf-scenario-badge"><?php echo intval($unresolved_count); ?> game<?php echo $unresolved_count !== 1 ? 's' : ''; ?> remaining</span>
            <?php else: ?>
                <span class="kf-scenario-badge kf-scenario-badge-done">All results in</span>
            <?php endif; ?>
            <span class="kf-scenario-chevron" id="kf-scenario-chevron">&#9654;</span>
        </div>
        <div class="kf-scenario-body" id="kf-scenario-body" style="display:none;">
            <p class="kf-scenario-intro">Select hypothetical outcomes for games to see projected standings in real time.</p>
            <div class="kf-scenario-games" id="kf-scenario-games">
                <?php foreach ($regular_matchups as $m): ?>
                    <div class="kf-scenario-game-row">
                        <span class="kf-scenario-game-label"><?php echo esc_html($m->team_b . ' @ ' . $m->team_a); ?></span>
                        <?php if ($m->result !== null && $m->result !== ''): ?>
                            <span class="kf-scenario-game-result kf-scenario-locked">&#10003; <?php echo esc_html($m->result); ?></span>
                        <?php else: ?>
                            <select class="kf-scenario-select" data-matchup-id="<?php echo esc_attr($m->id); ?>" onchange="kfScenarioCompute()">
                                <option value="">&#8212; Pick outcome &#8212;</option>
                                <option value="<?php echo esc_attr($m->team_b); ?>"><?php echo esc_html($m->team_b); ?></option>
                                <option value="<?php echo esc_attr($m->team_a); ?>"><?php echo esc_html($m->team_a); ?></option>
                                <option value="TIE">TIE</option>
                            </select>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
            <div class="kf-scenario-standings" id="kf-scenario-standings" style="display:none;">
                <h4 class="kf-scenario-standings-title">&#128202; Projected Standings</h4>
                <table class="kf-scenario-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Player</th>
                            <th>Proj. Pts</th>
                            <th>vs Now</th>
                        </tr>
                    </thead>
                    <tbody id="kf-scenario-tbody"></tbody>
                </table>
            </div>
        </div>
    </div>
    <script>
    (function() {
        var KF_SIM = {
            players:  <?php echo wp_json_encode($js_sim_players); ?>,
            matchups: <?php echo wp_json_encode($js_sim_matchups); ?>,
            picks:    <?php echo wp_json_encode($js_sim_picks); ?>,
            current:  <?php echo wp_json_encode($js_sim_current); ?>
        };

        function kfScenarioOpen() {
            document.getElementById('kf-scenario-body').style.display = 'block';
            document.getElementById('kf-scenario-chevron').innerHTML = '&#9660;';
            // Auto-compute if no games are pending
            var allLocked = KF_SIM.matchups.every(function(m) { return m.result !== null; });
            if (allLocked) { kfScenarioCompute(); }
        }
        function kfScenarioClose() {
            document.getElementById('kf-scenario-body').style.display = 'none';
            document.getElementById('kf-scenario-chevron').innerHTML = '&#9654;';
        }
        window.kfScenarioOpen  = kfScenarioOpen;
        window.kfScenarioClose = kfScenarioClose;

        window.kfScenarioCompute = function() {
            // Gather outcomes: locked results + user-selected hypothetical outcomes
            var outcomes = {};
            KF_SIM.matchups.forEach(function(m) {
                if (m.result !== null && m.result !== '') {
                    outcomes[String(m.id)] = m.result;
                }
            });
            document.querySelectorAll('.kf-scenario-select').forEach(function(sel) {
                if (sel.value) {
                    outcomes[sel.getAttribute('data-matchup-id')] = sel.value;
                }
            });

            // Compute projected points for each player
            var projected = {};
            Object.keys(KF_SIM.players).forEach(function(pid) { projected[pid] = 0; });
            KF_SIM.matchups.forEach(function(m) {
                var result = outcomes[String(m.id)];
                if (!result) return;
                var mPicks = KF_SIM.picks[String(m.id)] || {};
                var isTie = (result.toLowerCase() === 'tie');
                Object.keys(mPicks).forEach(function(pid) {
                    var p = mPicks[pid];
                    if (!p) return;
                    if (isTie) {
                        projected[pid] = (projected[pid] || 0) + Math.floor(p.points / 2);
                    } else if (p.pick && p.pick.toLowerCase() === result.toLowerCase()) {
                        projected[pid] = (projected[pid] || 0) + p.points;
                    }
                });
            });

            // Build and sort standings
            var standings = Object.keys(KF_SIM.players).map(function(pid) {
                return {
                    id: pid,
                    name: KF_SIM.players[pid],
                    projected: projected[pid] || 0,
                    current: KF_SIM.current[pid] || 0
                };
            });
            standings.sort(function(a, b) { return b.projected - a.projected; });

            // Render the standings table
            var tbody = document.getElementById('kf-scenario-tbody');
            tbody.innerHTML = '';
            standings.forEach(function(p, i) {
                var delta = p.projected - p.current;
                var deltaStr, deltaClass;
                if (delta > 0)      { deltaStr = '+' + delta; deltaClass = 'kf-scenario-delta-up'; }
                else if (delta < 0) { deltaStr = String(delta); deltaClass = 'kf-scenario-delta-down'; }
                else                { deltaStr = '&#8212;'; deltaClass = 'kf-scenario-delta-neutral'; }
                var safeName = p.name.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
                var tr = document.createElement('tr');
                tr.innerHTML =
                    '<td>' + (i + 1) + '</td>' +
                    '<td>' + safeName + '</td>' +
                    '<td><strong>' + p.projected + '</strong></td>' +
                    '<td><span class="kf-scenario-delta ' + deltaClass + '">' + deltaStr + '</span></td>';
                tbody.appendChild(tr);
            });

            document.getElementById('kf-scenario-standings').style.display = 'block';
        };
    })();
    </script>
    <?php endif; // end scenario simulator ?>

    </div>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        // --- CSV Export Script ---
        // (Full CSV logic defined here)
        const exportButton = document.getElementById('kf-export-csv');
        if(exportButton) {
            exportButton.addEventListener('click', function() {
                const table = document.getElementById('kf-summary-table');
                let csv = [];
                // Add header title rows
                const titleRow = `"<?php echo esc_js($week->season_name); ?> - Week <?php echo esc_js($week->week_number); ?> Summary"`;
                csv.push(titleRow);

                // Process table rows
                for (let i = 0; i < table.rows.length; i++) {
                    const row = [];
                    const cells = table.rows[i].cells;
                    for (let j = 0; j < cells.length; j++) {
                        let cellText = cells[j].innerText.replace(/(\r\n|\n|\r)/gm, " ").replace(/"/g, '""').trim();
                        
                        // Handle multi-line header content for cleaner CSV output
                        if (i === 0 && j > 1) {
                            const headerContent = cells[j].querySelector('.kf-header-cell-content');
                            if(headerContent) {
                                let mainText = headerContent.querySelector('span').textContent.trim();
                                let smallText = headerContent.querySelector('small') ? headerContent.querySelector('small').textContent.trim() : '';
                                cellText = `${mainText} (${smallText})`;
                            }
                        }
                        
                        // Handle colspan by inserting empty cells
                        const colspan = cells[j].getAttribute('colspan') || 1;
                        row.push(`"${cellText}"`);
                        for (let k = 1; k < colspan; k++) {
                            row.push('""');
                        }
                    }
                    csv.push(row.join(','));
                }

                const csvContent = "data:text/csv;charset=utf-8," + csv.join('\n');
                const encodedUri = encodeURI(csvContent);
                const link = document.createElement("a");
                link.setAttribute("href", encodedUri);
                link.setAttribute("download", "<?php echo esc_js(sanitize_title($week->season_name . '-week-' . $week->week_number)); ?>.csv");
                document.body.appendChild(link); 
                link.click();
                document.body.removeChild(link);
            });
        }
        
        // --- Live Scoring Update ---
        <?php if (!$is_finalized): ?>
            <?php foreach($live_totals as $player_id => $totals): ?>
                const subtotalEl_<?php echo esc_js($player_id); ?> = document.getElementById('live-subtotal-<?php echo esc_js($player_id); ?>');
                if(subtotalEl_<?php echo esc_js($player_id); ?>) {
                    subtotalEl_<?php echo esc_js($player_id); ?>.textContent = '<?php echo esc_js($totals['subtotal']); ?>';
                }
            <?php endforeach; ?>
            <?php if ($last_week_bpow_winner_id && !$picks_are_hidden): ?>
                const bpowSubtotalEl = document.getElementById('live-subtotal-bpow-<?php echo esc_js($last_week_bpow_winner_id); ?>');
                if (bpowSubtotalEl) {
                    bpowSubtotalEl.textContent = '<?php echo esc_js($bpow_live_totals['subtotal']); ?>';
                }
            <?php endif; ?>
        <?php endif; ?>
        
        // --- Zoom/Print Utility Logic (Assumed functionality outside of scope) ---
        // Placeholder for zoom/print logic if not handled by a global script.
    });
    </script>
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