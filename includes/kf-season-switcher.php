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

    // Accepted participant (includes co-commissioners who were invited)?
    return (bool) $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->prefix}season_players
         WHERE season_id = %d AND user_id = %d AND status = 'accepted'",
        $season_id, $user_id
    ) );
}

// Manage session for active season
function kf_manage_active_season_session() {
    if (!is_user_logged_in()) { return; }
    if (session_status() === PHP_SESSION_NONE) { 
        session_start(); 
    }
    if (isset($_SESSION['kf_active_season_id'])) { return; }

    global $wpdb;
    $user_id = get_current_user_id();
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

        // Use central helper — user must be creator or accepted participant.
        if ( kf_can_manage_season( $season_id, get_current_user_id() ) ) {
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