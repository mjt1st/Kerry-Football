<?php
/**
 * Shortcode handler for the "Edit Season" page.
 * Allows a commissioner to safely edit season parameters.
 *
 * @package Kerry_Football
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Renders the form to edit an existing season.
 */
function kf_edit_season_form_shortcode() {
    // Security check: User must be logged in.
    if ( ! is_user_logged_in() ) {
        return '<div class="kf-container"><p>You do not have permission to view this page.</p></div>';
    }

    if (session_status() === PHP_SESSION_NONE) { session_start(); }
    global $wpdb;

    // Get the season ID from the session.
    $season_id = isset($_SESSION['kf_active_season_id']) ? (int)$_SESSION['kf_active_season_id'] : 0;
    if (!$season_id) {
        return '<div class="kf-container"><p>No active season selected. Please select a season from the main menu to edit.</p></div>';
    }
    if (!kf_can_manage_season($season_id)) {
        return '<div class="kf-container"><p>You do not have permission to view this page.</p></div>';
    }

    $seasons_table = $wpdb->prefix . 'seasons';
    $weeks_table = $wpdb->prefix . 'weeks';

    // Fetch the current season data to populate the form.
    $season = $wpdb->get_row($wpdb->prepare("SELECT * FROM $seasons_table WHERE id = %d", $season_id));
    if (!$season) {
        return '<div class="kf-container"><p>Season not found.</p></div>';
    }

    // --- Handle Form Submission ---
    if ('POST' === $_SERVER['REQUEST_METHOD'] && isset($_POST['kf_update_season_nonce'])) {
        if (wp_verify_nonce($_POST['kf_update_season_nonce'], 'kf_update_season_action')) {
            
            $sport_type = in_array($_POST['sport_type'] ?? '', ['nfl', 'college-football']) ? $_POST['sport_type'] : 'nfl';
            $update_data = [
                'name'              => sanitize_text_field($_POST['season_name']),
                'mwow_bonus_points' => intval($_POST['mwow_bonus_points']),
                'dd_max_uses'       => intval($_POST['dd_max_uses']),
                'dd_enabled_week'   => intval($_POST['dd_enabled_week']),
                'sport_type'        => $sport_type,
                'is_active'         => isset($_POST['is_active']) ? 1 : 0,
            ];

            if (isset($_POST['num_weeks'])) {
                $update_data['num_weeks'] = intval($_POST['num_weeks']);
            }

            $wpdb->update($seasons_table, $update_data, ['id' => $season_id]);
            
            // Re-fetch the data to show the updated values.
            $season = $wpdb->get_row($wpdb->prepare("SELECT * FROM $seasons_table WHERE id = %d", $season_id));

            echo '<div class="notice notice-success is-dismissible"><p>Season updated successfully!</p></div>';
        } else {
            echo '<div class="notice notice-error"><p>Security check failed.</p></div>';
        }
    }

    // Check if any weeks exist for this season to determine if num_weeks should be locked.
    $week_count = $wpdb->get_var($wpdb->prepare("SELECT COUNT(id) FROM $weeks_table WHERE season_id = %d", $season_id));
    $is_num_weeks_locked = ($week_count > 0);

    ob_start();
    ?>
    <div class="kf-container">
        <h2>Edit Season: <?php echo esc_html($season->name); ?></h2>
        <a href="<?php echo esc_url(site_url('/commissioner-dashboard/')); ?>" class="kf-button kf-button-secondary" style="margin-bottom: 1.5em;">&larr; Back to Dashboard</a>

        <?php // MODIFICATION: Added the 'kf-tracked-form' class to the form tag. ?>
        <?php // This class enables the universal "unsaved changes" warning JavaScript handler. ?>
        <form method="POST" class="kf-card kf-tracked-form">
            <?php wp_nonce_field('kf_update_season_action', 'kf_update_season_nonce'); ?>
            
            <div class="kf-form-group">
                <label for="season_name">Season Name</label>
                <input type="text" id="season_name" name="season_name" value="<?php echo esc_attr($season->name); ?>" required>
            </div>

            <div class="kf-form-group">
                <label for="sport_type">Sport Type</label>
                <select id="sport_type" name="sport_type">
                    <option value="nfl" <?php selected($season->sport_type ?? 'nfl', 'nfl'); ?>>NFL (Pro Football)</option>
                    <option value="college-football" <?php selected($season->sport_type ?? 'nfl', 'college-football'); ?>>College Football (NCAAF)</option>
                </select>
                <p class="kf-form-note">Sets the default sport when browsing live games for this season's weeks.</p>
            </div>

            <div class="kf-form-group">
                <label for="num_weeks">Number of Weeks</label>
                <input type="number" id="num_weeks" name="num_weeks" value="<?php echo esc_attr($season->num_weeks); ?>" <?php if ($is_num_weeks_locked) echo 'disabled'; ?> required>
                <?php if ($is_num_weeks_locked): ?>
                    <p style="font-size: 0.9em; color: #777;"><em>This field is locked because weeks have already been created for this season.</em></p>
                <?php endif; ?>
            </div>

            <div class="kf-form-group">
                <label for="mwow_bonus_points">MWOW Bonus Points</label>
                <input type="number" id="mwow_bonus_points" name="mwow_bonus_points" value="<?php echo esc_attr($season->mwow_bonus_points); ?>" required>
            </div>

            <hr style="margin: 2em 0;">
            <h3 style="border:none;">Double Down Settings</h3>

            <div class="kf-form-group">
                <label for="dd_max_uses">Max Double Down Uses</label>
                <input type="number" id="dd_max_uses" name="dd_max_uses" value="<?php echo esc_attr($season->dd_max_uses); ?>" required>
            </div>

            <div class="kf-form-group">
                <label for="dd_enabled_week">Enable Double Down Starting in Week</label>
                <input type="number" id="dd_enabled_week" name="dd_enabled_week" value="<?php echo esc_attr($season->dd_enabled_week); ?>" required>
            </div>

            <hr style="margin: 2em 0;">
            <h3 style="border:none;">Season Status</h3>

             <div class="kf-form-group">
                <label>
                    <input type="checkbox" name="is_active" value="1" <?php checked($season->is_active, 1); ?>>
                    Mark Season as Active
                </label>
                 <p style="font-size: 0.9em; color: #777;"><em>An active season appears on the homepage and allows players to make picks.</em></p>
            </div>

            <button type="submit" class="kf-button">Update Season</button>
        </form>
    </div>
    <?php
    return ob_get_clean();
}
