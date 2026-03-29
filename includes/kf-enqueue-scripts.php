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
        // Using filemtime() as the version so browsers/caches auto-bust on every file change.
        wp_enqueue_style(
            'kerry-football-styles',
            $plugin_base_url . 'assets/css/kf-styles.css',
            [],
            filemtime( KF_PLUGIN_PATH . 'assets/css/kf-styles.css' )
        );

        // Enqueue the main JS file on all front-end pages.
        wp_enqueue_script(
            'kerry-football-main-js',
            $plugin_base_url . 'assets/js/kf-table-controls.js',
            [ 'jquery' ],
            filemtime( KF_PLUGIN_PATH . 'assets/js/kf-table-controls.js' ),
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

        // SPORTS API V1: Load game browser JS on pages that use the week-setup shortcode.
        // Also loaded on enter-results pages for the refresh scores button.
        global $post;
        $load_game_browser = false;
        if ( $post && is_a( $post, 'WP_Post' ) ) {
            if ( has_shortcode( $post->post_content, 'kf_week_setup' ) ||
                 has_shortcode( $post->post_content, 'kf_enter_results' ) ) {
                $load_game_browser = true;
            }
        }
        if ( $load_game_browser && current_user_can( 'manage_options' ) ) {
            wp_enqueue_script(
                'kerry-football-game-browser',
                $plugin_base_url . 'assets/js/kf-game-browser.js',
                [ 'jquery', 'kerry-football-main-js' ],
                filemtime( KF_PLUGIN_PATH . 'assets/js/kf-game-browser.js' ),
                true
            );
        }
    }
}
// Add our function to the 'wp_enqueue_scripts' hook.
add_action( 'wp_enqueue_scripts', 'kf_enqueue_assets' );

// The final PHP tag is omitted to prevent accidental whitespace output.