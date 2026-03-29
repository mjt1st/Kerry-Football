<?php
/**
 * "My Picks" Shortcode
 *
 * FIX PACK (V2.3.1)
 * - SEASON SCOPE: Derive season_id from week_id (no session reliance).
 * - POINT OPTIONS: Built strictly from the number of non-tiebreaker matchups in THIS week.
 * - DB SAVE GUARDS: Standard and BPOW saves are fully separated. Only save a section if it has input.
 * - NO CROSS-POLLINATION: BPOW picks never land in Standard (and vice versa).
 * - READBACK: Queries scoped strictly to is_bpow=0 vs is_bpow=1.
 * - INLINE, SCOPED JS: Disables already-picked point values within each section independently.
 * - CLEANUP: Remove duplicate ob_end_clean() usage.
 */

// ---------- Utility: Read-only card ----------
function _kf_display_readonly_picks_view($title, $message, $dd_selection_exists, $existing_picks_results) {
    ?>
    <div class="kf-card" style="border-left:5px solid #ffc107;background:#fff3cd;margin-bottom:1.5em;">
        <h3 style="margin:0 0 .5em 0;border:none;font-size:1.2em;"><?php echo esc_html($title); ?></h3>
        <p style="margin:0;"><?php echo wp_kses_post($message); ?></p>
    </div>
    <?php if ($dd_selection_exists): ?>
        <div class="kf-card" style="border-left:5px solid var(--kf-primary-color);background:#e3f2fd;margin-bottom:1.5em;">
            <p style="margin:0;font-weight:bold;">⭐ Double Down was used for this week.</p>
        </div>
    <?php endif; ?>

    <table class="kf-table">
        <thead><tr><th>Matchup</th><th>Pick</th><th>Points</th></tr></thead>
        <tbody>
        <?php
        if (!empty($existing_picks_results)) {
            foreach ($existing_picks_results as $pick) {
                echo '<tr>';
                echo '<td>' . esc_html($pick->team_b . ' @ ' . $pick->team_a) . '</td>';
                echo '<td>' . esc_html($pick->pick) . '</td>';
                echo '<td>' . ($pick->point_value > 0 ? esc_html($pick->point_value) : 'Tiebreaker') . '</td>';
                echo '</tr>';
            }
        } else {
            echo '<tr><td colspan="3">No picks were submitted for this week.</td></tr>';
        }
        ?>
        </tbody>
    </table>
    <?php
}

