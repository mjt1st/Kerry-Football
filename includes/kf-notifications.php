<?php
/**
 * Handles all email notifications for the plugin.
 * REFACTORED to use a central, settings-aware email function.
 * Adds new notifications for "Picks Ready" and "Deadline Reminder".
 *
 * @package Kerry_Football
 * - FIX: The core email function now defaults to TRUE (opt-in) if no notification setting is found in the database.
 */

if (!defined('ABSPATH')) exit;

/**
 * NEW Core Email Function: Sends an email to players who have opted-in.
 * This is the new central function for all notifications.
 *
 * @param int    $season_id The ID of the season.
 * @param string $notification_type The key for the notification (e.g., 'week_finalized').
 * @param string $subject The email subject.
 * @param string $message The email body.
 * @param array  $limit_to_user_ids Optional. An array of user IDs to limit the recipients to. If empty, all players are considered.
 */
function kf_send_email_to_eligible_players($season_id, $notification_type, $subject, $message, $limit_to_user_ids = []) {
    global $wpdb;

    // Get all active players for the season
    $all_players = $wpdb->get_results($wpdb->prepare(
        "SELECT u.ID, u.user_email FROM {$wpdb->prefix}users u 
         JOIN {$wpdb->prefix}season_players sp ON u.ID = sp.user_id 
         WHERE sp.season_id = %d AND sp.status = 'accepted'",
        $season_id
    ));

    if (empty($all_players)) return;

    // Get league default settings for this notification type
    $default_setting_raw = $wpdb->get_var($wpdb->prepare(
        "SELECT is_enabled FROM {$wpdb->prefix}notification_settings WHERE user_id = 0 AND season_id = %d AND notification_type = %s",
        $season_id, $notification_type
    ));
    
    // --- FIX --- 
    // If the database query returns NULL (meaning the setting has never been saved), we should default to TRUE (opt-in).
    // Otherwise, we cast the stored value (0 or 1) to a boolean.
    $default_setting = ($default_setting_raw === null) ? true : (bool)$default_setting_raw;
    
    // Get all user-specific overrides for this notification type
    $user_overrides_results = $wpdb->get_results($wpdb->prepare(
        "SELECT user_id, is_enabled FROM {$wpdb->prefix}notification_settings WHERE season_id = %d AND notification_type = %s AND user_id > 0",
        $season_id, $notification_type
    ), OBJECT_K);

    $user_overrides = [];
    if (!empty($user_overrides_results)) {
        foreach($user_overrides_results as $user_id => $setting) {
            $user_overrides[$user_id] = (bool)$setting->is_enabled;
        }
    }

    $recipients = [];
    foreach ($all_players as $player) {
        // If a specific list of users is provided, skip anyone not in that list
        if (!empty($limit_to_user_ids) && !in_array($player->ID, $limit_to_user_ids)) {
            continue;
        }

        $send_email = $default_setting; // Start with the league default
        if (array_key_exists($player->ID, $user_overrides)) {
            $send_email = $user_overrides[$player->ID]; // Apply user's override if it exists
        }

        if ($send_email) {
            $recipients[] = $player->user_email;
        }
    }

    if (empty($recipients)) {
        error_log("Kerry Football Notification: The notification '$notification_type' for season $season_id had no eligible recipients.");
        return;
    }

    $headers = ['Content-Type: text/html; charset=UTF-8'];
    $from_email = get_option('admin_email');
    $from_name = get_bloginfo('name');
    $headers[] = "From: $from_name <$from_email>";

    // Send the email in a single batch
    wp_mail($recipients, $subject, $message, $headers);
}


/**
 * Sends "Results Are In" email on week finalization.
 * REFRACTORED to use the new core email function.
 *
 * @param int $week_id
 */
function kf_send_results_notification($week_id) {
    global $wpdb;
    $week = $wpdb->get_row($wpdb->prepare("SELECT w.week_number, w.mwow_winner_user_id, w.bpow_winner_user_id, s.id as season_id, s.name as season_name FROM {$wpdb->prefix}weeks w JOIN {$wpdb->prefix}seasons s ON w.season_id = s.id WHERE w.id = %d", $week_id));
    if (!$week) return;

    $scores = $wpdb->get_results($wpdb->prepare("SELECT u.display_name, s.score FROM {$wpdb->prefix}scores s JOIN {$wpdb->prefix}users u ON s.user_id = u.ID WHERE s.week_id = %d ORDER BY s.score DESC", $week_id));
    if (empty($scores)) return;

    $mwow_name = $week->mwow_winner_user_id ? get_userdata($week->mwow_winner_user_id)->display_name : 'None';
    $bpow_name = $week->bpow_winner_user_id ? get_userdata($week->bpow_winner_user_id)->display_name : 'None';

    $scores_table_html = '<table style="border-collapse: collapse; width: 100%;"><thead><tr><th style="border: 1px solid #ddd; padding: 8px;">Player</th><th style="border: 1px solid #ddd; padding: 8px;">Score</th></tr></thead><tbody>';
    foreach ($scores as $score) {
        $scores_table_html .= '<tr><td style="border: 1px solid #ddd; padding: 8px;">' . esc_html($score->display_name) . '</td><td style="border: 1px solid #ddd; padding: 8px;">' . esc_html($score->score) . '</td></tr>';
    }
    $scores_table_html .= '</tbody></table>';

    $subject = "Results Are In for Week {$week->week_number} of {$week->season_name}!";
    $message = "<p>Week {$week->week_number} is finalized. Here's the recap:</p>" . $scores_table_html . "<p><strong>MWOW Winner:</strong> {$mwow_name}</p><p><strong>Next BPOW:</strong> {$bpow_name}</p><p>View full details: <a href='" . site_url('/player-dashboard/') . "'>Dashboard</a></p>";

    kf_send_email_to_eligible_players($week->season_id, 'week_finalized', $subject, $message);
}


