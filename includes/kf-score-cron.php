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
/**
 * Supported score-check cadences, in minutes.
 *
 * The host cron must fire at least this often or the setting does nothing — WordPress only
 * runs an event when it is due, so a 5-minute event behind a 15-minute runner updates every
 * 15 minutes. The API Settings screen says so next to the selector.
 *
 * @return int[]
 */
function kf_score_check_choices() {
    return [ 5, 10, 15, 30 ];
}

/**
 * The configured cadence in minutes, clamped to a supported value.
 *
 * @return int
 */
function kf_score_check_minutes() {
    $minutes = (int) get_option( 'kf_score_check_minutes', 15 );
    return in_array( $minutes, kf_score_check_choices(), true ) ? $minutes : 15;
}

/**
 * WP-Cron schedule slug for the configured cadence.
 *
 * @return string
 */
function kf_score_check_schedule() {
    return 'kf_every_' . kf_score_check_minutes() . '_minutes';
}

function kf_cron_add_intervals( $schedules ) {
    foreach ( kf_score_check_choices() as $minutes ) {
        $schedules[ 'kf_every_' . $minutes . '_minutes' ] = [
            'interval' => $minutes * 60,
            'display'  => sprintf( 'Every %d Minutes (Kerry Football)', $minutes ),
        ];
    }
    // Retained so any event still scheduled under the old slug keeps a valid interval
    // until kf_schedule_score_cron() migrates it on the next page load.
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
    $wanted   = kf_score_check_schedule();
    $existing = wp_get_schedule( 'kf_check_game_scores' );

    // Changing the interval definition does not move an event that is already scheduled —
    // it keeps running on whatever recurrence it was created with. Reschedule when the two
    // disagree, which also migrates events created under the old 'kf_fifteen_minutes' slug.
    if ( $existing && $existing !== $wanted ) {
        wp_clear_scheduled_hook( 'kf_check_game_scores' );
        $existing = false;
    }

    if ( ! $existing && ! wp_next_scheduled( 'kf_check_game_scores' ) ) {
        wp_schedule_event( time(), $wanted, 'kf_check_game_scores' );
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

    // Only talk to ESPN when a tracked game could plausibly be underway.
    //
    // Restricting the cron itself to game hours would be the obvious approach, but the same
    // runner fires every other WordPress event — deadline reminder emails included — so
    // narrowing it would delay those too. Gating on the data instead is self-maintaining:
    // it follows the actual schedule with no windows to update when fixtures move.
    //
    // A game counts as in-window from kickoff until six hours after, which covers overtime
    // and long weather delays. Games whose kickoff we do not know are always included, so a
    // missing date can never silently stop a game updating.
    $now          = time();
    $grace        = 6 * HOUR_IN_SECONDS;
    $in_window    = false;
    foreach ( $pending_matchups as $m ) {
        if ( $m->game_status === 'in_progress' ) {
            $in_window = true;
            break;
        }
        if ( empty( $m->game_datetime ) || $m->game_datetime === '0000-00-00 00:00:00' ) {
            $in_window = true;   // unknown kickoff — never skip on a guess
            break;
        }
        $kickoff = strtotime( $m->game_datetime . ' UTC' );
        if ( $kickoff && $now >= $kickoff && $now <= ( $kickoff + $grace ) ) {
            $in_window = true;
            break;
        }
    }

    if ( ! $in_window ) {
        update_option( 'kf_cron_last_report', [
            'at'        => $now,
            'sport'     => '',
            'pending'   => count( $pending_matchups ),
            'fetched'   => 0,
            'updated'   => 0,
            'unmatched' => [],
            'skipped'   => true,
        ], false );
        update_option( 'kf_cron_last_run', $now );
        delete_transient( 'kf_score_cron_running' );
        return;
    }

    // Which sport to ask ESPN about. The season knows this ('nfl' or 'college-football'),
    // so use it rather than the global default: a college league whose site default is NFL
    // spent every run querying the NFL scoreboard, missing everything, then doing one
    // wasted per-game lookup each (all 404s) before finally trying college.
    $season_ids = array_unique( wp_list_pluck( $pending_matchups, 'season_id' ) );
    $season_sport = null;
    if ( count( $season_ids ) === 1 ) {
        $season_sport = $wpdb->get_var( $wpdb->prepare(
            "SELECT sport_type FROM {$wpdb->prefix}seasons WHERE id = %d",
            intval( reset( $season_ids ) )
        ) );
    }
    $default_sport = ( $season_sport === 'college-football' || $season_sport === 'nfl' )
        ? $season_sport
        : get_option( 'kf_default_sport', 'nfl' );

    // Drop the cached scoreboard before fetching, exactly as the manual refresh does.
    // kf_espn_fetch_scoreboard() caches for 15 minutes and this job runs every 15 minutes,
    // so without this the cron regularly re-read the copy it had fetched the run before —
    // live scores could sit up to half an hour behind while the job appeared to be working.
    foreach ( $event_ids as $cached_event_id ) {
        delete_transient( 'kf_espn_evt_' . $cached_event_id );
    }
    $alt_sport = ( $default_sport === 'nfl' ) ? 'college-football' : 'nfl';
    foreach ( [ $default_sport, $alt_sport ] as $cached_sport ) {
        kf_espn_clear_scoreboard_cache( $cached_sport );
    }

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


    $cron_updated = 0;   // how many rows this run actually wrote
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

        // Same period/clock handling as the manual refresh path below.
        if ( kf_matchups_have_status_detail() ) {
            $update_data['status_detail'] =
                ( $game['game_status'] === 'in_progress' && ! empty( $game['status_detail'] ) )
                    ? substr( (string) $game['status_detail'], 0, 60 )
                    : null;
        }

        // Update scores if available
        if ( $game['home_score'] !== null ) {
            $update_data['home_score'] = intval( $game['home_score'] );
        }
        if ( $game['away_score'] !== null ) {
            $update_data['away_score'] = intval( $game['away_score'] );
        }

        // If game is final, auto-populate the result
        // Only fill in a result that is not already set. The commissioner can override any
        // result on the Enter Results page, and before this guard the next cron run (every 15
        // minutes) silently reverted that correction back to ESPN's version. Overrides exist
        // for the cases ESPN gets wrong or does not model — forfeits, abandoned games, a bad
        // feed — so a stored result always wins. Scores and status still refresh below.
        $result_already_set = isset( $matchup->result ) && trim( (string) $matchup->result ) !== '';

        if ( ! $result_already_set && $game['game_status'] === 'final' && $game['home_score'] !== null && $game['away_score'] !== null ) {
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
        $cron_updated++;
    }

    // Record what the run actually did. "The cron ran" on its own says nothing useful when
    // scores have not moved — this distinguishes ESPN returning nothing, from nothing being
    // eligible to update, from rows being written that simply had not changed.
    update_option( 'kf_cron_last_report', [
        'at'        => time(),
        'sport'     => $default_sport,
        'pending'   => count( $pending_matchups ),
        'fetched'   => count( $scores ),
        'updated'   => $cron_updated,
        'unmatched' => array_values( array_diff( $event_ids, array_keys( $scores ) ) ),
    ], false );

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
    kf_espn_clear_scoreboard_cache( $default_sport );

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
        // ESPN's "9:55 - 3rd" for live rows. Only meaningful mid-game: for a scheduled game
        // shortDetail is a kickoff time in ESPN's own timezone, which we already store and
        // render properly ourselves, so it is cleared rather than saved.
        if ( kf_matchups_have_status_detail() ) {
            $update_data['status_detail'] =
                ( $game['game_status'] === 'in_progress' && ! empty( $game['status_detail'] ) )
                    ? substr( (string) $game['status_detail'], 0, 60 )
                    : null;
        }

        if ( $game['home_score'] !== null ) {
            $update_data['home_score'] = intval( $game['home_score'] );
        }
        if ( $game['away_score'] !== null ) {
            $update_data['away_score'] = intval( $game['away_score'] );
        }

        // Only fill in a result that is not already set. The commissioner can override any
        // result on the Enter Results page, and before this guard the next cron run (every 15
        // minutes) silently reverted that correction back to ESPN's version. Overrides exist
        // for the cases ESPN gets wrong or does not model — forfeits, abandoned games, a bad
        // feed — so a stored result always wins. Scores and status still refresh below.
        $result_already_set = isset( $matchup->result ) && trim( (string) $matchup->result ) !== '';

        if ( ! $result_already_set && $game['game_status'] === 'final' && $game['home_score'] !== null && $game['away_score'] !== null ) {
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
