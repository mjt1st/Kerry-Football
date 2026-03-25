<?php
/**
 * Consolidated, intelligent tool for importing weekly player picks and points from a "wide" CSV file.
 *
 * This file replaces the legacy kf-picks-importer.php and kf-points-importer.php.
 * It is specifically designed to parse the master CSV format where each row is a game
 * and player picks/points are in paired columns.
 *
 * It automatically:
 * - Maps team names to matchup_ids.
 * - Handles the special tiebreaker row.
 * - Parses paired player columns (pick/points).
 * - Identifies and flags BPOW picks.
 * - FINAL FIX: Matches users by their "Display Name" instead of "user_login" for convenience and privacy.
 *
 * @package Kerry_Football
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// 1. Add the new admin menu page and remove the old ones.
add_action('admin_menu', function() {
    add_menu_page( 'KF Data Importer', 'KF Data Importer', 'manage_options', 'kf-data-importer', 'kf_render_wide_importer_page', 'dashicons-database-import', 22);
    remove_menu_page('kf-picks-importer');
    remove_menu_page('kf-points-importer');
}, 99);

/**
 * 2. Renders the HTML for the importer page.
 */
function kf_render_wide_importer_page() {
    ?>
    <div class="wrap">
        <h1>Kerry Football: Data Importer</h1>
        <p>This tool imports all player picks and points for a week from a single master CSV file.</p>
        <p>It reads the "wide" format where each row is a game and player names in the header match their WordPress <strong>Display Name</strong>.</p>

        <?php
        if ( 'POST' === $_SERVER['REQUEST_METHOD'] && isset($_POST['kf_data_nonce']) ) {
            if ( check_admin_referer( 'kf_run_data_import', 'kf_data_nonce' ) ) {
                $week_id = isset($_POST['week_id']) ? intval($_POST['week_id']) : 0;
                $file = $_FILES['import_csv'] ?? null;

                if ( !$week_id || empty($file['tmp_name']) ) {
                    echo '<div class="notice notice-error"><p><strong>Error:</strong> Please select a week and choose a file.</p></div>';
                } else {
                    $result_messages = kf_process_wide_import($week_id, $file);
                    echo '<div class="notice notice-info" style="padding: 12px; margin-top:20px;"><h3>Import Results:</h3>' . implode('', $result_messages) . '</div>';
                }
            }
        }
        ?>

        <form method="POST" enctype="multipart/form-data" class="kf-card" style="margin-top: 20px;">
            <?php wp_nonce_field( 'kf_run_data_import', 'kf_data_nonce' ); ?>
            <table class="form-table">
                <tr valign="top">
                    <th scope="row"><label for="week_id">Target Week</label></th>
                    <td>
                        <select id="week_id" name="week_id" required>
                            <option value="">-- Select a Week --</option>
                            <?php 
                            global $wpdb;
                            $weeks = $wpdb->get_results("SELECT id, week_number, season_id FROM {$wpdb->prefix}weeks ORDER BY season_id DESC, week_number DESC");
                            foreach ($weeks as $week) {
                                $season_name = $wpdb->get_var($wpdb->prepare("SELECT name FROM {$wpdb->prefix}seasons WHERE id = %d", $week->season_id));
                                echo '<option value="' . esc_attr($week->id) . '">' . esc_html($season_name) . ' - Week ' . esc_html($week->week_number) . '</option>';
                            }
                            ?>
                        </select>
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row"><label for="import_csv">Master CSV File</label></th>
                    <td><input type="file" id="import_csv" name="import_csv" accept=".csv" required></td>
                </tr>
            </table>
            <?php submit_button('Import Data'); ?>
        </form>
    </div>
    <?php
}

/**
 * 3. Processes the uploaded "wide" format CSV.
 */