/**
 * NEW: Sends "Picks Ready" notification when a week is published.
 *
 * @param int $week_id
 */
function kf_send_picks_ready_notification($week_id) {
    global $wpdb;
    $week = $wpdb->get_row($wpdb->prepare("SELECT w.week_number, w.submission_deadline, s.id as season_id, s.name as season_name FROM {$wpdb->prefix}weeks w JOIN {$wpdb->prefix}seasons s ON w.season_id = s.id WHERE w.id = %d", $week_id));
    if (!$week) return;
    
    $subject = "Picks are ready for Week {$week->week_number} of {$week->season_name}!";
    $deadline_formatted = date("l, F jS \a\\t g:i A T", strtotime($week->submission_deadline));
    $picks_url = esc_url(add_query_arg('week_id', $week_id, site_url('/my-picks/')));
    $message = "<p>Week {$week->week_number} is now open for picks.</p>"
             . "<p><strong>Submission Deadline:</strong> {$deadline_formatted}</p>"
             . "<p><a href='" . $picks_url . "'>Click here to make your picks!</a></p>";

    kf_send_email_to_eligible_players($week->season_id, 'picks_ready', $subject, $message);
}


/**
 * NEW: Schedules the one-time deadline reminder email using WP-Cron.
 *
 * @param int    $week_id
 * @param string $deadline_gmt The submission deadline in GMT format.
 */
function kf_schedule_deadline_reminder($week_id, $deadline_gmt) {
    $reminder_time = strtotime($deadline_gmt) - HOUR_IN_SECONDS * 24; // 24 hours before deadline
    // Ensure we don't schedule an event in the past
    if ($reminder_time > time()) {
        wp_schedule_single_event($reminder_time, 'kf_execute_deadline_reminder_hook', ['week_id' => $week_id]);
    }
}
add_action('kf_execute_deadline_reminder_hook', 'kf_execute_deadline_reminder', 10, 1);

/**
 * NEW: Clears a scheduled reminder if the week is unpublished or deadline changes.
 *
 * @param int $week_id
 */
function kf_unschedule_deadline_reminder($week_id) {
    wp_clear_scheduled_hook('kf_execute_deadline_reminder_hook', ['week_id' => $week_id]);
}

/**
 * NEW: The function executed by WP-Cron to send the reminder email.
 *
 * @param int $week_id
 */
function kf_execute_deadline_reminder($week_id) {
    global $wpdb;
    $week = $wpdb->get_row($wpdb->prepare("SELECT w.week_number, w.submission_deadline, s.id as season_id, s.name as season_name FROM {$wpdb->prefix}weeks w JOIN {$wpdb->prefix}seasons s ON w.season_id = s.id WHERE w.id = %d", $week_id));
    if (!$week || $week->status !== 'published') {
        return; // Don't send if week is no longer published
    }

    // Find players who HAVE NOT submitted picks for this week
    $players_with_picks = $wpdb->get_col($wpdb->prepare("SELECT DISTINCT user_id FROM {$wpdb->prefix}picks WHERE week_id = %d", $week_id));
    $all_players = $wpdb->get_col($wpdb->prepare("SELECT user_id FROM {$wpdb->prefix}season_players WHERE season_id = %d AND status = 'accepted'", $week->season_id));
    
    $players_to_remind = array_diff($all_players, $players_with_picks);

    if (empty($players_to_remind)) {
        return; // Everyone has submitted picks
    }

    $subject = "Reminder: Picks for Week {$week->week_number} are due soon!";
    $deadline_formatted = date("l, F jS \a\\t g:i A T", strtotime($week->submission_deadline));
    $picks_url = esc_url(add_query_arg('week_id', $week_id, site_url('/my-picks/')));
    $message = "<p>This is a reminder that your picks for Week {$week->week_number} are due soon.</p>"
             . "<p><strong>Submission Deadline:</strong> {$deadline_formatted}</p>"
             . "<p><a href='" . $picks_url . "'>Click here to make your picks!</a></p>";
    
    kf_send_email_to_eligible_players($week->season_id, 'picks_reminder', $subject, $message, $players_to_remind);
}


/**
 * Sends an invitation email to a new player. This is a system email and does not check settings.
 */
function kf_send_player_invite($user_id, $season_id) {
    // This function remains unchanged as it is a direct, transactional email.
    global $wpdb;
    $user = get_userdata($user_id);
    if (!$user || !$user->user_email) return false;
    $season = $wpdb->get_row($wpdb->prepare("SELECT name FROM {$wpdb->prefix}seasons WHERE id = %d", $season_id));
    if (!$season) return false;

    $subject = "Invitation to Join Season: " . $season->name;
    $message = "<p>Hi {$user->display_name},</p><p>You've been invited to join the '{$season->name}' season. Please log in and accept the invitation.</p>";
    $headers = ['Content-Type: text/html; charset=UTF-8', "From: " . get_bloginfo('name') . " <" . get_option('admin_email') . ">"];

    return wp_mail($user->user_email, $subject, $message, $headers);
}

/**
 * @deprecated This function is now replaced by kf_send_email_to_eligible_players()
 */
function kf_send_notification_to_season_players($season_id, $subject, $message) {
    // This function is being kept for backwards compatibility but should not be used for new notifications.
    // It will now respect notification settings for a generic 'general' type.
    kf_send_email_to_eligible_players($season_id, 'general', $subject, $message);
}