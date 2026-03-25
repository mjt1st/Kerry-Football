<?php
/**
 * Registers all shortcodes for the Kerry Football plugin.
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Adds all of the plugin's shortcodes to WordPress.
 */
function kf_register_shortcodes() {
    // COMMENT: Renamed the function for the create season page to be more descriptive.
    add_shortcode('kf_season_setup', 'kf_create_season_shortcode');

    add_shortcode('kf_week_setup', 'kf_week_setup_form');
    add_shortcode('kf_commissioner_dashboard', 'kf_commissioner_dashboard_shortcode');
    add_shortcode('kf_player_picks', 'kf_player_picks_shortcode'); // Note: This seems to be a legacy shortcode.
    add_shortcode('kf_season_summary', 'kf_season_summary_view');
    add_shortcode('kf_week_summary', 'kf_week_summary_view');
    add_shortcode('kf_player_management', 'kf_player_management_shortcode');
    add_shortcode('kf_homepage', 'kf_homepage_shortcode');
    add_shortcode('kf_my_picks', 'kf_my_picks_shortcode');
    add_shortcode('kf_edit_season', 'kf_edit_season_form_shortcode');
    add_shortcode('kf_player_dashboard', 'kf_player_dashboard_view');
    add_shortcode('kf_manage_weeks', 'kf_manage_weeks_view_shortcode');
    add_shortcode('kf_enter_results', 'kf_enter_results_shortcode');
    
    // LATE PICKS V2.1: Register the shortcode for the commissioner's review page.
    add_shortcode('kf_review_late_submissions', 'kf_review_late_picks_view');
    
    add_shortcode('kerry_football_notification_settings', 'kf_notification_settings_view');

    // COMMENT: Removed a duplicate registration for 'kf_edit_season' that pointed to an old function name.
}