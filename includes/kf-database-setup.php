<?php
/**
 * Kerry Football Database Setup
 *
 * @package Kerry_Football
 */

// Security: Exit if this file is accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Creates or updates the necessary database tables upon plugin activation.
 * This function serves as the "source of truth" for the plugin's database schema.
 */
function kf_install_db() {
    global $wpdb;
    // The dbDelta function is used to examine the current table structure, compare it to the desired table structure, and either add or modify the table as necessary.
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    $charset_collate = $wpdb->get_charset_collate();

    // --- edk_seasons ---
    // Stores the settings for each season of the league.
    $sql_seasons = "CREATE TABLE {$wpdb->prefix}seasons (
        id INT AUTO_INCREMENT,
        name VARCHAR(100) NOT NULL,
        num_weeks INT NOT NULL,
        weekly_point_total INT NOT NULL,
        default_matchup_count INT NOT NULL,
        default_point_values TEXT NOT NULL,
        mwow_bonus_points INT NOT NULL,
        dd_max_uses INT DEFAULT 4,
        dd_enabled_week INT DEFAULT 9,
        is_active TINYINT(1) DEFAULT 1,
        sport_type VARCHAR(20) DEFAULT 'nfl',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id)
    ) $charset_collate;";

    // --- edk_season_players ---
    // Links WordPress users to a season and tracks their invitation status.
    $sql_season_players = "CREATE TABLE {$wpdb->prefix}season_players (
        id INT AUTO_INCREMENT,
        season_id INT NOT NULL,
        user_id BIGINT UNSIGNED NOT NULL,
        status ENUM('invited','accepted','declined') DEFAULT 'invited',
        PRIMARY KEY  (id),
        UNIQUE KEY unique_season_user (season_id, user_id),
        KEY user_id (user_id)
    ) $charset_collate;";

    // --- edk_season_player_order ---
    // Manages the commissioner-defined custom display order for players on summary pages.
    $sql_season_player_order = "CREATE TABLE {$wpdb->prefix}season_player_order (
        id INT AUTO_INCREMENT,
        season_id INT NOT NULL,
        user_id BIGINT UNSIGNED NOT NULL,
        display_order INT NOT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY unique_player_order (season_id, user_id)
    ) $charset_collate;";

    // --- edk_weeks ---
    // Defines each week within a season, its deadline, status, and award winners.
    $sql_weeks = "CREATE TABLE {$wpdb->prefix}weeks (
        id INT AUTO_INCREMENT,
        season_id INT NOT NULL,
        week_number INT NOT NULL,
        submission_deadline DATETIME,
        status ENUM('draft', 'published', 'finalized', 'tie_resolution_needed') DEFAULT 'draft',
        mwow_winner_user_id BIGINT UNSIGNED DEFAULT NULL,
        bpow_winner_user_id BIGINT UNSIGNED DEFAULT NULL,
        matchup_count INT DEFAULT NULL,
        point_values TEXT DEFAULT NULL,
        tie_data TEXT DEFAULT NULL,
        PRIMARY KEY  (id),
        KEY season_id (season_id)
    ) $charset_collate;";

    // --- edk_matchups ---
    // Stores the individual games for each week.
    // SPORTS API V1: Added columns for ESPN game tracking, live scores, and betting odds.
    $sql_matchups = "CREATE TABLE {$wpdb->prefix}matchups (
        id INT AUTO_INCREMENT,
        week_id INT NOT NULL,
        team_a VARCHAR(64) NOT NULL,
        team_b VARCHAR(64) NOT NULL,
        result VARCHAR(64) DEFAULT NULL,
        is_tiebreaker TINYINT(1) DEFAULT 0,
        espn_game_id VARCHAR(20) DEFAULT NULL,
        odds_api_event_id VARCHAR(64) DEFAULT NULL,
        game_datetime DATETIME DEFAULT NULL,
        home_score INT DEFAULT NULL,
        away_score INT DEFAULT NULL,
        game_status VARCHAR(20) DEFAULT NULL,
        spread_home DECIMAL(4,1) DEFAULT NULL,
        spread_away DECIMAL(4,1) DEFAULT NULL,
        moneyline_home INT DEFAULT NULL,
        moneyline_away INT DEFAULT NULL,
        over_under DECIMAL(4,1) DEFAULT NULL,
        odds_updated_at DATETIME DEFAULT NULL,
        PRIMARY KEY  (id),
        KEY week_id (week_id)
    ) $charset_collate;";

    // --- edk_api_usage ---
    // SPORTS API V1: Tracks API credit usage per month (primarily for The Odds API free tier).
    $sql_api_usage = "CREATE TABLE {$wpdb->prefix}api_usage (
        id INT AUTO_INCREMENT,
        api_name VARCHAR(32) NOT NULL,
        endpoint VARCHAR(128) NOT NULL,
        credits_used INT DEFAULT 1,
        requested_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        month_key VARCHAR(7) NOT NULL,
        PRIMARY KEY  (id),
        KEY month_key (month_key)
    ) $charset_collate;";

    // --- edk_picks ---
    // The central table for storing each player's pick for each matchup.
    $sql_picks = "CREATE TABLE {$wpdb->prefix}picks (
        id INT AUTO_INCREMENT,
        user_id BIGINT UNSIGNED NOT NULL,
        week_id INT NOT NULL,
        matchup_id INT NOT NULL,
        pick VARCHAR(100) NOT NULL,
        point_value INT NOT NULL, -- RECONCILED: Kept as INT as it stores whole numbers (1-16), not tiebreaker differences.
        is_bpow TINYINT(1) DEFAULT 0,
        submitted_at DATETIME DEFAULT CURRENT_TIMESTAMP, -- RECONCILED: Added this column back to track submission times.
        PRIMARY KEY  (id),
        KEY user_id (user_id),
        KEY week_id (week_id)
    ) $charset_collate;";

    // --- edk_scores ---
    // Caches the calculated results for each player for each week after finalization.
    $sql_scores = "CREATE TABLE {$wpdb->prefix}scores (
        id INT AUTO_INCREMENT,
        user_id BIGINT UNSIGNED NOT NULL,
        week_id INT NOT NULL,
        score INT NOT NULL,
        wins INT NOT NULL,
        subtotal INT NOT NULL,
        tiebreaker_diff INT NOT NULL,
        mwow_bonus_awarded INT DEFAULT 0,
        is_bpow_score TINYINT(1) DEFAULT 0, -- RECONCILED: Renamed from is_bp_column_score for clarity and consistency.
        PRIMARY KEY  (id),
        UNIQUE KEY unique_user_week_score (user_id, week_id)
    ) $charset_collate;";

    // --- edk_score_history ---
    // Archives original scores that have been replaced by a Double Down action.
    $sql_score_history = "CREATE TABLE {$wpdb->prefix}score_history (
        id INT AUTO_INCREMENT,
        score_id INT NOT NULL,
        user_id BIGINT UNSIGNED NOT NULL,
        week_id INT NOT NULL,
        original_score INT NOT NULL,
        original_subtotal INT DEFAULT NULL,
        original_wins INT DEFAULT NULL,
        original_mwow_bonus_awarded INT DEFAULT NULL,
        original_is_bpow_score TINYINT(1) DEFAULT NULL,
        replaced_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        replaced_by_week_id INT NOT NULL,
        PRIMARY KEY  (id),
        KEY user_id (user_id),
        KEY week_id (week_id),
        KEY replaced_by_week_id (replaced_by_week_id)
    ) $charset_collate;";

    // --- edk_double_down_log ---
    // A permanent log of completed Double Down transactions to prevent re-use of weeks.
    $sql_double_down_log = "CREATE TABLE {$wpdb->prefix}double_down_log (
        id INT AUTO_INCREMENT,
        user_id BIGINT UNSIGNED NOT NULL,
        season_id INT NOT NULL,
        source_week_id INT NOT NULL,
        target_week_id INT NOT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY unique_dd_transaction (user_id, season_id, source_week_id),
        KEY season_id (season_id)
    ) $charset_collate;";

    // --- edk_dd_selections ---
    // Temporarily stores a player's choice to use a Double Down for an upcoming week before it is finalized.
    $sql_dd_selections = "CREATE TABLE {$wpdb->prefix}dd_selections (
        id INT AUTO_INCREMENT,
        user_id BIGINT UNSIGNED NOT NULL,
        week_id INT NOT NULL,
        season_id INT NOT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY unique_dd_selection (user_id, week_id, season_id)
    ) $charset_collate;";

    // --- edk_pending_picks ---
    // Stores player pick submissions that are made after a deadline and are awaiting commissioner approval.
    $sql_pending_picks = "CREATE TABLE {$wpdb->prefix}pending_picks (
        id INT AUTO_INCREMENT,
        user_id BIGINT UNSIGNED NOT NULL,
        week_id INT NOT NULL,
        picks_data TEXT NOT NULL,
        status ENUM('pending','approved','declined') DEFAULT 'pending',
        requested_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        reviewed_at DATETIME,
        PRIMARY KEY (id),
        KEY user_id (user_id),
        KEY week_id (week_id)
    ) $charset_collate;";

    // --- Execute all CREATE TABLE statements using dbDelta ---
    // This WordPress function ensures that tables are created or updated safely without losing data.
    dbDelta( $sql_seasons );
    dbDelta( $sql_season_players );
    dbDelta( $sql_season_player_order );
    dbDelta( $sql_weeks );
    dbDelta( $sql_matchups );
    dbDelta( $sql_api_usage );
    dbDelta( $sql_picks );
    dbDelta( $sql_scores );
    dbDelta( $sql_score_history );
    dbDelta( $sql_double_down_log );
    dbDelta( $sql_dd_selections );
    dbDelta( $sql_pending_picks );
}
