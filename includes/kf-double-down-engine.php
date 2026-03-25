<?php
/**
 * Kerry Football Double Down Engine.
 *
 * This file contains all logic related to applying, reversing, and checking
 * Double Down transactions. It is separated from the main scoring engine
 * to improve maintainability and separation of concerns.
 *
 * @package Kerry_Football
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

/**
 * Applies Double Down logic for all players for a finalized week.
 * This function should be called AFTER initial scores for the week are inserted.
 *
 * @param int $source_week_id The ID of the week the DD is being used on.
 * @param int $season_id The ID of the current season.
 */
function kf_apply_double_down_for_week($source_week_id, $season_id) {
    global $wpdb;

    // Table names
    $weeks_table = $wpdb->prefix . 'weeks';
    $scores_table = $wpdb->prefix . 'scores';
    $dd_selections_table = $wpdb->prefix . 'dd_selections';
    $score_history_table = $wpdb->prefix . 'score_history';

    // Get all players who selected DD for this week
    $dd_players = $wpdb->get_col($wpdb->prepare(
        "SELECT user_id FROM $dd_selections_table WHERE week_id = %d AND season_id = %d",
        $source_week_id,
        $season_id
    ));

    if (empty($dd_players)) {
        error_log("DD LOGIC (Week $source_week_id): No players selected Double Down. Skipping.");
        return;
    }

    foreach ($dd_players as $player_id) {
        error_log("DD LOGIC (Week $source_week_id): Processing DD for Player ID: $player_id");

        // --- Find the single lowest-scoring, eligible target week for this player ---
        
        // First, get IDs of all weeks already involved in a DD for this player to exclude them.
        $locked_weeks_as_source = $wpdb->get_col($wpdb->prepare("SELECT replaced_by_week_id FROM $score_history_table sh JOIN $weeks_table w ON sh.replaced_by_week_id = w.id WHERE sh.user_id = %d AND w.season_id = %d", $player_id, $season_id));
        $locked_weeks_as_target = $wpdb->get_col($wpdb->prepare("SELECT week_id FROM $score_history_table sh JOIN $weeks_table w ON sh.week_id = w.id WHERE sh.user_id = %d AND w.season_id = %d", $player_id, $season_id));
        
        $locked_week_ids = array_unique(array_merge($locked_weeks_as_source, $locked_weeks_as_target));
        
        // Also exclude the current source week from being a potential target.
        $locked_week_ids[] = $source_week_id; 

        $placeholders = implode(',', array_fill(0, count($locked_week_ids), '%d'));

        // BUG FIX: The query now correctly selects `week_id` and the score's primary key `id` as `score_id`.
        $target_week_data = $wpdb->get_row($wpdb->prepare(
            "SELECT id as score_id, week_id, score, subtotal, wins, mwow_bonus_awarded, is_bpow_score 
            FROM {$wpdb->prefix}scores 
            WHERE user_id = %d 
            AND week_id IN (SELECT id FROM $weeks_table WHERE season_id = %d AND status = 'finalized')
            AND week_id NOT IN ($placeholders)
            ORDER BY score ASC, (SELECT week_number FROM $weeks_table WHERE id = week_id) ASC 
            LIMIT 1",
            array_merge([$player_id, $season_id], $locked_week_ids)
        ));

        if (!$target_week_data) {
            error_log("DD LOGIC (Week $source_week_id): No eligible target week found for Player ID: $player_id. Skipping.");
            continue;
        }

        $target_week_id = $target_week_data->week_id;
        $target_score_id = $target_week_data->score_id;

        // --- Get the source score data from the current week ---
        $source_score_data = $wpdb->get_row($wpdb->prepare(
            "SELECT subtotal, wins, is_bpow_score FROM $scores_table WHERE week_id = %d AND user_id = %d",
            $source_week_id,
            $player_id
        ));

        if (!$source_score_data) {
            error_log("DD LOGIC (Week $source_week_id): Source score data not found for Player ID: $player_id. Skipping.");
            continue;
        }

        // --- Log the original data to score_history ---
        $history_logged = $wpdb->insert($score_history_table, [
            'score_id'                  => $target_score_id, // BUG FIX: Use the correct score_id
            'user_id'                   => $player_id,
            'week_id'                   => $target_week_id,
            'original_score'            => $target_week_data->score,
            'original_subtotal'         => $target_week_data->subtotal,
            'original_wins'             => $target_week_data->wins,
            'original_mwow_bonus_awarded' => $target_week_data->mwow_bonus_awarded,
            'original_is_bpow_score'    => $target_week_data->is_bpow_score,
            'replaced_at'               => current_time('mysql'),
            'replaced_by_week_id'       => $source_week_id
        ]);
        
        if (!$history_logged) {
            error_log("DD LOGIC (Week $source_week_id): FAILED to insert into score_history for Player ID: $player_id. Skipping update.");
            continue;
        }

        // --- Update the target week's score in the edk_scores table ---
        $new_score = $source_score_data->subtotal;
        
        $wpdb->update($scores_table,
            [
                'score'              => $new_score,
                'subtotal'           => $source_score_data->subtotal,
                'wins'               => $source_score_data->wins,
                'is_bpow_score'      => $source_score_data->is_bpow_score,
                'mwow_bonus_awarded' => 0 
            ],
            [
                'id' => $target_score_id // BUG FIX: Update by the score's primary key for safety
            ]
        );
        error_log("DD LOGIC (Week $source_week_id): SUCCESS. Player $player_id replaced Week $target_week_id score with $new_score.");
    }
}


/**
 * Reverses all Double Down transactions associated with a given source week.
 */
function kf_reverse_double_down_for_week($source_week_id) {
    global $wpdb;

    $scores_table = $wpdb->prefix . 'scores';
    $score_history_table = $wpdb->prefix . 'score_history';
    $dd_selections_table = $wpdb->prefix . 'dd_selections';
    $weeks_table = $wpdb->prefix . 'weeks';

    $history_entries = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM $score_history_table WHERE replaced_by_week_id = %d",
        $source_week_id
    ));

    if (empty($history_entries)) {
        error_log("DD REVERSAL (Source Week $source_week_id): No DD transactions originated from this week. Nothing to reverse.");
        return;
    }
    
    foreach ($history_entries as $entry) {
        $wpdb->update($scores_table,
            [
                'score'              => $entry->original_score,
                'subtotal'           => $entry->original_subtotal,
                'wins'               => $entry->original_wins,
                'mwow_bonus_awarded' => $entry->original_mwow_bonus_awarded,
                'is_bpow_score'      => $entry->original_is_bpow_score,
            ],
            [
                'id' => $entry->score_id // Update using the specific score ID
            ]
        );

        $season_id = $wpdb->get_var($wpdb->prepare("SELECT season_id FROM $weeks_table WHERE id = %d", $source_week_id));
        if ($season_id) {
            $wpdb->replace($dd_selections_table, [
                'user_id'   => $entry->user_id,
                'week_id'   => $source_week_id,
                'season_id' => $season_id,
            ]);
        }
        
        $wpdb->delete($score_history_table, ['id' => $entry->id]);

        error_log("DD REVERSAL (Source Week $source_week_id): SUCCESS. Restored original score for Player {$entry->user_id} on Target Week {$entry->week_id}.");
    }
}