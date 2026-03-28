<?php
/**
 * Shortcode handler for the "Week Setup/Edit" page.
 *
 * This file has been completely rewritten to implement the correct workflow for week creation and editing.
 *
 * Key Improvements:
 * 1.  Correct Tiebreaker Logic: The form now shows one entry per game. The commissioner flags one game
 * as the tiebreaker, and the backend automatically creates the two required database records.
 * 2.  Dynamic Matchups: For new weeks, the number of matchup fields can be changed, and the form updates instantly (via JavaScript).
 * 3.  Per-Week Point Values: The point values for the week can be customized and are saved with the week.
 * 4.  Live Point Validation: A new JavaScript feature provides immediate feedback on the sum of the entered point values.
 * 5.  Robust Saving: A "nuke and rebuild" strategy is used for 'draft' weeks to ensure data integrity.
 * 6.  REPAIR MODE: If an existing week has missing matchup_count or point_values data, the form allows editing and pre-fills defaults.
 * * @package Kerry_Football
 * * TIMEZONE FIX (V2.0):
 * - MODIFIED: The 'Submission Deadline' input now uses get_date_from_gmt() to correctly display the stored UTC time in the site's local timezone.
 * - MODIFIED: The email notification now uses PHP DateTime objects to correctly format the deadline in the site's local time with the proper abbreviation (e.g., EDT).
 * * UX FIX (V2.0):
 * - MODIFIED: Swapped the display order of team inputs to show Away Team then Home Team, matching standard convention.
 */

