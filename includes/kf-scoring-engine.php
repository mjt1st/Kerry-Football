<?php
/**
 * Kerry Football Scoring Engine.
 *
 * FINAL REFACTORED VERSION + TIE SUPPORT:
 * - Determines BPOW/MWOW awards from regular picks only.
 * - Swaps BPOW player's score if their second set of picks is higher.
 * - Counts tie results as half points (floor) for everyone; ties do NOT add wins.
 * - Contains kf_check_for_ties() for summary view's proactive checks.
 * - Calls the dedicated Double Down engine to handle DD transactions.
 * - Handles reversal by calling the DD engine first, then proceeding.
 */

if (!defined('ABSPATH')) { exit; }

// Include the Double Down engine if present
if (file_exists(plugin_dir_path(__FILE__) . 'kf-double-down-engine.php')) {
    require_once plugin_dir_path(__FILE__) . 'kf-double-down-engine.php';
}

/**
 * Helper: decide winner from player stats.
 */
function _kf_determine_winner($player_stats, $primary_stat, &$tied_players_list = []) {
    $winner_id = null;
    $status = 'resolved';
    $max_stat_value = -999;

    foreach ($player_stats as $stats) {
        if ($stats[$primary_stat] > $max_stat_value) {
            $max_stat_value = $stats[$primary_stat];
        }
    }

    $tied_players = [];
    foreach ($player_stats as $user_id => $stats) {
        if ($stats[$primary_stat] == $max_stat_value && $max_stat_value >= 0) {
            $tied_players[] = $user_id;
        }
    }

    if (count($tied_players) === 1) {
        $winner_id = $tied_players[0];
    } elseif (count($tied_players) > 1) {
        $min_diff = PHP_INT_MAX;
        $finalists = [];
        foreach ($tied_players as $player_id) {
            $diff = $player_stats[$player_id]['tiebreaker_diff'] ?? PHP_INT_MAX;
            if ($diff < $min_diff) {
                $min_diff = $diff;
                $finalists = [$player_id];
            } elseif ($diff == $min_diff) {
                $finalists[] = $player_id;
            }
        }
        if (count($finalists) === 1) {
            $winner_id = $finalists[0];
        } else {
            $status = 'tie_resolution_needed';
            $tied_players_list = $finalists;
        }
    }

    return ['winner_id' => $winner_id, 'status' => $status];
}

/**
 * Core stat calc for a week (TIE-AWARE).
 * - Regular picks feed awards.
 * - BPOW picks only used to possibly replace that player's subtotal/wins.
 */
