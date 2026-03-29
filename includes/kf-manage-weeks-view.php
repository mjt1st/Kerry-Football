<?php
/**
 * Shortcode handler for the "Manage Weeks" commissioner page.
 *
 * @package Kerry_Football
 * - NEW: Triggers the "Picks Ready" notification when a week is published.
 * - NEW: Schedules/unschedules the 24-hour deadline reminder via WP-Cron on publish/unpublish.
 */

function kf_manage_weeks_view_shortcode() {
    if (!is_user_logged_in()) {
        return '<div class="kf-container"><p>You do not have access to this page.</p></div>';
    }
    if (session_status() === PHP_SESSION_NONE) { session_start(); }

    global $wpdb;

    $season_id = $_SESSION['kf_active_season_id'] ?? 0;

    if (!$season_id) {
        return '<div class="kf-container"><h1>Manage Weeks</h1><p>No active season selected. Please create or activate a season.</p></div>';
    }
    if (!kf_can_manage_season($season_id)) {
        return '<div class="kf-container"><p>You do not have access to this page.</p></div>';
    }

    $weeks_table = $wpdb->prefix . 'weeks';
    $season_table = $wpdb->prefix . 'seasons';
    $season = $wpdb->get_row($wpdb->prepare("SELECT * FROM $season_table WHERE id = %d", $season_id));
    if (!$season) { return '<div class="kf-container"><p>The selected season could not be found.</p></div>';}

    // --- Handle Actions (Publish, Unpublish) ---
    if (isset($_GET['action']) && isset($_GET['week_id']) && isset($_GET['_wpnonce'])) {
        $action = sanitize_key($_GET['action']);
        $week_id = intval($_GET['week_id']);
        $nonce = $_GET['_wpnonce'];
        $redirect_url = remove_query_arg(['action', 'week_id', '_wpnonce']);
        
        $week_info = $wpdb->get_row($wpdb->prepare("SELECT submission_deadline FROM $weeks_table WHERE id = %d", $week_id));

        if ($action === 'publish' && wp_verify_nonce($nonce, 'kf_publish_week_' . $week_id)) {
            $wpdb->update($weeks_table, ['status' => 'published'], ['id' => $week_id]);
            
            // NEW: Trigger the "Picks Ready" notification
            if (function_exists('kf_send_picks_ready_notification')) {
                kf_send_picks_ready_notification($week_id);
            }
            // NEW: Schedule the deadline reminder
            if ($week_info && function_exists('kf_schedule_deadline_reminder')) {
                kf_schedule_deadline_reminder($week_id, $week_info->submission_deadline);
            }
            
            wp_safe_redirect($redirect_url);
            exit;
        }
        
        if ($action === 'unpublish' && wp_verify_nonce($nonce, 'kf_unpublish_week_' . $week_id)) {
            $wpdb->update($weeks_table, ['status' => 'draft'], ['id' => $week_id]);
            
            // NEW: Un-schedule the deadline reminder
            if (function_exists('kf_unschedule_deadline_reminder')) {
                kf_unschedule_deadline_reminder($week_id);
            }

            wp_safe_redirect($redirect_url);
            exit;
        }
    }

    $all_weeks = $wpdb->get_results($wpdb->prepare("SELECT * FROM $weeks_table WHERE season_id = %d ORDER BY week_number ASC", $season_id));

    // Per-week: count players who have submitted picks and total accepted players
    $total_players = (int)$wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->prefix}season_players WHERE season_id = %d AND status = 'accepted'",
        $season_id
    ));
    $picks_per_week = [];
    if (!empty($all_weeks)) {
        $week_ids = wp_list_pluck($all_weeks, 'id');
        $ph = implode(',', array_fill(0, count($week_ids), '%d'));
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT week_id, COUNT(DISTINCT user_id) AS cnt FROM {$wpdb->prefix}picks
             WHERE week_id IN ($ph) AND is_bpow = 0 GROUP BY week_id",
            $week_ids
        ));
        foreach ($rows as $r) {
            $picks_per_week[(int)$r->week_id] = (int)$r->cnt;
        }
    }

    ob_start();
    ?>
    <div class="kf-container">
        <h1>Manage Weeks</h1>
        <h2 class="kf-page-subtitle">For Season: <?php echo esc_html($season->name); ?></h2>
        
        <div class="kf-action-bar">
            <a href="<?php echo esc_url(site_url('/season-summary/')); ?>" class="kf-button">View Season Summary</a>
            <a href="<?php echo esc_url(site_url('/week-setup/')); ?>" class="kf-button kf-button-action">+ Add New Week</a>
        </div>

        <div class="kf-table-wrapper">
            <table class="kf-table">
                <thead>
                    <tr>
                        <th>Week</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($all_weeks)): ?>
                        <tr><td colspan="3">
                            <div class="kf-empty-state">
                                <div class="kf-empty-icon">🏈</div>
                                <h3>No weeks yet</h3>
                                <p>Get started by adding the first week of the season.</p>
                                <a href="<?php echo esc_url(site_url('/week-setup/')); ?>" class="kf-button kf-button-action">+ Add First Week</a>
                            </div>
                        </td></tr>
                    <?php else: ?>
                        <?php foreach($all_weeks as $week): ?>
                            <tr>
                                <td>
                                    <?php
                                    $week_summary_url = add_query_arg(['week_id' => $week->id], site_url('/week-summary/'));
                                    if ($week->status !== 'draft'): ?>
                                        <a href="<?php echo esc_url($week_summary_url); ?>">
                                            <strong>Week <?php echo esc_html($week->week_number); ?></strong>
                                        </a>
                                    <?php else: ?>
                                        <strong>Week <?php echo esc_html($week->week_number); ?></strong>
                                    <?php endif; ?>
                                    <?php if ($week->status === 'published' && $total_players > 0):
                                        $submitted = $picks_per_week[(int)$week->id] ?? 0;
                                        $pct = $total_players > 0 ? round(($submitted / $total_players) * 100) : 0;
                                        $bar_class = $submitted === 0 ? 'kf-progress-none' : ($submitted < $total_players ? 'kf-progress-partial' : '');
                                    ?>
                                        <div class="kf-picks-progress">
                                            <div class="kf-picks-progress-bar-wrap">
                                                <div class="kf-picks-progress-bar <?php echo esc_attr($bar_class); ?>" style="width:<?php echo esc_attr($pct); ?>%"></div>
                                            </div>
                                            <?php echo intval($submitted); ?>/<?php echo intval($total_players); ?> picks
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td class="kf-status-cell">
                                    <?php 
                                        if ($week->status === 'finalized') {
                                            echo '<span class="kf-status-finalized">Finalized</span>';
                                        } elseif ($week->status === 'published') {
                                            echo '<span class="kf-status-submitted">Published</span>';
                                        } elseif ($week->status === 'tie_resolution_needed') {
                                            echo '<span class="kf-status-pending">Tie Resolution Needed</span>';
                                        } else {
                                            echo '<span class="kf-status-pending">Draft</span>';
                                        }
                                    ?>
                                </td>
                                <td>
                                    <div class="kf-actions-group">
                                        <?php if ($week->status === 'draft'): ?>
                                            <a href="<?php echo esc_url(add_query_arg(['week_id' => $week->id], site_url('/week-setup/'))); ?>" class="kf-button">Edit</a>
                                            <?php $publish_url = wp_nonce_url(add_query_arg(['action' => 'publish', 'week_id' => $week->id]), 'kf_publish_week_' . $week->id); ?>
                                            <a href="<?php echo esc_url($publish_url); ?>" class="kf-button kf-button-action">Publish</a>
                                        <?php elseif ($week->status === 'published'): ?>
                                            <a href="<?php echo esc_url(add_query_arg(['week_id' => $week->id], site_url('/enter-results/'))); ?>" class="kf-button">Enter Results</a>
                                            <?php $unpublish_url = wp_nonce_url(add_query_arg(['action' => 'unpublish', 'week_id' => $week->id]), 'kf_unpublish_week_' . $week->id); ?>
                                            <a href="<?php echo esc_url($unpublish_url); ?>" class="kf-button kf-button-secondary">Unpublish</a>
                                        <?php elseif ($week->status === 'tie_resolution_needed'): ?>
                                            <a href="<?php echo esc_url($week_summary_url); ?>" class="kf-button kf-button-action">View / Resolve Tie</a>
                                            <a href="<?php echo esc_url(add_query_arg(['week_id' => $week->id], site_url('/enter-results/'))); ?>" class="kf-button">Enter Results</a>
                                        <?php elseif ($week->status === 'finalized'): ?>
                                            <a href="<?php echo esc_url($week_summary_url); ?>" class="kf-button">View Summary</a>
                                        <?php endif; ?>
                                    </div>
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