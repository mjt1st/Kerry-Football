<?php
/**
 * Shortcode handler for the Commissioner Dashboard.
 *
 * @package Kerry_Football
 * * LATE PICKS V2.1:
 * - NEW: Added a "Review Late Submissions" button to the actions for each active season.
 */

function kf_commissioner_dashboard_shortcode() {
    if (!is_user_logged_in() || !current_user_can('manage_options')) {
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

    $seasons = $wpdb->get_results("SELECT * FROM $seasons_table ORDER BY is_active DESC, id DESC");

    ob_start();
    ?>
    <div class="kf-container">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1em;">
            <h1>Commissioner Dashboard</h1>
            <a href="<?php echo esc_url(site_url('/season-setup/')); ?>" class="kf-button">Create New Season</a>
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