function _kf_calculate_player_stats_for_week($week_id) {
    global $wpdb;

    $weeks_table     = $wpdb->prefix . 'weeks';
    $matchups_table  = $wpdb->prefix . 'matchups';
    $picks_table     = $wpdb->prefix . 'picks';
    $players_table   = $wpdb->prefix . 'season_players';

    $week_data = $wpdb->get_row($wpdb->prepare("SELECT * FROM $weeks_table WHERE id = %d", $week_id));
    if (!$week_data) {
        error_log("kf_calculate_player_stats_for_week: No week data for Week ID $week_id");
        return [];
    }

    $players = $wpdb->get_col($wpdb->prepare(
        "SELECT user_id FROM $players_table WHERE season_id = %d AND status = 'accepted'",
        $week_data->season_id
    ));

    $tiebreaker_matchup = $wpdb->get_row($wpdb->prepare(
        "SELECT id, result FROM $matchups_table WHERE week_id = %d AND is_tiebreaker = 1",
        $week_id
    ));
    $actual_tiebreaker_score = $tiebreaker_matchup ? floatval($tiebreaker_matchup->result) : null;

    $player_stats = [];
    $last_week_bpow_winner_id = null;
    if ($week_data->week_number > 1) {
        $last_week_bpow_winner_id = (int)$wpdb->get_var($wpdb->prepare(
            "SELECT bpow_winner_user_id
             FROM $weeks_table
             WHERE season_id = %d AND week_number = %d AND status = 'finalized'",
            $week_data->season_id, $week_data->week_number - 1
        ));
    }

    foreach ($players as $player_id) {
        // --- TIE-AWARE regular stats ---
        $regular_stats = $wpdb->get_row($wpdb->prepare("
            SELECT
                SUM(
                    CASE
                        WHEN LOWER(TRIM(m.result)) IN ('tie','t','draw') THEN 0
                        WHEN LOWER(TRIM(p.pick)) = LOWER(TRIM(m.result)) THEN 1
                        ELSE 0
                    END
                ) AS wins,
                SUM(
                    CASE
                        WHEN LOWER(TRIM(m.result)) IN ('tie','t','draw') THEN FLOOR(p.point_value / 2)
                        WHEN LOWER(TRIM(p.pick)) = LOWER(TRIM(m.result)) THEN p.point_value
                        ELSE 0
                    END
                ) AS subtotal
            FROM $picks_table p
            JOIN $matchups_table m ON p.matchup_id = m.id
            WHERE p.user_id = %d AND p.week_id = %d AND m.is_tiebreaker = 0 AND p.is_bpow = 0
        ", $player_id, $week_id));

        $stats_for_awards = [
            'wins'     => (int)($regular_stats->wins ?? 0),
            'subtotal' => (int)($regular_stats->subtotal ?? 0),
        ];

        // --- TIE-AWARE BPOW stats (only for last week's BPOW winner) ---
        $bpow_stats_for_score = [];
        if ($player_id === $last_week_bpow_winner_id) {
            $bpow_stats = $wpdb->get_row($wpdb->prepare("
                SELECT
                    SUM(
                        CASE
                            WHEN LOWER(TRIM(m.result)) IN ('tie','t','draw') THEN 0
                            WHEN LOWER(TRIM(p.pick)) = LOWER(TRIM(m.result)) THEN 1
                            ELSE 0
                        END
                    ) AS wins,
                    SUM(
                        CASE
                            WHEN LOWER(TRIM(m.result)) IN ('tie','t','draw') THEN FLOOR(p.point_value / 2)
                            WHEN LOWER(TRIM(p.pick)) = LOWER(TRIM(m.result)) THEN p.point_value
                            ELSE 0
                        END
                    ) AS subtotal
                FROM $picks_table p
                JOIN $matchups_table m ON p.matchup_id = m.id
                WHERE p.user_id = %d AND p.week_id = %d AND m.is_tiebreaker = 0 AND p.is_bpow = 1
            ", $player_id, $week_id));

            $bpow_stats_for_score = [
                'wins'     => (int)($bpow_stats->wins ?? 0),
                'subtotal' => (int)($bpow_stats->subtotal ?? 0),
            ];
        }

        // Tiebreaker diff (unchanged)
        $tiebreaker_pick_value = $wpdb->get_var($wpdb->prepare(
            "SELECT p.pick
             FROM {$wpdb->prefix}picks p
             JOIN {$wpdb->prefix}matchups m ON p.matchup_id = m.id
             WHERE p.user_id = %d AND p.week_id = %d AND m.is_tiebreaker = 1 AND p.is_bpow = 0",
            $player_id, $week_id
        ));

        $player_stats[$player_id] = [
            'stats_for_awards'    => $stats_for_awards,
            'bpow_stats_for_score'=> $bpow_stats_for_score,
            'tiebreaker_diff'     => ($tiebreaker_matchup && is_numeric($tiebreaker_pick_value) && is_numeric($actual_tiebreaker_score))
                                        ? abs($actual_tiebreaker_score - floatval($tiebreaker_pick_value))
                                        : PHP_INT_MAX,
        ];
    }

    return $player_stats;
}

/**
 * Dry-run tie checks for awards (unchanged; now benefits from tie-aware stats).
 */
function kf_check_for_ties($week_id) {
    $player_stats = _kf_calculate_player_stats_for_week($week_id);
    if (empty($player_stats)) {
        return [
            'mwow_requires_resolution' => false,
            'mwow_tied_players'        => [],
            'bpow_requires_resolution' => false,
            'bpow_tied_players'        => [],
        ];
    }

    $stats_for_awards = [];
    foreach ($player_stats as $user_id => $stats) {
        $stats_for_awards[$user_id] = [
            'wins'            => $stats['stats_for_awards']['wins'],
            'subtotal'        => $stats['stats_for_awards']['subtotal'],
            'tiebreaker_diff' => $stats['tiebreaker_diff'],
        ];
    }

    $mwow_tied_players = [];
    $bpow_tied_players = [];

    $mwow_result = _kf_determine_winner($stats_for_awards, 'wins', $mwow_tied_players);
    $bpow_result = _kf_determine_winner($stats_for_awards, 'subtotal', $bpow_tied_players);

    return [
        'mwow_requires_resolution' => ($mwow_result['status'] === 'tie_resolution_needed'),
        'mwow_tied_players'        => $mwow_tied_players,
        'bpow_requires_resolution' => ($bpow_result['status'] === 'tie_resolution_needed'),
        'bpow_tied_players'        => $bpow_tied_players,
    ];
}

/**
 * Finalize week (now using tie-aware stats).
 */
function kf_finalize_week_logic($week_id, $manual_winners = []) {
    global $wpdb;

    $weeks_table    = $wpdb->prefix . 'weeks';
    $scores_table   = $wpdb->prefix . 'scores';
    $seasons_table  = $wpdb->prefix . 'seasons';
    $players_table  = $wpdb->prefix . 'season_players';
    $matchups_table = $wpdb->prefix . 'matchups';

    $wpdb->query('START TRANSACTION');

    // Verify all matchups incl. tiebreaker have a result (Tie counts as a result)
    $total_matchups = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM $matchups_table WHERE week_id = %d AND is_tiebreaker = 0",
        $week_id
    ));
    $matchups_with_results = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM $matchups_table WHERE week_id = %d AND result IS NOT NULL AND result != ''",
        $week_id
    ));
    if ($matchups_with_results < ($total_matchups + 1)) {
        $wpdb->query('ROLLBACK');
        return "Not all matchups have results entered, including the tiebreaker score.";
    }

    $player_stats = _kf_calculate_player_stats_for_week($week_id);
    $week_data = $wpdb->get_row($wpdb->prepare(
        "SELECT w.*, s.mwow_bonus_points
         FROM $weeks_table w JOIN $seasons_table s ON w.season_id = s.id
         WHERE w.id = %d",
        $week_id
    ));
    $players = $wpdb->get_col($wpdb->prepare(
        "SELECT user_id FROM $players_table WHERE season_id = %d AND status = 'accepted'",
        $week_data->season_id
    ));

    if (empty($player_stats) || !$week_data) {
        $wpdb->query('ROLLBACK');
        return "Invalid week or no players found.";
    }

    $stats_for_awards = [];
    foreach ($player_stats as $user_id => $stats) {
        $stats_for_awards[$user_id] = [
            'wins'            => $stats['stats_for_awards']['wins'],
            'subtotal'        => $stats['stats_for_awards']['subtotal'],
            'tiebreaker_diff' => $stats['tiebreaker_diff'],
        ];
    }

    $mwow_winner_id = $manual_winners['mwow'] ?? null;
    $bpow_winner_id = $manual_winners['bpow'] ?? null;

    $mwow_tied_players = [];
    $bpow_tied_players = [];
    $tie_data = [];

    if (!$mwow_winner_id) {
        $mwow_result = _kf_determine_winner($stats_for_awards, 'wins', $mwow_tied_players);
        if ($mwow_result['status'] === 'tie_resolution_needed') {
            $tie_data['mwow'] = $mwow_tied_players;
        } else {
            $mwow_winner_id = $mwow_result['winner_id'];
        }
    }

    if (!$bpow_winner_id) {
        $bpow_result = _kf_determine_winner($stats_for_awards, 'subtotal', $bpow_tied_players);
        if ($bpow_result['status'] === 'tie_resolution_needed') {
            $tie_data['bpow'] = $bpow_tied_players;
        } else {
            $bpow_winner_id = $bpow_result['winner_id'];
        }
    }

    if (!empty($tie_data)) {
        $wpdb->update($weeks_table, [
            'status'   => 'tie_resolution_needed',
            'tie_data' => json_encode($tie_data),
        ], ['id' => $week_id]);
        $wpdb->query('COMMIT');
        return "Ties detected for " .
               (isset($tie_data['mwow']) ? 'MWOW' : '') .
               (isset($tie_data['mwow']) && isset($tie_data['bpow']) ? ' and ' : '') .
               (isset($tie_data['bpow']) ? 'BPOW' : '') .
               " that require commissioner resolution.";
    }

    // Insert scores
    $mwow_bonus = (int)($week_data->mwow_bonus_points ?? 0);
    $wpdb->delete($scores_table, ['week_id' => $week_id]);

    foreach ($players as $player_id) {
        $current_stats  = $player_stats[$player_id];
        $final_wins     = $current_stats['stats_for_awards']['wins'];
        $final_subtotal = $current_stats['stats_for_awards']['subtotal'];
        $is_bpow_score  = 0;

        // If BPOW picks are better, use them (already tie-aware)
        if (!empty($current_stats['bpow_stats_for_score'])) {
            if ($current_stats['bpow_stats_for_score']['subtotal'] > $final_subtotal) {
                $final_wins     = $current_stats['bpow_stats_for_score']['wins'];
                $final_subtotal = $current_stats['bpow_stats_for_score']['subtotal'];
                $is_bpow_score  = 1;
            }
        }

        // MWOW bonus only if final score came from standard picks
        $mwow_bonus_awarded = ($player_id == $mwow_winner_id && $is_bpow_score == 0) ? $mwow_bonus : 0;
        $final_score = $final_subtotal + $mwow_bonus_awarded;

        $wpdb->insert($scores_table, [
            'user_id'           => $player_id,
            'week_id'           => $week_id,
            'score'             => $final_score,
            'wins'              => $final_wins,
            'subtotal'          => $final_subtotal,
            'tiebreaker_diff'   => $current_stats['tiebreaker_diff'],
            'mwow_bonus_awarded'=> $mwow_bonus_awarded,
            'is_bpow_score'     => $is_bpow_score,
        ]);
    }

    // Apply Double Down moves
    if (function_exists('kf_apply_double_down_for_week')) {
        kf_apply_double_down_for_week($week_id, $week_data->season_id);
    }

    // Finalize week
    $wpdb->update($weeks_table, [
        'status'              => 'finalized',
        'mwow_winner_user_id' => $mwow_winner_id,
        'bpow_winner_user_id' => $bpow_winner_id,
        'tie_data'            => null,
    ], ['id' => $week_id]);

    $wpdb->query('COMMIT');
    return true;
}

/**
 * Reverse week finalization (unchanged).
 */
function kf_reverse_week($week_id) {
    global $wpdb;

    $weeks_table  = $wpdb->prefix . 'weeks';
    $scores_table = $wpdb->prefix . 'scores';

    $wpdb->query('START TRANSACTION');

    if (function_exists('kf_reverse_double_down_for_week')) {
        kf_reverse_double_down_for_week($week_id);
    }

    $wpdb->delete($scores_table, ['week_id' => $week_id]);

    $wpdb->update($weeks_table, [
        'status'              => 'published',
        'mwow_winner_user_id' => null,
        'bpow_winner_user_id' => null,
        'tie_data'            => null,
    ], ['id' => $week_id]);

    $wpdb->query('COMMIT');
    return true;
}
