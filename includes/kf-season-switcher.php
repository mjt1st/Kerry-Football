<?php
/**
 * Handles the Global Active Season context for all users.
 *
 * @package Kerry_Football
 * - Manages session for active season, setting default if none.
 * - MODIFICATION: AJAX handler now receives the intended redirect URL from the client-side script and returns it, fixing the primary navigation bug.
 */

if (!defined('ABSPATH')) exit;

/**
 * Central access-control helper.
 *
 * A user can manage a season if they created it OR are enrolled as an
 * accepted participant. Site-level admins (manage_options) are NOT
 * automatically granted access to every season — they still need to
 * be the creator or an accepted participant, keeping leagues isolated.
 *
 * Exception: the Site Admin Dashboard bypasses this because it is a
 * site-management tool, not a league-management tool.
 *
 * @param  int      $season_id
 * @param  int|null $user_id   Defaults to current user.
 * @return bool
 */
function kf_can_manage_season( $season_id, $user_id = null ) {
    if ( ! $user_id ) {
        $user_id = get_current_user_id();
    }
    // Site administrators are implicit commissioners in every league.
    if ( user_can( $user_id, 'manage_options' ) ) return true;

    global $wpdb;

    // Created this season?
    $is_creator = (bool) $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->prefix}seasons WHERE id = %d AND created_by = %d",
        $season_id, $user_id
    ) );
    if ( $is_creator ) return true;

    // Accepted participant explicitly flagged as co-commissioner for this season.
    return (bool) $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->prefix}season_players
         WHERE season_id = %d AND user_id = %d AND status = 'accepted' AND is_commissioner = 1",
        $season_id, $user_id
    ) );
}

/**
 * Can this user USE this season at all — see its summaries, switch to it, open its weeks?
 *
 * Distinct from kf_can_manage_season(), which is the commissioner check (admin, creator, or an
 * accepted member flagged is_commissioner). Ordinary players are accepted members with that flag
 * off, so the manage check refused them — and it was guarding season switching and the active
 * season stored in the session, not just commissioner actions. The effect was that a player in
 * two leagues could not switch between them: every request cleared their chosen season and reset
 * it to whichever one the fallback query picked, and the week summary then refused every week
 * belonging to the other league.
 *
 * Access grants no management rights. Every commissioner page calls kf_can_manage_season() for
 * itself; this is only about which league the viewer is currently looking at.
 *
 * @param  int      $season_id
 * @param  int|null $user_id   Defaults to current user.
 * @return bool
 */
function kf_can_access_season( $season_id, $user_id = null ) {
    if ( ! $user_id ) {
        $user_id = get_current_user_id();
    }
    if ( ! $season_id || ! $user_id ) {
        return false;
    }
    if ( kf_can_manage_season( $season_id, $user_id ) ) {
        return true;
    }

    global $wpdb;

    // Any accepted member of the league, co-commissioner flag or not.
    return (bool) $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->prefix}season_players
         WHERE season_id = %d AND user_id = %d AND status = 'accepted'",
        $season_id, $user_id
    ) );
}

/**
 * Does the current user commission at least one league the given player belongs to?
 *
 * Used where a commissioner may look at another member's data but the page has no league in
 * the URL to scope it (player stats). "Commissioner somewhere" is not enough.
 *
 * @param  int      $player_id
 * @param  int|null $user_id   Defaults to current user.
 * @return bool
 */
function kf_shares_managed_season( $player_id, $user_id = null ) {
    $player_id = (int) $player_id;
    if ( $player_id <= 0 ) {
        return false;
    }
    if ( ! $user_id ) {
        $user_id = get_current_user_id();
    }

    global $wpdb;
    $season_ids = $wpdb->get_col( $wpdb->prepare(
        "SELECT season_id FROM {$wpdb->prefix}season_players WHERE user_id = %d AND status = 'accepted'",
        $player_id
    ) );
    foreach ( (array) $season_ids as $season_id ) {
        if ( kf_can_manage_season( (int) $season_id, $user_id ) ) {
            return true;
        }
    }
    return false;
}

/**
 * Makes $season_id the league this request is about, if the viewer is entitled to it.
 *
 * Pages keyed to a record (a week id in the URL) have two sources of truth: the record's own
 * league and whichever league the session happens to hold. Trusting the session while acting on
 * the record is how Week Setup came to edit — and on save, re-home — a week belonging to another
 * league, and how Manage Weeks could publish one. Trusting the record without checking is worse.
 * So: check entitlement to the RECORD's league, then follow it.
 *
 * @param  int  $season_id      League the record belongs to.
 * @param  bool $require_manage true for commissioner-only pages, false for anything a member may view.
 * @return bool Whether the viewer may proceed.
 */
function kf_enter_season_context( $season_id, $require_manage = false ) {
    $season_id = (int) $season_id;
    if ( $season_id <= 0 ) {
        return false;
    }

    $allowed = $require_manage
        ? kf_can_manage_season( $season_id )
        : kf_can_access_season( $season_id );
    if ( ! $allowed ) {
        return false;
    }

    if ( session_status() === PHP_SESSION_NONE && ! headers_sent() ) {
        session_start();
    }
    if ( (int) ( $_SESSION['kf_active_season_id'] ?? 0 ) !== $season_id ) {
        $_SESSION['kf_active_season_id'] = $season_id;
        delete_transient( 'kf_default_season_' . get_current_user_id() );
    }

    return true;
}

