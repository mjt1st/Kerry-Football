<?php
/**
 * Handles the Global Active Season context for all users.
 *
 * @package Kerry_Football
 * - Manages session for active season, setting default if none.
 * - MODIFICATION: AJAX handler now receives the intended redirect URL from the client-side script and returns it, fixing the primary navigation bug.
 */

if (!defined('ABSPATH')) exit;

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
        $default_season_id = $wpdb->get_var($wpdb->prepare(
            "SELECT s.id FROM {$wpdb->prefix}seasons s JOIN {$wpdb->prefix}season_players sp ON s.id = sp.season_id WHERE sp.user_id = %d AND sp.status = 'accepted' AND s.is_active = 1 ORDER BY s.id DESC LIMIT 1",
            $user_id
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
        global $wpdb;

        // Check if user is a participant. This check is still valid.
        $season_exists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}seasons s JOIN {$wpdb->prefix}season_players sp ON s.id = sp.season_id WHERE s.id = %d AND sp.user_id = %d AND sp.status = 'accepted'",
            $season_id,
            get_current_user_id()
        ));

        // As before, we will tackle universal admin access separately. For now, they must be a player.
        if ($season_exists) {
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