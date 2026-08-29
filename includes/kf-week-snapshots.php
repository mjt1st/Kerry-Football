<?php
/**
 * Kerry Football — Week Snapshots
 *
 * A behind-the-scenes record of a week's state, taken immediately before any operation that
 * could change picks or scoring.
 *
 * Why this exists, and what it is actually protecting:
 *
 * Of everything a week holds, only PICKS are irrecoverable. Scores are derived — they can be
 * recomputed from picks plus results. Matchups can be re-fetched from ESPN. But a pick is
 * player input; if it is lost there is nothing to regenerate it from, and the player is not
 * going to remember which of sixteen games they put eleven points on.
 *
 * `score_history` does NOT cover this. Despite the name it is Double Down bookkeeping: it
 * stores the single score a DD overwrote so that one value can be put back. It records no
 * picks, no matchups, and nothing about weeks that were never touched by a DD.
 *
 * Snapshots are captured, never restored automatically. Restoring is deliberately a separate,
 * human decision: matchup ids change when a week is rewritten, so putting picks back means
 * re-mapping them onto the new rows, and silently guessing at that is how you turn a
 * recoverable problem into a scoring dispute nobody can audit.
 *
 * @package Kerry_Football
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * How many snapshots to keep per week. Old ones are pruned oldest-first so a week that is
 * repeatedly finalized and reversed cannot grow without bound.
 */
if ( ! defined( 'KF_SNAPSHOT_KEEP_PER_WEEK' ) ) {
    define( 'KF_SNAPSHOT_KEEP_PER_WEEK', 10 );
}

/**
 * Captures the current state of a week.
 *
 * Safe to call anywhere: it only ever reads the week's data and inserts one row. It never
 * modifies matchups, picks or scores, so adding a call site cannot break the operation it is
 * protecting. Failures are logged and swallowed for the same reason — a snapshot problem must
 * never stop a commissioner from finalizing a week.
 *
 * @param int    $week_id Week to capture.
 * @param string $reason  Short slug describing what was about to happen, e.g. 'pre_finalize'.
 * @return int|false Inserted snapshot id, or false on failure.
 */
function kf_snapshot_week( $week_id, $reason = 'manual' ) {
    global $wpdb;

    $week_id = intval( $week_id );
    if ( $week_id <= 0 ) {
        return false;
    }

    $table = $wpdb->prefix . 'week_snapshots';

    // If the table is not there yet (upgrade not run), do nothing rather than fatal.
    if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
        return false;
    }

    $week = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}weeks WHERE id = %d", $week_id ), ARRAY_A );
    if ( ! $week ) {
        return false;
    }

    $matchups = $wpdb->get_results( $wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}matchups WHERE week_id = %d ORDER BY id ASC", $week_id ), ARRAY_A );

    $picks = $wpdb->get_results( $wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}picks WHERE week_id = %d ORDER BY user_id ASC, matchup_id ASC", $week_id ), ARRAY_A );

    $scores = $wpdb->get_results( $wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}scores WHERE week_id = %d ORDER BY user_id ASC", $week_id ), ARRAY_A );

    $pending = $wpdb->get_results( $wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}pending_picks WHERE week_id = %d ORDER BY id ASC", $week_id ), ARRAY_A );

    $payload = wp_json_encode( [
        'captured_at'   => current_time( 'mysql', true ),
        'plugin_version'=> defined( 'KF_PLUGIN_VERSION' ) ? KF_PLUGIN_VERSION : '',
        'week'          => $week,
        'matchups'      => $matchups,
        'picks'         => $picks,
        'scores'        => $scores,
        'pending_picks' => $pending,
    ] );

    if ( $payload === false ) {
        error_log( 'Kerry Football: snapshot for week ' . $week_id . ' could not be encoded as JSON.' );
        return false;
    }

    $inserted = $wpdb->insert( $table, [
        'week_id'      => $week_id,
        'season_id'    => intval( $week['season_id'] ),
        'reason'       => sanitize_key( $reason ),
        'created_by'   => get_current_user_id(),
        'created_at'   => current_time( 'mysql', true ),
        'pick_count'   => count( $picks ),
        'matchup_count'=> count( $matchups ),
        'payload'      => $payload,
    ] );

    if ( $inserted === false ) {
        error_log( 'Kerry Football: failed to store snapshot for week ' . $week_id . ' — ' . $wpdb->last_error );
        return false;
    }

    $snapshot_id = (int) $wpdb->insert_id;
    kf_prune_week_snapshots( $week_id );

    return $snapshot_id;
}