// ---------- Utility: Render picks form section (Standard or BPOW) ----------
function _kf_display_picks_form(
    $is_bpow_form,
    $week_id,
    $current_week,
    $target_user_id,
    $season_id,
    $active_season_for_dd,
    $existing_picks,            // array keyed by matchup_id with ['pick','point_value']
    $dd_selection_exists,
    $deadline_passed,
    $is_commissioner
) {
    global $wpdb;

    $pick_name_prefix  = $is_bpow_form ? 'bpow_picks'  : 'picks';
    $point_name_prefix = $is_bpow_form ? 'bpow_points' : 'points';

    $is_late_submission_form = ($deadline_passed && !$is_commissioner && !$is_bpow_form);
    if ($is_late_submission_form) {
        echo '<div class="notice notice-warning" style="margin-bottom:1em;"><p><strong>The deadline has passed.</strong> Your picks will be submitted as a late request and must be approved by the commissioner.</p></div>';
    }

    echo '<input type="hidden" name="is_bpow_submission_helper" value="'. ($is_bpow_form ? '1' : '0') .'">';
    if ($is_late_submission_form) {
        echo '<input type="hidden" name="is_late_submission" value="1">';
    }

    // Double Down (only Standard form & not late request)
    if (!$is_bpow_form && !$is_late_submission_form) {
        $dd_uses_completed = (int)$wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(sh.id) FROM {$wpdb->prefix}score_history sh
             JOIN {$wpdb->prefix}weeks w ON sh.replaced_by_week_id = w.id
             WHERE sh.user_id = %d AND w.season_id = %d AND w.status = 'finalized'",
            $target_user_id, $season_id
        ));
        $dd_uses_remaining = max(0, (int)$active_season_for_dd->dd_max_uses - $dd_uses_completed);
        $is_dd_eligible = ((int)$current_week->week_number >= (int)$active_season_for_dd->dd_enabled_week && $dd_uses_remaining > 0);

        if ($is_dd_eligible): ?>
            <div class="kf-card" style="margin-bottom:2em;background:#f8f9fa;border-left:5px solid var(--kf-primary-color);">
                <h3 style="color:var(--kf-primary-color);border:none;margin-top:0;padding-bottom:0;">⭐ Double Down Available!</h3>
                <p style="margin-top:.5em;">This player has <strong><?php echo (int)$dd_uses_remaining; ?></strong> use(s) remaining.</p>
                <label for="double_down" style="font-weight:bold;font-size:1.1em;cursor:pointer;">
                    <input type="checkbox" id="double_down" name="double_down" value="1" <?php checked($dd_selection_exists); ?> style="transform:scale(1.2);margin-right:10px;">Yes, use Double Down for this week.
                </label>
            </div>
        <?php endif;
    }

       // Matchups (non-tiebreakers) for this WEEK
    $matchups = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}matchups WHERE week_id = %d AND is_tiebreaker = 0 ORDER BY id ASC",
        $week_id
    ));
    // Tiebreaker row only in Standard form
    $tiebreaker_matchup = !$is_bpow_form ? $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}matchups WHERE week_id = %d AND is_tiebreaker = 1",
        $week_id
    )) : null;

    /**
     * POINT OPTIONS:
     * 1) Prefer custom per-week point_values from the weeks table (e.g. "6,7,8,14,15,16,17,18,19").
     * 2) If empty, fall back to legacy behavior: 1..(number of non-tiebreaker games).
     */
    $point_options = [];
    $point_values_raw = '';
    if (!empty($current_week) && isset($current_week->point_values)) {
        $point_values_raw = trim((string)$current_week->point_values);
    }

    if ($point_values_raw !== '') {
        // Build from CSV in week.point_values
        $tmp = [];
        foreach (explode(',', $point_values_raw) as $val) {
            $v = (int)trim($val);
            if ($v > 0) {
                $tmp[$v] = $v; // de-dupe by value
            }
        }
        if (!empty($tmp)) {
            ksort($tmp, SORT_NUMERIC);
            $point_options = array_values($tmp);
        }
    }

    // Fallback: if no usable custom list, keep legacy 1..#games behavior
    if (empty($point_options)) {
        $max_points = $matchups ? count($matchups) : 0;
        $point_options = $max_points > 0 ? range(1, $max_points) : [];
    }


    // Scope wrapper helps JS isolate sections
    $scope = $is_bpow_form ? 'bpow' : 'standard';
    echo '<div class="kf-form-section" data-scope="'. esc_attr($scope) .'">';

    ?>
    <table class="kf-table kf-picks-table-<?php echo esc_attr($scope); ?>">
        <thead><tr><th>Matchup</th><th>Pick</th><th>Point Value</th></tr></thead>
        <tbody>
        <?php foreach ($matchups as $matchup):
            $saved_pick     = $existing_picks[$matchup->id] ?? null;
            $selected_point = (int)($saved_pick['point_value'] ?? 0);
            $has_odds       = !empty($matchup->spread_home) || !empty($matchup->moneyline_home) || !empty($matchup->over_under);
            ?>
            <tr>
                <td>
                    <?php echo esc_html($matchup->team_b . ' @ ' . $matchup->team_a); ?>
                    <?php // SPORTS API V1: Show odds below matchup name if available ?>
                    <?php if ($has_odds): ?>
                        <div class="kf-odds-line">
                            <?php if ($matchup->spread_home !== null): ?>
                                <span>Spread: <?php
                                    $spread_val = floatval($matchup->spread_home);
                                    $spread_display = ($spread_val > 0 ? '+' : '') . number_format($spread_val, 1);
                                    // Figure out which team is the favorite
                                    $fav_team = $spread_val < 0 ? esc_html($matchup->team_a) : esc_html($matchup->team_b);
                                    $fav_spread = $spread_val < 0 ? $spread_display : (($matchup->spread_away !== null) ? (floatval($matchup->spread_away) > 0 ? '+' : '') . number_format(floatval($matchup->spread_away), 1) : '');
                                    echo esc_html($fav_team) . ' ' . esc_html($fav_spread);
                                ?></span>
                            <?php endif; ?>
                            <?php if ($matchup->over_under !== null): ?>
                                <span>O/U: <?php echo esc_html(number_format(floatval($matchup->over_under), 1)); ?></span>
                            <?php endif; ?>
                            <?php if ($matchup->moneyline_home !== null && $matchup->moneyline_away !== null): ?>
                                <span>ML: <?php
                                    $ml_home = intval($matchup->moneyline_home);
                                    $ml_away = intval($matchup->moneyline_away);
                                    echo esc_html(($ml_home > 0 ? '+' : '') . $ml_home) . '/' . esc_html(($ml_away > 0 ? '+' : '') . $ml_away);
                                ?></span>
                            <?php endif; ?>
                        </div>
                        <?php if ( ! empty( $matchup->odds_updated_at ) ) : ?>
                            <div class="kf-odds-timestamp">
                                Odds via ESPN &middot; as of <?php echo esc_html( date( 'M j, Y', strtotime( $matchup->odds_updated_at ) ) ); ?>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </td>
                <td>
                    <select name="<?php echo esc_attr($pick_name_prefix); ?>[<?php echo (int)$matchup->id; ?>]" required class="kf-pick-select">
                        <option value="">-- Select Winner --</option>
                        <option value="<?php echo esc_attr($matchup->team_a); ?>" <?php selected($saved_pick['pick'] ?? '', $matchup->team_a); ?>><?php echo esc_html($matchup->team_a); ?></option>
                        <option value="<?php echo esc_attr($matchup->team_b); ?>" <?php selected($saved_pick['pick'] ?? '', $matchup->team_b); ?>><?php echo esc_html($matchup->team_b); ?></option>
                    </select>
                </td>
                <td>
                    <select name="<?php echo esc_attr($point_name_prefix); ?>[<?php echo (int)$matchup->id; ?>]" required class="kf-point-select">
                        <option value="">--</option>
                        <?php foreach ($point_options as $point): ?>
                            <option value="<?php echo (int)$point; ?>" <?php selected($selected_point, (int)$point); ?>><?php echo (int)$point; ?></option>
                        <?php endforeach; ?>
                    </select>
                </td>
            </tr>
        <?php endforeach; ?>

        <?php if ($tiebreaker_matchup):
            $tb_saved = $existing_picks[$tiebreaker_matchup->id] ?? null;
            $tb_has_odds = !empty($tiebreaker_matchup->spread_home) || !empty($tiebreaker_matchup->over_under);
            ?>
            <tr class="kf-tiebreaker-row">
                <td>
                    <?php echo esc_html($tiebreaker_matchup->team_b . ' @ ' . $tiebreaker_matchup->team_a); ?> <strong>(Tiebreaker)</strong>
                    <?php if ($tb_has_odds): ?>
                        <div class="kf-odds-line">
                            <?php if ($tiebreaker_matchup->spread_home !== null): ?>
                                <span>Spread: <?php
                                    $sp = floatval($tiebreaker_matchup->spread_home);
                                    $sp_d = ($sp > 0 ? '+' : '') . number_format($sp, 1);
                                    $fav = $sp < 0 ? esc_html($tiebreaker_matchup->team_a) : esc_html($tiebreaker_matchup->team_b);
                                    $fav_sp = $sp < 0 ? $sp_d : (($tiebreaker_matchup->spread_away !== null) ? (floatval($tiebreaker_matchup->spread_away) > 0 ? '+' : '') . number_format(floatval($tiebreaker_matchup->spread_away), 1) : '');
                                    echo esc_html($fav) . ' ' . esc_html($fav_sp);
                                ?></span>
                            <?php endif; ?>
                            <?php if ($tiebreaker_matchup->over_under !== null): ?>
                                <span>O/U: <?php echo esc_html(number_format(floatval($tiebreaker_matchup->over_under), 1)); ?></span>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </td>
                <td><input type="number" step="any" inputmode="decimal" name="<?php echo esc_attr($pick_name_prefix); ?>[<?php echo (int)$tiebreaker_matchup->id; ?>]" value="<?php echo esc_attr($tb_saved['pick'] ?? ''); ?>" placeholder="Total Points" class="small-text" required></td>
                <td><input type="hidden" name="<?php echo esc_attr($point_name_prefix); ?>[<?php echo (int)$tiebreaker_matchup->id; ?>]" value="0">Tiebreaker</td>
            </tr>
        <?php endif; ?>
        </tbody>
    </table>
    <?php
    echo '</div>'; // .kf-form-section
}

