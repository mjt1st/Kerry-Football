<?php
/**
 * Kerry Football — team names, picks and results as text.
 *
 * WordPress adds a backslash before every apostrophe in submitted form data, and the save paths
 * here did not take it back out. A college team like Hawai'i was therefore stored by Week Setup as
 * "Hawai\'i Rainbow Warriors"; the score cron copied that into the matchup's result; and a player's
 * pick, which posts the stored name back, was slashed a second time into "Hawai\\\'i …". Scoring
 * asks whether the pick equals the result as plain text, so it never matched: a correct pick on any
 * team whose name contains an apostrophe scored as a loss, and the week summary coloured it red.
 *
 * Three parts, and all three are needed:
 *   1. The save paths now unslash (kf-week-setup.php, kf-player-picks.php, kf-enter-results-view.php).
 *   2. Every comparison goes through kf_team_key() / kf_sql_team_key(), so a stray backslash in data
 *      written before this release cannot cost anyone points again.
 *   3. kf_repair_slashed_team_text() cleans what is already stored, once, snapshotting first.
 *
 * Scores already written for finalized weeks are NOT recalculated here: that would silently rewrite
 * weekly winners, Double Down outcomes and season standings. The repair reports which weeks are
 * affected so a commissioner can reverse and re-finalize them deliberately.
 *
 * @package Kerry_Football
 * @since   1.8.24
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * The comparison form of a team name, pick or result.
 *
 * Backslashes removed, trimmed, lower-cased. Only for comparing — never store this.
 *
 * @param string|null $value
 * @return string
 */
function kf_team_key( $value ) {
    return strtolower( trim( kf_strip_backslashes( $value ) ) );
}

/**
 * A stored name with every backslash removed, case and spacing untouched.
 *
 * Not stripslashes(): that removes one layer, and a pick carries two — the name was slashed when the
 * game was saved, then slashed again when the pick posted that name back — so a single pass still
 * left "Hawai\'i", which does not equal the cleaned team name. No real team name contains a
 * backslash, so every one of them goes.
 *
 * @param string|null $value
 * @return string
 */
function kf_strip_backslashes( $value ) {
    return str_replace( '\\', '', (string) $value );
}

/**
 * kf_strip_backslashes() through the pending late-picks JSON structure.
 *
 * @param mixed $value
 * @return mixed
 */
function kf_strip_backslashes_deep( $value ) {
    if ( is_array( $value ) ) {
        return array_map( 'kf_strip_backslashes_deep', $value );
    }
    return is_string( $value ) ? kf_strip_backslashes( $value ) : $value;
}

/**
 * The same normalisation as SQL, for the scoring queries.
 *
 * CHAR(92) rather than a '\\' literal: what a backslash literal means depends on the server's
 * NO_BACKSLASH_ESCAPES mode, and CHAR(92) is a backslash either way.
 *
 * @param string $column Already-safe column reference, e.g. 'p.pick'. Never user input.
 * @return string SQL expression.
 */
function kf_sql_team_key( $column ) {
    return "REPLACE(LOWER(TRIM($column)), CHAR(92), '')";
}

/**
 * Remove stray backslashes from stored team names, picks, results and pending late picks. Runs once.
 *
 * Every affected week is snapshotted before anything changes, so the previous values are recoverable
 * exactly as they were. Rows without a backslash are left untouched, which means this is a no-op on a
 * league that never had a team with an apostrophe in its name.
 *
 * @return array The report, also stored in the kf_slash_repair_report option.
 */
