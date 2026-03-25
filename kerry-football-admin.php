<?php
// --- START OF FILE: kerry-football-admin.php ---
/**
 * Plugin Name: Kerry Football Admin
 * Description: A plugin for managing a private fantasy football league.
 * Version: 1.4.7
 * Author: Kerry/Gemini
 *
 * * STABILITY FIX (V2.1.6): Added aggressive session start on the 'init' hook to prevent 
 * * 'headers already sent' errors caused by nested includes trying to start the session too late.
 */

// Security: Exit if this file is accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * CRITICAL STABILITY FUNCTION: Starts the PHP session very early.
 * This function should run before any content is output by the theme or other plugins.
 * We must check !headers_sent() to prevent the login crash if output has already started.
 */
function kf_start_session_early() {
    // Only start a session if one isn't active, we're not inside the /wp-admin (for cron/WP-CLI), and no headers have been sent.
    if (session_status() === PHP_SESSION_NONE && !is_admin() && !headers_sent()) {
        session_start();
    }
}
// Hook this function to run very early in the WordPress load process.
add_action('init', 'kf_start_session_early', 1);

/**
 * =============================================================
 * Plugin Setup & File Includes
 * =============================================================
 */

define( 'KF_PLUGIN_PATH', plugin_dir_path( __FILE__ ) );

// --- Require all other plugin files ---
// BUG FIX: Corrected the spelling of kf-enqueue-scripts.php
require_once KF_PLUGIN_PATH . 'includes/kf-enqueue-scripts.php';
require_once KF_PLUGIN_PATH . 'includes/kf-database-setup.php';
require_once KF_PLUGIN_PATH . 'includes/kf-menus.php';
require_once KF_PLUGIN_PATH . 'includes/kf-notifications.php';
require_once KF_PLUGIN_PATH . 'includes/kf-scoring-engine.php';
require_once KF_PLUGIN_PATH . 'includes/kf-season-switcher.php';
require_once KF_PLUGIN_PATH . 'includes/kf-shortcodes.php';

// View Handlers
require_once KF_PLUGIN_PATH . 'includes/kf-commissioner-dashboard.php';
require_once KF_PLUGIN_PATH . 'includes/kf-edit-season.php';
require_once KF_PLUGIN_PATH . 'includes/kf-enter-results-view.php';
require_once KF_PLUGIN_PATH . 'includes/kf-homepage.php';
require_once KF_PLUGIN_PATH . 'includes/kf-manage-weeks-view.php';
require_once KF_PLUGIN_PATH . 'includes/kf-player-dashboard-view.php';
require_once KF_PLUGIN_PATH . 'includes/kf-player-management.php';
require_once KF_PLUGIN_PATH . 'includes/kf-player-picks.php';
require_once KF_PLUGIN_PATH . 'includes/kf-review-late-picks-view.php'; // LATE PICKS V2.1: Load the new review page.
require_once KF_PLUGIN_PATH . 'includes/kf-season-summary-view.php';
require_once KF_PLUGIN_PATH . 'includes/kf-create-season-view.php';
require_once KF_PLUGIN_PATH . 'includes/kf-week-setup.php';
require_once KF_PLUGIN_PATH . 'includes/kf-week-summary-view.php';
require_once KF_PLUGIN_PATH . 'includes/kf-notification-settings-view.php';


// Importer tools
require_once KF_PLUGIN_PATH . 'includes/kf-matchup-importer.php';
if ( file_exists( KF_PLUGIN_PATH . 'includes/kf-data-importer.php' ) ) {
    require_once KF_PLUGIN_PATH . 'includes/kf-data-importer.php';
}

/**
 * =============================================================
 * AJAX Handlers & Plugin Core Logic
 * =============================================================
 */

