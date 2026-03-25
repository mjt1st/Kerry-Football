<?php
/**
 * Shortcode handler for the "Notification Settings" page.
 * Displays controls for players and commissioners to manage email notification preferences.
 *
 * @package Kerry_Football
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

function kf_notification_settings_view() {
    if (!is_user_logged_in()) {
        return '<p>You must be logged in to view this page.</p>';
    }
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    global $wpdb;
    $user_id = get_current_user_id();
    $season_id = $_SESSION['kf_active_season_id'] ?? 0;
    $is_commissioner = current_user_can('manage_options');

    if (!$season_id) {
        return '<div class="kf-container"><h1>Notification Settings</h1><p>Please select a season to manage your notification settings.</p></div>';
    }
    
    // MODIFICATION: Added 'picks_ready' and 'picks_reminder' to the list of controllable notifications.
    $notification_types = [
        'week_finalized' => 'Week Finalized: Receive an email when a week\'s results are in.',
        'picks_ready'    => 'Picks Ready: Receive an email when a new week is published and ready for picks.',
        'picks_reminder' => 'Picks Reminder: Receive a reminder email 24 hours before the weekly deadline if you haven\'t submitted picks.'
    ];

    ob_start();
    ?>
    <div class="kf-container kf-notification-settings">
        <h1>Notification Settings</h1>
        
        <?php if ($is_commissioner) : ?>
            <div class="kf-tabs">
                <button class="kf-tab-link active" onclick="openTab(event, 'mySettings')">My Personal Settings</button>
                <button class="kf-tab-link" onclick="openTab(event, 'leagueDefaults')">League Default Settings</button>
            </div>
        <?php endif; ?>

        <div id="mySettings" class="kf-tab-content" style="display: block;">
            <h3>My Personal Settings</h3>
            <p>These settings apply only to you for the current season and will override any league defaults set by the commissioner.</p>
            <?php kf_display_settings_form($user_id, $season_id, $notification_types); ?>
        </div>

        <?php if ($is_commissioner) : ?>
            <div id="leagueDefaults" class="kf-tab-content">
                <h3>League Default Settings</h3>
                <p>These are the default settings for all players in the current season. Each player can override these defaults on their own settings page.</p>
                <?php kf_display_settings_form(0, $season_id, $notification_types); // User ID 0 for league defaults ?>
            </div>
        <?php endif; ?>
    </div>

    <script>
    function openTab(evt, tabName) {
        var i, tabcontent, tablinks;
        tabcontent = document.getElementsByClassName("kf-tab-content");
        for (i = 0; i < tabcontent.length; i++) {
            tabcontent[i].style.display = "none";
        }
        tablinks = document.getElementsByClassName("kf-tab-link");
        for (i = 0; i < tablinks.length; i++) {
            tablinks[i].className = tablinks[i].className.replace(" active", "");
        }
        document.getElementById(tabName).style.display = "block";
        evt.currentTarget.className += " active";
    }
    // Automatically select the first tab if it exists
    document.addEventListener('DOMContentLoaded', function() {
        const firstTab = document.querySelector('.kf-tab-link');
        if(firstTab) {
            firstTab.click();
        }
    });
    </script>
    <?php
    return ob_get_clean();
}


function kf_display_settings_form($user_id, $season_id, $notification_types) {
    global $wpdb;
    $settings_table = $wpdb->prefix . 'notification_settings';

    $defaults_results = $wpdb->get_results($wpdb->prepare("SELECT notification_type, is_enabled FROM $settings_table WHERE user_id = 0 AND season_id = %d", $season_id), OBJECT_K);
    
    $user_settings_results = [];
    if ($user_id > 0) {
        $user_settings_results = $wpdb->get_results($wpdb->prepare("SELECT notification_type, is_enabled FROM $settings_table WHERE user_id = %d AND season_id = %d", $user_id, $season_id), OBJECT_K);
    }

    echo '<div class="kf-card">';
    foreach ($notification_types as $type => $description) {
        $is_checked = true;

        if ($user_id > 0) {
            if (isset($user_settings_results[$type])) {
                $is_checked = (bool)$user_settings_results[$type]->is_enabled;
            } elseif (isset($defaults_results[$type])) {
                $is_checked = (bool)$defaults_results[$type]->is_enabled;
            }
        } else {
             if (isset($defaults_results[$type])) {
                $is_checked = (bool)$defaults_results[$type]->is_enabled;
            }
        }
        ?>
        <div class="kf-setting-row">
            <label class="kf-switch">
                <input type="checkbox" 
                       class="kf-notification-toggle"
                       data-user-id="<?php echo esc_attr($user_id); ?>"
                       data-season-id="<?php echo esc_attr($season_id); ?>"
                       data-notification-type="<?php echo esc_attr($type); ?>"
                       <?php checked($is_checked, true); ?>>
                <span class="kf-slider round"></span>
            </label>
            <span class="kf-setting-description"><?php echo esc_html($description); ?></span>
            <span class="kf-setting-status" style="display:none; margin-left: 10px;"></span>
        </div>
        <?php
    }
    echo '</div>';
}