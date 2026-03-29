<?php
/**
 * Kerry Football — Site Admin Dashboard
 *
 * Commissioner-only page providing user management (block/unblock,
 * password reset) and a league health overview across all seasons.
 *
 * Shortcode: [kf_admin_dashboard]
 *
 * @package Kerry_Football
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

function kf_admin_dashboard_shortcode() {
    if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
        return '<div class="kf-container"><p>You do not have access to this page.</p></div>';
    }

    global $wpdb;

    // --- Handle inline POST actions (block/unblock, password reset) ---
    $action_message = '';
    if ( isset( $_POST['kf_admin_action'], $_POST['kf_admin_action_nonce'] )
        && wp_verify_nonce( $_POST['kf_admin_action_nonce'], 'kf_admin_action' )
    ) {
        $target_id = intval( $_POST['target_user_id'] ?? 0 );
        $action    = sanitize_key( $_POST['kf_admin_action'] );

        if ( $target_id > 0 ) {
            if ( $action === 'block' ) {
                update_user_meta( $target_id, 'kf_account_blocked', 1 );
                $u = get_userdata( $target_id );
                $action_message = '<div class="notice notice-warning is-dismissible"><p>' . esc_html( $u->display_name ) . ' has been blocked from logging in.</p></div>';
            } elseif ( $action === 'unblock' ) {
                delete_user_meta( $target_id, 'kf_account_blocked' );
                $u = get_userdata( $target_id );
                $action_message = '<div class="notice notice-success is-dismissible"><p>' . esc_html( $u->display_name ) . '\'s account has been unblocked.</p></div>';
            } elseif ( $action === 'reset_password' ) {
                $u = get_userdata( $target_id );
                if ( $u ) {
                    $key        = get_password_reset_key( $u );
                    $reset_link = network_site_url( 'wp-login.php?action=rp&key=' . $key . '&login=' . rawurlencode( $u->user_login ), 'login' );
                    $subject    = 'Password Reset — ' . get_bloginfo( 'name' );
                    $body       = "<p>Hi {$u->display_name},</p>"
                                . "<p>A password reset was requested for your account. Click the link below to set a new password:</p>"
                                . "<p><a href=\"{$reset_link}\">{$reset_link}</a></p>"
                                . "<p>This link expires in 24 hours. If you didn't request this, you can ignore this email.</p>";
                    $headers    = [ 'Content-Type: text/html; charset=UTF-8' ];
                    wp_mail( $u->user_email, $subject, $body, $headers );
                    $action_message = '<div class="notice notice-success is-dismissible"><p>Password reset email sent to ' . esc_html( $u->display_name ) . ' (' . esc_html( $u->user_email ) . ').</p></div>';
                }
            }
        }
    }

    // --- Fetch all users with league data ---
    $all_users = get_users( [ 'orderby' => 'display_name', 'order' => 'ASC' ] );

    // Season counts per user
    $season_counts = $wpdb->get_results(
        "SELECT user_id, COUNT(*) as season_count
         FROM {$wpdb->prefix}season_players
         WHERE status = 'accepted'
         GROUP BY user_id",
        OBJECT_K
    );

    // Pending invitations per user
    $pending_counts = $wpdb->get_results(
        "SELECT user_id, COUNT(*) as pending_count
         FROM {$wpdb->prefix}season_players
         WHERE status = 'invited'
         GROUP BY user_id",
        OBJECT_K
    );

    // Last pick submission per user (proxy for last activity)
    $last_activity = $wpdb->get_results(
        "SELECT user_id, MAX(submitted_at) as last_pick
         FROM {$wpdb->prefix}picks
         GROUP BY user_id",
        OBJECT_K
    );

    // --- Stats ---
    $total_users    = count( $all_users );
    $blocked_count  = count( get_users( [ 'meta_key' => 'kf_account_blocked', 'meta_value' => '1' ] ) );
    $active_seasons = $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}seasons WHERE is_active = 1" );
    $open_weeks     = $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}weeks WHERE status = 'published'" );

    // --- Active season health ---
    $seasons = $wpdb->get_results(
        "SELECT * FROM {$wpdb->prefix}seasons ORDER BY is_active DESC, id DESC"
    );

    ob_start();
    ?>
    <div class="kf-container">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1em;">
            <h1>Site Admin Dashboard</h1>
            <a href="<?php echo esc_url( site_url( '/commissioner-dashboard/' ) ); ?>" class="kf-button kf-button-secondary">&larr; Commissioner Dashboard</a>
        </div>

        <?php echo $action_message; ?>

        <!-- ===== STATS ROW ===== -->
        <div class="kf-admin-stats-row">
            <div class="kf-admin-stat-card">
                <span class="kf-admin-stat-number"><?php echo $total_users; ?></span>
                <span class="kf-admin-stat-label">Total Users</span>
            </div>
            <div class="kf-admin-stat-card <?php echo $blocked_count > 0 ? 'kf-stat-warning' : ''; ?>">
                <span class="kf-admin-stat-number"><?php echo $blocked_count; ?></span>
                <span class="kf-admin-stat-label">Blocked</span>
            </div>
            <div class="kf-admin-stat-card">
                <span class="kf-admin-stat-number"><?php echo $active_seasons; ?></span>
                <span class="kf-admin-stat-label">Active Seasons</span>
            </div>
            <div class="kf-admin-stat-card <?php echo $open_weeks > 0 ? 'kf-stat-highlight' : ''; ?>">
                <span class="kf-admin-stat-number"><?php echo $open_weeks; ?></span>
                <span class="kf-admin-stat-label">Open Weeks</span>
            </div>
        </div>

        <!-- ===== USER MANAGEMENT ===== -->
        <div class="kf-card" style="margin-top:2em;">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1em;">
                <h2 style="margin:0;border:none;padding:0;">User Management</h2>
                <input type="text" id="kf-user-search" placeholder="Filter by name or email&hellip;"
                       style="max-width:220px;padding:6px 10px;border:1px solid #d1d5db;border-radius:6px;">
            </div>

            <div style="overflow-x:auto;">
                <table class="kf-table" id="kf-user-table">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Email</th>
                            <th>Username</th>
                            <th>Role</th>
                            <th>Registered</th>
                            <th>Seasons</th>
                            <th>Last Pick</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $all_users as $u ) :
                            $is_blocked      = (bool) get_user_meta( $u->ID, 'kf_account_blocked', true );
                            $is_commissioner = in_array( 'administrator', (array) $u->roles ) || user_can( $u->ID, 'manage_options' );
                            $season_ct       = $season_counts[ $u->ID ]->season_count ?? 0;
                            $pending_ct      = $pending_counts[ $u->ID ]->pending_count ?? 0;
                            $last_pick       = $last_activity[ $u->ID ]->last_pick ?? null;
                            $registered      = date_i18n( 'M j, Y', strtotime( $u->user_registered ) );
                            $last_pick_fmt   = $last_pick ? date_i18n( 'M j, Y', strtotime( $last_pick ) ) : '—';
                        ?>
                        <tr class="kf-user-row <?php echo $is_blocked ? 'kf-row-blocked' : ''; ?>"
                            data-name="<?php echo esc_attr( strtolower( $u->display_name ) ); ?>"
                            data-email="<?php echo esc_attr( strtolower( $u->user_email ) ); ?>">
                            <td>
                                <strong><?php echo esc_html( $u->display_name ); ?></strong>
                                <?php if ( $is_commissioner ) : ?>
                                    <span class="kf-admin-badge kf-badge-commissioner">Commissioner</span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo esc_html( $u->user_email ); ?></td>
                            <td style="color:#6b7280;font-size:0.9em;"><?php echo esc_html( $u->user_login ); ?></td>
                            <td>
                                <?php
                                $roles = (array) $u->roles;
                                $roles = array_filter( $roles, fn($r) => ! in_array( $r, ['administrator','subscriber'] ) );
                                echo esc_html( $roles ? implode( ', ', array_map( 'ucfirst', $roles ) ) : ucfirst( implode( ', ', (array) $u->roles ) ) );
                                ?>
                            </td>
                            <td style="white-space:nowrap;font-size:0.9em;"><?php echo $registered; ?></td>
                            <td style="text-align:center;">
                                <?php if ( $season_ct > 0 ) : ?>
                                    <span title="<?php echo $season_ct; ?> accepted"><?php echo $season_ct; ?></span>
                                <?php endif; ?>
                                <?php if ( $pending_ct > 0 ) : ?>
                                    <span class="kf-admin-badge" style="background:#fef3c7;color:#92400e;" title="<?php echo $pending_ct; ?> pending"><?php echo $pending_ct; ?> pending</span>
                                <?php endif; ?>
                                <?php if ( $season_ct === 0 && $pending_ct === 0 ) echo '—'; ?>
                            </td>
                            <td style="font-size:0.9em;white-space:nowrap;"><?php echo $last_pick_fmt; ?></td>
                            <td>
                                <?php if ( $is_blocked ) : ?>
                                    <span class="kf-admin-badge kf-badge-blocked">Blocked</span>
                                <?php else : ?>
                                    <span class="kf-admin-badge kf-badge-active">Active</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div style="display:flex;gap:6px;flex-wrap:wrap;align-items:center;">
                                    <!-- Password Reset -->
                                    <form method="POST" onsubmit="return confirm('Send a password reset email to <?php echo esc_js( $u->display_name ); ?>?');">
                                        <?php wp_nonce_field( 'kf_admin_action', 'kf_admin_action_nonce' ); ?>
                                        <input type="hidden" name="target_user_id" value="<?php echo esc_attr( $u->ID ); ?>">
                                        <button type="submit" name="kf_admin_action" value="reset_password"
                                                class="kf-button kf-button-secondary" style="font-size:0.8em;padding:4px 10px;">
                                            &#128274; Reset Password
                                        </button>
                                    </form>

                                    <!-- Block / Unblock (don't allow blocking yourself) -->
                                    <?php if ( $u->ID !== get_current_user_id() ) : ?>
                                        <?php if ( $is_blocked ) : ?>
                                            <form method="POST" onsubmit="return confirm('Unblock <?php echo esc_js( $u->display_name ); ?>?');">
                                                <?php wp_nonce_field( 'kf_admin_action', 'kf_admin_action_nonce' ); ?>
                                                <input type="hidden" name="target_user_id" value="<?php echo esc_attr( $u->ID ); ?>">
                                                <button type="submit" name="kf_admin_action" value="unblock"
                                                        class="kf-button" style="font-size:0.8em;padding:4px 10px;background:#16a34a;">
                                                    &#10003; Unblock
                                                </button>
                                            </form>
                                        <?php else : ?>
                                            <form method="POST" onsubmit="return confirm('Block <?php echo esc_js( $u->display_name ); ?>? They will not be able to log in.');">
                                                <?php wp_nonce_field( 'kf_admin_action', 'kf_admin_action_nonce' ); ?>
                                                <input type="hidden" name="target_user_id" value="<?php echo esc_attr( $u->ID ); ?>">
                                                <button type="submit" name="kf_admin_action" value="block"
                                                        class="kf-button" style="font-size:0.8em;padding:4px 10px;background:#dc2626;color:#fff;">
                                                    &#128683; Block
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                    <?php else : ?>
                                        <span style="font-size:0.8em;color:#9ca3af;">(you)</span>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- ===== LEAGUE HEALTH ===== -->
        <div style="margin-top:2.5em;">
            <h2 style="margin-bottom:1em;">League Health</h2>

            <?php if ( empty( $seasons ) ) : ?>
                <div class="kf-card"><p>No seasons found.</p></div>
            <?php else : ?>
                <?php foreach ( $seasons as $season ) :
                    // Player counts by status
                    $player_stats = $wpdb->get_results( $wpdb->prepare(
                        "SELECT status, COUNT(*) as cnt
                         FROM {$wpdb->prefix}season_players
                         WHERE season_id = %d GROUP BY status",
                        $season->id
                    ), OBJECT_K );
                    $accepted_ct = $player_stats['accepted']->cnt ?? 0;
                    $invited_ct  = $player_stats['invited']->cnt ?? 0;
                    $declined_ct = $player_stats['declined']->cnt ?? 0;

                    // Week stats
                    $week_stats = $wpdb->get_results( $wpdb->prepare(
                        "SELECT status, COUNT(*) as cnt FROM {$wpdb->prefix}weeks WHERE season_id = %d GROUP BY status",
                        $season->id
                    ), OBJECT_K );
                    $draft_ct     = $week_stats['draft']->cnt ?? 0;
                    $published_ct = $week_stats['published']->cnt ?? 0;
                    $finalized_ct = $week_stats['finalized']->cnt ?? 0;

                    // For each open week, count picks submitted vs. expected
                    $open_weeks_data = $wpdb->get_results( $wpdb->prepare(
                        "SELECT id, week_number FROM {$wpdb->prefix}weeks WHERE season_id = %d AND status = 'published' ORDER BY week_number ASC",
                        $season->id
                    ) );
                ?>
                <div class="kf-card kf-health-card" style="margin-bottom:1.5em;">
                    <div class="kf-health-card-header">
                        <div>
                            <h3 style="margin:0;border:none;padding:0;"><?php echo esc_html( $season->name ); ?></h3>
                        </div>
                        <span class="<?php echo $season->is_active ? 'kf-status-active' : 'kf-status-archived'; ?>">
                            <?php echo $season->is_active ? 'Active' : 'Archived'; ?>
                        </span>
                    </div>

                    <div class="kf-health-grid">
                        <!-- Players -->
                        <div class="kf-health-block">
                            <div class="kf-health-block-title">Players</div>
                            <div class="kf-health-block-stats">
                                <span class="kf-health-stat"><strong><?php echo $accepted_ct; ?></strong> accepted</span>
                                <?php if ( $invited_ct > 0 ) : ?>
                                    <span class="kf-health-stat kf-health-warn"><strong><?php echo $invited_ct; ?></strong> pending</span>
                                <?php endif; ?>
                                <?php if ( $declined_ct > 0 ) : ?>
                                    <span class="kf-health-stat" style="color:#9ca3af;"><strong><?php echo $declined_ct; ?></strong> declined</span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Weeks -->
                        <div class="kf-health-block">
                            <div class="kf-health-block-title">Weeks</div>
                            <div class="kf-health-block-stats">
                                <?php if ( $finalized_ct > 0 ) : ?>
                                    <span class="kf-health-stat"><strong><?php echo $finalized_ct; ?></strong> finalized</span>
                                <?php endif; ?>
                                <?php if ( $published_ct > 0 ) : ?>
                                    <span class="kf-health-stat kf-health-open"><strong><?php echo $published_ct; ?></strong> open</span>
                                <?php endif; ?>
                                <?php if ( $draft_ct > 0 ) : ?>
                                    <span class="kf-health-stat" style="color:#9ca3af;"><strong><?php echo $draft_ct; ?></strong> draft</span>
                                <?php endif; ?>
                                <?php if ( $finalized_ct + $published_ct + $draft_ct === 0 ) : ?>
                                    <span style="color:#9ca3af;">No weeks yet</span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Open Week Pick Status -->
                        <?php foreach ( $open_weeks_data as $ow ) :
                            $submitted = $wpdb->get_var( $wpdb->prepare(
                                "SELECT COUNT(DISTINCT user_id) FROM {$wpdb->prefix}picks WHERE week_id = %d",
                                $ow->id
                            ) );
                            $missing_players = $wpdb->get_results( $wpdb->prepare(
                                "SELECT u.display_name FROM {$wpdb->prefix}users u
                                 JOIN {$wpdb->prefix}season_players sp ON u.ID = sp.user_id
                                 WHERE sp.season_id = %d AND sp.status = 'accepted'
                                 AND u.ID NOT IN (SELECT DISTINCT user_id FROM {$wpdb->prefix}picks WHERE week_id = %d)",
                                $season->id, $ow->id
                            ) );
                        ?>
                        <div class="kf-health-block">
                            <div class="kf-health-block-title">Week <?php echo $ow->week_number; ?> Picks</div>
                            <div class="kf-health-block-stats">
                                <span class="kf-health-stat <?php echo empty($missing_players) ? '' : 'kf-health-warn'; ?>">
                                    <strong><?php echo $submitted; ?> / <?php echo $accepted_ct; ?></strong> submitted
                                </span>
                                <?php if ( ! empty( $missing_players ) ) : ?>
                                    <div style="margin-top:4px;font-size:0.82em;color:#b45309;">
                                        Missing: <?php echo esc_html( implode( ', ', array_column( $missing_players, 'display_name' ) ) ); ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <script>
    // Live search/filter for the user table
    document.getElementById('kf-user-search').addEventListener('input', function () {
        const q = this.value.toLowerCase();
        document.querySelectorAll('#kf-user-table .kf-user-row').forEach(function (row) {
            const match = row.dataset.name.includes(q) || row.dataset.email.includes(q);
            row.style.display = match ? '' : 'none';
        });
    });
    </script>
    <?php
    return ob_get_clean();
}