// AJAX handler to save notification settings
function kf_ajax_update_notification_setting() {
    check_ajax_referer('kf_ajax_nonce', 'nonce');

    $user_id = isset($_POST['user_id']) ? intval($_POST['user_id']) : -1;
    $season_id = isset($_POST['season_id']) ? intval($_POST['season_id']) : 0;
    $notification_type = isset($_POST['notification_type']) ? sanitize_text_field($_POST['notification_type']) : '';
    $is_enabled = isset($_POST['is_enabled']) && $_POST['is_enabled'] === 'true' ? 1 : 0;
    $current_user_id = get_current_user_id();
    $is_commissioner = current_user_can('manage_options');

    // Security check: Ensure the user has permission to change this setting
    if ($user_id !== $current_user_id && !$is_commissioner) {
        wp_send_json_error(['message' => 'Permission denied.']);
        return;
    }
    // Corrected logic: Commissioner can edit others (user_id > 0) or the global default (user_id = 0)
    if ($is_commissioner && $user_id !== $current_user_id && $user_id !== 0) {
         // The original logic was incorrect, commissioners can edit others. Assuming original intent was to check if the user_id is valid for setting.
         // Removing the redundant/incorrect check as long as current_user_can('manage_options') is true.
    }


    if ($season_id <= 0 || empty($notification_type) || $user_id < 0) {
        wp_send_json_error(['message' => 'Invalid data provided.']);
        return;
    }

    global $wpdb;
    $table = $wpdb->prefix . 'notification_settings';

    // Use REPLACE to either insert a new row or update an existing one
    $result = $wpdb->replace($table, [
        'user_id' => $user_id,
        'season_id' => $season_id,
        'notification_type' => $notification_type,
        'is_enabled' => $is_enabled
    ]);

    if ($result === false) {
        wp_send_json_error(['message' => 'Database error.']);
    } else {
        wp_send_json_success(['message' => 'Setting saved.']);
    }
}
add_action('wp_ajax_kf_update_notification_setting', 'kf_ajax_update_notification_setting');


// AJAX handler to reverse a finalized week.
function kf_ajax_reverse_week_finalization() {
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'kf_reverse_week_nonce')) {
        wp_send_json_error(['message' => 'Security check failed.']);
        return;
    }
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'You do not have permission to perform this action.']);
        return;
    }

    $week_id = isset($_POST['week_id']) ? intval($_POST['week_id']) : 0;
    if ($week_id <= 0) {
        wp_send_json_error(['message' => 'Invalid week ID provided.']);
        return;
    }
    
    if (function_exists('kf_reverse_week')) {
        $result = kf_reverse_week($week_id);
        if ($result) {
            wp_send_json_success(['message' => 'Week has been successfully reversed.']);
        } else {
            wp_send_json_error(['message' => 'An unknown error occurred during the reversal process.']);
        }
    } else {
        wp_send_json_error(['message' => 'Scoring engine is not available.']);
    }
}
add_action('wp_ajax_kf_reverse_week', 'kf_ajax_reverse_week_finalization');

// AJAX handler to save the custom player display order.
function kf_save_player_order_ajax_handler() {
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'kf_season_switcher_nonce')) {
        wp_send_json_error(['message' => 'Security check failed.']);
        return;
    }
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'You do not have permission.']);
        return;
    }

    $season_id = isset($_POST['season_id']) ? intval($_POST['season_id']) : 0;
    $player_order_json = isset($_POST['player_order']) ? stripslashes($_POST['player_order']) : '[]';
    $player_order_ids = json_decode($player_order_json, true);

    if (!$season_id || !is_array($player_order_ids)) {
        wp_send_json_error(['message' => 'Invalid data received.']);
        return;
    }

    global $wpdb;
    $player_order_table = $wpdb->prefix . 'season_player_order';

    foreach ($player_order_ids as $index => $user_id) {
        $wpdb->update($player_order_table, ['display_order' => $index], ['season_id' => $season_id, 'user_id' => intval($user_id)]);
    }

    wp_send_json_success(['message' => 'Player order saved.']);
}
add_action('wp_ajax_kf_save_player_order', 'kf_save_player_order_ajax_handler');


/**
 * =============================================================
 * Plugin Activation / Deactivation Hooks
 * =============================================================
 */

// Register Plugin Hooks.
register_activation_hook( __FILE__, 'kf_install_db' );
add_action( 'init', 'kf_register_shortcodes' );

// Add deactivation hook to clean up sessions.
register_deactivation_hook( __FILE__, 'kf_deactivate_plugin' );
function kf_deactivate_plugin() {
    // NOTE: Session is started by the kf_start_session_early() function on 'init'.
    if (session_status() === PHP_SESSION_ACTIVE) {
        unset($_SESSION['kf_active_season_id']);
    }
}
// --- END OF FILE: kerry-football-admin.php ---