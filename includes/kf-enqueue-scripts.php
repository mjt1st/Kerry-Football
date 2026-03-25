<?php
/**
 * Handles enqueueing of scripts and styles for the plugin.
 *
 * @package Kerry_Football
 *
 * * CRITICAL FIX (V2.1.4): Wrapped session_start() with a !headers_sent() check 
 * * to prevent fatal login/header errors when this function is called too late.
 * * This resolves the PHP Warning seen in the debug log.
 * * CRITICAL BLANK SCREEN FIX (V2.1.5): Removed final PHP tag to prevent accidental whitespace/BOM output.
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Enqueues the main plugin stylesheet and scripts for the front end.
 */
function kf_enqueue_assets() {
    // We only want to load our assets on the front-end, not in the admin dashboard.
    if ( ! is_admin() ) {

        // Ensure the PHP session is started so we can access session variables.
        // FIX: Added !headers_sent() check to prevent critical PHP warnings.
        if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
            session_start();
        }
        
        $plugin_base_url = plugin_dir_url( dirname( __FILE__ ) );

        // Enqueue the main plugin stylesheet.
        wp_enqueue_style(
            'kerry-football-styles',
            $plugin_base_url . 'assets/css/kf-styles.css',
            [],
            '1.0.5' // Incremented version number
        );

        // Enqueue the main JS file on all front-end pages.
        wp_enqueue_script(
            'kerry-football-main-js',
            $plugin_base_url . 'assets/js/kf-table-controls.js',
            [ 'jquery' ],
            '1.0.5', // Incremented version number
            true // Load the script in the footer
        );

        // --- Pass data from PHP to our JavaScript file. ---
        // This provides our script with the AJAX URL, a security token (nonce), and the active season ID.
        wp_localize_script(
            'kerry-football-main-js',
            'kf_ajax_data',
            [
                'ajax_url'         => admin_url('admin-ajax.php'),
                'nonce'            => wp_create_nonce('kf_season_switcher_nonce'),
                // --- FIX --- Add the active season ID to make it available in JavaScript.
                'active_season_id' => $_SESSION['kf_active_season_id'] ?? 0,
            ]
        );
    }
}
// Add our function to the 'wp_enqueue_scripts' hook.
add_action( 'wp_enqueue_scripts', 'kf_enqueue_assets' );

// The final PHP tag is omitted to prevent accidental whitespace output.