// ---------- Shortcode ----------
function kf_my_picks_shortcode() {
    ob_start();

    if (!is_user_logged_in()) {
        $out = '<div class="kf-container"><p>You must be logged in to make your picks.</p></div>';
        ob_end_clean();
        return $out;
    }

    global $wpdb;

    $current_user_id     = get_current_user_id();
    $is_commissioner     = current_user_can('manage_options');
    $target_user_id      = $current_user_id;
    $is_editing_as_other = false;

    if ($is_commissioner && isset($_GET['view_as']) && is_numeric($_GET['view_as'])) {
        $target_user_id      = (int)$_GET['view_as'];
        $is_editing_as_other = true;
    }

    // --- Week first (authoritative) ---
    $week_id = isset($_GET['week_id']) ? (int)$_GET['week_id'] : 0;
    if (!$week_id) {
        $out = '<div class="kf-container"><p>No week was specified.</p></div>';
        ob_end_clean();
        return $out;
    }

    $weeks_table  = $wpdb->prefix . 'weeks';
    $current_week = $wpdb->get_row($wpdb->prepare("SELECT * FROM $weeks_table WHERE id = %d", $week_id));
    if (!$current_week) {
        $out = '<div class="kf-container"><p>The requested week could not be found.</p></div>';
        ob_end_clean();
        return $out;
    }

    // --- Derive season from week (prevents cross-season bleed) ---
    $season_id = (int)$current_week->season_id;
    $active_season_for_dd = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}seasons WHERE id = %d",
        $season_id
    ));
    if (!$active_season_for_dd) {
        $out = '<div class="kf-container"><p>Season not found for this week.</p></div>';
        ob_end_clean();
        return $out;
    }

    if ('draft' === $current_week->status && !$is_commissioner) {
        $out = '<div class="kf-container"><h1>Picks Not Available</h1><p>The matchups for this week have not been published yet. Please check back later.</p></div>';
        ob_end_clean();
        return $out;
    }

    $player_info = get_userdata($target_user_id);
    if (!$player_info) {
        $out = '<div class="kf-container"><p>Invalid player specified.</p></div>';
        ob_end_clean();
        return $out;
    }

    $player_status = $wpdb->get_var($wpdb->prepare(
        "SELECT status FROM {$wpdb->prefix}season_players WHERE user_id = %d AND season_id = %d",
        $target_user_id, $season_id
    ));
    if ($player_status !== 'accepted') {
        $out = "<div class='kf-container'><p><strong>".esc_html($player_info->display_name)."</strong> is not an active player in this season.</p></div>";
        ob_end_clean();
        return $out;
    }

    // Deadline
    $deadline_passed = false;
    if (!empty($current_week->submission_deadline) && $current_week->submission_deadline !== '0000-00-00 00:00:00') {
        $current_time_gmt = current_time('mysql', 1);
        if ($current_time_gmt >= $current_week->submission_deadline) {
            $deadline_passed = true;
        }
    }

    // Pending late submission (only show if actually still pending, not if declined/approved)
    $pending_submission = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}pending_picks WHERE user_id = %d AND week_id = %d AND status = 'pending' ORDER BY requested_at DESC LIMIT 1",
        $target_user_id, $week_id
    ));

    // BPOW eligibility — find the most recent finalized week (not just week_number - 1)
    $is_bpow_eligible = false;
    if ((int)$current_week->week_number > 1) {
        $last_bpow_winner = $wpdb->get_var($wpdb->prepare(
            "SELECT bpow_winner_user_id FROM $weeks_table
             WHERE season_id = %d AND week_number < %d AND status = 'finalized'
             ORDER BY week_number DESC LIMIT 1",
            $season_id, (int)$current_week->week_number
        ));
        if ($last_bpow_winner && (int)$last_bpow_winner === (int)$target_user_id) {
            $is_bpow_eligible = true;
        }
    }

    // --- FORM HANDLING (HARD SEPARATION: STD vs BPOW) ---
    if (
        'POST' === $_SERVER['REQUEST_METHOD'] &&
        isset($_POST['kf_submit_picks_nonce']) &&
        wp_verify_nonce($_POST['kf_submit_picks_nonce'], 'kf_submit_picks_action')
    ) {
        $is_late_submission_request = !empty($_POST['is_late_submission']) && $_POST['is_late_submission'] === '1';

        if ($current_week->status === 'finalized' || ($deadline_passed && !$is_commissioner && !$is_late_submission_request)) {
            echo '<div class="notice notice-error"><p>Submissions are closed for this week.</p></div>';
        } else {
            // Raw inputs (keep the two sections totally independent)
            $std_picks_raw  = (isset($_POST['picks'])       && is_array($_POST['picks']))       ? $_POST['picks']       : [];
            $std_points_raw = (isset($_POST['points'])      && is_array($_POST['points']))      ? $_POST['points']      : [];
            $bpw_picks_raw  = (isset($_POST['bpow_picks'])  && is_array($_POST['bpow_picks']))  ? $_POST['bpow_picks']  : [];
            $bpw_points_raw = (isset($_POST['bpow_points']) && is_array($_POST['bpow_points'])) ? $_POST['bpow_points'] : [];

            // Build normalized STD arrays strictly from STD inputs
            $standard_picks  = [];
            $standard_points = [];
            foreach ($std_picks_raw as $mid => $pick) {
                $mid  = (int)$mid;
                $pick = sanitize_text_field((string)$pick);
                $pv   = isset($std_points_raw[$mid]) ? (int)$std_points_raw[$mid] : null;
                // accept normal rows (pick != '') or tiebreaker row (pv === 0 with numeric total in pick)
                if ($pick !== '' || $pv === 0) {
                    $standard_picks[$mid]  = $pick;
                    $standard_points[$mid] = ($pv === null ? 0 : (int)$pv);
                }
            }

            // Build normalized BPOW arrays strictly from BPOW inputs
            $bpow_picks  = [];
            $bpow_points = [];
            foreach ($bpw_picks_raw as $mid => $pick) {
                $mid  = (int)$mid;
                $pick = sanitize_text_field((string)$pick);
                if ($pick === '') { continue; }           // BPOW has no tiebreaker row
                $pv   = isset($bpw_points_raw[$mid]) ? (int)$bpw_points_raw[$mid] : 0;
                $bpow_picks[$mid]  = $pick;
                $bpow_points[$mid] = $pv;
            }

            // Server-side validation: matchup IDs must belong to this week
            $valid_matchup_ids = $wpdb->get_col($wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}matchups WHERE week_id = %d", $week_id
            ));
            $valid_non_tb_ids = $wpdb->get_col($wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}matchups WHERE week_id = %d AND is_tiebreaker = 0", $week_id
            ));
            $valid_tb_ids = $wpdb->get_col($wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}matchups WHERE week_id = %d AND is_tiebreaker = 1", $week_id
            ));

            // Filter out any matchup IDs not belonging to this week
            $standard_picks  = array_filter($standard_picks,  fn($v, $k) => in_array((int)$k, $valid_matchup_ids), ARRAY_FILTER_USE_BOTH);
            $standard_points = array_filter($standard_points, fn($v, $k) => in_array((int)$k, $valid_matchup_ids), ARRAY_FILTER_USE_BOTH);
            if (!empty($bpow_picks)) {
                $bpow_picks  = array_filter($bpow_picks,  fn($v, $k) => in_array((int)$k, $valid_matchup_ids), ARRAY_FILTER_USE_BOTH);
                $bpow_points = array_filter($bpow_points, fn($v, $k) => in_array((int)$k, $valid_matchup_ids), ARRAY_FILTER_USE_BOTH);
            }

            // Check that ALL non-tiebreaker matchups have a pick (standard picks)
            $std_matchup_ids_submitted = array_keys(array_filter($standard_points, fn($v) => (int)$v > 0));
            $missing_matchups = array_diff($valid_non_tb_ids, $std_matchup_ids_submitted);
            // Tiebreaker matchup is allowed to have point_value=0, so check it separately
            $has_tiebreaker_pick = !empty($valid_tb_ids) ? !empty(array_intersect(array_map('intval', array_keys($standard_picks)), array_map('intval', $valid_tb_ids))) : true;

            if (!empty($missing_matchups) || !$has_tiebreaker_pick) {
                echo '<div class="notice notice-error"><p>Error: You must make a pick for every game before submitting.</p></div>';
            } else {

            // Server-side uniqueness check for point values (>0) inside each section
            $std_non_tb_points = array_values(array_filter($standard_points, fn($v) => (int)$v > 0));
            $bpw_non_tb_points = array_values(array_filter($bpow_points,     fn($v) => (int)$v > 0));

            $std_valid = (count($standard_picks) === count($standard_points)) &&
                         (count($std_non_tb_points) === count(array_unique($std_non_tb_points)));
            $bpw_valid = true;
            if ($is_bpow_eligible && !empty($bpow_picks)) {
                $bpw_valid = (count($bpow_picks) === count($bpow_points)) &&
                             (count($bpw_non_tb_points) === count(array_unique($bpw_non_tb_points)));
            }

                        // --- Range guard (prevents invalid point values for THIS week) ---
            // Prefer the current week's custom point_values, else fall back to 1..#games.
            $allowed_points = [];
            $week_point_values_raw = '';
            if (!empty($current_week) && isset($current_week->point_values)) {
                $week_point_values_raw = trim((string)$current_week->point_values);
            }

            if ($week_point_values_raw !== '') {
                foreach (explode(',', $week_point_values_raw) as $val) {
                    $v = (int)trim($val);
                    if ($v > 0) {
                        $allowed_points[$v] = true;
                    }
                }
            } else {
                // Legacy behavior: 1..number of non-tiebreaker matchups
                $max_points = (int)$wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$wpdb->prefix}matchups WHERE week_id = %d AND is_tiebreaker = 0",
                    $week_id
                ));
                if ($max_points > 0) {
                    for ($i = 1; $i <= $max_points; $i++) {
                        $allowed_points[$i] = true;
                    }
                }
            }

            $std_in_range = array_filter(
                $std_non_tb_points,
                fn($v) => isset($allowed_points[(int)$v])
            );
            $bpw_in_range = array_filter(
                $bpw_non_tb_points,
                fn($v) => isset($allowed_points[(int)$v])
            );

            $std_valid = $std_valid && (count($std_in_range) === count($std_non_tb_points));
            $bpw_valid = $bpw_valid && (count($bpw_in_range) === count($bpw_non_tb_points));

            if (!$std_valid || ($is_bpow_eligible && !empty($bpow_picks) && !$bpw_valid)) {
                echo '<div class="notice notice-error"><p>Error: Please ensure you have made a pick for every game and used each point value only once (within this week\'s range).</p></div>';
            } else {
                if ($is_late_submission_request && !$is_commissioner) {
                    $to_store = ['picks' => $standard_picks, 'points' => $standard_points];
                    $wpdb->delete("{$wpdb->prefix}pending_picks", ['user_id' => $target_user_id, 'week_id' => $week_id]);
                    $wpdb->insert("{$wpdb->prefix}pending_picks", [
                        'user_id'      => $target_user_id,
                        'week_id'      => $week_id,
                        'picks_data'   => wp_json_encode($to_store),
                        'status'       => 'pending',
                        'requested_at' => current_time('mysql', 1),
                    ]);
                    echo '<div class="notice notice-success is-dismissible"><p>Your late submission has been sent to the commissioner for review.</p></div>';
                } else {
                    $wpdb->query('START TRANSACTION');

                    // --- Save STANDARD (is_bpow=0) only if we received any STD input this submit ---
                    $has_any_std = !empty($standard_picks);
                    if ($has_any_std) {
                        $wpdb->delete("{$wpdb->prefix}picks", [
                            'user_id' => $target_user_id,
                            'week_id' => $week_id,
                            'is_bpow' => 0,
                        ]);
                        foreach ($standard_picks as $mid => $pick) {
                            $wpdb->insert("{$wpdb->prefix}picks", [
                                'user_id'     => $target_user_id,
                                'week_id'     => $week_id,
                                'matchup_id'  => (int)$mid,
                                'pick'        => $pick,
                                'point_value' => (int)($standard_points[$mid] ?? 0),
                                'is_bpow'     => 0,
                            ]);
                        }
                    }
                    // NOTE: If no STD input, we do not touch existing STD rows at all.

                    // --- Save BPOW (is_bpow=1) only if eligible AND we received BPOW input ---
                    if ($is_bpow_eligible) {
                        $has_any_bpw = !empty($bpow_picks);
                        if ($has_any_bpw) {
                            $wpdb->delete("{$wpdb->prefix}picks", [
                                'user_id' => $target_user_id,
                                'week_id' => $week_id,
                                'is_bpow' => 1,
                            ]);
                            foreach ($bpow_picks as $mid => $pick) {
                                $wpdb->insert("{$wpdb->prefix}picks", [
                                    'user_id'     => $target_user_id,
                                    'week_id'     => $week_id,
                                    'matchup_id'  => (int)$mid,
                                    'pick'        => $pick,
                                    'point_value' => (int)($bpow_points[$mid] ?? 0),
                                    'is_bpow'     => 1,
                                ]);
                            }
                        }
                    }

                    // Double Down
                    $is_double_down_selected = !empty($_POST['double_down']) ? 1 : 0;
                    $wpdb->delete("{$wpdb->prefix}dd_selections", ['user_id' => $target_user_id, 'week_id' => $week_id]);
                    if ($is_double_down_selected) {
                        $wpdb->insert("{$wpdb->prefix}dd_selections", [
                            'user_id'  => $target_user_id,
                            'season_id'=> $season_id,
                            'week_id'  => $week_id,
                        ]);
                    }

                    $wpdb->query('COMMIT');

                    // Quick log to confirm separation at save time:
                    error_log(sprintf('[KF Picks] saved: user=%d week=%d std=%d bpw=%d',
                        (int)$target_user_id, (int)$week_id, count($standard_picks), count($bpow_picks)));

                    echo '<div class="notice notice-success is-dismissible"><p>Picks have been saved successfully!</p></div>';
                }
            }
        } // end missing matchups check
        }
    }

    // ---------- Display data ----------
    $all_season_players = [];
    if ($is_commissioner) {
        $all_season_players = $wpdb->get_results($wpdb->prepare(
            "SELECT u.ID, u.display_name
             FROM {$wpdb->prefix}users u
             JOIN {$wpdb->prefix}season_players sp ON u.ID = sp.user_id
             WHERE sp.season_id = %d AND sp.status = 'accepted'
             ORDER BY u.display_name ASC",
            $season_id
        ));
    }
    $prev_week = $wpdb->get_row($wpdb->prepare(
        "SELECT id FROM {$wpdb->prefix}weeks WHERE season_id = %d AND week_number < %d AND status != 'draft' ORDER BY week_number DESC LIMIT 1",
        $season_id, $current_week->week_number
    ));
    $next_week = $wpdb->get_row($wpdb->prepare(
        "SELECT id FROM {$wpdb->prefix}weeks WHERE season_id = %d AND week_number > %d AND status != 'draft' ORDER BY week_number ASC LIMIT 1",
        $season_id, $current_week->week_number
    ));

    // Existing Standard picks (is_bpow = 0)
    $existing_picks_results = $wpdb->get_results($wpdb->prepare(
        "SELECT m.team_a, m.team_b, p.pick, p.point_value, m.id as matchup_id
         FROM {$wpdb->prefix}picks p
         JOIN {$wpdb->prefix}matchups m ON p.matchup_id = m.id
         WHERE p.user_id = %d AND p.week_id = %d AND p.is_bpow = 0
         ORDER BY p.point_value DESC, m.id ASC",
        $target_user_id, $week_id
    ));
    $existing_picks = [];
    foreach ($existing_picks_results as $row) {
        $existing_picks[(int)$row->matchup_id] = [
            'pick'        => $row->pick,
            'point_value' => (int)$row->point_value,
        ];
    }

    // Existing BPOW picks (is_bpow = 1, no tiebreaker)
    $existing_bpow_picks = [];
    if ($is_bpow_eligible) {
        $existing_bpow_picks_results = $wpdb->get_results($wpdb->prepare(
            "SELECT m.id as matchup_id, p.pick, p.point_value
             FROM {$wpdb->prefix}picks p
             JOIN {$wpdb->prefix}matchups m ON p.matchup_id = m.id
             WHERE p.user_id = %d AND p.week_id = %d AND p.is_bpow = 1 AND m.is_tiebreaker = 0
             ORDER BY p.point_value DESC, m.id ASC",
            $target_user_id, $week_id
        ));
        foreach ($existing_bpow_picks_results as $row) {
            $existing_bpow_picks[(int)$row->matchup_id] = [
                'pick'        => $row->pick,
                'point_value' => (int)$row->point_value,
            ];
        }
    }

    $dd_selection_exists = $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM {$wpdb->prefix}dd_selections WHERE user_id = %d AND week_id = %d",
        $target_user_id, $week_id
    ));

    // ---------- Render ----------
    ob_start(); ?>
    <div class="kf-container">
        <div class="kf-breadcrumbs">
            <a href="<?php echo esc_url(site_url('/')); ?>">Homepage</a> &raquo;
            <a href="<?php echo esc_url(site_url('/player-dashboard/')); ?>">Player Dashboard</a> &raquo;
            <span>Picks for Week <?php echo esc_html($current_week->week_number); ?></span>
        </div>

        <?php if ($is_editing_as_other):
            $season_summary_url = add_query_arg(['view_as' => $target_user_id], site_url('/season-summary/')); ?>
            <div class="kf-back-link" style="margin-bottom:1em;">
                <a href="<?php echo esc_url($season_summary_url); ?>">← Back to <?php echo esc_html($player_info->display_name); ?>'s Season Summary</a>
            </div>
        <?php endif; ?>

        <div class="kf-page-header">
            <h2>Picks for Week <?php echo esc_html($current_week->week_number); ?></h2>
            <div class="kf-week-nav">
                <?php
                $nav_args = [];
                if ($is_editing_as_other) { $nav_args['view_as'] = $target_user_id; }
                if ($prev_week): ?>
                    <a href="<?php echo esc_url(add_query_arg(array_merge($nav_args, ['week_id' => $prev_week->id]), get_permalink())); ?>" class="kf-button">&laquo; Prev Week</a>
                <?php endif; ?>
                <?php if ($next_week): ?>
                    <a href="<?php echo esc_url(add_query_arg(array_merge($nav_args, ['week_id' => $next_week->id]), get_permalink())); ?>" class="kf-button">Next Week &raquo;</a>
                <?php endif; ?>
            </div>
        </div>

        <?php
        if ($current_week->status !== 'finalized') {
            $deadline_formatted = 'Not set';
            if ($current_week->submission_deadline) {
                try {
                    $utc_dt = new DateTime($current_week->submission_deadline, new DateTimeZone('UTC'));
                    $site_tz = new DateTimeZone(wp_timezone_string());
                    $site_dt = $utc_dt->setTimezone($site_tz);
                    $deadline_formatted = $site_dt->format('l, F jS \a\\t g:i A T');
                } catch (Exception $e) {
                    $deadline_formatted = date("l, F jS \a\\t g:i A T", strtotime($current_week->submission_deadline));
                }
            }
            echo '<p style="text-align:center;font-size:1.1em;margin-top:-1em;margin-bottom:2em;"><strong>Submission Deadline:</strong> ' . esc_html($deadline_formatted) . '</p>';
        }
        ?>

        <?php if ($is_editing_as_other): ?>
            <div class="notice notice-info" style="margin-bottom:1em;">
                <p><strong>Commissioner Mode:</strong> You are editing picks for <strong><?php echo esc_html($player_info->display_name); ?></strong>.</p>
            </div>
        <?php endif; ?>

        <?php
        $is_locked_for_player = ($deadline_passed && !$is_commissioner);

        if ($current_week->status === 'finalized') {
            _kf_display_readonly_picks_view('Week Finalized', 'This week is locked and cannot be changed.', $dd_selection_exists, $existing_picks_results);
        } elseif ($pending_submission && !$is_commissioner) { ?>
            <div class="kf-card" style="border-left:5px solid #ffc107;background:#fff3cd;margin-bottom:1.5em;">
                <h3 style="margin:0 0 .5em 0;border:none;font-size:1.2em;">Pending Late Submission</h3>
                <p style="margin:0;">Your picks were submitted as a late request on <strong><?php echo esc_html( date(get_option('date_format').' '.get_option('time_format'), strtotime($pending_submission->requested_at)) ); ?></strong> and are awaiting commissioner review.</p>
            </div>
        <?php
        } elseif ($is_locked_for_player) { ?>
            <div class="kf-picks-container">
                <form method="POST" class="kf-tracked-form">
                    <?php wp_nonce_field('kf_submit_picks_action', 'kf_submit_picks_nonce'); ?>
                    <?php
                        echo '<input type="hidden" name="is_late_submission" value="1">';
                        echo '<input type="hidden" name="is_bpow_submission_helper" value="0">';
                        _kf_display_picks_form(false, $week_id, $current_week, $target_user_id, $active_season_for_dd->id, $active_season_for_dd, $existing_picks, $dd_selection_exists, true, false);
                    ?>
                    <div class="kf-picks-actions">
                        <button type="submit" name="kf_submit_picks" class="kf-button kf-button-action">Submit Late Picks for Review</button>
                    </div>
                </form>
            </div>
        <?php
        } else { ?>
            <div class="kf-picks-container">
                <form method="POST" class="kf-tracked-form">
                    <?php wp_nonce_field('kf_submit_picks_action', 'kf_submit_picks_nonce'); ?>
                    <?php if ($deadline_passed && $is_commissioner) { echo '<div class="notice notice-warning" style="margin-bottom:1em;"><p><strong>Commissioner Notice:</strong> The player deadline has passed, but you may still make administrative changes.</p></div>'; } ?>

                    <?php if ($is_bpow_eligible): ?>
                        <div class="notice notice-info"><p>Congratulations on winning Best Player of the Week! Use the two sections below to make your standard and bonus picks. The system will automatically use the higher of your two scores.</p></div>

                        <div class="kf-picks-grid">
                            <div class="kf-picks-column">
                                <h2>Standard Picks</h2>
                                <?php _kf_display_picks_form(false, $week_id, $current_week, $target_user_id, $active_season_for_dd->id, $active_season_for_dd, $existing_picks, $dd_selection_exists, false, $is_commissioner); ?>
                            </div>
                            <div class="kf-picks-column">
                                <h2>BPOW Bonus Picks</h2>
                                <?php _kf_display_picks_form(true,  $week_id, $current_week, $target_user_id, $active_season_for_dd->id, $active_season_for_dd, $existing_bpow_picks, false, false, $is_commissioner); ?>
                            </div>
                        </div>
                    <?php else: ?>
                        <?php _kf_display_picks_form(false, $week_id, $current_week, $target_user_id, $active_season_for_dd->id, $active_season_for_dd, $existing_picks, $dd_selection_exists, false, $is_commissioner); ?>
                    <?php endif; ?>

                    <div class="kf-picks-actions">
                        <button type="submit" name="kf_submit_picks" class="kf-button kf-button-action">Save All Picks</button>
                    </div>
                </form>
            </div>
        <?php } ?>
    </div>
    <?php
    $page_html = ob_get_clean();

    // ---------- Inline, scoped JS (Standard vs BPOW independent) ----------
    $script = <<<'JS'