/**
 * Returns true if the user has commissioner access to at least one season.
 * Used to gate access to pages that require commissioner role but have no
 * specific season context yet (e.g. the Commissioner Dashboard listing page).
 *
 * @param int|null $user_id Defaults to current user.
 * @return bool
 */
function kf_is_any_commissioner( $user_id = null ) {
    if ( ! $user_id ) {
        $user_id = get_current_user_id();
    }
    if ( user_can( $user_id, 'manage_options' ) ) return true;

    global $wpdb;

    // Creator of any season?
    $is_creator = (bool) $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->prefix}seasons WHERE created_by = %d",
        $user_id
    ) );
    if ( $is_creator ) return true;

    // Explicitly-flagged co-commissioner of any accepted season?
    return (bool) $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->prefix}season_players
         WHERE user_id = %d AND status = 'accepted' AND is_commissioner = 1",
        $user_id
    ) );
}

// Manage session for active season
function kf_manage_active_season_session() {
    if (!is_user_logged_in()) { return; }
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    global $wpdb;
    $user_id = get_current_user_id();

    // If a season ID is already in session, validate that this user still belongs to it.
    // This guards against stale sessions after a user is removed from a season,
    // or a session fixation scenario where the session contains an arbitrary ID.
    if (isset($_SESSION['kf_active_season_id'])) {
        $session_season_id = (int) $_SESSION['kf_active_season_id'];
        // Access, not management: this is "may you look at this league", and it runs on every
        // request. Checking the commissioner helper here wiped an ordinary player's chosen
        // season on every page load.
        $still_valid = kf_can_access_season( $session_season_id, $user_id );
        if ($still_valid) {
            return; // Valid — nothing to do.
        }
        // Not valid — clear and fall through to pick a new default.
        unset($_SESSION['kf_active_season_id']);
    }
    $cache_key = 'kf_default_season_' . $user_id;
    $default_season_id = get_transient($cache_key);
    if (false === $default_season_id) {
        // Try: most recent active season the user is accepted in.
        // Also pick up seasons they created even if they aren't in season_players yet.
        $default_season_id = $wpdb->get_var($wpdb->prepare(
            "SELECT s.id FROM {$wpdb->prefix}seasons s
             LEFT JOIN {$wpdb->prefix}season_players sp ON s.id = sp.season_id AND sp.user_id = %d AND sp.status = 'accepted'
             WHERE s.is_active = 1 AND (sp.user_id IS NOT NULL OR s.created_by = %d)
             ORDER BY s.id DESC LIMIT 1",
            $user_id, $user_id
        ));
        set_transient($cache_key, $default_season_id, 3600);
    }
    if ($default_season_id) { $_SESSION['kf_active_season_id'] = (int)$default_season_id; }
}
add_action('init', 'kf_manage_active_season_session');

// Handle AJAX request to change the active season
function kf_ajax_set_active_season() {
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'kf_season_switcher_nonce')) {
        wp_send_json_error(['message' => 'Security check failed.']);
        return;
    }
    if (session_status() === PHP_SESSION_NONE) { session_start(); }
    
    $season_id = isset($_POST['season_id']) ? intval($_POST['season_id']) : 0;
    
    // NEW: Get the desired redirect URL from the POST data, with a fallback.
    $redirect_url = isset($_POST['redirect_url']) ? esc_url_raw($_POST['redirect_url']) : site_url('/season-summary/');

    if ($season_id > 0) {

        // Creator, co-commissioner or plain accepted participant — anyone who belongs to the
        // league may switch to it. Commissioner pages gate themselves separately.
        if ( kf_can_access_season( $season_id, get_current_user_id() ) ) {
            $_SESSION['kf_active_season_id'] = $season_id;
            delete_transient('kf_default_season_' . get_current_user_id());
            
            // MODIFICATION: Send back the redirect URL that was provided by the JavaScript.
            wp_send_json_success([
                'message' => 'Active season updated successfully.',
                'redirect_url' => $redirect_url
            ]);
        } else {
            wp_send_json_error(['message' => 'You are not a participant in this season.']);
        }
    } else {
        wp_send_json_error(['message' => 'Invalid season ID.']);
    }
}
add_action('wp_ajax_kf_set_active_season', 'kf_ajax_set_active_season');

// This filter is for the menu walker and remains unchanged.
add_filter('wp_nav_menu_objects', 'kf_set_menu_current', 10, 2);
function kf_set_menu_current($items, $args) {
    $current_season_id = isset($_SESSION['kf_active_season_id']) ? (int)$_SESSION['kf_active_season_id'] : 0;
    foreach ($items as $item) {
        if (!isset($item->current)) {
            $item->current = false;
        }
        if (isset($item->url) && strpos($item->url, 'season_id=') !== false) {
            parse_str(parse_url($item->url, PHP_URL_QUERY), $query);
            $item_season_id = isset($query['season_id']) ? (int)$query['season_id'] : 0;
            $item->current = ($item_season_id === $current_season_id);
        }
    }
    return $items;
}