function kf_repair_slashed_team_text() {
    global $wpdb;

    $report = [
        'ran_at'           => current_time( 'mysql', 1 ),
        'matchups'         => 0,
        'picks'            => 0,
        'pending'          => 0,
        'weeks'            => [],   // week_id => ['week_number' => n, 'season' => name, 'status' => s]
        'finalized_weeks'  => [],   // the ones whose stored scores are now out of date
    ];

    $has_slash = function ( $value ) {
        return is_string( $value ) && strpos( $value, '\\' ) !== false;
    };

    // --- Find what needs changing, before touching anything -------------------------------------
    $matchup_fixes = [];
    $matchups = $wpdb->get_results( "SELECT id, week_id, team_a, team_b, result FROM {$wpdb->prefix}matchups" );
    foreach ( (array) $matchups as $m ) {
        $clean = [];
        foreach ( [ 'team_a', 'team_b', 'result' ] as $field ) {
            if ( $has_slash( $m->$field ) ) {
                $clean[ $field ] = kf_strip_backslashes( $m->$field );
            }
        }
        if ( $clean ) {
            $matchup_fixes[] = [ 'id' => (int) $m->id, 'week_id' => (int) $m->week_id, 'set' => $clean ];
        }
    }

    $pick_fixes = [];
    $picks = $wpdb->get_results( "SELECT id, week_id, pick FROM {$wpdb->prefix}picks" );
    foreach ( (array) $picks as $p ) {
        if ( $has_slash( $p->pick ) ) {
            $pick_fixes[] = [ 'id' => (int) $p->id, 'week_id' => (int) $p->week_id, 'set' => [ 'pick' => kf_strip_backslashes( $p->pick ) ] ];
        }
    }

    $pending_fixes = [];
    $pending = $wpdb->get_results( "SELECT id, week_id, picks_data FROM {$wpdb->prefix}pending_picks WHERE status = 'pending'" );
    foreach ( (array) $pending as $row ) {
        $decoded = json_decode( (string) $row->picks_data, true );
        if ( ! is_array( $decoded ) ) {
            continue;
        }
        $cleaned = kf_strip_backslashes_deep( $decoded );
        if ( $cleaned !== $decoded ) {
            $pending_fixes[] = [ 'id' => (int) $row->id, 'week_id' => (int) $row->week_id, 'set' => [ 'picks_data' => wp_json_encode( $cleaned ) ] ];
        }
    }

    $affected_weeks = array_unique( array_merge(
        wp_list_pluck( $matchup_fixes, 'week_id' ),
        wp_list_pluck( $pick_fixes, 'week_id' ),
        wp_list_pluck( $pending_fixes, 'week_id' )
    ) );

    if ( ! $affected_weeks ) {
        update_option( 'kf_slash_repair_report', $report, false );
        return $report;
    }

    // --- Snapshot every affected week first -----------------------------------------------------
    foreach ( $affected_weeks as $week_id ) {
        $week = $wpdb->get_row( $wpdb->prepare(
            "SELECT w.id, w.week_number, w.status, s.name AS season_name
             FROM {$wpdb->prefix}weeks w
             LEFT JOIN {$wpdb->prefix}seasons s ON w.season_id = s.id
             WHERE w.id = %d",
            $week_id
        ) );
        if ( ! $week ) {
            continue;
        }
        if ( function_exists( 'kf_snapshot_week' ) ) {
            kf_snapshot_week( (int) $week_id, 'pre_slash_repair' );
        }
        $entry = [
            'week_number' => (int) $week->week_number,
            'season'      => (string) $week->season_name,
            'status'      => (string) $week->status,
        ];
        $report['weeks'][ (int) $week_id ] = $entry;
        if ( $week->status === 'finalized' ) {
            $report['finalized_weeks'][ (int) $week_id ] = $entry;
        }
    }

    // --- Apply -----------------------------------------------------------------------------------
    foreach ( $matchup_fixes as $fix ) {
        $wpdb->update( $wpdb->prefix . 'matchups', $fix['set'], [ 'id' => $fix['id'] ] );
        $report['matchups']++;
    }
    foreach ( $pick_fixes as $fix ) {
        $wpdb->update( $wpdb->prefix . 'picks', $fix['set'], [ 'id' => $fix['id'] ] );
        $report['picks']++;
    }
    foreach ( $pending_fixes as $fix ) {
        $wpdb->update( $wpdb->prefix . 'pending_picks', $fix['set'], [ 'id' => $fix['id'] ] );
        $report['pending']++;
    }

    update_option( 'kf_slash_repair_report', $report, false );
    error_log( sprintf(
        'Kerry Football: repaired backslashed names — %d matchups, %d picks, %d pending, across %d week(s); %d finalized week(s) need re-finalizing.',
        $report['matchups'], $report['picks'], $report['pending'], count( $report['weeks'] ), count( $report['finalized_weeks'] )
    ) );

    return $report;
}

/**
 * Run the repair once, after the schema upgrade on init.
 *
 * Guarded by its own option rather than the schema version: this changes data, not structure, and a
 * league that never had an affected team simply records an empty report and never looks again.
 */
function kf_maybe_repair_slashed_team_text() {
    if ( get_option( 'kf_slash_repair_done' ) ) {
        return;
    }
    update_option( 'kf_slash_repair_done', '1', false ); // Set first: never retry in a loop on failure.
    kf_repair_slashed_team_text();
}
add_action( 'init', 'kf_maybe_repair_slashed_team_text', 11 ); // after kf_maybe_upgrade_db()

// No closing PHP tag.
