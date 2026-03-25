<?php
/**
 * Shortcode handler for the Commissioner's "Review Late Submissions" page.
 *
 * @package Kerry_Football
 * * TIMEZONE FIX (V2.1):
 * - MODIFIED: The 'Date Submitted' column now displays in the site's local timezone instead of UTC.
 * * CRITICAL LOGIN FIX (V2.1.7): Removed unsafe inline session_start() call, relying on kerry-football-admin.php's early 'init' hook to start the session safely.
 */

function kf_review_late_picks_view() {
    // Ensure user is a logged-in commissioner
    if (!is_user_logged_in() || !current_user_can('manage_options')) {
        return '<p>You do not have permission to view this page.</p>';
    }
    // REMOVED UNSAFE SESSION START: if (session_status() === PHP_SESSION_NONE) { session_start(); }

    global $wpdb;
    $season_id = $_SESSION['kf_active_season_id'] ?? 0;
    if (!$season_id) {
        return '<div class="kf-container"><h1>Review Late Submissions</h1><p>Please select an active season from the main menu.</p></div>';
    }

    $pending_picks_table = $wpdb->prefix . 'pending_picks';
    $picks_table = $wpdb->prefix . 'picks';

    // --- HANDLE POST ACTIONS (APPROVE/DECLINE) ---
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['kf_review_nonce']) && wp_verify_nonce($_POST['kf_review_nonce'], 'kf_review_late_picks_action')) {
        $pending_id = isset($_POST['pending_id']) ? intval($_POST['pending_id']) : 0;
        $action = isset($_POST['action']) ? sanitize_key($_POST['action']) : '';

        $submission = $wpdb->get_row($wpdb->prepare("SELECT * FROM $pending_picks_table WHERE id = %d", $pending_id));

        if ($submission && $submission->status === 'pending') {
            if ($action === 'approve') {
                $decoded_data = json_decode($submission->picks_data, true);
                $picks = $decoded_data['picks'] ?? [];
                $points = $decoded_data['points'] ?? [];

                if (!empty($picks)) {
                    // Start a transaction to ensure data integrity
                    $wpdb->query('START TRANSACTION');

                    // Delete any existing standard picks for this user/week (BPOW picks are separate)
                    $wpdb->delete($picks_table, ['user_id' => $submission->user_id, 'week_id' => $submission->week_id, 'is_bpow' => 0]);
                    
                    // Insert the newly approved picks
                    foreach ($picks as $matchup_id => $pick) {
                        $point_value = $points[$matchup_id] ?? 0;
                        if (!empty($pick) || $pick === '0') {
                            $wpdb->insert($picks_table, [
                                'user_id' => $submission->user_id,
                                'week_id' => $submission->week_id,
                                'matchup_id' => intval($matchup_id),
                                'pick' => sanitize_text_field($pick),
                                'point_value' => intval($point_value),
                                'is_bpow' => 0 // Late submissions are always standard picks
                            ]);
                        }
                    }

                    // Update the pending submission status
                    $wpdb->update($pending_picks_table, 
                        ['status' => 'approved', 'reviewed_at' => current_time('mysql', 1)], 
                        ['id' => $pending_id]
                    );

                    $wpdb->query('COMMIT');
                    echo '<div class="notice notice-success is-dismissible"><p>Late submission approved successfully.</p></div>';
                } else {
                    $wpdb->query('ROLLBACK');
                    echo '<div class="notice notice-error is-dismissible"><p>Error: Could not decode picks data.</p></div>';
                }

            } elseif ($action === 'decline') {
                $wpdb->update($pending_picks_table, 
                    ['status' => 'declined', 'reviewed_at' => current_time('mysql', 1)], 
                    ['id' => $pending_id]
                );
                echo '<div class="notice notice-success is-dismissible"><p>Late submission declined.</p></div>';
            }
        }
    }

    // --- FETCH DATA FOR DISPLAY ---
    $weeks_table = $wpdb->prefix . 'weeks';
    $users_table = $wpdb->users;
    
    $pending_submissions = $wpdb->get_results($wpdb->prepare(
        "SELECT pp.*, u.display_name, w.week_number 
         FROM $pending_picks_table pp
         JOIN $users_table u ON pp.user_id = u.ID
         JOIN $weeks_table w ON pp.week_id = w.id
         WHERE w.season_id = %d AND pp.status = 'pending'
         ORDER BY pp.requested_at ASC",
        $season_id
    ));

    ob_start();
    ?>
    <div class="kf-container">
        <h1>Review Late Submissions</h1>
        <p>The following picks were submitted by players after the weekly deadline. You can approve or decline them here.</p>
        
        <div class="kf-table-wrapper">
            <table class="kf-table">
                <thead>
                    <tr>
                        <th>Player</th>
                        <th>Week</th>
                        <th>Date Submitted</th>
                        <th style="width: 25%;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($pending_submissions)): ?>
                        <tr><td colspan="4">There are no pending late submissions.</td></tr>
                    <?php else: ?>
                        <?php foreach ($pending_submissions as $sub): ?>
                            <tr>
                                <td><?php echo esc_html($sub->display_name); ?></td>
                                <td>Week <?php echo esc_html($sub->week_number); ?></td>
                                <td>
                                    <?php
                                    // --- TIMEZONE FIX: Convert UTC time to site's local time for display ---
                                    try {
                                        $utc_dt = new DateTime($sub->requested_at, new DateTimeZone('UTC'));
                                        $site_tz = new DateTimeZone(wp_timezone_string());
                                        $site_dt = $utc_dt->setTimezone($site_tz);
                                        echo esc_html($site_dt->format('Y-m-d g:i A T'));
                                    } catch (Exception $e) {
                                        // Fallback in case of an error
                                        echo esc_html($sub->requested_at);
                                    }
                                    ?>
                                </td>
                                <td>
                                    <form method="POST" style="display: inline-block; margin-right: 10px;">
                                        <?php wp_nonce_field('kf_review_late_picks_action', 'kf_review_nonce'); ?>
                                        <input type="hidden" name="pending_id" value="<?php echo esc_attr($sub->id); ?>">
                                        <button type="submit" name="action" value="approve" class="kf-button kf-button-action">Approve</button>
                                    </form>
                                    <form method="POST" style="display: inline-block;">
                                        <?php wp_nonce_field('kf_review_late_picks_action', 'kf_review_nonce'); ?>
                                        <input type="hidden" name="pending_id" value="<?php echo esc_attr($sub->id); ?>">
                                        <button type="submit" name="action" value="decline" class="kf-button kf-button-secondary">Decline</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php
    return ob_get_clean();
}

// The closing PHP tag is omitted to prevent accidental whitespace/BOM output.