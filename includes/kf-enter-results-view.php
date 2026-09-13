<?php
/**
 * Shortcode handler for entering weekly game results (Commissioner).
 * FIX: allows status 'published' or 'tie_resolution_needed'.
 * NEW: adds "Tie" option for non-tiebreaker matchups.
 */
function kf_enter_results_shortcode() {
    if (!is_user_logged_in()) {
        return kf_notice_login_required( 'this page' );
    }
    if (session_status() === PHP_SESSION_NONE) { session_start(); }

    global $wpdb;
    $season_id = kf_page_season_id();
    $week_id   = isset($_GET['week_id']) ? intval($_GET['week_id']) : 0;
    if (!$week_id) {
        // Nothing in the URL to go on, so the session's league is all there is.
        if (!$season_id || !kf_can_manage_season($season_id)) {
            return kf_notice_page(
                'You do not run this league',
                'Entering results is for commissioners. If you run a different league, switch to it.',
                array_merge( [ kf_notice_action( 'Home', site_url( '/' ) ), kf_notice_action( 'Season Summary', site_url( '/season-summary/' ) ) ], kf_league_switch_actions( '/manage-weeks/', (int) $season_id ) ),
                'warn'
            );
        }
        return kf_notice_page(
            'No week chosen',
            'Pick the week you want to enter results for.',
            [ kf_notice_action( 'Manage Weeks', site_url( '/manage-weeks/' ) ), kf_notice_action( 'Home', site_url( '/' ) ) ],
            'info'
        );
    }
    // With a week id, the WEEK decides the league and is authorised below. Gating on the
    // session's league here refused a commissioner of two leagues who followed a link to the
    // league they did not happen to have selected.

    $weeks_table     = $wpdb->prefix . 'weeks';
    $matchups_table  = $wpdb->prefix . 'matchups';
    $season_name     = $wpdb->get_var($wpdb->prepare("SELECT name FROM {$wpdb->prefix}seasons WHERE id = %d", $season_id));

    // Load by id, then let the week decide the league — a commissioner of two leagues clicking
    // a link for the other one used to be told the week "is not available for result entry".
    $week = $wpdb->get_row($wpdb->prepare("SELECT * FROM $weeks_table WHERE id = %d", $week_id));
    if ( $week && ! kf_enter_season_context( (int) $week->season_id, true ) ) {
        return kf_notice_page(
            'That week belongs to another league',
            'You can only enter results for a league you run.',
            array_merge( [ kf_notice_action( 'Manage Weeks', site_url( '/manage-weeks/' ) ), kf_notice_action( 'Home', site_url( '/' ) ) ], kf_league_switch_actions( '/manage-weeks/', (int) $season_id ) ),
            'warn'
        );
    }
    if ( $week && (int) $week->season_id !== (int) $season_id ) {
        $season_id   = (int) $week->season_id;
        $season_name = $wpdb->get_var($wpdb->prepare("SELECT name FROM {$wpdb->prefix}seasons WHERE id = %d", $season_id));
    }

    if (!$week) {
        return kf_notice_page(
            'That week no longer exists',
            'The week this link points to has been removed.',
            [ kf_notice_action( 'Manage Weeks', site_url( '/manage-weeks/' ) ), kf_notice_action( 'Home', site_url( '/' ) ) ],
            'warn'
        );
    }
    if (!in_array($week->status, ['published', 'tie_resolution_needed'], true)) {
        return kf_notice_page(
            'Results are not open for this week',
            'A week has to be published, and not yet finalized, before results can be entered.',
            [ kf_notice_action( 'Manage Weeks', site_url( '/manage-weeks/' ) ), kf_notice_action( 'Home', site_url( '/' ) ) ],
            'info'
        );
    }

    // Save results
    if ($_SERVER['REQUEST_METHOD'] === 'POST'
        && isset($_POST['kf_results_nonce'])
        && wp_verify_nonce($_POST['kf_results_nonce'], 'kf_save_results_action_' . $week_id)
    ) {
        $results = $_POST['results'] ?? [];

        // Get valid matchup IDs for this week to prevent cross-week tampering
        $valid_matchup_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT id FROM $matchups_table WHERE week_id = %d", $week_id
        ));

        foreach ($results as $matchup_id => $result) {
            $matchup_id = intval($matchup_id);
            if (!in_array($matchup_id, $valid_matchup_ids)) {
                continue; // Skip matchups that don't belong to this week
            }
            $sanitized_result = sanitize_text_field(stripslashes($result));
            $wpdb->update(
                $matchups_table,
                ['result' => $sanitized_result],
                ['id' => $matchup_id]
            );
        }

        // Optional live updates if your site defines this
        if (file_exists(plugin_dir_path(__FILE__) . 'kf-scoring-engine.php')) {
            require_once plugin_dir_path(__FILE__) . 'kf-scoring-engine.php';
            if (function_exists('kf_update_live_scores')) {
                kf_update_live_scores($week_id);
            }
        }

        echo '<div class="notice notice-success is-dismissible"><p>Results saved successfully! Player scores have been updated.</p></div>';
    }

    $matchups = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM $matchups_table WHERE week_id = %d ORDER BY is_tiebreaker ASC, id ASC",
        $week_id
    ));

    // --- Live totals data: load all player picks for this week ---
    $season_players_rows = $wpdb->get_results($wpdb->prepare(
        "SELECT u.ID, u.display_name
         FROM {$wpdb->prefix}users u
         JOIN {$wpdb->prefix}season_players sp ON u.ID = sp.user_id
         WHERE sp.season_id = %d AND sp.status = 'accepted'
         ORDER BY u.display_name ASC",
        $season_id
    ));
    $season_players_map = [];
    foreach ($season_players_rows as $r) {
        $season_players_map[$r->ID] = $r->display_name;
    }

    // Build picks lookup: [matchup_id][user_id] = {pick, point_value}
    $all_picks_rows = $wpdb->get_results($wpdb->prepare(
        "SELECT p.user_id, p.matchup_id, p.pick, p.point_value
         FROM {$wpdb->prefix}picks p
         JOIN {$wpdb->prefix}matchups m ON p.matchup_id = m.id
         WHERE m.week_id = %d AND p.is_bpow = 0 AND m.is_tiebreaker = 0",
        $week_id
    ));
    $picks_by_matchup = [];
    foreach ($all_picks_rows as $r) {
        $picks_by_matchup[(int)$r->matchup_id][(int)$r->user_id] = [
            'pick' => $r->pick,
            'pts'  => (int)$r->point_value,
        ];
    }

    // Build JS-safe data structures
    $js_players     = [];
    foreach ($season_players_map as $uid => $name) {
        $js_players[] = ['id' => (int)$uid, 'name' => $name];
    }
    $js_matchup_picks = [];
    foreach ($picks_by_matchup as $mid => $player_picks) {
        $js_matchup_picks[$mid] = $player_picks;
    }
    $js_matchups = [];
    foreach ($matchups as $m) {
        if ($m->is_tiebreaker) continue;
        $js_matchups[] = [
            'id'     => (int)$m->id,
            'team_a' => $m->team_a,
            'team_b' => $m->team_b,
            'result' => $m->result,
        ];
    }

    $all_results_entered = true;
    foreach ($matchups as $m) {
        if ($m->result === null || $m->result === '') {
            $all_results_entered = false;
            break;
        }
    }

    // SPORTS API V1: Detect if this is an API-mode week (any matchup has ESPN ID)
    $is_api_week = false;
    foreach ($matchups as $m) {
        if (!empty($m->espn_game_id)) {
            $is_api_week = true;
            break;
        }
    }

    ob_start(); ?>
    <div class="kf-container">
        <h1>Enter Results</h1>
        <h2 style="margin-top:0;">Week <?php echo esc_html($week->week_number); ?> of <?php echo esc_html($season_name); ?></h2>
        <a href="<?php echo esc_url( kf_league_url( site_url('/manage-weeks/'), $season_id ) ); ?>">&larr; Back to Manage Weeks</a>

        <?php
        // Warn commissioner if auto-score is turned off for an API week
        if ($is_api_week && get_option('kf_auto_score_enabled', '1') !== '1'):
        ?>
            <div style="background:#fef3c7;border:1px solid #f59e0b;border-left:4px solid #d97706;border-radius:6px;padding:10px 16px;margin-top:1em;font-size:0.92em;">
                <strong>⚠️ Auto-score is disabled.</strong>
                Results will <em>not</em> be filled in automatically from ESPN. You must enter them manually, or
                <a href="<?php echo esc_url(site_url('/api-settings/')); ?>">enable auto-score in API Settings</a>.
            </div>
        <?php endif; ?>

        <?php if ($is_api_week): ?>
            <div style="display:flex;justify-content:flex-end;margin-top:1em;">
                <button type="button" id="kf-refresh-scores-btn" class="kf-button" onclick="kfRefreshScores(<?php echo intval($week_id); ?>)">&#128260; Refresh Scores Now</button>
            </div>
            <div id="kf-refresh-status" style="display:none;margin-top:8px;"></div>
        <?php endif; ?>

        <?php if (!empty($season_players_map)): ?>
        <div class="kf-live-totals-panel" id="kf-live-totals-panel">
            <h3>&#128200; Live Running Totals <small style="font-weight:400;font-size:0.82em;color:#555;">(updates as you select results)</small></h3>
            <div class="kf-live-totals-grid" id="kf-live-totals-grid">
                <?php foreach ($season_players_map as $uid => $name): ?>
                    <div class="kf-live-total-chip" id="kf-chip-<?php echo esc_attr($uid); ?>">
                        <span class="kf-live-total-chip-name"><?php echo esc_html($name); ?></span>
                        <span class="kf-live-total-chip-score" id="kf-chip-score-<?php echo esc_attr($uid); ?>">0</span>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <form method="POST" class="kf-card kf-tracked-form" style="margin-top: 1.5em;">
            <?php wp_nonce_field('kf_save_results_action_' . $week_id, 'kf_results_nonce'); ?>
            <p>For each matchup, select the winning team, <strong>or choose “Tie”</strong>. For the tiebreaker, enter the total points. You can save your progress at any time.</p>

            <table class="kf-table kf-results-table">
                <thead>
                    <tr>
                        <th>Matchup</th>
                        <?php if ($is_api_week): ?><th style="width:120px;">Score</th><th style="width:100px;">Status</th><?php endif; ?>
                        <th style="width:<?php echo $is_api_week ? '35%' : '50%'; ?>;">Result</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($matchups as $matchup):
                    $has_espn = !empty($matchup->espn_game_id);
                    $is_auto_filled = $has_espn && !empty($matchup->result) && $matchup->game_status === 'final';
                    ?>
                    <tr>
                        <td>
                            <?php // Away @ home, like every other page — the score beside it is away–home, and listing
                                  // home first made a 31–17 win read as a 17–31 loss. ?>
                            <strong><?php echo esc_html($matchup->team_b); ?></strong> @ <strong><?php echo esc_html($matchup->team_a); ?></strong>
                            <?php
                            // Kickoff time, so the commissioner can see at a glance which games
                            // have even started. Stored UTC, shown in the site timezone.
                            if ( ! empty( $matchup->game_datetime ) && $matchup->game_datetime !== '0000-00-00 00:00:00' ) :
                                $kf_kick = get_date_from_gmt( $matchup->game_datetime, 'D M j, g:i A' );
                            ?>
                                <br><span class="kf-kickoff"><?php echo esc_html( $kf_kick ); ?></span>
                            <?php endif; ?>
                            <?php if ($matchup->is_tiebreaker): ?><br><em style="font-size:0.9em;">(Tiebreaker Game)</em><?php endif; ?>
                        </td>

                        <?php if ($is_api_week): ?>
                        <td class="kf-score-cell">
                            <?php if ($has_espn && ($matchup->home_score !== null || $matchup->away_score !== null)): ?>
                                <strong><?php echo intval($matchup->away_score); ?></strong> - <strong><?php echo intval($matchup->home_score); ?></strong>
                            <?php elseif ($has_espn): ?>
                                <span style="color:#999;">&mdash;</span>
                            <?php else: ?>
                                <span style="color:#999;">Manual</span>
                            <?php endif; ?>
                        </td>
                        <td class="kf-status-cell">
                            <?php if ($has_espn): ?>
                                <?php
                                $status = $matchup->game_status ?? 'scheduled';
                                $badge_class = 'kf-badge-scheduled';
                                $badge_text = 'Scheduled';
                                if ($status === 'final') {
                                    $badge_class = 'kf-badge-final';
                                    $badge_text = 'FINAL';
                                } elseif ($status === 'in_progress') {
                                    $badge_class = 'kf-badge-live';
                                    $badge_text = 'LIVE';
                                }
                                ?>
                                <span class="kf-status-badge <?php echo esc_attr($badge_class); ?>"><?php echo esc_html($badge_text); ?></span>
                                <?php // Quarter and clock while the game is running, e.g. "9:55 - 3rd". ?>
                                <?php if ( ! empty( $matchup->status_detail ) && $matchup->game_status === 'in_progress' ) : ?>
                                    <br><small class="kf-status-clock"><?php echo esc_html( $matchup->status_detail ); ?></small>
                                <?php endif; ?>
                                <?php if ($is_auto_filled): ?>
                                    <br><small class="kf-auto-tag">Auto</small>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                        <?php endif; ?>

                        <td>
                            <?php if ($matchup->is_tiebreaker): ?>
                                <input type="number" step="any"
                                       name="results[<?php echo esc_attr($matchup->id); ?>]"
                                       value="<?php echo esc_attr($matchup->result); ?>"
                                       placeholder="Enter Total Points">
                            <?php else: ?>
                                <select name="results[<?php echo esc_attr($matchup->id); ?>]">
                                    <option value="">-- Result Pending --</option>
                                    <option value="<?php echo esc_attr($matchup->team_b); ?>" <?php selected($matchup->result, $matchup->team_b); ?>>
                                        <?php echo esc_html($matchup->team_b); ?>
                                    </option>
                                    <option value="<?php echo esc_attr($matchup->team_a); ?>" <?php selected($matchup->result, $matchup->team_a); ?>>
                                        <?php echo esc_html($matchup->team_a); ?>
                                    </option>
                                    <option value="TIE" <?php echo (strtolower((string)$matchup->result) === 'tie') ? 'selected' : ''; ?>>Tie</option>
                                </select>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <?php if ($is_api_week): ?>
                <p class="kf-form-note" style="margin-top:8px;">
                    <?php
                    $final_count = 0;
                    $total_api = 0;
                    foreach ($matchups as $m) {
                        if (!empty($m->espn_game_id)) {
                            $total_api++;
                            if ($m->game_status === 'final') $final_count++;
                        }
                    }
                    ?>
                    <?php echo intval($final_count); ?> of <?php echo intval($total_api); ?> API-linked games are final.
                    Results marked “Auto” were filled by the score checker.
                    You can override any result before finalizing.
                </p>
            <?php endif; ?>

            <div class="kf-form-actions" style="display:flex;justify-content:space-between;align-items:center;margin-top:1.5em;">
                <button type="submit" name="save_results" class="kf-button">Save Results</button>

                <?php if ($all_results_entered): ?>
                    <a href="<?php echo esc_url(add_query_arg('week_id', $week_id, site_url('/week-summary/'))); ?>" class="kf-button kf-button-action">
                        Proceed to Finalize &rarr;
                    </a>
                <?php else: ?>
                    <span style="font-size:0.9em;color:#777;">The "Finalize" button will appear here once all results are entered.</span>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <?php if (!empty($season_players_map)): ?>
    <script>
    (function() {
        var KF_PLAYERS   = <?php echo wp_json_encode($js_players); ?>;
        var KF_MATCHUPS  = <?php echo wp_json_encode($js_matchups); ?>;
        var KF_PICKS     = <?php echo wp_json_encode($js_matchup_picks); ?>;

        function computeTotals(currentResults) {
            var totals = {};
            KF_PLAYERS.forEach(function(p) { totals[p.id] = 0; });

            KF_MATCHUPS.forEach(function(m) {
                var result = currentResults[m.id] !== undefined ? currentResults[m.id] : (m.result || '');
                if (!result) return;
                var isTie = (result.toLowerCase() === 'tie');
                KF_PLAYERS.forEach(function(p) {
                    var picks = KF_PICKS[m.id];
                    if (!picks || !picks[p.id]) return;
                    var pick = picks[p.id].pick;
                    var pts  = picks[p.id].pts;
                    if (isTie) {
                        totals[p.id] += Math.floor(pts / 2);
                    } else if (pick.toLowerCase() === result.toLowerCase()) {
                        totals[p.id] += pts;
                    }
                });
            });
            return totals;
        }

        function updateDisplay(totals) {
            var maxScore = Math.max.apply(null, Object.values(totals));
            KF_PLAYERS.forEach(function(p) {
                var chip = document.getElementById('kf-chip-' + p.id);
                var scoreEl = document.getElementById('kf-chip-score-' + p.id);
                if (!chip || !scoreEl) return;
                scoreEl.textContent = totals[p.id] || 0;
                if (totals[p.id] === maxScore && maxScore > 0) {
                    chip.classList.add('kf-chip-leading');
                } else {
                    chip.classList.remove('kf-chip-leading');
                }
            });
        }

        function gatherCurrentSelections() {
            var current = {};
            KF_MATCHUPS.forEach(function(m) {
                var sel = document.querySelector('select[name="results[' + m.id + ']"]');
                if (sel) { current[m.id] = sel.value; }
            });
            return current;
        }

        function refresh() {
            var selections = gatherCurrentSelections();
            var totals = computeTotals(selections);
            updateDisplay(totals);
        }

        document.addEventListener('DOMContentLoaded', function() {
            // Initial compute from existing DB values
            refresh();
            // Listen to all result selects
            document.querySelectorAll('select[name^="results["]').forEach(function(sel) {
                sel.addEventListener('change', refresh);
            });
        });
    })();
    </script>
    <?php endif; ?>

    <?php if ($is_api_week): ?>
    <script>
    // Surface the previous refresh outcome after the reload, so the result is readable instead
    // of vanishing along with the page that reported it.
    document.addEventListener('DOMContentLoaded', function () {
        try {
            var carried = window.sessionStorage.getItem('kfRefreshResult');
            if (!carried) { return; }
            window.sessionStorage.removeItem('kfRefreshResult');
            var d = document.getElementById('kf-refresh-status');
            if (d) {
                d.style.display = 'block';
                d.className = 'notice notice-success';
                d.textContent = carried;
            }
        } catch (e) { /* private window */ }
    });

    function kfRefreshScores(weekId) {
        var btn = document.getElementById('kf-refresh-scores-btn');
        var statusDiv = document.getElementById('kf-refresh-status');
        btn.disabled = true;
        btn.textContent = 'Refreshing...';
        statusDiv.style.display = 'block';
        statusDiv.textContent = 'Checking ESPN for updated scores...';
        statusDiv.className = 'notice notice-info';

        var formData = new FormData();
        formData.append('action', 'kf_refresh_scores');
        formData.append('nonce', kf_ajax_data.nonce);
        formData.append('week_id', weekId);

        fetch(kf_ajax_data.ajax_url, { method: 'POST', body: formData })
        .then(function(res) { return res.json(); })
        .then(function(response) {
            btn.disabled = false;
            btn.innerHTML = '&#128260; Refresh Scores Now';
            if (response.success) {
                var updated = parseInt(response.data.updated, 10) || 0;
                if (updated === 0) {
                    // Nothing was written. Do NOT reload — this message is the only thing that
                    // distinguishes "no games linked" from "ESPN returned nothing", and reloading
                    // it away leaves an unchanged page with no explanation.
                    statusDiv.textContent = response.data.message + ' Nothing was changed.';
                    statusDiv.className = 'notice notice-warning';
                    return;
                }
                try {
                    window.sessionStorage.setItem('kfRefreshResult', response.data.message);
                } catch (e) { /* private window */ }
                statusDiv.textContent = response.data.message + ' Reloading...';
                statusDiv.className = 'notice notice-success';
                setTimeout(function() { location.reload(); }, 1200);
            } else {
                statusDiv.textContent = response.data ? response.data.message : 'Error refreshing scores.';
                statusDiv.className = 'notice notice-error';
            }
        })
        .catch(function() {
            btn.disabled = false;
            btn.innerHTML = '&#128260; Refresh Scores Now';
            statusDiv.textContent = 'Network error. Please try again.';
            statusDiv.className = 'notice notice-error';
        });
    }
    </script>
    <?php endif; ?>

    <?php
    // Linking lives here as well as on Week Setup: once a week is published, Manage Weeks
    // offers no route to Week Setup at all, and this is the page a live week is worked from.
    if ( function_exists( 'kf_render_espn_linker' ) ) {
        kf_render_espn_linker( $week_id, intval( $week->week_number ?? 0 ) );
    }
    ?>

    <?php
    return ob_get_clean();
}
