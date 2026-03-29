<?php
/**
 * Kerry Football — Automatic Score Updates via WP-Cron
 *
 * Checks ESPN for live/final scores every 15 minutes and auto-populates
 * the result column for completed games. Only operates on API-mode weeks
 * (matchups that have an espn_game_id).
 *
 * Commissioner must still manually review and finalize each week.
 *
 * @package Kerry_Football
 * @since   Sports API V1
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Registers a custom 15-minute cron interval.
 */
function kf_cron_add_intervals( $schedules ) {
    $schedules['kf_fifteen_minutes'] = [
        'interval' => 15 * 60,
        'display'  => 'Every 15 Minutes (Kerry Football)',
    ];
    return $schedules;
}
add_filter( 'cron_schedules', 'kf_cron_add_intervals' );

/**
 * Schedules the score-checking cron event if not already scheduled.
 * Called on plugin activation and on 'init' as a safety net.
 */
function kf_schedule_score_cron() {
    if ( ! wp_next_scheduled( 'kf_check_game_scores' ) ) {
        wp_schedule_event( time(), 'kf_fifteen_minutes', 'kf_check_game_scores' );
    }
}
add_action( 'init', 'kf_schedule_score_cron' );

/**
 * Unschedules the cron event on plugin deactivation.
 */
function kf_unschedule_score_cron() {
    $timestamp = wp_next_scheduled( 'kf_check_game_scores' );
    if ( $timestamp ) {
        wp_unschedule_event( $timestamp, 'kf_check_game_scores' );
    }
}

/**
 * Main cron callback: checks scores for all pending API-mode games.
 */
function kf_cron_check_scores() {
    // Bail if auto-score is disabled
    if ( get_option( 'kf_auto_score_enabled', '1' ) !== '1' ) {
        return;
    }

    // Transient lock: prevent concurrent execution (e.g. two cron workers firing at once).
    // Lock expires after 5 minutes — well beyond any realistic ESPN fetch time.
    if ( get_transient( 'kf_score_cron_running' ) ) {
        error_log( 'Kerry Football: kf_cron_check_scores skipped — previous run still in progress.' );
        return;
    }
    set_transient( 'kf_score_cron_running', 1, 5 * MINUTE_IN_SECONDS );

    global $wpdb;
    $matchups_table = $wpdb->prefix . 'matchups';
    $weeks_table    = $wpdb->prefix . 'weeks';

    // Find all matchups that:
    // 1. Have an ESPN game ID (API mode)
    // 2. Are not yet final
    // 3. Belong to published (non-finalized) weeks
    $pending_matchups = $wpdb->get_results(
        "SELECT m.*, w.season_id
         FROM {$matchups_table} m
         JOIN {$weeks_table} w ON m.week_id = w.id
         WHERE m.espn_game_id IS NOT NULL
           AND m.espn_game_id != ''
           AND (m.game_status IS NULL OR m.game_status NOT IN ('final', 'canceled', 'postponed'))
           AND w.status IN ('published', 'tie_resolution_needed')
         ORDER BY m.week_id ASC"
    );

    if ( empty( $pending_matchups ) ) {
        delete_transient( 'kf_score_cron_running' );
        return; // Nothing to check
    }

    // Group by sport (determine sport from ESPN game format or default)
    // ESPN game IDs don't encode sport, so we'll try NFL first (most common)
    // and fall back to college-football if needed.
    $event_ids = [];
    foreach ( $pending_matchups as $m ) {
        $event_ids[] = $m->espn_game_id;
    }
    $event_ids = array_unique( $event_ids );

    // Try fetching from NFL first
    $default_sport = get_option( 'kf_default_sport', 'nfl' );
    $scores        = kf_espn_fetch_scores( $default_sport, $event_ids );

    // If some IDs weren't found and we have a secondary sport, try that too
    $found_ids = array_keys( $scores );
    $missing   = array_diff( $event_ids, $found_ids );
    if ( ! empty( $missing ) ) {
        $alt_sport  = $default_sport === 'nfl' ? 'college-football' : 'nfl';
        $alt_scores = kf_espn_fetch_scores( $alt_sport, $missing );
        $scores     = array_merge( $scores, $alt_scores );
    }

    if ( empty( $scores ) ) {
        // Record consecutive ESPN failures for health dashboard
        $failures = (int) get_option( 'kf_cron_consecutive_failures', 0 ) + 1;
        update_option( 'kf_cron_consecutive_failures', $failures );
        if ( $failures >= 3 ) {
            error_log( "Kerry Football: ESPN score fetch has failed {$failures} consecutive times. Check ESPN API connectivity." );
        }
        delete_transient( 'kf_score_cron_running' );
        return;
    }

    // Reset failure counter on success
    update_option( 'kf_cron_consecutive_failures', 0 );

    // Update each matchup with fresh score data
    foreach ( $pending_matchups as $matchup ) {
        $game = $scores[ $matchup->espn_game_id ] ?? null;
        if ( ! $game ) {
            continue;
        }

        $update_data = [
            'game_status' => $game['game_status'],
        ];

        // Update scores if available
        if ( $game['home_score'] !== null ) {
            $update_data['home_score'] = intval( $game['home_score'] );
        }
        if ( $game['away_score'] !== null ) {
            $update_data['away_score'] = intval( $game['away_score'] );
        }

        // If game is final, auto-populate the result
        if ( $game['game_status'] === 'final' && $game['home_score'] !== null && $game['away_score'] !== null ) {
            $home_score = intval( $game['home_score'] );
            $away_score = intval( $game['away_score'] );

            if ( $matchup->is_tiebreaker ) {
                // Tiebreaker: result is the total combined score
                $update_data['result'] = $home_score + $away_score;
            } else {
                // Regular game: result is the winning team name
                if ( $home_score > $away_score ) {
                    $update_data['result'] = $matchup->team_a; // Home team wins
                } elseif ( $away_score > $home_score ) {
                    $update_data['result'] = $matchup->team_b; // Away team wins
                } else {
                    $update_data['result'] = 'TIE';
                }
            }
        }

        $wpdb->update(
            $matchups_table,
            $update_data,
            [ 'id' => $matchup->id ]
        );
    }

    // Record successful run time for health monitoring dashboard.
    update_option( 'kf_cron_last_run', time() );

    // Release the concurrency lock.
    delete_transient( 'kf_score_cron_running' );
}
add_action( 'kf_check_game_scores', 'kf_cron_check_scores' );