/**
 * Drops the oldest snapshots for a week beyond KF_SNAPSHOT_KEEP_PER_WEEK.
 *
 * @param int $week_id
 * @return void
 */
function kf_prune_week_snapshots( $week_id ) {
    global $wpdb;

    $table = $wpdb->prefix . 'week_snapshots';
    $keep  = (int) KF_SNAPSHOT_KEEP_PER_WEEK;

    $doomed = $wpdb->get_col( $wpdb->prepare(
        "SELECT id FROM {$table} WHERE week_id = %d ORDER BY id DESC LIMIT %d, 100",
        intval( $week_id ),
        $keep
    ) );

    foreach ( $doomed as $id ) {
        $wpdb->delete( $table, [ 'id' => intval( $id ) ] );
    }
}

/**
 * Lists snapshots for a week, newest first. Payload is excluded — it is large and only needed
 * when one is actually being inspected.
 *
 * @param int $week_id
 * @return array
 */
function kf_get_week_snapshots( $week_id ) {
    global $wpdb;

    $table = $wpdb->prefix . 'week_snapshots';
    if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
        return [];
    }

    return $wpdb->get_results( $wpdb->prepare(
        "SELECT id, week_id, season_id, reason, created_by, created_at, pick_count, matchup_count
         FROM {$table} WHERE week_id = %d ORDER BY id DESC",
        intval( $week_id )
    ) );
}

/**
 * Returns one snapshot including its payload, after checking the caller may manage the season
 * it belongs to.
 *
 * @param int $snapshot_id
 * @return object|null
 */
function kf_get_week_snapshot( $snapshot_id ) {
    global $wpdb;

    $table = $wpdb->prefix . 'week_snapshots';
    $row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", intval( $snapshot_id ) ) );
    if ( ! $row ) {
        return null;
    }
    if ( ! kf_can_manage_season( (int) $row->season_id ) ) {
        return null;
    }
    return $row;
}

/**
 * Human-readable label for a snapshot reason slug.
 *
 * @param string $reason
 * @return string
 */
function kf_snapshot_reason_label( $reason ) {
    $labels = [
        'pre_finalize'      => 'Before finalizing',
        'pre_reverse'       => 'Before reversing',
        'pre_matchup_write' => 'Before rewriting games',
        'pre_late_picks'    => 'Before approving late picks',
        'manual'            => 'Manual',
    ];
    return $labels[ $reason ] ?? ucfirst( str_replace( '_', ' ', $reason ) );
}

/**
 * Serves a snapshot as a JSON download.
 *
 * Hooked early on `init` so the file can be sent before any theme output. Requires a nonce
 * and re-checks season management rights via kf_get_week_snapshot() — a snapshot contains
 * every pick in the week, so it must never be reachable by guessing an id.
 *
 * @return void
 */
function kf_maybe_download_week_snapshot() {
    if ( empty( $_GET['kf_snapshot'] ) || ! is_user_logged_in() ) {
        return;
    }

    $snapshot_id = intval( $_GET['kf_snapshot'] );
    if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( $_GET['_wpnonce'], 'kf_download_snapshot_' . $snapshot_id ) ) {
        return;
    }

    $snapshot = kf_get_week_snapshot( $snapshot_id );
    if ( ! $snapshot ) {
        return; // not found, or caller cannot manage that season
    }

    nocache_headers();
    header( 'Content-Type: application/json; charset=utf-8' );
    $filename = 'kf-week-' . intval( $snapshot->week_id ) . '-snapshot-' . intval( $snapshot->id ) . '.json';
    header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
    echo $snapshot->payload;
    exit;
}
add_action( 'init', 'kf_maybe_download_week_snapshot', 5 );

// No closing PHP tag to prevent whitespace issues.
