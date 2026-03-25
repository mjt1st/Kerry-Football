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
    if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
        return '<div class="kf-container"><p>You do not have permission to access this page.</p></div>';
    }
    
    if (session_status() === PHP_SESSION_NONE) { session_start(); }
    global $wpdb;

    $season_id = $_SESSION['kf_active_season_id'] ?? 0;
    if ( ! $season_id ) {
        return '<div class="kf-container"><h1>Manage Players</h1><p>No season selected. Please choose a season from the main menu to manage players.</p></div>';
    }

    $season_players_table = $wpdb->prefix . 'season_players';
    $seasons_table        = $wpdb->prefix . 'seasons';
    $player_order_table   = $wpdb->prefix . 'season_player_order';
    $users_table          = $wpdb->prefix . 'users';

    $season_name = $wpdb->get_var( $wpdb->prepare( "SELECT name FROM $seasons_table WHERE id = %d", $season_id ) );
    if ( ! $season_name ) {
        return '<div class="kf-container"><p>The selected season could not be found.</p></div>';
    }

    // --- Handle Form Submissions (Add, Update, Remove) ---
    if ( isset( $_POST['kf_add_player'] ) && check_admin_referer( 'kf_add_player_nonce' ) ) {
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
        echo '<div class="notice notice-success is-dismissible"><p>Player added as "invited".</p></div>';
    }
    if ( isset( $_POST['kf_update_player_status'] ) && check_admin_referer( 'kf_update_status_nonce' ) ) {
        $player_entry_id = intval( $_POST['player_entry_id'] );
        $new_status      = sanitize_text_field( $_POST['new_status'] );
        $wpdb->update( $season_players_table, [ 'status' => $new_status ], [ 'id' => $player_entry_id ] );
        echo '<div class="notice notice-success is-dismissible"><p>Player status updated.</p></div>';
    }
    if ( isset( $_POST['kf_remove_player'] ) && check_admin_referer( 'kf_remove_player_nonce' ) ) {
        $player_entry_id = intval( $_POST['player_entry_id'] );
        $user_id_to_remove = $wpdb->get_var($wpdb->prepare("SELECT user_id FROM $season_players_table WHERE id = %d", $player_entry_id));
        $wpdb->delete( $season_players_table, [ 'id' => $player_entry_id ] );
        if ($user_id_to_remove) {
            $wpdb->delete( $player_order_table, ['season_id' => $season_id, 'user_id' => $user_id_to_remove] );
        }
        echo '<div class="notice notice-success is-dismissible"><p>Player removed from the season.</p></div>';
    }


    // --- One-Time Population of Player Order ---
    $current_player_count = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $season_players_table WHERE season_id = %d", $season_id));
    $order_count = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $player_order_table WHERE season_id = %d", $season_id));
    
    if ($current_player_count > 0 && $order_count != $current_player_count) {
        $players_to_order = $wpdb->get_results($wpdb->prepare("SELECT sp.user_id FROM $season_players_table sp JOIN $users_table u ON sp.user_id = u.ID WHERE sp.season_id = %d ORDER BY u.display_name ASC", $season_id));
        
        $wpdb->delete($player_order_table, ['season_id' => $season_id]);
        
        foreach ($players_to_order as $index => $player) {
            $wpdb->insert($player_order_table, 
                ['season_id' => $season_id, 'user_id' => $player->user_id, 'display_order' => $index],
                ['%d', '%d', '%d']
            );
        }
    }
    
    // --- Fetch Data for Display, now using the custom order ---
    $season_players_results = $wpdb->get_results( $wpdb->prepare( 
        "SELECT sp.id, sp.user_id, sp.status, u.display_name 
         FROM $season_players_table sp 
         JOIN $users_table u ON sp.user_id = u.ID 
         LEFT JOIN $player_order_table spo ON sp.user_id = spo.user_id AND sp.season_id = spo.season_id
         WHERE sp.season_id = %d 
         ORDER BY spo.display_order ASC, u.display_name ASC", 
         $season_id 
    ));
    $players_in_season_ids = wp_list_pluck( $season_players_results, 'user_id' );
    $users_to_add = get_users( [ 'exclude' => $players_in_season_ids, 'orderby' => 'login', 'order' => 'ASC' ] );

    ob_start();
    ?>
    <div class="kf-container">
        <h1>Manage Players</h1>
        <h2 class="kf-page-subtitle"><?php echo esc_html( $season_name ); ?></h2>
        <a href="<?php echo esc_url( site_url( '/commissioner-dashboard/' ) ); ?>">&larr; Back to Commissioner Dashboard</a>

        <div class="kf-manage-players-grid">
            <div class="kf-card">
                <h3 class="kf-card-title">Players in Season</h3>
                <p class="kf-card-subtitle">Drag and drop players to change their column order on summary pages.</p>
                
                <div id="kf-sortable-players" class="kf-sortable-list">
                    <?php if ( empty( $season_players_results ) ) : ?>
                        <p>No players have been added to this season yet.</p>
                    <?php else : ?>
                        <?php foreach ( $season_players_results as $player ) : ?>
                            <div class="kf-player-sort-item" draggable="true" data-user-id="<?php echo esc_attr( $player->user_id ); ?>">
                                <span class="kf-drag-handle">☰</span>
                                <span class="kf-player-name"><?php echo esc_html( $player->display_name ); ?></span>
                                <span class="kf-player-status"><?php echo esc_html( ucfirst( $player->status ) ); ?></span>
                                <div class="kf-player-actions">
                                    <form method="POST" style="display: inline-block;">
                                        <?php wp_nonce_field( 'kf_update_status_nonce' ); ?>
                                        <input type="hidden" name="player_entry_id" value="<?php echo esc_attr( $player->id ); ?>">
                                        <select name="new_status">
                                            <option value="invited" <?php selected( $player->status, 'invited' ); ?>>Invited</option>
                                            <option value="accepted" <?php selected( $player->status, 'accepted' ); ?>>Accepted</option>
                                            <option value="declined" <?php selected( $player->status, 'declined' ); ?>>Declined</option>
                                        </select>
                                        <button type="submit" name="kf_update_player_status" class="button button-primary">Update</button>
                                    </form>
                                    <form method="POST" style="display: inline-block;" onsubmit="return confirm('Are you sure you want to remove this player?');">
                                        <?php wp_nonce_field( 'kf_remove_player_nonce' ); ?>
                                        <input type="hidden" name="player_entry_id" value="<?php echo esc_attr( $player->id ); ?>">
                                        <button type="submit" name="kf_remove_player" class="button button-link-delete">Remove</button>
                                    </form>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
                <div class="kf-sortable-actions">
                    <button id="kf-save-player-order" class="button kf-button-action" style="display:none;">Save Player Order</button>
                    <span id="kf-order-status" class="kf-order-status-spinner" style="display:none;">Saving...</span>
                </div>
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
    <?php
    return ob_get_clean();
}