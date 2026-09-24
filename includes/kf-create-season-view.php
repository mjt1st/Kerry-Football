<?php
/**
 * Shortcode handler for the "Create New Season" page.
 * Provides a form for the commissioner to input all the required settings for a new season.
 *
 * @package Kerry_Football
 * - MODIFICATION: Automatically enrolls the creating commissioner as an accepted player.
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Renders the season setup form and handles the creation of a new season.
 *
 * @return string The HTML for the season setup form.
 */
function kf_create_season_shortcode() {
    // Security Check: Ensure the user is logged in.
    if (!is_user_logged_in()) {
        return kf_notice_login_required( 'this page' );
    }

    // Security Check: Ensure the user has the 'commissioner' role.
    $user = wp_get_current_user();
    if (!current_user_can('manage_options')) { // Use capability check instead of role name for better compatibility
        return kf_notice_page(
            'Commissioners only',
            'Creating a league is for commissioners.',
            [ kf_notice_action( 'Home', site_url( '/' ) ), kf_notice_action( 'Player Dashboard', site_url( '/player-dashboard/' ) ) ],
            'warn'
        );
    }

    // Start output buffering to capture HTML.
    ob_start();

    $new_season_id = 0; // Set when a league is created on this request.

    // --- Handle Form Submission ---
    // Check if the form was submitted and the submit button was clicked.
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['kf_season_submit'])) {
        // Security Check: Verify the WordPress nonce for security.
        if (!isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'kf_create_season')) {
            echo '<div class="notice notice-error"><p>❌ Security check failed.</p></div>';
        } else {
            global $wpdb;

            // Sanitize and retrieve all data from the form POST request.
            // wp_unslash: WordPress adds slashes to $_POST, so without it "Kerry's League" was saved as
            // "Kerry\'s League" and displayed that way everywhere.
            $name               = sanitize_text_field( wp_unslash( $_POST['season_name'] ) );
            $num_weeks          = intval($_POST['num_weeks']);
            $weekly_points      = intval($_POST['weekly_point_total']);
            $matchup_count      = intval($_POST['default_matchup_count']);
            // Normalised, not just sanitised: a list typed with a stray full stop is stored as a
            // clean comma-separated list instead of silently losing every value after it.
            $point_values       = kf_normalize_point_values( wp_unslash( $_POST['default_point_values'] ) );
            $mwow_bonus         = intval($_POST['mwow_bonus']);
            $dd_max             = intval($_POST['dd_max']);
            $dd_week            = intval($_POST['dd_start_week']);
            $sport_type         = in_array($_POST['sport_type'] ?? '', ['nfl', 'college-football']) ? $_POST['sport_type'] : 'nfl';

            $seasons_table = $wpdb->prefix . 'seasons';

            // Check if a season with the same name already exists to prevent duplicates.
            $existing = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM $seasons_table WHERE name = %s",
                $name
            ));

            if ($existing > 0) {
                // Display an error if the season name is already taken.
                echo '<div class="notice notice-error"><p>❌ A season with that name already exists.</p></div>';
            } else {
                // Insert the new season data into the database.
                $result = $wpdb->insert($seasons_table, [
                    'name'                    => $name,
                    'num_weeks'               => $num_weeks,
                    'weekly_point_total'      => $weekly_points,
                    'default_matchup_count'   => $matchup_count,
                    'default_point_values'    => $point_values,
                    'mwow_bonus_points'       => $mwow_bonus,
                    'dd_max_uses'             => $dd_max,
                    'dd_enabled_week'         => $dd_week,
                    'sport_type'              => $sport_type,
                    'created_by'              => get_current_user_id(),
                    'is_active'               => 1 // New seasons are active by default.
                ]);

                // Display a success or failure message based on the insert result.
                if ($result === false) {
                    echo '<div class="notice notice-error"><p>❌ Insert failed: ' . esc_html($wpdb->last_error) . '</p></div>';
                } else {
                    // --- NEW: Auto-enroll the commissioner ---
                    $new_season_id = $wpdb->insert_id;
                    $commissioner_id = get_current_user_id();

                    // 1. Add to the season_players table as 'accepted'
                    $players_table = $wpdb->prefix . 'season_players';
                    $wpdb->insert($players_table, [
                        'season_id' => $new_season_id,
                        'user_id'   => $commissioner_id,
                        'status'    => 'accepted'
                    ]);

                    // 2. Add to the season_player_order table to ensure display consistency
                    $order_table = $wpdb->prefix . 'season_player_order';
                    $wpdb->insert($order_table, [
                        'season_id'     => $new_season_id,
                        'user_id'       => $commissioner_id,
                        'display_order' => 1 // As the first player, order is 1
                    ]);

                    // Say where to go next. The page used to stop at the success line, and the only way
                    // into the new league was back through the homepage. Each link names the league, so
                    // it opens that league whichever one was selected before.
                    $next_steps = [
                        'Add the first week' => site_url( '/manage-weeks/' ),
                        'Invite players'     => site_url( '/manage-players/' ),
                        'League settings'    => site_url( '/edit-season/' ),
                    ];
                    echo '<div class="notice notice-success"><p>✅ <strong>' . esc_html( $name ) . '</strong> created. You have been added as a player.</p><p class="kf-next-steps">';
                    foreach ( $next_steps as $label => $url ) {
                        echo '<a class="kf-button" href="' . esc_url( kf_league_url( $url, $new_season_id ) ) . '">' . esc_html( $label ) . '</a> ';
                    }
                    echo '</p></div>';
                }
            }
        }
    }

    // Repopulate form values from POST on error, or use defaults for first load. After a successful
    // create the form starts empty again: refilled with what was just submitted, it invited a second click.
    $repopulate = isset( $_POST['kf_season_submit'] ) && ! $new_season_id;
    $v_name        = $repopulate ? esc_attr( wp_unslash( $_POST['season_name'] ?? '' ) ) : '';
    $v_sport       = $repopulate ? sanitize_text_field( $_POST['sport_type'] ?? 'nfl' ) : 'nfl';
    $v_num_weeks   = $repopulate ? intval( $_POST['num_weeks'] ?? 0 )                  : '';
    $v_wpt         = $repopulate ? intval( $_POST['weekly_point_total'] ?? 0 )         : '';
    $v_matchups    = $repopulate ? intval( $_POST['default_matchup_count'] ?? 0 )      : '';
    $v_points      = $repopulate ? esc_attr( wp_unslash( $_POST['default_point_values'] ?? '' ) ) : '';
    $v_mwow        = $repopulate ? intval( $_POST['mwow_bonus'] ?? 0 )                 : '';
    $v_dd_max      = $repopulate ? intval( $_POST['dd_max'] ?? 4 )                     : 4;
    $v_dd_week     = $repopulate ? intval( $_POST['dd_start_week'] ?? 9 )              : 9;
    ?>
    <div class="kf-container">
        <h2>Season Setup</h2>
        <?php // This class enables the universal "unsaved changes" warning JavaScript handler. ?>
        <form method="POST" class="kf-tracked-form">
            <?php wp_nonce_field('kf_create_season'); ?>
            <p><label>Season Name<br><input type="text" name="season_name" value="<?php echo $v_name; ?>" required></label></p>
            <p>
                <label>Sport Type<br>
                    <select name="sport_type">
                        <option value="nfl" <?php selected( $v_sport, 'nfl' ); ?>>NFL (Pro Football)</option>
                        <option value="college-football" <?php selected( $v_sport, 'college-football' ); ?>>College Football (NCAAF)</option>
                    </select>
                </label>
                <span style="display:block;font-size:0.85em;color:#777;margin-top:4px;">Sets the default sport when browsing live games for this season's weeks.</span>
            </p>
            <p><label>Number of Weeks<br><input type="number" name="num_weeks" value="<?php echo $v_num_weeks ?: ''; ?>" required></label></p>
            <p><label>Weekly Point Total<br><input type="number" name="weekly_point_total" value="<?php echo $v_wpt ?: ''; ?>" required></label></p>
            <p><label>Default Matchup Count<br><input type="number" name="default_matchup_count" value="<?php echo $v_matchups ?: ''; ?>" required></label></p>
            <p><label>Default Point Values (comma-separated)<br><input type="text" name="default_point_values" value="<?php echo $v_points; ?>" required placeholder="1,2,3,...15"></label></p>
            <p><label>MWOW Bonus Points<br><input type="number" name="mwow_bonus" value="<?php echo $v_mwow ?: ''; ?>" required></label></p>
            <p><label>Max Double Down Uses<br><input type="number" name="dd_max" value="<?php echo $v_dd_max; ?>"></label></p>
            <p><label>DD Starts in Week<br><input type="number" name="dd_start_week" value="<?php echo $v_dd_week; ?>"></label></p>
            <p><button type="submit" name="kf_season_submit" class="kf-button">Create Season</button></p>
        </form>
    </div>
    <?php

    // Return the buffered content.
    return ob_get_clean();
}