<script>
(function(){
  function n(v){ if(v===''||v==null){return null;} var x=parseInt(v,10); return isNaN(x)?null:x; }
  function initSection(section){
    if(!section || section.dataset.jsInitialized==='true'){ return; }
    var selects = section.querySelectorAll('.kf-point-select');
    function update(){
      var chosen = new Set(Array.from(selects).map(function(s){return n(s.value);}).filter(function(v){return v!==null;}));
      selects.forEach(function(sel){
        var cur = n(sel.value);
        sel.querySelectorAll('option').forEach(function(opt){
          var ov = n(opt.value);
          if(ov===null){ opt.disabled=false; return; }
          opt.disabled = (chosen.has(ov) && ov !== cur);
        });
      });
    }
    selects.forEach(function(sel){
      sel.addEventListener('change', update);
      sel.addEventListener('input', update);
    });
    update();
    section.dataset.jsInitialized='true';
  }
  function boot(){ document.querySelectorAll('.kf-form-section').forEach(initSection); }
  if(document.readyState==='loading'){ document.addEventListener('DOMContentLoaded', boot); } else { boot(); }
  var mo = new MutationObserver(function(){ boot(); });
  mo.observe(document.body, {childList:true, subtree:true});
})();
</script>
JS;

    return $page_html . $script;
}

// Register shortcode (idempotent)
if (function_exists('add_shortcode')) {
    if (!shortcode_exists('kf_my_picks')) {
        add_shortcode('kf_my_picks', 'kf_my_picks_shortcode');
    }
    if (!shortcode_exists('my_picks')) {
        add_shortcode('my_picks', 'kf_my_picks_shortcode');
    }
}
