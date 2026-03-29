<?php
/**
 * Shortcode handler for the Commissioner Dashboard.
 *
 * @package Kerry_Football
 * * LATE PICKS V2.1:
 * - NEW: Added a "Review Late Submissions" button to the actions for each active season.
 */

function kf_commissioner_dashboard_shortcode() {
    if (!is_user_logged_in() || !kf_is_any_commissioner()) {
        return '<p>You do not have access to this page.</p>';
    }

    global $wpdb;
    $seasons_table = $wpdb->prefix . 'seasons';
    
    // --- Handle Actions (Toggle Status & Delete Season) ---
    if (isset($_GET['action']) && isset($_GET['season_id']) && isset($_GET['_wpnonce'])) {
        $action = sanitize_key($_GET['action']);
        $season_id = intval($_GET['season_id']);
        $nonce = $_GET['_wpnonce'];

        if ($action === 'toggle_status' && wp_verify_nonce($nonce, 'kf_toggle_status_' . $season_id)) {
            $current_status = $wpdb->get_var($wpdb->prepare("SELECT is_active FROM $seasons_table WHERE id = %d", $season_id));
            $new_status = $current_status ? 0 : 1;
            $wpdb->update($seasons_table, ['is_active' => $new_status], ['id' => $season_id]);
            wp_safe_redirect(remove_query_arg(['action', 'season_id', '_wpnonce']));
            exit;
        }
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_season']) && isset($_POST['kf_delete_season_nonce']) && wp_verify_nonce($_POST['kf_delete_season_nonce'], 'kf_delete_season_action')) {
        $delete_season_id = intval($_POST['season_id']);
        if ($delete_season_id > 0) {
            // Cascading delete: remove all related data in dependency order
            $wpdb->delete($wpdb->prefix . 'score_history', ['user_id' => 0], ['%d']); // placeholder — delete via week_id subquery below
            // Delete score_history entries for weeks in this season
            $wpdb->query($wpdb->prepare(
                "DELETE sh FROM {$wpdb->prefix}score_history sh
                 JOIN {$wpdb->prefix}weeks w ON sh.week_id = w.id
                 WHERE w.season_id = %d", $delete_season_id
            ));
            // Delete double_down_log entries for this season
            $wpdb->delete($wpdb->prefix . 'double_down_log', ['season_id' => $delete_season_id]);
            // Delete dd_selections for this season
            $wpdb->delete($wpdb->prefix . 'dd_selections', ['season_id' => $delete_season_id]);
            // Delete scores for weeks in this season
            $wpdb->query($wpdb->prepare(
                "DELETE sc FROM {$wpdb->prefix}scores sc
                 JOIN {$wpdb->prefix}weeks w ON sc.week_id = w.id
                 WHERE w.season_id = %d", $delete_season_id
            ));
            // Delete picks for weeks in this season
            $wpdb->query($wpdb->prepare(
                "DELETE p FROM {$wpdb->prefix}picks p
                 JOIN {$wpdb->prefix}weeks w ON p.week_id = w.id
                 WHERE w.season_id = %d", $delete_season_id
            ));
            // Delete pending_picks for weeks in this season
            $wpdb->query($wpdb->prepare(
                "DELETE pp FROM {$wpdb->prefix}pending_picks pp
                 JOIN {$wpdb->prefix}weeks w ON pp.week_id = w.id
                 WHERE w.season_id = %d", $delete_season_id
            ));
            // Delete matchups for weeks in this season
            $wpdb->query($wpdb->prepare(
                "DELETE m FROM {$wpdb->prefix}matchups m
                 JOIN {$wpdb->prefix}weeks w ON m.week_id = w.id
                 WHERE w.season_id = %d", $delete_season_id
            ));
            // Delete weeks
            $wpdb->delete($wpdb->prefix . 'weeks', ['season_id' => $delete_season_id]);
            // Delete player order
            $wpdb->delete($wpdb->prefix . 'season_player_order', ['season_id' => $delete_season_id]);
            // Delete season players
            $wpdb->delete($wpdb->prefix . 'season_players', ['season_id' => $delete_season_id]);
            // Delete notification settings
            $wpdb->query($wpdb->prepare(
                "DELETE FROM {$wpdb->prefix}notification_settings WHERE season_id = %d", $delete_season_id
            ));
            // Finally, delete the season itself
            $wpdb->delete($seasons_table, ['id' => $delete_season_id]);

            // Clear session if the deleted season was active
            if (isset($_SESSION['kf_active_season_id']) && (int)$_SESSION['kf_active_season_id'] === $delete_season_id) {
                unset($_SESSION['kf_active_season_id']);
            }

            echo '<div class="notice notice-success is-dismissible"><p>Season and all related data have been deleted.</p></div>';
        }
    }

    $current_user_id = get_current_user_id();

    if ( current_user_can( 'manage_options' ) ) {
        // Site administrators see every league.
        $seasons = $wpdb->get_results( "SELECT * FROM $seasons_table ORDER BY is_active DESC, id DESC" );
    } else {
        // Non-admin commissioners see only seasons they created or are enrolled in.
        $seasons = $wpdb->get_results( $wpdb->prepare(
            "SELECT DISTINCT s.* FROM $seasons_table s
             LEFT JOIN {$wpdb->prefix}season_players sp ON s.id = sp.season_id AND sp.user_id = %d AND sp.status = 'accepted'
             WHERE s.created_by = %d OR (sp.user_id IS NOT NULL AND sp.is_commissioner = 1)
             ORDER BY s.is_active DESC, s.id DESC",
            $current_user_id, $current_user_id
        ) );
    }

    // --- Cron Health Data ---
    $cron_last_run        = (int) get_option( 'kf_cron_last_run', 0 );
    $cron_failures        = (int) get_option( 'kf_cron_consecutive_failures', 0 );
    $auto_score_enabled   = get_option( 'kf_auto_score_enabled', '1' ) === '1';
    $cron_next_scheduled  = wp_next_scheduled( 'kf_check_game_scores' );

    ob_start();
    ?>
    <div class="kf-container">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1em;">
            <h1>Commissioner Dashboard</h1>
            <div style="display:flex;gap:8px;">
                <a href="<?php echo esc_url(site_url('/api-settings/')); ?>" class="kf-button kf-button-secondary">&#9881; API Settings</a>
                <a href="<?php echo esc_url(site_url('/season-setup/')); ?>" class="kf-button">Create New Season</a>
            </div>
        </div>

        <?php
        // --- Cron Health Panel ---
        $cron_minutes_ago = $cron_last_run ? (int) round( ( time() - $cron_last_run ) / 60 ) : null;
        $cron_status_color  = '#16a34a'; // green
        $cron_status_label  = '';
        $cron_status_detail = '';

        if ( ! $auto_score_enabled ) {
            $cron_status_color  = '#92400e';
            $cron_status_label  = '⚠️ Auto-score disabled';
            $cron_status_detail = 'Scores will not update automatically. Enable in <a href="' . esc_url( site_url( '/api-settings/' ) ) . '">API Settings</a>.';
        } elseif ( ! $cron_next_scheduled ) {
            $cron_status_color  = '#dc2626';
            $cron_status_label  = '🔴 Score cron NOT scheduled';
            $cron_status_detail = 'WP-Cron has lost the score-check event. Deactivate and reactivate the plugin to reschedule it.';
        } elseif ( $cron_last_run === 0 ) {
            $cron_status_color  = '#2563eb';
            $cron_status_label  = '🔵 Cron scheduled — never run yet';
            $cron_status_detail = 'Next run: ' . esc_html( human_time_diff( $cron_next_scheduled, time() ) ) . ' from now.';
        } elseif ( $cron_failures >= 3 ) {
            $cron_status_color  = '#dc2626';
            $cron_status_label  = "🔴 ESPN fetch failing ({$cron_failures} consecutive errors)";
            $cron_status_detail = 'Last run: ' . esc_html( $cron_minutes_ago ) . ' min ago. Check ESPN API or switch to manual mode.';
        } elseif ( $cron_minutes_ago > 60 ) {
            $cron_status_color  = '#d97706';
            $cron_status_label  = "🟡 Last run: {$cron_minutes_ago} min ago";
            $cron_status_detail = 'Score updates may be delayed. WP-Cron requires site traffic to trigger. Next scheduled: ' . esc_html( human_time_diff( $cron_next_scheduled, time() ) ) . ' from now.';
        } else {
            $cron_status_label  = "✅ Last scores checked: {$cron_minutes_ago} min ago";
            $cron_status_detail = 'Next run: ~' . esc_html( human_time_diff( $cron_next_scheduled, time() ) ) . ' from now.';
        }
        ?>
        <div style="background:#f8fafc;border:1px solid #e2e8f0;border-left:4px solid <?php echo esc_attr($cron_status_color); ?>;border-radius:6px;padding:10px 16px;margin-bottom:1.5em;font-size:0.9em;">
            <strong style="color:<?php echo esc_attr($cron_status_color); ?>;"><?php echo $cron_status_label; ?></strong>
            <?php if ( $cron_status_detail ): ?>
                <span style="color:#64748b;margin-left:12px;"><?php echo $cron_status_detail; ?></span>
            <?php endif; ?>
        </div>

        <div class="kf-table-wrapper">
            <table class="kf-table">
                <thead>
                    <tr>
                        <th>Season Name</th>
                        <th>Status</th>
                        <th style="width: 60%;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($seasons)): ?>
                        <tr><td colspan="3">No seasons found.</td></tr>
                    <?php else: ?>
                        <?php foreach ($seasons as $s): ?>
                            <tr>
                                <td><strong><?php echo esc_html($s->name); ?></strong></td>
                                <td class="kf-status-cell"><?php echo $s->is_active ? '<span class="kf-status-active">Active</span>' : '<span class="kf-status-archived">Archived</span>'; ?></td>
                                <td>
                                    <div class="kf-actions-group">
                                        <a href="#" class="kf-button kf-button-action kf-season-select-and-go" 
                                           data-season-id="<?php echo esc_attr($s->id); ?>" 
                                           data-redirect-url="<?php echo esc_url(site_url('/manage-weeks/')); ?>">Manage Weeks</a>
                                        
                                        <?php if ($s->is_active): ?>
                                            <span class="kf-action-separator">|</span>
                                            <a href="#" class="kf-button kf-button-action kf-season-select-and-go" 
                                               data-season-id="<?php echo esc_attr($s->id); ?>" 
                                               data-redirect-url="<?php echo esc_url(site_url('/review-late-submissions/')); ?>">Review Late Picks</a>
                                        <?php endif; ?>
                                        
                                        <span class="kf-action-separator">|</span>
                                        <a href="#" class="kf-button kf-season-select-and-go" data-season-id="<?php echo esc_attr($s->id); ?>" data-redirect-url="<?php echo esc_url(site_url('/season-summary/')); ?>">Summary</a>
                                        <span class="kf-action-separator">|</span>
                                        <a href="#" class="kf-button kf-button-secondary kf-season-select-and-go" data-season-id="<?php echo esc_attr($s->id); ?>" data-redirect-url="<?php echo esc_url(site_url('/manage-players/')); ?>">Players</a>
                                        <span class="kf-action-separator">|</span>
                                        <a href="#" class="kf-button kf-button-secondary kf-season-select-and-go" data-season-id="<?php echo esc_attr($s->id); ?>" data-redirect-url="<?php echo esc_url(site_url('/edit-season/')); ?>">Settings</a>
                                        <span class="kf-action-separator">|</span>
                                        <?php
                                        $toggle_url = wp_nonce_url(add_query_arg(['action' => 'toggle_status', 'season_id' => $s->id]), 'kf_toggle_status_' . $s->id);
                                        $toggle_text = $s->is_active ? 'Archive' : 'Activate';
                                        ?>
                                        <a href="<?php echo esc_url($toggle_url); ?>" class="kf-button kf-button-secondary"><?php echo $toggle_text; ?></a>
                                        <span class="kf-action-separator">|</span>
                                        <form method="POST" onsubmit="return confirm('Are you sure you want to PERMANENTLY delete this season and all related data? This cannot be undone.');" style="display:inline;">
                                            <?php wp_nonce_field('kf_delete_season_action', 'kf_delete_season_nonce'); ?>
                                            <input type="hidden" name="season_id" value="<?php echo esc_attr($s->id); ?>">
                                            <button type="submit" name="delete_season" class="kf-button-as-link kf-danger-text">Delete</button>
                                        </form>
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