function kf_week_setup_form() {
    // Standard security and session checks
    if (!is_user_logged_in() || !current_user_can('manage_options')) { return '<p>You do not have access to this page.</p>'; }
    if (session_status() === PHP_SESSION_NONE) { session_start(); }

    global $wpdb;
    $season_id = $_SESSION['kf_active_season_id'] ?? 0;
    if (!$season_id) { return '<div class="kf-container"><h1>Week Setup</h1><p>No season selected.</p></div>'; }

    // Define table names
    $weeks_table = $wpdb->prefix . 'weeks';
    $matchups_table = $wpdb->prefix . 'matchups';

    // Fetch the active season settings, which are crucial for defaults and validation
    $season = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}seasons WHERE id = %d", $season_id));
    if (!$season) { return '<p>Invalid season selected.</p>'; }

    // Determine if we are editing an existing week or creating a new one
    $week_id = isset($_GET['week_id']) ? intval($_GET['week_id']) : 0;
    $edit_mode = $week_id > 0;
    $week = null;
    $is_matchup_editable = true; // Can the matchups themselves be changed?
    $is_repair_mode = false;     // Is this a special case to fix a broken week?

    if ($edit_mode) {
        $week = $wpdb->get_row($wpdb->prepare("SELECT * FROM $weeks_table WHERE id = %d", $week_id));
        if ($week && $week->status !== 'draft') {
            $is_matchup_editable = false;
        }
        if ($week && (!$is_matchup_editable && (!isset($week->matchup_count) || !$week->matchup_count))) {
            $is_repair_mode = true;
        }
    }
    
    // =================================================================================================
    // --- HANDLE FORM SUBMISSION (POST REQUEST) ---
    // =================================================================================================
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['kf_week_nonce']) && wp_verify_nonce($_POST['kf_week_nonce'], 'kf_week_action')) {
        
        if (!$is_matchup_editable && !$is_repair_mode) {
             echo '<div class="notice notice-error"><p>This week is locked and cannot be edited.</p></div>';
        } else {
            $week_data = [
                'season_id'           => $season_id,
                'week_number'         => intval($_POST['week_number']),
                // Correctly convert the local time from the form input to GMT/UTC for database storage.
                'submission_deadline'   => get_gmt_from_date(sanitize_text_field($_POST['deadline'])),
                'matchup_count'       => intval($_POST['matchup_count']),
                'point_values'        => sanitize_text_field($_POST['point_values']),
            ];

            $is_publishing = isset($_POST['kf_week_publish']);
            $current_status = $is_publishing ? 'published' : 'draft';
            
            if ($week && $week->status === 'draft') {
                 $week_data['status'] = $current_status;
            } else if (!$week) {
                 $week_data['status'] = $current_status;
            }

            if ($edit_mode) {
                $wpdb->update($weeks_table, $week_data, ['id' => $week_id]);
            } else {
                $wpdb->insert($weeks_table, $week_data);
                $week_id = $wpdb->insert_id;
            }

            if($is_matchup_editable || $is_repair_mode) {
                $wpdb->delete($matchups_table, ['week_id' => $week_id]);

                $team_a_list      = $_POST['team_a'] ?? []; // Home Team
                $team_b_list      = $_POST['team_b'] ?? []; // Away Team
                $tiebreaker_index = isset($_POST['tiebreaker_marker']) ? intval($_POST['tiebreaker_marker']) : -1;

                // SPORTS API V1: Optional ESPN/Odds fields (present when games added via Browse)
                $espn_ids         = $_POST['espn_game_id'] ?? [];
                $game_datetimes   = $_POST['game_datetime'] ?? [];
                $odds_event_ids   = $_POST['odds_api_event_id'] ?? [];
                $spreads_home     = $_POST['spread_home'] ?? [];
                $spreads_away     = $_POST['spread_away'] ?? [];
                $moneylines_home  = $_POST['moneyline_home'] ?? [];
                $moneylines_away  = $_POST['moneyline_away'] ?? [];
                $over_unders      = $_POST['over_under'] ?? [];

                foreach ($team_a_list as $index => $teamA) {
                    $teamA = sanitize_text_field($teamA);
                    $teamB = sanitize_text_field($team_b_list[$index]);
                    if (!empty($teamA) && !empty($teamB)) {
                        $matchup_data = [
                            'week_id' => $week_id,
                            'team_a'  => $teamA,
                            'team_b'  => $teamB,
                        ];

                        // Add ESPN/API fields if present (API mode)
                        $espn_id = isset($espn_ids[$index]) ? sanitize_text_field($espn_ids[$index]) : '';
                        if (!empty($espn_id)) {
                            $matchup_data['espn_game_id']      = $espn_id;
                            $matchup_data['game_status']        = 'scheduled';
                        }
                        if (!empty($game_datetimes[$index])) {
                            // Convert ISO date to MySQL datetime
                            $dt = sanitize_text_field($game_datetimes[$index]);
                            $matchup_data['game_datetime'] = date('Y-m-d H:i:s', strtotime($dt));
                        }
                        if (!empty($odds_event_ids[$index])) {
                            $matchup_data['odds_api_event_id'] = sanitize_text_field($odds_event_ids[$index]);
                        }
                        if (isset($spreads_home[$index]) && $spreads_home[$index] !== '') {
                            $matchup_data['spread_home'] = floatval($spreads_home[$index]);
                        }
                        if (isset($spreads_away[$index]) && $spreads_away[$index] !== '') {
                            $matchup_data['spread_away'] = floatval($spreads_away[$index]);
                        }
                        if (isset($moneylines_home[$index]) && $moneylines_home[$index] !== '') {
                            $matchup_data['moneyline_home'] = intval($moneylines_home[$index]);
                        }
                        if (isset($moneylines_away[$index]) && $moneylines_away[$index] !== '') {
                            $matchup_data['moneyline_away'] = intval($moneylines_away[$index]);
                        }
                        if (isset($over_unders[$index]) && $over_unders[$index] !== '') {
                            $matchup_data['over_under'] = floatval($over_unders[$index]);
                        }
                        if (!empty($espn_id)) {
                            $matchup_data['odds_updated_at'] = current_time('mysql', true);
                        }

                        $wpdb->insert($matchups_table, array_merge($matchup_data, ['is_tiebreaker' => 0]));
                        if ($index === $tiebreaker_index) {
                            $wpdb->insert($matchups_table, array_merge($matchup_data, ['is_tiebreaker' => 1]));
                        }
                    }
                }
            }

            if ($is_publishing) {
                $week_info = $wpdb->get_row($wpdb->prepare("SELECT week_number, submission_deadline FROM $weeks_table WHERE id = %d", $week_id));
                $subject = "Picks are Open for Week {$week_info->week_number} of {$season->name}!";
                
                // --- TIMEZONE FIX ---
                // Convert the UTC time from the DB to the site's local timezone for the notification email.
                $deadline_formatted = 'N/A';
                if ($week_info->submission_deadline) {
                    try {
                        $utc_dt = new DateTime($week_info->submission_deadline, new DateTimeZone('UTC'));
                        $site_tz = new DateTimeZone(wp_timezone_string());
                        $site_dt = $utc_dt->setTimezone($site_tz);
                        $deadline_formatted = $site_dt->format('l, F jS \a\\t g:i A T');
                    } catch (Exception $e) {
                        // Fallback for safety
                        $deadline_formatted = date("l, F jS \a\\t g:i A T", strtotime($week_info->submission_deadline . ' GMT'));
                    }
                }

                $picks_page_url = site_url('/player-dashboard/');
                $message = "<p>Heads up, players!</p><p>Week {$week_info->week_number} is now open for picks. The deadline to submit is <strong>{$deadline_formatted}</strong>.</p><p><a href='{$picks_page_url}'>Click here to make your picks!</a></p><p>Good luck!</p>";
                // Use the proper notification function that respects per-player preferences
                if (function_exists('kf_send_picks_ready_notification')) {
                    kf_send_picks_ready_notification($week_id);
                } else {
                    kf_send_notification_to_season_players($season_id, $subject, $message);
                }
                // Schedule deadline reminder (24 hours before deadline)
                if (function_exists('kf_schedule_deadline_reminder') && !empty($week_info->submission_deadline)) {
                    kf_schedule_deadline_reminder($week_id, $week_info->submission_deadline);
                }
                echo '<div class="notice notice-success is-dismissible"><p>Week has been published/updated and players notified. You will be redirected shortly.</p></div>';
            } else {
                echo '<div class="notice notice-success is-dismissible"><p>Week data has been saved. You will be redirected shortly.</p></div>';
            }
            echo "<script>setTimeout(function() { window.location.href = '" . esc_url_raw(site_url('/manage-weeks/')) . "'; }, 2000);</script>";
        }
    }

    // =================================================================================================
    // --- FETCH DATA FOR FORM DISPLAY ---
    // =================================================================================================
    $page_title = 'Create New Week';
    $matchups = [];
    $tiebreaker_parent_id = null;
    
    if ($edit_mode) {
        $page_title = 'Edit Week ' . $week->week_number;
        $matchups = $wpdb->get_results($wpdb->prepare("SELECT * FROM $matchups_table WHERE week_id = %d AND is_tiebreaker = 0 ORDER BY id ASC", $week_id));
        $tiebreaker_game = $wpdb->get_row($wpdb->prepare("SELECT team_a, team_b FROM $matchups_table WHERE week_id = %d AND is_tiebreaker = 1", $week_id));
        if ($tiebreaker_game) {
            foreach ($matchups as $m) {
                if ($m->team_a == $tiebreaker_game->team_a && $m->team_b == $tiebreaker_game->team_b) {
                    $tiebreaker_parent_id = $m->id;
                    break;
                }
            }
        }
        $matchup_count_val = $week->matchup_count;
        $point_values_val = $week->point_values;

        if (empty($matchup_count_val) && !empty($matchups)) {
            $matchup_count_val = count($matchups);
        }

        if (empty($point_values_val)) {
            $point_values_val = $season->default_point_values;
        }

    } else { // Create mode
        $matchup_count_val = $season->default_matchup_count;
        $point_values_val = $season->default_point_values;
    }

    ob_start(); ?>
    <div class="kf-container">
        <h1><?php echo esc_html($page_title); ?></h1>
        <h2 style="margin-top:0;">For Season: <?php echo esc_html($season->name); ?></h2>
        <a href="<?php echo esc_url(site_url('/manage-weeks/')); ?>">← Back to Manage Weeks</a>
        
        <?php if (!$is_matchup_editable && !$is_repair_mode) : ?>
            <div class="notice notice-warning" style="margin-top: 20px;"><p><strong>Editing Locked:</strong> This week is published or finalized. To prevent issues with player picks, matchup details cannot be changed. You can still modify the deadline and re-publish to send an updated notification.</p></div>
        <?php elseif ($is_repair_mode): ?>
             <div class="notice notice-info" style="margin-top: 20px;"><p><strong>Repair Mode:</strong> This week appears to be missing game count and point data. Please fill in the required fields below and save to repair the week.</p></div>
        <?php endif; ?>

        <?php // SPORTS API V1: Game Browser Mode Toggle (only show for editable weeks) ?>
        <?php if ($is_matchup_editable) : ?>
            <?php $default_sport = get_option('kf_default_sport', 'nfl'); ?>
            <div class="kf-mode-toggle" style="margin-top:1.5em;display:flex;gap:0;border-radius:6px;overflow:hidden;border:2px solid var(--kf-primary-color, #2196F3);max-width:400px;">
                <button type="button" class="kf-mode-toggle-btn kf-mode-active" data-mode="manual" style="flex:1;padding:10px 16px;border:none;cursor:pointer;font-weight:bold;font-size:1em;transition:all 0.2s;">&#9998; Manual Entry</button>
                <button type="button" class="kf-mode-toggle-btn" data-mode="api" style="flex:1;padding:10px 16px;border:none;cursor:pointer;font-weight:bold;font-size:1em;transition:all 0.2s;">&#127944; Browse Live Games</button>
            </div>
            <p class="kf-form-note" style="margin-top:6px;">
                <strong>Browse Live Games</strong> pulls real games from ESPN — enables auto-scores and shows odds to players.<br>
                <strong>Manual Entry</strong> works like before — you type team names and enter results yourself.
            </p>

            <div id="kf-game-browser" style="display:none;margin-top:1.5em;" class="kf-card">
                <h3 style="margin-top:0;">Browse Games</h3>
                <div style="display:flex;flex-wrap:wrap;gap:12px;align-items:flex-end;margin-bottom:1em;">
                    <div class="kf-form-group" style="margin-bottom:0;">
                        <label for="kf-sport-select">Sport</label>
                        <select id="kf-sport-select">
                            <option value="nfl" <?php selected($default_sport, 'nfl'); ?>>NFL</option>
                            <option value="college-football" <?php selected($default_sport, 'college-football'); ?>>College Football</option>
                        </select>
                    </div>
                    <div class="kf-form-group" style="margin-bottom:0;">
                        <label for="kf-week-select">Week</label>
                        <select id="kf-week-select">
                            <option value="">-- Select --</option>
                            <?php for ($w = 1; $w <= 18; $w++) : ?>
                                <option value="<?php echo $w; ?>">Week <?php echo $w; ?></option>
                            <?php endfor; ?>
                            <option value="wildcard">Wild Card</option>
                            <option value="divisional">Divisional</option>
                            <option value="conference">Conference</option>
                            <option value="superbowl">Super Bowl</option>
                        </select>
                    </div>
                    <div class="kf-form-group" id="kf-conference-group" style="margin-bottom:0;display:none;">
                        <label for="kf-conference-filter">Conference</label>
                        <select id="kf-conference-filter">
                            <option value="">All Conferences</option>
                            <option value="sec">SEC</option>
                            <option value="big-ten">Big Ten</option>
                            <option value="big-12">Big 12</option>
                            <option value="acc">ACC</option>
                            <option value="pac-12">Pac-12</option>
                            <option value="aac">AAC</option>
                            <option value="mountain-west">Mountain West</option>
                            <option value="sun-belt">Sun Belt</option>
                            <option value="mac">MAC</option>
                            <option value="cusa">Conference USA</option>
                        </select>
                    </div>
                    <button type="button" id="kf-fetch-games-btn" class="kf-button" style="white-space:nowrap;">Fetch Games</button>
                </div>

                <div id="kf-browser-status" class="kf-browser-status" style="display:none;"></div>
                <div id="kf-games-list"></div>

                <div style="display:flex;justify-content:space-between;align-items:center;margin-top:1em;padding-top:1em;border-top:1px solid #ddd;">
                    <span>Selected: <strong id="kf-selected-count">0</strong> game(s)</span>
                    <button type="button" id="kf-add-selected-btn" class="kf-button kf-button-action" disabled>Add Selected Games</button>
                </div>
            </div>
        <?php endif; ?>

        <form method="POST" id="week-edit-form" class="kf-card kf-tracked-form" style="margin-top: 1.5em;">
            <?php wp_nonce_field('kf_week_action', 'kf_week_nonce'); ?>
            
            <fieldset>
                <legend>Week Details</legend>
                <div class="kf-form-group"><label for="week_number">Week Number</label><input type="number" id="week_number" name="week_number" value="<?php echo esc_attr($week->week_number ?? ''); ?>" required></div>
                
                <div class="kf-form-group">
                    <label for="deadline">Submission Deadline (in your local time)</label>
                    <input type="datetime-local" id="deadline" name="deadline" value="<?php echo esc_attr($week ? get_date_from_gmt($week->submission_deadline, 'Y-m-d\TH:i') : ''); ?>" required>
                </div>
            </fieldset>

            <fieldset <?php if (!$is_matchup_editable && !$is_repair_mode) echo 'disabled'; ?>>
                <legend>Game & Point Setup</legend>
                <div class="kf-form-group">
                    <label for="kf_matchup_count">Number of Games</label>
                    <input type="number" id="kf_matchup_count" name="matchup_count" value="<?php echo esc_attr($matchup_count_val); ?>" <?php if ($edit_mode && !$is_repair_mode) echo 'readonly'; ?>>
                    <?php if ($edit_mode && !$is_repair_mode): ?><p class="kf-form-note">Number of games is fixed after a week is created.</p><?php endif; ?>
                </div>
                 <div class="kf-form-group">
                    <label for="kf_point_values">Point Values (comma-separated)</label>
                    <textarea id="kf_point_values" name="point_values" rows="3"><?php echo esc_html($point_values_val); ?></textarea>
                    <p class="kf-form-note">Points Sum: <strong id="kf_points_sum_display">--</strong> | Required Total: <strong><?php echo esc_html($season->weekly_point_total); ?></strong></p>
                </div>
            </fieldset>
            
            <hr><h3>Matchups</h3>
            <div id="matchups-container" <?php if (!$is_matchup_editable && !$is_repair_mode) echo 'style="opacity:0.6;"'; ?>>
                <?php
                $num_to_display = $edit_mode ? count($matchups) : intval($matchup_count_val);
                
                for ($i = 0; $i < $num_to_display; $i++) {
                    $matchup = $matchups[$i] ?? null;
                    $is_tiebreaker_checked = $edit_mode ? ($matchup && $matchup->id == $tiebreaker_parent_id) : ($i === 0);
                    ?>
                    <fieldset class="matchup-fieldset" style="margin-bottom: 16px; padding: 12px; border: 1px solid #ccc; border-radius: 4px;" <?php if (!$is_matchup_editable && !$is_repair_mode) echo 'disabled'; ?>>
                        <legend>Matchup <?php echo $i + 1; ?></legend>
                        <div class="kf-form-group"><label>Away Team (Team B): <input type="text" name="team_b[]" value="<?php echo esc_attr($matchup->team_b ?? ''); ?>" required></label></div>
                        <div class="kf-form-group"><label>Home Team (Team A): <input type="text" name="team_a[]" value="<?php echo esc_attr($matchup->team_a ?? ''); ?>" required></label></div>
                        <div class="kf-form-group"><label><input type="radio" name="tiebreaker_marker" value="<?php echo $i; ?>" <?php checked($is_tiebreaker_checked); ?> required> Mark as Tiebreaker</label></div>
                    </fieldset>
                    <?php
                }
                ?>
            </div>
            
            <div class="kf-form-actions">
                <button type="submit" name="kf_week_save_draft" class="kf-button">Save</button>
                <?php if (($week && $week->status !== 'finalized') || !$week): ?>
                     <button type="submit" name="kf_week_publish" class="kf-button kf-button-action" onclick="return confirm('This will save the week and send a notification to all players. Are you sure?');">Save & Publish</button>
                <?php endif; ?>
            </div>
        </form>
    </div>
    
    <?php // In-page JavaScript for dynamic form functionality ?>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const matchupCountInput = document.getElementById('kf_matchup_count');
        const matchupsContainer = document.getElementById('matchups-container');
        const pointsInput = document.getElementById('kf_point_values');
        const pointsDisplay = document.getElementById('kf_points_sum_display');
        const weekEditForm = document.getElementById('week-edit-form');
        const requiredTotal = <?php echo intval($season->weekly_point_total); ?>;
        
        function createMatchupFieldset(index) {
            const fieldset = document.createElement('fieldset');
            fieldset.className = 'matchup-fieldset';
            fieldset.style.cssText = "margin-bottom: 16px; padding: 12px; border: 1px solid #ccc; border-radius: 4px;";
            // UX CHANGE: Swapped order to Away then Home
            fieldset.innerHTML = `
                <legend>Matchup ${index + 1}</legend>
                <div class="kf-form-group"><label>Away Team (Team B): <input type="text" name="team_b[]" value="" required></label></div>
                <div class="kf-form-group"><label>Home Team (Team A): <input type="text" name="team_a[]" value="" required></label></div>
                <div class="kf-form-group"><label><input type="radio" name="tiebreaker_marker" value="${index}" ${index === 0 ? 'checked' : ''} required> Mark as Tiebreaker</label></div>
            `;
            return fieldset;
        }
        
        function syncMatchups() {
            if (!matchupCountInput || matchupCountInput.readOnly) return; 
            
            const desiredCount = parseInt(matchupCountInput.value, 10) || 0;
            let currentCount = matchupsContainer.querySelectorAll('.matchup-fieldset').length;

            while(currentCount < desiredCount) {
                matchupsContainer.appendChild(createMatchupFieldset(currentCount));
                currentCount++;
            }
            while(currentCount > desiredCount) {
                matchupsContainer.removeChild(matchupsContainer.lastChild);
                currentCount--;
            }
        }

        function validatePoints() {
            if (!pointsInput) return;
            const values = pointsInput.value.split(',').map(v => parseInt(v.trim(), 10));
            const sum = values.filter(v => !isNaN(v)).reduce((acc, val) => acc + val, 0);
            
            pointsDisplay.textContent = sum;
            if (sum === requiredTotal) {
                pointsDisplay.style.color = 'green';
            } else {
                pointsDisplay.style.color = 'red';
            }
        }
        
        if (matchupCountInput) {
            matchupCountInput.addEventListener('change', syncMatchups);
            matchupCountInput.addEventListener('keyup', syncMatchups);
        }
        if (pointsInput) {
            pointsInput.addEventListener('input', validatePoints);
        }
        if (weekEditForm) {
            weekEditForm.addEventListener('submit', function(e) {
                const currentSum = parseInt(pointsDisplay.textContent, 10);
                if (currentSum !== requiredTotal) {
                    e.preventDefault();
                    alert('Error: The sum of the point values (' + currentSum + ') does not match the required weekly total of ' + requiredTotal + '. Please correct the values before saving.');
                }
            });
        }
        
        // Initial runs on page load
        validatePoints();
    });
    </script>
    <?php
    return ob_get_clean();
}