function kf_process_wide_import($week_id, $file) {
    global $wpdb;
    $messages = [];
    $picks_table = "{$wpdb->prefix}picks";

    // --- Step A: Pre-computation & Mapping (Database to PHP) ---
    $all_db_matchups = $wpdb->get_results($wpdb->prepare("SELECT id, team_a, team_b, is_tiebreaker FROM {$wpdb->prefix}matchups WHERE week_id = %d", $week_id));
    $regular_matchups_map = [];
    $tiebreaker_matchups_map = [];
    foreach ($all_db_matchups as $m) {
        $key = strtolower(trim($m->team_a)) . '|' . strtolower(trim($m->team_b));
        if ($m->is_tiebreaker) {
            $tiebreaker_matchups_map[$key] = $m->id;
        } else {
            $regular_matchups_map[$key] = $m->id;
        }
    }
    
    // --- FINAL FIX: Map users by Display Name instead of login for convenience ---
    $users_map = [];
    $all_user_roles = wp_roles()->get_names();
    $users_query = new WP_User_Query([
        'role__in' => array_keys($all_user_roles),
        'fields' => ['ID', 'display_name'] // Fetch display_name
    ]);
    foreach ($users_query->get_results() as $user) {
        if (!empty($user->display_name)) {
            // Use the display_name as the key for the map
            $users_map[strtolower($user->display_name)] = $user->ID;
        }
    }

    $inserted_count = 0;
    $updated_count = 0;
    $skipped_rows = 0;

    if (($handle = fopen($file['tmp_name'], "r")) !== FALSE) {
        // --- Step B: Header Parsing (CSV to PHP) ---
        $header = fgetcsv($handle);
        $time_col_idx = array_search('Time', $header);
        $home_col_idx = array_search('Home', $header);
        $away_col_idx = array_search('Away', $header);
        $winner_col_idx = array_search('Winner', $header);

        if ($home_col_idx === false || $away_col_idx === false || $time_col_idx === false) {
            return ["<p>❌ **Error:** Could not find 'Time', 'Home', and 'Away' columns in the CSV header.</p>"];
        }
        
        $player_columns = [];
        $processed_players = [];
        $unmapped_players = [];

        for ($i = $winner_col_idx + 1; $i < count($header); $i += 2) {
            $display_name = strtolower(trim($header[$i]));
            if (empty($display_name)) continue;
            
            if (!isset($users_map[$display_name])) {
                if (!in_array($header[$i], $unmapped_players)) {
                    $unmapped_players[] = $header[$i];
                }
                continue;
            }

            $user_id = $users_map[$display_name];
            $is_bpow = isset($processed_players[$user_id]);

            $player_columns[] = [
                'name' => $header[$i],
                'user_id' => $user_id,
                'pick_col' => $i,
                'points_col' => $i + 1,
                'is_bpow' => $is_bpow ? 1 : 0,
            ];
            $processed_players[$user_id] = true;
        }

        if (!empty($player_columns)) {
            $messages[] = '<p><strong>User Mapping Found:</strong> Successfully mapped the following players from the CSV header: ' . implode(', ', array_column($player_columns, 'name')) . '</p>';
        }
        if (!empty($unmapped_players)) {
            $messages[] = '<p><strong>⚠️ User Mapping Failed:</strong> Could not find a WordPress user with the Display Name of: <strong>' . implode(', ', $unmapped_players) . '</strong>. Please check that the names in the CSV header exactly match the Display Names in WordPress. Picks for these users were not imported.</p>';
        }

        // --- Step C: Row Processing (CSV to Database) ---
        while (($data = fgetcsv($handle)) !== FALSE) {
            if (empty(array_filter($data))) continue;

            $home_team = trim($data[$home_col_idx]);
            $away_team = trim($data[$away_col_idx]);
            $time_val = trim($data[$time_col_idx]);
            $lookup_key = strtolower($home_team) . '|' . strtolower($away_team);

            $is_tiebreaker_row = (strtolower($time_val) === 'tie');
            $matchup_id = $is_tiebreaker_row ? ($tiebreaker_matchups_map[$lookup_key] ?? 0) : ($regular_matchups_map[$lookup_key] ?? 0);

            if (!$matchup_id) {
                $skipped_rows++;
                continue;
            }
            
            foreach ($player_columns as $p_col) {
                $pick = '';
                $points = 0;
                
                if ($is_tiebreaker_row) {
                    $pick = trim($data[$p_col['points_col']]);
                    $points = 0;
                } else {
                    $pick = trim($data[$p_col['pick_col']]);
                    $points = intval($data[$p_col['points_col']]);
                }
                
                if ($pick === '') continue;

                $pick_data = [
                    'user_id'       => $p_col['user_id'],
                    'week_id'       => $week_id,
                    'matchup_id'    => $matchup_id,
                    'pick'          => $pick,
                    'point_value'   => $points,
                    'is_bpow'       => $p_col['is_bpow'],
                ];
                
                $existing_id = $wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM $picks_table WHERE user_id = %d AND week_id = %d AND matchup_id = %d AND is_bpow = %d",
                    $pick_data['user_id'], $pick_data['week_id'], $pick_data['matchup_id'], $pick_data['is_bpow']
                ));

                if ($existing_id) {
                    $wpdb->update($picks_table, $pick_data, ['id' => $existing_id]);
                    $updated_count++;
                } else {
                    $wpdb->insert($picks_table, $pick_data);
                    $inserted_count++;
                }
            }
        }
        fclose($handle);
    }

    $messages[] = "<p>✅ Import complete. Inserted {$inserted_count} new picks and updated {$updated_count} existing picks.</p>";
    if ($skipped_rows > 0) {
        $messages[] = "<p>⚠️ Skipped {$skipped_rows} game rows because a matching game could not be found in the database for this week.</p>";
    }
    return $messages;
}