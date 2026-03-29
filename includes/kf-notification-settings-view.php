<?php
/**
 * Shortcode handler for the "Notification Settings" page.
 * Shows notification preferences for ALL seasons the player is accepted in,
 * not just the currently active/selected one.
 *
 * @package Kerry_Football
 */

if (!defined('ABSPATH')) {
    exit;
}

function kf_notification_settings_view() {
    if (!is_user_logged_in()) {
        return '<p>You must be logged in to view this page.</p>';
    }

    global $wpdb;
    $user_id         = get_current_user_id();
    $is_commissioner = kf_is_any_commissioner();

    $notification_types = [
        'week_finalized' => 'Week Finalized — receive an email when a week\'s results are posted.',
        'picks_ready'    => 'Picks Ready — receive an email when a new week is published and open for picks.',
        'picks_reminder' => 'Picks Reminder — receive a reminder 24 hours before the deadline if you haven\'t submitted picks yet.',
    ];

    // Fetch all seasons this user is accepted in (players) or all active seasons (commissioner).
    if ($is_commissioner) {
        $seasons = $wpdb->get_results(
            "SELECT id, name, is_active FROM {$wpdb->prefix}seasons ORDER BY is_active DESC, id DESC"
        );
    } else {
        $seasons = $wpdb->get_results( $wpdb->prepare(
            "SELECT s.id, s.name, s.is_active
             FROM {$wpdb->prefix}seasons s
             JOIN {$wpdb->prefix}season_players sp ON s.id = sp.season_id
             WHERE sp.user_id = %d AND sp.status = 'accepted'
             ORDER BY s.is_active DESC, s.id DESC",
            $user_id
        ) );
    }

    ob_start();
    ?>
    <div class="kf-container kf-notification-settings">
        <h1>Notification Settings</h1>
        <p class="kf-form-note" style="margin-bottom:1.5em;">
            Manage your email preferences for each season you're participating in.
            Changes save automatically when you toggle a switch.
        </p>

        <?php if ($is_commissioner) : ?>
            <div class="kf-tabs">
                <button class="kf-tab-link active" onclick="kfOpenTab(event, 'mySettings')">My Personal Settings</button>
                <button class="kf-tab-link" onclick="kfOpenTab(event, 'leagueDefaults')">League Default Settings</button>
            </div>
        <?php endif; ?>

        <?php // ---- MY PERSONAL SETTINGS ---- ?>
        <div id="mySettings" class="kf-tab-content" style="display:block;">
            <?php if ($is_commissioner) : ?>
                <h3>My Personal Settings</h3>
                <p class="kf-form-note">These apply only to you and override the league defaults for each season.</p>
            <?php endif; ?>

            <?php if (empty($seasons)) : ?>
                <div class="kf-card"><p>You are not enrolled in any seasons yet.</p></div>
            <?php else : ?>
                <?php foreach ($seasons as $season) : ?>
                    <div class="kf-card" style="margin-bottom:1.5em;">
                        <div style="display:flex;align-items:center;gap:10px;margin-bottom:1em;padding-bottom:0.75em;border-bottom:1px solid #e5e7eb;">
                            <h3 style="margin:0;border:none;padding:0;"><?php echo esc_html($season->name); ?></h3>
                            <?php if ($season->is_active) : ?>
                                <span class="kf-status-active" style="font-size:0.75em;">Active</span>
                            <?php else : ?>
                                <span class="kf-status-archived" style="font-size:0.75em;">Archived</span>
                            <?php endif; ?>
                        </div>
                        <?php kf_display_notification_toggles($user_id, $season->id, $notification_types); ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <?php // ---- LEAGUE DEFAULT SETTINGS (commissioner only) ---- ?>
        <?php if ($is_commissioner) : ?>
            <div id="leagueDefaults" class="kf-tab-content" style="display:none;">
                <h3>League Default Settings</h3>
                <p class="kf-form-note">These are the defaults applied to all players in each season. Individual players can override these on their own settings page.</p>

                <?php if (empty($seasons)) : ?>
                    <div class="kf-card"><p>No seasons found.</p></div>
                <?php else : ?>
                    <?php foreach ($seasons as $season) : ?>
                        <div class="kf-card" style="margin-bottom:1.5em;">
                            <div style="display:flex;align-items:center;gap:10px;margin-bottom:1em;padding-bottom:0.75em;border-bottom:1px solid #e5e7eb;">
                                <h3 style="margin:0;border:none;padding:0;"><?php echo esc_html($season->name); ?></h3>
                                <?php if ($season->is_active) : ?>
                                    <span class="kf-status-active" style="font-size:0.75em;">Active</span>
                                <?php else : ?>
                                    <span class="kf-status-archived" style="font-size:0.75em;">Archived</span>
                                <?php endif; ?>
                            </div>
                            <?php kf_display_notification_toggles(0, $season->id, $notification_types); ?>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>

    <script>
    function kfOpenTab(evt, tabName) {
        document.querySelectorAll('.kf-tab-content').forEach(el => el.style.display = 'none');
        document.querySelectorAll('.kf-tab-link').forEach(el => el.classList.remove('active'));
        document.getElementById(tabName).style.display = 'block';
        evt.currentTarget.classList.add('active');
    }
    </script>
    <?php
    return ob_get_clean();
}


/**
 * Renders the toggle switches for a given user + season combination.
 * user_id = 0 renders league defaults (commissioner only).
 */
function kf_display_notification_toggles($user_id, $season_id, $notification_types) {
    global $wpdb;
    $settings_table = $wpdb->prefix . 'notification_settings';

    // Always load the league defaults for this season as the fallback
    $defaults = $wpdb->get_results( $wpdb->prepare(
        "SELECT notification_type, is_enabled FROM $settings_table WHERE user_id = 0 AND season_id = %d",
        $season_id
    ), OBJECT_K );

    // Load personal overrides if rendering for a specific user
    $personal = [];
    if ($user_id > 0) {
        $personal = $wpdb->get_results( $wpdb->prepare(
            "SELECT notification_type, is_enabled FROM $settings_table WHERE user_id = %d AND season_id = %d",
            $user_id, $season_id
        ), OBJECT_K );
    }

    foreach ($notification_types as $type => $description) {
        // Resolve effective value: personal override → league default → on
        if ($user_id > 0) {
            if (isset($personal[$type])) {
                $is_checked = (bool)$personal[$type]->is_enabled;
            } elseif (isset($defaults[$type])) {
                $is_checked = (bool)$defaults[$type]->is_enabled;
            } else {
                $is_checked = true;
            }
        } else {
            $is_checked = isset($defaults[$type]) ? (bool)$defaults[$type]->is_enabled : true;
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
            <span class="kf-setting-status" style="display:none;margin-left:10px;font-size:0.85em;color:#6b7280;"></span>
        </div>
        <?php
    }
}
