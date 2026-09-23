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
        return kf_notice_page(
            'Commissioners only',
            'This page is for people who run a league.',
            [ kf_notice_action( 'Home', site_url( '/' ) ), kf_notice_action( 'Player Dashboard', site_url( '/player-dashboard/' ) ) ],
            'warn'
        );
    }

    global $wpdb;
    $seasons_table = $wpdb->prefix . 'seasons';
    
    // --- Handle Actions (Toggle Status & Delete Season) ---
    if (isset($_GET['action']) && isset($_GET['season_id']) && isset($_GET['_wpnonce'])) {
        $action = sanitize_key($_GET['action']);
        $season_id = intval($_GET['season_id']);
        $nonce = $_GET['_wpnonce'];

        // The nonce proves the request came from this user, not that the league is theirs to change.
        if ($action === 'toggle_status' && wp_verify_nonce($nonce, 'kf_toggle_status_' . $season_id) && kf_can_manage_season($season_id)) {
            $current_status = $wpdb->get_var($wpdb->prepare("SELECT is_active FROM $seasons_table WHERE id = %d", $season_id));
            $new_status = $current_status ? 0 : 1;
            $wpdb->update($seasons_table, ['is_active' => $new_status], ['id' => $season_id]);
            return kf_redirect_after_action(remove_query_arg(['action', 'season_id', '_wpnonce']));
        }
    }

    // Deleting a league destroys every week, pick and score in it. Until 1.8.21 this checked only a
    // nonce shared by every league and "commissioner of something", so any co-commissioner could
    // delete any league on the site by posting its id. Now: a nonce bound to that league, and only a
    // site admin or the league's creator — the same people who may grant co-commissioner.
    $delete_season_id = ( $_SERVER['REQUEST_METHOD'] === 'POST' && isset( $_POST['delete_season'], $_POST['season_id'] ) )
        ? absint( $_POST['season_id'] ) : 0;
    $delete_row = $delete_season_id
        ? $wpdb->get_row( $wpdb->prepare( "SELECT id, created_by FROM $seasons_table WHERE id = %d", $delete_season_id ) )
        : null;
    if ( $delete_row
         && isset( $_POST['kf_delete_season_nonce'] )
         && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['kf_delete_season_nonce'] ) ), 'kf_delete_season_' . $delete_season_id )
         && ( current_user_can( 'manage_options' ) || (int) $delete_row->created_by === get_current_user_id() ) ) {
        if ($delete_season_id > 0) {
            // Cascading delete: remove all related data in dependency order.
            // (A "placeholder" delete of score_history rows with user_id 0 — across EVERY league —
            // used to run here. The week-scoped delete below is the real one.)
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

    if ( isset( $_POST['kf_dismiss_slash_report'], $_POST['kf_dismiss_slash_nonce'] )
         && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['kf_dismiss_slash_nonce'] ) ), 'kf_dismiss_slash_report' ) ) {
        $kf_done = get_option( 'kf_slash_repair_report', [] );
        if ( is_array( $kf_done ) ) {
            $kf_done['dismissed'] = true;
            update_option( 'kf_slash_repair_report', $kf_done, false );
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
    $cron_report          = get_option( 'kf_cron_last_report', [] );

    // One-time repair of names stored with backslashes (see kf-team-names.php). Finalized weeks keep
    // the scores they were given, so the ones that were affected have to be re-finalized by hand.
    $kf_slash_report = get_option( 'kf_slash_repair_report', [] );
    $kf_slash_weeks  = ( is_array( $kf_slash_report ) && ! empty( $kf_slash_report['finalized_weeks'] ) )
        ? $kf_slash_report['finalized_weeks'] : [];

    ob_start();
    ?>
    <div class="kf-container">
        <?php if ( $kf_slash_weeks && empty( $kf_slash_report['dismissed'] ) ) : ?>
            <div class="kf-card kf-notice-card kf-notice-warn" style="margin-bottom:1.5em;">
                <h2 class="kf-notice-title">Some finished weeks need re-scoring</h2>
                <p class="kf-notice-message">
                    Team names containing an apostrophe were stored with a stray backslash, so a correct pick on
                    one of those teams scored as a loss. The names and picks have been corrected — a snapshot of
                    each week was taken first — but these weeks were already finalized, so their scores still
                    reflect the old comparison. Reverse and finalize each one again to rescore it.
                </p>
                <ul>
                    <?php foreach ( $kf_slash_weeks as $kf_wid => $kf_w ) : ?>
                        <li>
                            <a href="<?php echo esc_url( add_query_arg( 'week_id', (int) $kf_wid, site_url( '/week-summary/' ) ) ); ?>">
                                <?php echo esc_html( $kf_w['season'] . ' — Week ' . $kf_w['week_number'] ); ?>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <form method="POST" style="margin:0;">
                    <?php wp_nonce_field( 'kf_dismiss_slash_report', 'kf_dismiss_slash_nonce' ); ?>
                    <button type="submit" name="kf_dismiss_slash_report" class="kf-button kf-button-secondary">Done — hide this</button>
                </form>
            </div>
        <?php endif; ?>
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

        // human_time_diff() returns an ABSOLUTE difference, so an overdue event used to read
        // "Next run: 12 mins from now" when it was in fact twelve minutes LATE. That hides
        // exactly the failure this panel exists to catch — and it matters more once WP-Cron is
        // disabled, because then a stalled host job is the only thing that can go wrong.
        $cron_next_phrase = '';
        if ( $cron_next_scheduled ) {
            $cron_next_phrase = ( $cron_next_scheduled >= time() )
                ? 'in ~' . human_time_diff( time(), $cron_next_scheduled )
                : 'overdue by ' . human_time_diff( $cron_next_scheduled, time() );
        }
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
            $cron_status_detail = 'Next run ' . esc_html( $cron_next_phrase ) . '.';
        } elseif ( $cron_failures >= 3 ) {
            $cron_status_color  = '#dc2626';
            $cron_status_label  = "🔴 ESPN fetch failing ({$cron_failures} consecutive errors)";
            $cron_status_detail = 'Last run: ' . esc_html( $cron_minutes_ago ) . ' min ago. Check ESPN API or switch to manual mode.';
        } elseif ( $cron_minutes_ago > 60 ) {
            $cron_status_color  = '#d97706';
            $cron_status_label  = "🟡 Last run: {$cron_minutes_ago} min ago";
            $cron_status_detail = 'Score updates may be delayed. Next run ' . esc_html( $cron_next_phrase ) . '.';
        } else {
            $cron_status_label  = "✅ Last scores checked: {$cron_minutes_ago} min ago";
            $cron_status_detail = 'Next run ' . esc_html( $cron_next_phrase ) . '.';
        }
        ?>
        <div style="background:#f8fafc;border:1px solid #e2e8f0;border-left:4px solid <?php echo esc_attr($cron_status_color); ?>;border-radius:6px;padding:10px 16px;margin-bottom:1.5em;font-size:0.9em;">
            <strong style="color:<?php echo esc_attr($cron_status_color); ?>;"><?php echo $cron_status_label; ?></strong>
            <?php if ( $cron_status_detail ): ?>
                <span style="color:#64748b;margin-left:12px;"><?php echo $cron_status_detail; ?></span>
            <?php endif; ?>

            <?php
            // What the last run actually did. "It ran" alone cannot tell you whether ESPN
            // returned nothing, nothing was eligible, or rows were written that had not changed.
            if ( ! empty( $cron_report ) && is_array( $cron_report ) ) :
                $rep_unmatched = isset( $cron_report['unmatched'] ) ? count( (array) $cron_report['unmatched'] ) : 0;
            ?>
                <div style="margin-top:6px;color:#64748b;font-size:0.92em;">
                    <?php if ( ! empty( $cron_report['skipped'] ) ) : ?>
                        Last run: no game in progress, so ESPN was not contacted
                        (<?php echo intval( $cron_report['pending'] ?? 0 ); ?> game(s) tracked).
                    <?php else : ?>
                    Last run checked <strong><?php echo intval( $cron_report['pending'] ?? 0 ); ?></strong> game(s)
                    via <strong><?php echo esc_html( $cron_report['sport'] ?? '?' ); ?></strong>,
                    matched <strong><?php echo intval( $cron_report['fetched'] ?? 0 ); ?></strong> at ESPN,
                    wrote <strong><?php echo intval( $cron_report['updated'] ?? 0 ); ?></strong>.
                    <?php endif; ?>
                    <?php if ( $rep_unmatched > 0 ) : ?>
                        <span style="color:#b45309;"><?php echo intval( $rep_unmatched ); ?> game(s) not found at ESPN.</span>
                    <?php endif; ?>
                </div>
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
                                        <a href="<?php echo esc_url( kf_league_url( site_url('/manage-weeks/'), $s->id ) ); ?>" class="kf-button kf-button-action">Manage Weeks</a>
                                        
                                        <?php if ($s->is_active): ?>
                                            <span class="kf-action-separator">|</span>
                                            <a href="<?php echo esc_url( kf_league_url( site_url('/review-late-submissions/'), $s->id ) ); ?>" class="kf-button kf-button-action">Review Late Picks</a>
                                        <?php endif; ?>
                                        
                                        <span class="kf-action-separator">|</span>
                                        <a href="<?php echo esc_url( kf_league_url( site_url('/season-summary/'), $s->id ) ); ?>" class="kf-button">Summary</a>
                                        <span class="kf-action-separator">|</span>
                                        <a href="<?php echo esc_url( kf_league_url( site_url('/manage-players/'), $s->id ) ); ?>" class="kf-button kf-button-secondary">Players</a>
                                        <span class="kf-action-separator">|</span>
                                        <a href="<?php echo esc_url( kf_league_url( site_url('/edit-season/'), $s->id ) ); ?>" class="kf-button kf-button-secondary">Settings</a>
                                        <span class="kf-action-separator">|</span>
                                        <?php
                                        $toggle_url = wp_nonce_url(add_query_arg(['action' => 'toggle_status', 'season_id' => $s->id]), 'kf_toggle_status_' . $s->id);
                                        $toggle_text = $s->is_active ? 'Archive' : 'Activate';
                                        ?>
                                        <a href="<?php echo esc_url($toggle_url); ?>" class="kf-button kf-button-secondary"><?php echo $toggle_text; ?></a>
                                        <span class="kf-action-separator">|</span>
                                        <?php if ( current_user_can( 'manage_options' ) || (int) $s->created_by === $current_user_id ) : ?>
                                        <form method="POST" onsubmit="return confirm('Are you sure you want to PERMANENTLY delete this season and all related data? This cannot be undone.');" style="display:inline;">
                                            <?php wp_nonce_field('kf_delete_season_' . (int) $s->id, 'kf_delete_season_nonce'); ?>
                                            <input type="hidden" name="season_id" value="<?php echo esc_attr($s->id); ?>">
                                            <button type="submit" name="delete_season" class="kf-button-as-link kf-danger-text">Delete</button>
                                        </form>
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