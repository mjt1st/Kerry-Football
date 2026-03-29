<?php
/**
 * Shortcode handler for the Player Management page.
 * Allows the commissioner to add/remove players from a season, manage their status, and set their display order.
 *
 * @package Kerry_Football
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Renders the player management interface.
 */
function kf_player_management_shortcode() {
    // Security check: Ensure user is a logged-in commissioner.
    if ( ! is_user_logged_in() ) {
        return '<div class="kf-container"><p>You do not have permission to access this page.</p></div>';
    }

    if (session_status() === PHP_SESSION_NONE) { session_start(); }
    global $wpdb;

    $season_id = isset($_SESSION['kf_active_season_id']) ? (int)$_SESSION['kf_active_season_id'] : 0;
    if ( ! $season_id ) {
        return '<div class="kf-container"><h1>Manage Players</h1><p>No season selected. Please choose a season from the main menu to manage players.</p></div>';
    }
    if ( ! kf_can_manage_season( $season_id ) ) {
        return '<div class="kf-container"><p>You do not have permission to access this page.</p></div>';
    }

    // Only site admins and season creators (not co-commissioners) may grant/revoke co-commissioner status.
    $can_grant_commissioner = current_user_can('manage_options') || (bool)$wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->prefix}seasons WHERE id = %d AND created_by = %d",
        $season_id, get_current_user_id()
    ));

    $season_players_table = $wpdb->prefix . 'season_players';
    $seasons_table        = $wpdb->prefix . 'seasons';
    $player_order_table   = $wpdb->prefix . 'season_player_order';
    $users_table          = $wpdb->prefix . 'users';

    $season_name = $wpdb->get_var( $wpdb->prepare( "SELECT name FROM $seasons_table WHERE id = %d", $season_id ) );
    if ( ! $season_name ) {
        return '<div class="kf-container"><p>The selected season could not be found.</p></div>';
    }

    // --- Handle Form Submissions (Add, Update, Remove) ---
    if ( isset( $_POST['kf_add_player'] ) && isset( $_POST['_wpnonce'] ) && wp_verify_nonce( $_POST['_wpnonce'], 'kf_add_player_nonce' ) ) {
        $user_to_add = intval( $_POST['user_id'] );
        $wpdb->insert( $season_players_table,
            [ 'season_id' => $season_id, 'user_id' => $user_to_add, 'status' => 'invited' ],
            [ '%d', '%d', '%s' ]
        );
        $max_order = $wpdb->get_var($wpdb->prepare("SELECT MAX(display_order) FROM $player_order_table WHERE season_id = %d", $season_id));
        $wpdb->insert($player_order_table,
            ['season_id' => $season_id, 'user_id' => $user_to_add, 'display_order' => $max_order + 1],
            ['%d', '%d', '%d']
        );
        // Send invitation email
        if ( function_exists( 'kf_send_player_invite' ) ) {
            kf_send_player_invite( $user_to_add, $season_id );
        }
        echo '<div class="notice notice-success is-dismissible"><p>Player invited. An invitation email has been sent.</p></div>';
    }
    if ( isset( $_POST['kf_update_player_status'] ) && isset( $_POST['_wpnonce'] ) && wp_verify_nonce( $_POST['_wpnonce'], 'kf_update_status_nonce' ) ) {
        $player_entry_id = intval( $_POST['player_entry_id'] );
        $new_status      = sanitize_text_field( $_POST['new_status'] );
        $wpdb->update( $season_players_table, [ 'status' => $new_status ], [ 'id' => $player_entry_id ] );
        echo '<div class="notice notice-success is-dismissible"><p>Player status updated.</p></div>';
    }
    if ( isset( $_POST['kf_remove_player'] ) && isset( $_POST['_wpnonce'] ) && wp_verify_nonce( $_POST['_wpnonce'], 'kf_remove_player_nonce' ) ) {
        $player_entry_id = intval( $_POST['player_entry_id'] );
        $user_id_to_remove = $wpdb->get_var($wpdb->prepare("SELECT user_id FROM $season_players_table WHERE id = %d", $player_entry_id));
        $wpdb->delete( $season_players_table, [ 'id' => $player_entry_id ] );
        if ($user_id_to_remove) {
            $wpdb->delete( $player_order_table, ['season_id' => $season_id, 'user_id' => $user_id_to_remove] );
        }
        echo '<div class="notice notice-success is-dismissible"><p>Player removed from the season.</p></div>';
    }


    // --- Sync Player Order (preserve existing custom order, only add/remove as needed) ---
    $current_player_ids = $wpdb->get_col($wpdb->prepare("SELECT user_id FROM $season_players_table WHERE season_id = %d", $season_id));
    $ordered_player_ids = $wpdb->get_col($wpdb->prepare("SELECT user_id FROM $player_order_table WHERE season_id = %d ORDER BY display_order ASC", $season_id));

    // If no order exists at all, initialize from scratch alphabetically
    if (empty($ordered_player_ids) && !empty($current_player_ids)) {
        $players_to_order = $wpdb->get_results($wpdb->prepare("SELECT sp.user_id FROM $season_players_table sp JOIN $users_table u ON sp.user_id = u.ID WHERE sp.season_id = %d ORDER BY u.display_name ASC", $season_id));
        foreach ($players_to_order as $index => $player) {
            $wpdb->insert($player_order_table,
                ['season_id' => $season_id, 'user_id' => $player->user_id, 'display_order' => $index],
                ['%d', '%d', '%d']
            );
        }
    } else {
        // Remove order entries for players no longer in the season
        $removed = array_diff($ordered_player_ids, $current_player_ids);
        foreach ($removed as $removed_id) {
            $wpdb->delete($player_order_table, ['season_id' => $season_id, 'user_id' => $removed_id]);
        }
        // Add missing players to end of order (preserves existing custom order)
        $missing = array_diff($current_player_ids, $ordered_player_ids);
        if (!empty($missing)) {
            $max_order = (int)$wpdb->get_var($wpdb->prepare("SELECT MAX(display_order) FROM $player_order_table WHERE season_id = %d", $season_id));
            foreach ($missing as $missing_id) {
                $max_order++;
                $wpdb->insert($player_order_table,
                    ['season_id' => $season_id, 'user_id' => $missing_id, 'display_order' => $max_order],
                    ['%d', '%d', '%d']
                );
            }
        }
    }
    
    // --- Fetch Data for Display, grouped by status ---
    $season_players_results = $wpdb->get_results( $wpdb->prepare(
        "SELECT sp.id, sp.user_id, sp.status, sp.is_commissioner, u.display_name
         FROM $season_players_table sp
         JOIN $users_table u ON sp.user_id = u.ID
         LEFT JOIN $player_order_table spo ON sp.user_id = spo.user_id AND sp.season_id = spo.season_id
         WHERE sp.season_id = %d
         ORDER BY spo.display_order ASC, u.display_name ASC",
         $season_id
    ) );
    $players_in_season_ids = wp_list_pluck( $season_players_results, 'user_id' );

    // Split into status groups
    $players_accepted = array_filter( $season_players_results, fn($p) => $p->status === 'accepted' );
    $players_invited  = array_filter( $season_players_results, fn($p) => $p->status === 'invited' );
    $players_declined = array_filter( $season_players_results, fn($p) => $p->status === 'declined' );
    $users_to_add = get_users( [ 'exclude' => $players_in_season_ids, 'orderby' => 'login', 'order' => 'ASC' ] );

    ob_start();
    ?>
    <div class="kf-container">
        <h1>Manage Players</h1>
        <h2 class="kf-page-subtitle"><?php echo esc_html( $season_name ); ?></h2>
        <a href="<?php echo esc_url( site_url( '/commissioner-dashboard/' ) ); ?>">&larr; Back to Commissioner Dashboard</a>

        <div class="kf-manage-players-grid">
            <div class="kf-card">

                <?php // --- ACCEPTED PLAYERS --- ?>
                <h3 class="kf-card-title">&#10003; Accepted
                    <span class="kf-player-count"><?php echo count( $players_accepted ); ?></span>
                </h3>
                <p class="kf-card-subtitle">Drag and drop to set column order on summary pages.</p>
                <div id="kf-sortable-players" class="kf-sortable-list">
                    <?php if ( empty( $players_accepted ) ) : ?>
                        <p style="color:#777;font-style:italic;padding:8px 0;">No accepted players yet.</p>
                    <?php else : ?>
                        <?php foreach ( $players_accepted as $player ) : ?>
                            <div class="kf-player-sort-item" draggable="true" data-user-id="<?php echo esc_attr( $player->user_id ); ?>">
                                <span class="kf-drag-handle">☰</span>
                                <span class="kf-player-name"><?php echo esc_html( $player->display_name ); ?></span>
                                <div class="kf-player-actions">
                                    <form method="POST" style="display:inline-block;">
                                        <?php wp_nonce_field( 'kf_update_status_nonce' ); ?>
                                        <input type="hidden" name="player_entry_id" value="<?php echo esc_attr( $player->id ); ?>">
                                        <select name="new_status">
                                            <option value="accepted" selected>Accepted</option>
                                            <option value="invited">Invited</option>
                                            <option value="declined">Declined</option>
                                        </select>
                                        <button type="submit" name="kf_update_player_status" class="button button-primary">Update</button>
                                    </form>
                                    <form method="POST" style="display:inline-block;" onsubmit="return confirm('Remove this player?');">
                                        <?php wp_nonce_field( 'kf_remove_player_nonce' ); ?>
                                        <input type="hidden" name="player_entry_id" value="<?php echo esc_attr( $player->id ); ?>">
                                        <button type="submit" name="kf_remove_player" class="button button-link-delete">Remove</button>
                                    </form>
                                    <?php if ($can_grant_commissioner && $player->user_id != get_current_user_id()): ?>
                                        <?php if ($player->is_commissioner): ?>
                                            <button class="kf-button kf-button-secondary kf-toggle-commissioner-btn"
                                                    data-user-id="<?php echo esc_attr($player->user_id); ?>"
                                                    data-season-id="<?php echo esc_attr($season_id); ?>"
                                                    data-make-commissioner="false"
                                                    style="font-size:0.82em;background:#fef3c7;color:#92400e;border-color:#f59e0b;"
                                                    title="Revoke co-commissioner access">★ Co-Commissioner</button>
                                        <?php else: ?>
                                            <button class="kf-button kf-button-secondary kf-toggle-commissioner-btn"
                                                    data-user-id="<?php echo esc_attr($player->user_id); ?>"
                                                    data-season-id="<?php echo esc_attr($season_id); ?>"
                                                    data-make-commissioner="true"
                                                    style="font-size:0.82em;"
                                                    title="Grant co-commissioner access">Make Commissioner</button>
                                        <?php endif; ?>
                                    <?php elseif ($player->is_commissioner && $player->user_id != get_current_user_id()): ?>
                                        <span style="font-size:0.82em;color:#92400e;">★ Co-Commissioner</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
                <div class="kf-sortable-actions">
                    <button id="kf-save-player-order" class="button kf-button-action" style="display:none;">Save Player Order</button>
                    <span id="kf-order-status" class="kf-order-status-spinner" style="display:none;">Saving...</span>
                </div>

                <?php // --- PENDING (INVITED) PLAYERS --- ?>
                <h3 class="kf-card-title" style="margin-top:2em;">&#9200; Pending
                    <span class="kf-player-count"><?php echo count( $players_invited ); ?></span>
                </h3>
                <p class="kf-card-subtitle">Invited but not yet accepted. Player will see an Accept/Decline prompt on their dashboard.</p>
                <?php if ( empty( $players_invited ) ) : ?>
                    <p style="color:#777;font-style:italic;padding:8px 0;">No pending invitations.</p>
                <?php else : ?>
                    <?php foreach ( $players_invited as $player ) : ?>
                        <div class="kf-player-sort-item" style="opacity:0.8;">
                            <span class="kf-player-name"><?php echo esc_html( $player->display_name ); ?></span>
                            <div class="kf-player-actions">
                                <form method="POST" style="display:inline-block;">
                                    <?php wp_nonce_field( 'kf_update_status_nonce' ); ?>
                                    <input type="hidden" name="player_entry_id" value="<?php echo esc_attr( $player->id ); ?>">
                                    <select name="new_status">
                                        <option value="invited" selected>Invited</option>
                                        <option value="accepted">Accepted</option>
                                        <option value="declined">Declined</option>
                                    </select>
                                    <button type="submit" name="kf_update_player_status" class="button button-primary">Update</button>
                                </form>
                                <form method="POST" style="display:inline-block;" onsubmit="return confirm('Remove this player?');">
                                    <?php wp_nonce_field( 'kf_remove_player_nonce' ); ?>
                                    <input type="hidden" name="player_entry_id" value="<?php echo esc_attr( $player->id ); ?>">
                                    <button type="submit" name="kf_remove_player" class="button button-link-delete">Remove</button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>

                <?php // --- DECLINED PLAYERS --- ?>
                <?php if ( ! empty( $players_declined ) ) : ?>
                    <h3 class="kf-card-title" style="margin-top:2em;">&#10007; Declined
                        <span class="kf-player-count"><?php echo count( $players_declined ); ?></span>
                    </h3>
                    <?php foreach ( $players_declined as $player ) : ?>
                        <div class="kf-player-sort-item" style="opacity:0.6;">
                            <span class="kf-player-name" style="text-decoration:line-through;"><?php echo esc_html( $player->display_name ); ?></span>
                            <div class="kf-player-actions">
                                <form method="POST" style="display:inline-block;">
                                    <?php wp_nonce_field( 'kf_update_status_nonce' ); ?>
                                    <input type="hidden" name="player_entry_id" value="<?php echo esc_attr( $player->id ); ?>">
                                    <select name="new_status">
                                        <option value="declined" selected>Declined</option>
                                        <option value="invited">Re-Invite</option>
                                        <option value="accepted">Accepted</option>
                                    </select>
                                    <button type="submit" name="kf_update_player_status" class="button button-primary">Update</button>
                                </form>
                                <form method="POST" style="display:inline-block;" onsubmit="return confirm('Remove this player?');">
                                    <?php wp_nonce_field( 'kf_remove_player_nonce' ); ?>
                                    <input type="hidden" name="player_entry_id" value="<?php echo esc_attr( $player->id ); ?>">
                                    <button type="submit" name="kf_remove_player" class="button button-link-delete">Remove</button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>

            </div>

            <div class="kf-card">
                <h3 class="kf-card-title">Add Player to Season</h3>
                <p class="kf-card-subtitle">This list shows all users who are not yet part of this season.</p>
                <table class="wp-list-table widefat fixed striped">
                    <thead><tr><th>Username</th><th>Display Name</th><th>Action</th></tr></thead>
                    <tbody>
                        <?php if ( empty( $users_to_add ) ) : ?>
                            <tr><td colspan="3">All registered users have been added to this season.</td></tr>
                        <?php else : ?>
                            <?php foreach ( $users_to_add as $user ) : ?>
                                <tr>
                                    <td><?php echo esc_html( $user->user_login ); ?></td>
                                    <td><?php echo esc_html( $user->display_name ); ?></td>
                                    <td>
                                        <form method="POST">
                                            <?php wp_nonce_field( 'kf_add_player_nonce' ); ?>
                                            <input type="hidden" name="user_id" value="<?php echo esc_attr( $user->ID ); ?>">
                                            <button type="submit" name="kf_add_player" class="button button-primary">Add Player</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        document.querySelectorAll('.kf-toggle-commissioner-btn').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var userId = this.dataset.userId;
                var seasonId = this.dataset.seasonId;
                var makeCo = this.dataset.makeCommissioner === 'true';
                var label = makeCo ? 'Grant co-commissioner access to this player?' : 'Revoke co-commissioner access from this player?';
                if (!confirm(label)) return;
                var originalText = this.textContent;
                this.textContent = 'Saving...';
                this.disabled = true;
                var self = this;
                var formData = new FormData();
                formData.append('action', 'kf_toggle_co_commissioner');
                formData.append('nonce', kf_ajax_data.nonce);
                formData.append('season_id', seasonId);
                formData.append('target_user_id', userId);
                formData.append('make_commissioner', makeCo ? 'true' : 'false');
                fetch(kf_ajax_data.ajax_url, { method: 'POST', body: formData })
                    .then(function(r) { return r.json(); })
                    .then(function(data) {
                        if (data.success) {
                            window.location.reload();
                        } else {
                            alert('Error: ' + (data.data && data.data.message ? data.data.message : 'Unknown error'));
                            self.textContent = originalText;
                            self.disabled = false;
                        }
                    })
                    .catch(function() {
                        alert('Network error. Please try again.');
                        self.textContent = originalText;
                        self.disabled = false;
                    });
            });
        });
    });
    </script>
    <?php
    return ob_get_clean();
}