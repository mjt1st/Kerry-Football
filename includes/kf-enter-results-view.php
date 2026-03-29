<?php
/**
 * Shortcode handler for entering weekly game results (Commissioner).
 * FIX: allows status 'published' or 'tie_resolution_needed'.
 * NEW: adds "Tie" option for non-tiebreaker matchups.
 */
function kf_enter_results_shortcode() {
    if (!is_user_logged_in() || !current_user_can('manage_options')) {
        return '<div class="kf-container"><p>You do not have access to this page.</p></div>';
    }
    if (session_status() === PHP_SESSION_NONE) { session_start(); }

    global $wpdb;
    $week_id   = isset($_GET['week_id']) ? intval($_GET['week_id']) : 0;
    $season_id = $_SESSION['kf_active_season_id'] ?? 0;

    if (!$week_id || !$season_id) {
        return '<div class="kf-container"><h1>Enter Results</h1><p>Could not determine the week or season. Please return to the Manage Weeks page.</p></div>';
    }

    $weeks_table     = $wpdb->prefix . 'weeks';
    $matchups_table  = $wpdb->prefix . 'matchups';
    $season_name     = $wpdb->get_var($wpdb->prepare("SELECT name FROM {$wpdb->prefix}seasons WHERE id = %d", $season_id));

    $week = $wpdb->get_row($wpdb->prepare("SELECT * FROM $weeks_table WHERE id = %d AND season_id = %d", $week_id, $season_id));

    if (!$week || !in_array($week->status, ['published', 'tie_resolution_needed'], true)) {
        return '<div class="kf-container"><p>This week is not available for result entry. It must be published and not yet finalized.</p></div>';
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
    <div class=”kf-container”>
        <h1>Enter Results</h1>
        <h2 style=”margin-top:0;”>Week <?php echo esc_html($week->week_number); ?> of <?php echo esc_html($season_name); ?></h2>
        <a href=”<?php echo esc_url(site_url('/manage-weeks/')); ?>”>&larr; Back to Manage Weeks</a>

        <?php if ($is_api_week): ?>
            <div style=”display:flex;justify-content:flex-end;margin-top:1em;”>
                <button type=”button” id=”kf-refresh-scores-btn” class=”kf-button” onclick=”kfRefreshScores(<?php echo intval($week_id); ?>)”>&#128260; Refresh Scores Now</button>
            </div>
            <div id=”kf-refresh-status” style=”display:none;margin-top:8px;”></div>
        <?php endif; ?>

        <?php if (!empty($season_players_map)): ?>
        <div class=”kf-live-totals-panel” id=”kf-live-totals-panel”>
            <h3>&#128200; Live Running Totals <small style=”font-weight:400;font-size:0.82em;color:#555;”>(updates as you select results)</small></h3>
            <div class=”kf-live-totals-grid” id=”kf-live-totals-grid”>
                <?php foreach ($season_players_map as $uid => $name): ?>
                    <div class=”kf-live-total-chip” id=”kf-chip-<?php echo esc_attr($uid); ?>”>
                        <span class=”kf-live-total-chip-name”><?php echo esc_html($name); ?></span>
                        <span class=”kf-live-total-chip-score” id=”kf-chip-score-<?php echo esc_attr($uid); ?>”>0</span>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <form method=”POST” class=”kf-card kf-tracked-form” style=”margin-top: 1.5em;”>
            <?php wp_nonce_field('kf_save_results_action_' . $week_id, 'kf_results_nonce'); ?>
            <p>For each matchup, select the winning team, <strong>or choose “Tie”</strong>. For the tiebreaker, enter the total points. You can save your progress at any time.</p>

            <table class=”kf-table”>
                <thead>
                    <tr>
                        <th>Matchup</th>
                        <?php if ($is_api_week): ?><th style=”width:120px;”>Score</th><th style=”width:100px;”>Status</th><?php endif; ?>
                        <th style=”width:<?php echo $is_api_week ? '35%' : '50%'; ?>;”>Result</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($matchups as $matchup):
                    $has_espn = !empty($matchup->espn_game_id);
                    $is_auto_filled = $has_espn && !empty($matchup->result) && $matchup->game_status === 'final';
                    ?>
                    <tr>
                        <td>
                            <strong><?php echo esc_html($matchup->team_a); ?></strong> vs <strong><?php echo esc_html($matchup->team_b); ?></strong>
                            <?php if ($matchup->is_tiebreaker): ?><br><em style=”font-size:0.9em;”>(Tiebreaker Game)</em><?php endif; ?>
                        </td>

                        <?php if ($is_api_week): ?>
                        <td class=”kf-score-cell”>
                            <?php if ($has_espn && ($matchup->home_score !== null || $matchup->away_score !== null)): ?>
                                <strong><?php echo intval($matchup->away_score); ?></strong> - <strong><?php echo intval($matchup->home_score); ?></strong>
                            <?php elseif ($has_espn): ?>
                                <span style=”color:#999;”>&mdash;</span>
                            <?php else: ?>
                                <span style=”color:#999;”>Manual</span>
                            <?php endif; ?>
                        </td>
                        <td class=”kf-status-cell”>
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
                                <span class=”kf-status-badge <?php echo esc_attr($badge_class); ?>”><?php echo esc_html($badge_text); ?></span>
                                <?php if ($is_auto_filled): ?>
                                    <br><small class=”kf-auto-tag”>Auto</small>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                        <?php endif; ?>

                        <td>
                            <?php if ($matchup->is_tiebreaker): ?>
                                <input type=”number” step=”any”
                                       name=”results[<?php echo esc_attr($matchup->id); ?>]”
                                       value=”<?php echo esc_attr($matchup->result); ?>”
                                       placeholder=”Enter Total Points”>
                            <?php else: ?>
                                <select name=”results[<?php echo esc_attr($matchup->id); ?>]”>
                                    <option value=””>-- Result Pending --</option>
                                    <option value=”<?php echo esc_attr($matchup->team_a); ?>” <?php selected($matchup->result, $matchup->team_a); ?>>
                                        <?php echo esc_html($matchup->team_a); ?>
                                    </option>
                                    <option value=”<?php echo esc_attr($matchup->team_b); ?>” <?php selected($matchup->result, $matchup->team_b); ?>>
                                        <?php echo esc_html($matchup->team_b); ?>
                                    </option>
                                    <option value=”TIE” <?php echo (strtolower((string)$matchup->result) === 'tie') ? 'selected' : ''; ?>>Tie</option>
                                </select>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <?php if ($is_api_week): ?>
                <p class=”kf-form-note” style=”margin-top:8px;”>
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

            <div class=”kf-form-actions” style=”display:flex;justify-content:space-between;align-items:center;margin-top:1.5em;”>
                <button type=”submit” name=”save_results” class=”kf-button”>Save Results</button>

                <?php if ($all_results_entered): ?>
                    <a href=”<?php echo esc_url(add_query_arg('week_id', $week_id, site_url('/week-summary/'))); ?>” class=”kf-button kf-button-action”>
                        Proceed to Finalize &rarr;
                    </a>
                <?php else: ?>
                    <span style=”font-size:0.9em;color:#777;”>The “Finalize” button will appear here once all results are entered.</span>
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
                statusDiv.textContent = response.data.message + ' Reloading...';
                statusDiv.className = 'notice notice-success';
                setTimeout(function() { location.reload(); }, 1500);
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
    return ob_get_clean();
}