/**
 * Manual score refresh for a specific week.
 * Called via AJAX by the "Refresh Scores Now" button on the Enter Results page.
 *
 * @param int $week_id The week to refresh scores for.
 * @return array [ 'updated' => int, 'message' => string ]
 */
function kf_refresh_week_scores( $week_id ) {
    global $wpdb;
    $matchups_table = $wpdb->prefix . 'matchups';

    $matchups = $wpdb->get_results( $wpdb->prepare(
        "SELECT * FROM {$matchups_table}
         WHERE week_id = %d
           AND espn_game_id IS NOT NULL
           AND espn_game_id != ''",
        $week_id
    ) );

    if ( empty( $matchups ) ) {
        return [ 'updated' => 0, 'message' => 'No API-linked games found for this week.' ];
    }

    $event_ids = array_unique( wp_list_pluck( $matchups, 'espn_game_id' ) );

    // Clear transient caches to force fresh data
    foreach ( $event_ids as $eid ) {
        delete_transient( 'kf_espn_evt_' . $eid );
    }

    $default_sport = get_option( 'kf_default_sport', 'nfl' );

    // Clear the scoreboard cache too
    $scoreboard_url = "https://site.api.espn.com/apis/site/v2/sports/football/{$default_sport}/scoreboard";
    delete_transient( 'kf_espn_' . md5( $scoreboard_url ) );

    // Now fetch fresh scores
    $scores = kf_espn_fetch_scores( $default_sport, $event_ids );

    // Try alternate sport for missing
    $found = array_keys( $scores );
    $missing = array_diff( $event_ids, $found );
    if ( ! empty( $missing ) ) {
        $alt = $default_sport === 'nfl' ? 'college-football' : 'nfl';
        $scores = array_merge( $scores, kf_espn_fetch_scores( $alt, $missing ) );
    }

    $updated = 0;
    foreach ( $matchups as $matchup ) {
        $game = $scores[ $matchup->espn_game_id ] ?? null;
        if ( ! $game ) {
            continue;
        }

        $update_data = [ 'game_status' => $game['game_status'] ];

        if ( $game['home_score'] !== null ) {
            $update_data['home_score'] = intval( $game['home_score'] );
        }
        if ( $game['away_score'] !== null ) {
            $update_data['away_score'] = intval( $game['away_score'] );
        }

        if ( $game['game_status'] === 'final' && $game['home_score'] !== null && $game['away_score'] !== null ) {
            $home = intval( $game['home_score'] );
            $away = intval( $game['away_score'] );

            if ( $matchup->is_tiebreaker ) {
                $update_data['result'] = $home + $away;
            } else {
                if ( $home > $away ) {
                    $update_data['result'] = $matchup->team_a;
                } elseif ( $away > $home ) {
                    $update_data['result'] = $matchup->team_b;
                } else {
                    $update_data['result'] = 'TIE';
                }
            }
        }

        $wpdb->update( $matchups_table, $update_data, [ 'id' => $matchup->id ] );
        $updated++;
    }

    return [
        'updated' => $updated,
        'message' => "{$updated} game(s) updated.",
    ];
}

// No closing PHP tag.
