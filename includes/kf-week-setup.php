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
    if (!is_user_logged_in()) { return kf_notice_login_required( 'this page' ); }
    if (session_status() === PHP_SESSION_NONE) { session_start(); }

    global $wpdb;

    // Define table names
    $weeks_table = $wpdb->prefix . 'weeks';
    $matchups_table = $wpdb->prefix . 'matchups';

    // Which league is this page about?
    //
    // With a week id in the URL the WEEK decides. The week is fetched by id alone, so its league
    // has to be authorised before anything is shown or saved: gating on the session's league
    // instead both refused a commissioner who arrived from another league, and — because the
    // save writes season_id from that same variable — let an edit re-home someone else's week
    // into the editor's league.
    $week_id   = isset($_GET['week_id']) ? intval($_GET['week_id']) : 0;
    $edit_mode = $week_id > 0;
    $week      = null;

    if ($edit_mode) {
        $week = $wpdb->get_row($wpdb->prepare("SELECT * FROM $weeks_table WHERE id = %d", $week_id));
        if (!$week) {
            return kf_notice_page(
                'That week no longer exists',
                'The week this link points to has been removed.',
                [ kf_notice_action( 'Manage Weeks', site_url( '/manage-weeks/' ) ), kf_notice_action( 'Home', site_url( '/' ) ) ],
                'warn'
            );
        }
        if (!kf_enter_season_context((int) $week->season_id, true)) {
            return kf_notice_page(
                'That week belongs to another league',
                'You can only set up weeks in a league you run.',
                array_merge( [ kf_notice_action( 'Manage Weeks', site_url( '/manage-weeks/' ) ), kf_notice_action( 'Home', site_url( '/' ) ) ], kf_league_switch_actions( '/manage-weeks/' ) ),
                'warn'
            );
        }
        $season_id = (int) $week->season_id;
    } else {
        $season_id = kf_page_season_id();
        if (!$season_id) {
            return kf_notice_page(
                'No league selected',
                'Choose which league you want to set a week up in.',
                array_merge( [ kf_notice_action( 'Home', site_url( '/' ) ) ], kf_league_switch_actions( '/manage-weeks/' ) ),
                'info'
            );
        }
        if (!kf_can_manage_season($season_id)) {
            return kf_notice_page(
                'You do not run this league',
                'Setting up weeks is for commissioners. If you run a different league, switch to it.',
                array_merge( [ kf_notice_action( 'Home', site_url( '/' ) ), kf_notice_action( 'Season Summary', site_url( '/season-summary/' ) ) ], kf_league_switch_actions( '/manage-weeks/', (int) $season_id ) ),
                'warn'
            );
        }
    }

    // Fetch the season settings, which are crucial for defaults and validation
    $season = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}seasons WHERE id = %d", $season_id));
    if (!$season) {
        return kf_notice_page(
            'League not found',
            'The league this week belongs to no longer exists.',
            array_merge( [ kf_notice_action( 'Home', site_url( '/' ) ) ], kf_league_switch_actions( '/manage-weeks/' ) ),
            'warn'
        );
    }

    $is_matchup_editable = true; // Can the matchups themselves be changed?
    $is_repair_mode = false;     // Is this a special case to fix a broken week?

    if ($edit_mode) {
        if ($week->status !== 'draft') {
            $is_matchup_editable = false;
        }
        if (!$is_matchup_editable && (!isset($week->matchup_count) || !$week->matchup_count)) {
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
                'point_values'        => kf_normalize_point_values( wp_unslash( $_POST['point_values'] ) ),
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

                $team_a_list      = $_POST['team_a'] ?? []; // Home Team
                $team_b_list      = $_POST['team_b'] ?? []; // Away Team
                $tiebreaker_index = isset($_POST['tiebreaker_marker']) ? intval($_POST['tiebreaker_marker']) : -1;

                // Hard control: never store more matchups than the week declares. The browser
                // caps this too, but the check has to exist here as well — the picks form and
                // the weekly point values are both sized from matchup_count, so a week holding
                // more games than it declares produces picks that cannot be scored.
                //
                // Validated BEFORE the delete below: that delete removes every matchup for the
                // week, so bailing out after it would destroy the existing games.
                $submitted_matchups = 0;
                foreach ( $team_a_list as $chk_index => $chk_team_a ) {
                    if ( ! empty( trim( (string) $chk_team_a ) ) && ! empty( trim( (string) ( $team_b_list[ $chk_index ] ?? '' ) ) ) ) {
                        $submitted_matchups++;
                    }
                }
                $declared_matchups = intval( $_POST['matchup_count'] );

                $too_many_matchups = ( $declared_matchups > 0 && $submitted_matchups > $declared_matchups );

                // SAFETY: never rewrite matchups for a week that already has picks.
                // Saving deletes every matchup for the week and re-inserts them, which assigns
                // new AUTO_INCREMENT ids. picks.matchup_id points at the old ids, so a save
                // after players have submitted would orphan every pick in the week and leave it
                // unscoreable. A published week is normally locked, but repair mode (a published
                // week with a missing matchup_count) re-opens this path — which is exactly the
                // situation where picks already exist.
                $existing_picks = $edit_mode ? (int) $wpdb->get_var( $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$wpdb->prefix}picks WHERE week_id = %d", $week_id ) ) : 0;
                $has_picks = $existing_picks > 0;

                if ( $has_picks ) {
                    echo '<div class="notice notice-error"><p><strong>Matchups not changed.</strong> This week already has '
                       . intval( $existing_picks ) . ' submitted pick(s). Rewriting the games now would detach every one of them '
                       . 'and leave the week impossible to score, so the matchups were left exactly as they were. '
                       . 'The week settings (deadline, week number) were saved. To change games, reverse the week first.</p></div>';
                }

                $block_matchup_write = $too_many_matchups || $has_picks;

                if ( $too_many_matchups ) {
                    echo '<div class="notice notice-error"><p><strong>Too many games.</strong> This week is set up for '
                       . intval( $declared_matchups ) . ' game(s) but ' . intval( $submitted_matchups )
                       . ' were submitted. Remove the extra matchups, or raise &ldquo;Games This Week&rdquo;, then save again. '
                       . 'The week settings were saved, but the matchups were left unchanged and nothing was published.</p></div>';
                }

                if ( ! $block_matchup_write ) {

                // Last record before the rewrite. The pick guard above should make this
                // unreachable with picks present, but a snapshot costs nothing and this is
                // the single most destructive statement in the plugin.
                if ( function_exists( 'kf_snapshot_week' ) ) { kf_snapshot_week( $week_id, 'pre_matchup_write' ); }

                $wpdb->delete($matchups_table, ['week_id' => $week_id]);

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
                    // wp_unslash: WordPress backslashes every apostrophe in $_POST, so "Hawai'i
                    // Rainbow Warriors" was stored as "Hawai\'i Rainbow Warriors" — and a pick, which
                    // posts that stored name back, picked up a second backslash and never matched it.
                    $teamA = sanitize_text_field( wp_unslash( $teamA ) );
                    $teamB = sanitize_text_field( wp_unslash( $team_b_list[$index] ) );
                    if (!empty($teamA) && !empty($teamB)) {
                        $matchup_data = [
                            'week_id' => $week_id,
                            'team_a'  => $teamA,
                            'team_b'  => $teamB,
                        ];

                        // Add ESPN/API fields if present (API mode)
                        $espn_id = isset($espn_ids[$index]) ? sanitize_text_field($espn_ids[$index]) : '';
                        // ESPN event ids are numeric. Anything else is discarded rather than
                        // stored: this value later reaches an outbound URL via the score cron.
                        if ( $espn_id !== '' && ! preg_match( '/^[0-9]{1,20}$/', $espn_id ) ) {
                            $espn_id = '';
                        }
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

                } // end: ! $block_matchup_write
            }

            // Never publish (and never email players) when the matchup write was rejected —
            // the week would go out with the wrong games.
            if ($is_publishing && empty($block_matchup_write)) {
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

                $picks_page_url = esc_url( kf_league_url( site_url('/player-dashboard/'), $season_id ) );
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
            echo '<script>setTimeout(function() { window.location.href = ' . wp_json_encode( kf_league_url( site_url('/manage-weeks/'), $season_id ) ) . '; }, 2000);</script>';
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

    // Fill the field with the list in canonical form, so a typo stored on the league's default
    // ("...,5,4.3.2.1") is visibly corrected here rather than copied onto another week.
    $point_values_val = kf_normalize_point_values($point_values_val);

    ob_start(); ?>
    <div class="kf-container">
        <h1><?php echo esc_html($page_title); ?></h1>
        <h2 style="margin-top:0;">For Season: <?php echo esc_html($season->name); ?></h2>
        <a href="<?php echo esc_url( kf_league_url( site_url('/manage-weeks/'), $season_id ) ); ?>">← Back to Manage Weeks</a>
        
        <?php if (!$is_matchup_editable && !$is_repair_mode) : ?>
            <div class="notice notice-warning" style="margin-top: 20px;"><p><strong>Editing Locked:</strong> This week is published or finalized. To prevent issues with player picks, matchup details cannot be changed. You can still modify the deadline and re-publish to send an updated notification.</p></div>
        <?php elseif ($is_repair_mode): ?>
             <div class="notice notice-info" style="margin-top: 20px;"><p><strong>Repair Mode:</strong> This week appears to be missing game count and point data. Please fill in the required fields below and save to repair the week.</p></div>
        <?php endif; ?>

        <?php
        // Pre-calculate existing weeks for duplicate guard + next-available pre-fill
        $existing_week_numbers = array_map( 'intval', $wpdb->get_col( $wpdb->prepare(
            "SELECT week_number FROM {$wpdb->prefix}weeks WHERE season_id = %d ORDER BY week_number ASC",
            $season_id
        ) ) );
        // In edit mode, exclude the week being edited from the "used" list
        if ( $edit_mode && $week ) {
            $existing_week_numbers = array_values( array_diff( $existing_week_numbers, [ intval( $week->week_number ) ] ) );
        }
        $next_week = 1;
        while ( in_array( $next_week, $existing_week_numbers ) ) { $next_week++; }
        $default_week_val = $edit_mode ? ( $week->week_number ?? '' ) : $next_week;

        // Sport / display settings for game browser
        $default_sport    = ! empty( $season->sport_type ) ? $season->sport_type : get_option( 'kf_default_sport', 'nfl' );
        $sport_label      = $default_sport === 'college-football' ? 'College Football' : 'NFL';
        $is_college       = $default_sport === 'college-football';
        $division_display = $is_college ? 'none'  : 'block';
        $conf_display     = $is_college ? 'block' : 'none';
        $show_postseason  = ! $is_college;
        ?>

        <form method="POST" id="week-edit-form" class="kf-tracked-form" style="margin-top:1.5em;">
            <?php wp_nonce_field('kf_week_action', 'kf_week_nonce'); ?>

            <!-- ═══════════════════════════════════════════════════════════
                 STEP 1 — Week basics: fill these before browsing or typing
                 ═══════════════════════════════════════════════════════════ -->
            <div class="kf-card kf-week-sticky-header" style="margin-bottom:1.25em;"
                 data-existing-weeks="<?php echo esc_attr( implode( ',', $existing_week_numbers ) ); ?>">
                <div class="kf-week-quick-setup">

                    <div class="kf-form-group" style="margin-bottom:0;">
                        <label for="week_number" style="font-weight:bold;">League Week #</label>
                        <input type="number" id="week_number" name="week_number"
                               value="<?php echo esc_attr( $default_week_val ); ?>"
                               min="1" max="30" required style="max-width:80px;display:block;">
                        <p class="kf-form-note" style="margin-top:3px;">
                            Next available: <strong>Week <?php echo $next_week; ?></strong>
                            <?php if ( ! empty( $existing_week_numbers ) ) : ?>
                                &nbsp;&middot;&nbsp; Used: <?php echo implode(', ', $existing_week_numbers); ?>
                            <?php endif; ?>
                        </p>
                        <p id="kf-week-dup-warning" style="display:none;color:#c0392b;font-weight:bold;font-size:0.85em;margin-top:4px;">
                            &#9888; This week number already exists for this season!
                        </p>
                    </div>

                    <div class="kf-form-group" style="margin-bottom:0;" <?php if (!$is_matchup_editable && !$is_repair_mode) echo 'style="opacity:0.65;"'; ?>>
                        <label for="kf_matchup_count" style="font-weight:bold;">Games This Week</label>
                        <input type="number" id="kf_matchup_count" name="matchup_count"
                               value="<?php echo esc_attr( $matchup_count_val ); ?>"
                               min="1" max="20" style="max-width:70px;display:block;"
                               <?php if ($edit_mode && !$is_repair_mode) echo 'readonly'; ?>>
                        <?php if ($edit_mode && !$is_repair_mode) : ?>
                            <p class="kf-form-note" style="margin-top:3px;">Fixed after creation.</p>
                        <?php endif; ?>
                    </div>

                    <?php // Read-only mirror of how many matchups are actually in the form. Has no
                          // name attribute on purpose so it is never submitted — "Games This Week"
                          // stays the single source of truth for matchup_count. ?>
                    <div class="kf-form-group" style="margin-bottom:0;">
                        <label for="kf_games_added" style="font-weight:bold;">Games Added</label>
                        <input type="number" id="kf_games_added" value="0" readonly tabindex="-1"
                               style="max-width:70px;display:block;background:#f3f4f6;cursor:default;">
                        <p class="kf-form-note" id="kf-games-added-note" style="margin-top:3px;">&nbsp;</p>
                    </div>

                    <div class="kf-form-group" style="margin-bottom:0;flex:1;min-width:220px;">
                        <label for="deadline" style="font-weight:bold;">Picks Deadline</label>
                        <input type="datetime-local" id="deadline" name="deadline"
                               value="<?php echo esc_attr($week ? get_date_from_gmt($week->submission_deadline, 'Y-m-d\TH:i') : ''); ?>"
                               required style="display:block;">
                        <p class="kf-form-note" style="margin-top:3px;">Your local time.</p>
                    </div>

                    <?php // Live profile of the week being built. Populated by kf-game-browser.js
                          // from the matchup fieldsets, so it reflects both browsed and manual games. ?>
                    <div class="kf-form-group kf-week-profile" style="margin-bottom:0;flex:1;min-width:280px;">
                        <label style="font-weight:bold;">Week Profile</label>
                        <?php
                        // Season baseline for comparison, excluding this week so it is measured
                        // against the rest of the season rather than against a set containing itself.
                        $kf_season_profile = function_exists( 'kf_get_season_spread_profile' )
                            ? kf_get_season_spread_profile( $season_id, $edit_mode ? $week_id : 0 )
                            : null;
                        ?>
                        <div id="kf-week-profile-body" class="kf-week-profile-body"
                            <?php if ( $kf_season_profile ) : ?>
                            data-season-avg="<?php echo esc_attr( number_format( (float) $kf_season_profile->avg_spread, 1, '.', '' ) ); ?>"
                            data-season-closest="<?php echo esc_attr( number_format( (float) $kf_season_profile->closest, 1, '.', '' ) ); ?>"
                            data-season-biggest="<?php echo esc_attr( number_format( (float) $kf_season_profile->biggest, 1, '.', '' ) ); ?>"
                            data-season-weeks="<?php echo esc_attr( intval( $kf_season_profile->weeks ) ); ?>"
                            data-season-games="<?php echo esc_attr( intval( $kf_season_profile->games ) ); ?>"
                            <?php endif; ?>>
                            <span class="kf-profile-empty">Add games to see the week profile.</span>
                        </div>
                    </div>

                </div>
                <div id="kf-week-profile-alerts" class="kf-week-profile-alerts"></div>
            </div>

            <?php if ($is_matchup_editable) : ?>
            <!-- ═══════════════════════════════════════════════════════════
                 STEP 2 — Add games: browse ESPN live or enter manually
                 ═══════════════════════════════════════════════════════════ -->
            <div class="kf-mode-toggle" style="display:flex;gap:0;border-radius:6px;overflow:hidden;border:2px solid var(--kf-primary-color, #2196F3);max-width:420px;">
                <button type="button" class="kf-mode-toggle-btn kf-mode-active" data-mode="manual"
                        style="flex:1;padding:10px 16px;border:none;cursor:pointer;font-weight:bold;font-size:1em;transition:all 0.2s;">&#9998; Manual Entry</button>
                <button type="button" class="kf-mode-toggle-btn" data-mode="api"
                        style="flex:1;padding:10px 16px;border:none;cursor:pointer;font-weight:bold;font-size:1em;transition:all 0.2s;">&#127944; Browse Live Games</button>
            </div>
            <p class="kf-form-note" style="margin-top:6px;margin-bottom:1.25em;">
                <strong>Browse Live Games</strong> pulls real games from ESPN &mdash; enables auto-scores and shows odds to players.&nbsp;
                <strong>Manual Entry</strong> works like before &mdash; type team names yourself.
            </p>

            <?php // NOTE: display and margin must live in ONE style attribute - a second
                  // style attribute on the same element is discarded by the HTML parser. ?>
            <div id="kf-game-browser" class="kf-card" style="display:none;margin-bottom:1.25em;">
                <h3 style="margin-top:0;display:flex;align-items:center;gap:0.5em;flex-wrap:wrap;">
                    <span>&#127944; Browse Games</span>
                    <span id="kf-browser-summary" style="font-size:0.62em;font-weight:400;color:#6b7280;"></span>
                    <button type="button" id="kf-browser-toggle" class="kf-button kf-button-secondary"
                            style="margin-left:auto;font-size:0.6em;padding:4px 12px;">Minimize</button>
                </h3>
                <p class="kf-form-note" style="margin-bottom:1em;">
                    Spread, O/U, and moneyline are pulled from ESPN at fetch time and shown on the picks form.
                    <strong>Odds only appear for upcoming games</strong> &mdash; ESPN does not post lines for completed games.
                </p>

                <!-- Fetch controls row -->
                <div class="kf-browser-fetch-row">
                    <div class="kf-form-group" style="margin-bottom:0;">
                        <label>Sport</label>
                        <div class="kf-sport-locked-display"><?php echo esc_html( $sport_label ); ?></div>
                        <input type="hidden" id="kf-sport-select" value="<?php echo esc_attr( $default_sport ); ?>">
                    </div>

                    <div class="kf-form-group" style="margin-bottom:0;">
                        <label for="kf-week-select">ESPN Calendar Week
                            <span class="kf-form-note" style="font-weight:normal;font-size:0.85em;"> &mdash; auto-filled from Week # above</span>
                        </label>
                        <select id="kf-week-select">
                            <option value="">-- Select --</option>
                            <?php for ($w = 1; $w <= 18; $w++) : ?>
                                <option value="<?php echo $w; ?>">Week <?php echo $w; ?></option>
                            <?php endfor; ?>
                            <?php if ($show_postseason) : ?>
                                <option value="wildcard">Wild Card</option>
                                <option value="divisional">Divisional</option>
                                <option value="conference">Conference Championship</option>
                                <option value="superbowl">Super Bowl</option>
                            <?php endif; ?>
                        </select>
                    </div>

                    <!-- NFL Division filter (client-side) -->
                    <div class="kf-form-group" id="kf-division-group" style="margin-bottom:0;display:<?php echo $division_display; ?>;">
                        <label for="kf-division-filter">Division</label>
                        <select id="kf-division-filter">
                            <option value="">All Divisions</option>
                            <optgroup label="AFC">
                                <option value="afc-east">AFC East</option>
                                <option value="afc-north">AFC North</option>
                                <option value="afc-south">AFC South</option>
                                <option value="afc-west">AFC West</option>
                            </optgroup>
                            <optgroup label="NFC">
                                <option value="nfc-east">NFC East</option>
                                <option value="nfc-north">NFC North</option>
                                <option value="nfc-south">NFC South</option>
                                <option value="nfc-west">NFC West</option>
                            </optgroup>
                        </select>
                    </div>

                    <!-- College Conference filter (client-side — full FBS list fetched once) -->
                    <div class="kf-form-group" id="kf-conference-group" style="margin-bottom:0;display:<?php echo $conf_display; ?>;">
                        <label for="kf-conference-filter">Conference</label>
                        <select id="kf-conference-filter">
                            <option value="fbs">FBS Only (all D-I)</option>
                            <optgroup label="Power 4">
                                <option value="sec">SEC</option>
                                <option value="big-ten">Big Ten</option>
                                <option value="big-12">Big 12</option>
                                <option value="acc">ACC</option>
                            </optgroup>
                            <optgroup label="Group of 5">
                                <option value="aac">American (AAC)</option>
                                <option value="mountain-west">Mountain West</option>
                                <option value="sun-belt">Sun Belt</option>
                                <option value="mac">MAC</option>
                                <option value="cusa">Conf USA</option>
                            </optgroup>
                            <option value="ind">Independents</option>
                            <option value="">All (incl. FCS)</option>
                        </select>
                    </div>

                    <button type="button" id="kf-fetch-games-btn" class="kf-button" style="white-space:nowrap;align-self:flex-end;">Fetch Games</button>
                </div>

                <div id="kf-browser-status" class="kf-browser-status" style="display:none;margin-top:0.75em;"></div>

                <!-- Sort / filter + top counter (shown after fetch) -->
                <div id="kf-sort-filter-bar" class="kf-sort-filter-bar" style="display:none;">
                    <div class="kf-sort-filter-inner">
                        <div class="kf-sort-filter-group">
                            <label>Sort</label>
                            <select id="kf-sort-select">
                                <option value="kickoff">Kickoff Time</option>
                                <option value="spread-biggest">Biggest Favorites First</option>
                                <option value="spread-closest">Closest Games First</option>
                                <option value="over-under">Highest O/U First</option>
                            </select>
                        </div>
                        <div class="kf-sort-filter-group">
                            <label>Show</label>
                            <select id="kf-spread-filter">
                                <option value="">All Games</option>
                                <option value="close">Close only (≤3.5)</option>
                                <option value="moderate">Moderate (4–9.5)</option>
                                <option value="big">Big spreads (10+)</option>
                                <option value="has-odds">Has odds data</option>
                            </select>
                        </div>
                        <div class="kf-sort-filter-group kf-game-search-wrap">
                            <label for="kf-game-search">Search</label>
                            <input type="text" id="kf-game-search" class="kf-game-search" placeholder="Team name&hellip;" autocomplete="off">
                        </div>
                        <div id="kf-game-stats" class="kf-game-stats"></div>
                        <div class="kf-selection-counter-top">
                            <span class="kf-selected-count-text">0 of 0 games selected</span>
                        </div>
                    </div>
                </div>

                <div id="kf-games-list" style="display:block;margin-top:0.5em;"></div>

                <div class="kf-browser-footer">
                    <span class="kf-selected-count-text">0 of 0 games selected</span>
                    <button type="button" id="kf-add-selected-btn" class="kf-button kf-button-action" disabled>Add Selected to Week</button>
                </div>
            </div>
            <?php endif; ?>

            <!-- ═══════════════════════════════════════════════════════════
                 STEP 3 — Point values (then matchups auto-fill below)
                 ═══════════════════════════════════════════════════════════ -->
            <fieldset class="kf-card" <?php if (!$is_matchup_editable && !$is_repair_mode) echo 'disabled'; ?>>
                <legend>Point Values</legend>
                <div class="kf-form-group">
                    <label for="kf_point_values">Point Values (comma-separated)</label>
                    <textarea id="kf_point_values" name="point_values" rows="3"><?php echo esc_html($point_values_val); ?></textarea>
                    <p class="kf-form-note">Points Sum: <strong id="kf_points_sum_display">--</strong> | Required Total: <strong><?php echo esc_html($season->weekly_point_total); ?></strong></p>
                </div>
            </fieldset>
            
            <hr><h3>Matchups</h3>
            <?php // Written to by the remove handler. Deliberately OUTSIDE #matchups-container:
                  // the game browser watches that container and re-renders on every change, so
                  // status text belongs beside it, not inside it. ?>
            <p class="kf-form-note" id="kf-matchup-note" aria-live="polite" style="min-height:1.2em;"></p>
            <div id="matchups-container" <?php if (!$is_matchup_editable && !$is_repair_mode) echo 'style="opacity:0.6;"'; ?>>
                <?php
                $num_to_display = $edit_mode ? count($matchups) : intval($matchup_count_val);
                
                for ($i = 0; $i < $num_to_display; $i++) {
                    $matchup = $matchups[$i] ?? null;
                    $is_tiebreaker_checked = $edit_mode ? ($matchup && $matchup->id == $tiebreaker_parent_id) : ($i === 0);
                    ?>
                    <fieldset class="matchup-fieldset" style="margin-bottom: 16px; padding: 12px; border: 1px solid #ccc; border-radius: 4px;" <?php if (!$is_matchup_editable && !$is_repair_mode) echo 'disabled'; ?>>
                        <legend>Matchup <?php echo $i + 1; ?> <?php if ($is_matchup_editable) : ?><button type="button" class="kf-matchup-remove kf-linkish" title="Remove this matchup from the week">&times; Remove</button><?php endif; ?></legend>
                        <div class="kf-form-group"><label>Away Team (Team B): <input type="text" name="team_b[]" value="<?php echo esc_attr($matchup->team_b ?? ''); ?>" required></label></div>
                        <div class="kf-form-group"><label>Home Team (Team A): <input type="text" name="team_a[]" value="<?php echo esc_attr($matchup->team_a ?? ''); ?>" required></label></div>
                        <div class="kf-form-group"><label><input type="radio" name="tiebreaker_marker" value="<?php echo $i; ?>" <?php checked($is_tiebreaker_checked); ?> required> Mark as Tiebreaker</label></div>
                        <?php
                        // Carry the ESPN/odds fields back through the form. Saving deletes every
                        // matchup for the week and re-inserts from POST, so without these hidden
                        // inputs an edit silently stripped espn_game_id, kickoff time and odds —
                        // which also stopped the score cron from ever matching those games again.
                        //
                        // These must be emitted for EVERY fieldset, even when empty: the POST
                        // handler reads them by the same index as team_a[]/team_b[], so skipping
                        // one would shift the remaining values onto the wrong matchups.
                        $api_fields = [
                            'espn_game_id'      => $matchup->espn_game_id      ?? '',
                            'game_datetime'     => $matchup->game_datetime     ?? '',
                            'odds_api_event_id' => $matchup->odds_api_event_id ?? '',
                            'spread_home'       => $matchup->spread_home       ?? '',
                            'spread_away'       => $matchup->spread_away       ?? '',
                            'moneyline_home'    => $matchup->moneyline_home    ?? '',
                            'moneyline_away'    => $matchup->moneyline_away    ?? '',
                            'over_under'        => $matchup->over_under        ?? '',
                        ];
                        foreach ( $api_fields as $field_name => $field_value ) {
                            printf(
                                '<input type="hidden" name="%s[]" value="%s">',
                                esc_attr( $field_name ),
                                esc_attr( $field_value === null ? '' : $field_value )
                            );
                        }
                        ?>
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

        <?php
        // Link-only ESPN attachment. Rendered for any saved week, including published ones:
        // it posts to kf_espn_apply_link, which UPDATEs the matchup row in place. It never goes
        // through the Save path that deletes and re-inserts matchups, so it is safe once picks
        // exist. Deliberately placed outside the form above so nothing here can submit it.
        ?>
        <?php if ( $edit_mode && ! empty( $matchups ) && function_exists( 'kf_render_espn_linker' ) ) : ?>
            <?php kf_render_espn_linker( $week_id, intval( $week->week_number ?? 0 ) ); ?>
        <?php endif; ?>

        <?php
        // Snapshot log. Read-only on purpose: restoring is a deliberate human decision, not a
        // button, because matchup ids change when a week is rewritten and picks would have to
        // be re-mapped onto the new rows.
        $kf_snapshots = $edit_mode && function_exists( 'kf_get_week_snapshots' ) ? kf_get_week_snapshots( $week_id ) : [];
        ?>
        <?php if ( ! empty( $kf_snapshots ) ) : ?>
        <div class="kf-card" style="margin-top:1.25em;">
            <h3 style="margin-top:0;">&#128451; Week snapshots</h3>
            <p class="kf-form-note" style="margin-top:0;">
                Taken automatically before anything that could change picks or scoring. Picks are the
                only part of a week that cannot be recomputed, so these exist to make sure a mistake is
                always recoverable.
            </p>
            <table class="kf-table" style="width:100%;">
                <thead><tr><th>When</th><th>Taken before</th><th>Picks</th><th>Games</th><th></th></tr></thead>
                <tbody>
                <?php foreach ( $kf_snapshots as $snap ) : ?>
                    <tr>
                        <td><?php echo esc_html( get_date_from_gmt( $snap->created_at, 'M j, g:i A' ) ); ?></td>
                        <td><?php echo esc_html( kf_snapshot_reason_label( $snap->reason ) ); ?></td>
                        <td><?php echo intval( $snap->pick_count ); ?></td>
                        <td><?php echo intval( $snap->matchup_count ); ?></td>
                        <td>
                            <a class="kf-linkish" href="<?php echo esc_url( wp_nonce_url( add_query_arg( [ 'kf_snapshot' => intval( $snap->id ) ] ), 'kf_download_snapshot_' . intval( $snap->id ) ) ); ?>">Download JSON</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
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
                <legend>Matchup ${index + 1} <?php if ($is_matchup_editable) : ?><button type="button" class="kf-matchup-remove kf-linkish" title="Remove this matchup from the week">&times; Remove</button><?php endif; ?></legend>
                <div class="kf-form-group"><label>Away Team (Team B): <input type="text" name="team_b[]" value="" required></label></div>
                <div class="kf-form-group"><label>Home Team (Team A): <input type="text" name="team_a[]" value="" required></label></div>
                <div class="kf-form-group"><label><input type="radio" name="tiebreaker_marker" value="${index}" ${index === 0 ? 'checked' : ''} required> Mark as Tiebreaker</label></div>
                <input type="hidden" name="espn_game_id[]" value="">
                <input type="hidden" name="game_datetime[]" value="">
                <input type="hidden" name="odds_api_event_id[]" value="">
                <input type="hidden" name="spread_home[]" value="">
                <input type="hidden" name="spread_away[]" value="">
                <input type="hidden" name="moneyline_home[]" value="">
                <input type="hidden" name="moneyline_away[]" value="">
                <input type="hidden" name="over_under[]" value="">
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
            // Trim from the end, but only BLANK fieldsets, and by removing the fieldset itself
            // rather than lastChild. Two reasons:
            //  - The PHP-rendered markup has whitespace text nodes between fieldsets, so
            //    removeChild(lastChild) deleted a newline and left the matchup in place. The
            //    declared count and the visible matchups drifted apart, and running the
            //    container empty eventually called removeChild(null), which throws.
            //  - A filled fieldset is a game the commissioner typed or added from ESPN.
            //    Lowering the target must never silently discard one; stop at the first filled
            //    matchup instead. "Games Added" then reads over the target, and the save handler
            //    rejects the write with a message naming both numbers.
            while (currentCount > desiredCount) {
                const fieldsets = matchupsContainer.querySelectorAll('.matchup-fieldset');
                const last      = fieldsets[fieldsets.length - 1];
                if (!last) break;

                const teamA = last.querySelector('input[name="team_a[]"]');
                const teamB = last.querySelector('input[name="team_b[]"]');
                const filled = (teamA && teamA.value.trim() !== '') || (teamB && teamB.value.trim() !== '');
                if (filled) break;

                last.remove();
                currentCount--;
            }
        }

        // --- Removing a matchup ---
        // The save handler walks $_POST['team_a'] by position and reads every other array —
        // team_b[], espn_game_id[], the odds fields — at that same index, and compares
        // tiebreaker_marker against it. Removing a whole fieldset keeps all of those arrays
        // aligned, but the tiebreaker's value is a hard-coded index, so it has to be rewritten
        // against the new positions or the flag lands on the wrong game.
        const matchupNote = document.getElementById('kf-matchup-note');

        function renumberMatchups() {
            const fieldsets = matchupsContainer.querySelectorAll('.matchup-fieldset');
            let hasTiebreaker = false;

            fieldsets.forEach(function (fs, i) {
                const legend = fs.querySelector('legend');
                // Only the leading text node is rewritten, so the ESPN badge and the Remove
                // button that follow it survive the renumber.
                if (legend && legend.firstChild && legend.firstChild.nodeType === 3) {
                    legend.firstChild.nodeValue = 'Matchup ' + (i + 1) + ' ';
                }
                const radio = fs.querySelector('input[name="tiebreaker_marker"]');
                if (radio) {
                    radio.value = i;
                    if (radio.checked) hasTiebreaker = true;
                }
            });

            // The radio is required, so a week with no checked tiebreaker cannot be saved at
            // all — silently falling back to the first matchup beats a form that won't submit
            // and won't say why.
            if (!hasTiebreaker && fieldsets.length) {
                const first = fieldsets[0].querySelector('input[name="tiebreaker_marker"]');
                if (first) { first.checked = true; return true; }
            }
            return false;
        }

        function describeMatchup(fs) {
            const a = fs.querySelector('input[name="team_a[]"]');
            const b = fs.querySelector('input[name="team_b[]"]');
            const home = a ? a.value.trim() : '';
            const away = b ? b.value.trim() : '';
            if (!home && !away) return '';
            return (away || '?') + ' @ ' + (home || '?');
        }

        if (matchupsContainer) {
            matchupsContainer.addEventListener('click', function (e) {
                const btn = e.target.closest('.kf-matchup-remove');
                if (!btn) return;
                e.preventDefault();

                const fs = btn.closest('.matchup-fieldset');
                if (!fs) return;

                // Confirm only when there is something to lose. Blank placeholder fieldsets are
                // the common case and nagging about those would train the click away.
                const label = describeMatchup(fs);
                if (label && !confirm('Remove ' + label + ' from this week?')) return;

                fs.remove();
                const tiebreakerMoved = renumberMatchups();

                // Removing a fieldset fires no change event, so the tracked-form guard in
                // kf-table-controls.js never learned the week had unsaved edits and let the
                // commissioner navigate away without the usual warning.
                if (typeof window.kerryFootballFormDirty !== 'undefined') { window.kerryFootballFormDirty = true; }

                if (matchupNote) {
                    let msg = label ? 'Removed ' + label + '.' : 'Removed an empty matchup.';
                    if (tiebreakerMoved) { msg += ' It was the tiebreaker, so Matchup 1 is now marked instead — change it if that is wrong.'; }
                    msg += ' Nothing is saved until you press Save.';
                    matchupNote.textContent = msg;
                }
            });
        }

        function validatePoints() {
            if (!pointsInput) return;
            // Any run of non-digits separates two values, matching kf_parse_point_values() on save.
            const values = pointsInput.value.split(/[^0-9]+/).map(v => parseInt(v, 10));
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