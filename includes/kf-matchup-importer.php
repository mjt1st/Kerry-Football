<?php
/**
 * A dedicated tool for importing weekly matchups from a CSV file.
 * This version contains a corrected nonce implementation and a more robust tiebreaker detection logic.
 *
 * @package Kerry_Football
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// 1. Add the new admin menu page for this specific tool.
add_action('admin_menu', function() {
    add_menu_page(
        'KF Matchup Importer',      // Page Title
        'KF Matchup Importer',      // Menu Title
        'manage_options',           // Capability
        'kf-matchup-importer',      // Menu Slug
        'kf_render_matchup_importer_page', // Function to render the page
        'dashicons-media-spreadsheet', // Icon
        21                          // Position
    );
});

/**
 * 2. Renders the HTML for the matchup importer admin page.
 */
function kf_render_matchup_importer_page() {
    ?>
    <div class="wrap">
        <h1>Kerry Football: Weekly Matchup Importer</h1>
        <p>This tool will create a new week and import all of its matchups from a single CSV file.</p>
        <p><strong>Required CSV Format:</strong> <code>Game, Time, Away, Home, Winner</code>. The tiebreaker game must appear twice: once for the winner, and once with "TIE" in the Time column and the total points in the Winner column.</p>

        <?php
        // Display results after a form submission.
        if ( 'POST' === $_SERVER['REQUEST_METHOD'] && isset($_POST['_wpnonce']) ) {
            if ( wp_verify_nonce( $_POST['_wpnonce'], 'kf_run_matchup_import' ) ) {
                $season_id = isset($_POST['season_id']) ? intval($_POST['season_id']) : 0;
                $week_num = isset($_POST['week_number']) ? intval($_POST['week_number']) : 0;
                $file = $_FILES['matchup_csv'] ?? null;

                if ( !$season_id || !$week_num || empty($file['tmp_name']) ) {
                    echo '<div class="notice notice-error"><p><strong>Error:</strong> Please select a season, enter a week number, and choose a file.</p></div>';
                } else {
                    $result_messages = kf_process_matchup_import($season_id, $week_num, $file);
                    echo '<div class="notice notice-info" style="padding: 12px; margin-top:20px;"><h3>Import Results:</h3>' . implode('', $result_messages) . '</div>';
                }
            } else {
                echo '<div class="notice notice-error"><p><strong>Error:</strong> Security check failed. Please try submitting the form again.</p></div>';
            }
        }
        ?>

        <form method="POST" enctype="multipart/form-data" style="margin-top: 20px; border: 1px solid #ccc; padding: 20px; background: #fff;">
            <?php wp_nonce_field( 'kf_run_matchup_import' ); ?>
            <table class="form-table">
                <tr valign="top">
                    <th scope="row"><label for="season_id">Target Season</label></th>
                    <td>
                        <?php
                        global $wpdb;
                        $seasons = $wpdb->get_results( "SELECT id, name FROM {$wpdb->prefix}seasons ORDER BY name ASC" );
                        if ( $seasons ) {
                            echo '<select id="season_id" name="season_id" required>';
                            echo '<option value="">-- Select a Season --</option>';
                            foreach ( $seasons as $season ) {
                                echo '<option value="' . esc_attr( $season->id ) . '">' . esc_html( $season->name ) . '</option>';
                            }
                            echo '</select>';
                        } else {
                            echo 'No seasons found. Please create a season first.';
                        }
                        ?>
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row"><label for="week_number">Week Number</label></th>
                    <td><input type="number" id="week_number" name="week_number" min="1" max="20" required></td>
                </tr>
                <tr valign="top">
                    <th scope="row"><label for="matchup_csv">Matchup CSV File</label></th>
                    <td><input type="file" id="matchup_csv" name="matchup_csv" accept=".csv" required></td>
                </tr>
            </table>
            <?php submit_button('Import Matchups'); ?>
        </form>
    </div>
    <?php
}

/**
 * 3. Processes the uploaded matchup CSV file.
 *
 * This function has been updated to use a more robust method for identifying the tiebreaker game.
 * Instead of checking if the 'winner' column is numeric, it now checks if the 'Time' column
 * explicitly contains the word "TIE".
 */
function kf_process_matchup_import($season_id, $week_num, $file) {
    global $wpdb;
    $messages = [];

    // Check if this week already exists for this season to prevent duplicates.
    $existing_week = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}weeks WHERE season_id = %d AND week_number = %d", $season_id, $week_num));
    if ($existing_week) {
        $messages[] = "<p>❌ <strong>Error:</strong> Week {$week_num} already exists for this season. Please delete it before re-importing.</p>";
        return $messages;
    }

    // Create the new week record.
    $wpdb->insert("{$wpdb->prefix}weeks", ['season_id' => $season_id, 'week_number' => $week_num, 'status' => 'draft']);
    $week_id = $wpdb->insert_id;
    if (!$week_id) {
        $messages[] = "<p>❌ <strong>Database Error:</strong> Could not create the new week record. Import aborted.</p>";
        return $messages;
    }
    $messages[] = "<p>✅ Created Week #{$week_num} with ID {$week_id}.</p>";


    // Process the CSV file line-by-line using the reliable fgetcsv function.
    $matchup_count = 0;
    if (($handle = fopen($file['tmp_name'], "r")) !== FALSE) {
        fgetcsv($handle); // Skip header row.

        while (($data = fgetcsv($handle)) !== FALSE) {
            // Ensure the row has enough columns to prevent errors.
            if (count($data) < 5) continue;

            // Define columns by index for clarity. Convention: team_a is Home, team_b is Away.
            $time_column   = trim($data[1]);
            $away_team     = trim($data[2]); // Becomes team_b
            $home_team     = trim($data[3]); // Becomes team_a
            $result_column = trim($data[4]);

            // Skip rows with missing team names.
            if (empty($home_team) || empty($away_team)) continue;
            
            // ====================================================================================
            // ROBUSTNESS FIX: Identify the tiebreaker row by checking for "TIE" in the 'Time'
            // column instead of checking if the result is numeric. This is more explicit and
            // avoids potential errors if a team name were, for example, "76ers".
            // ====================================================================================
            $is_tiebreaker_flag = (strtoupper($time_column) === 'TIE') ? 1 : 0;
            
            $wpdb->insert("{$wpdb->prefix}matchups", [
                'week_id'       => $week_id,
                'team_a'        => $home_team,
                'team_b'        => $away_team,
                'result'        => $result_column,
                'is_tiebreaker' => $is_tiebreaker_flag,
            ]);
            $matchup_count++;
        }
        fclose($handle);
    }

    $messages[] = "<p>✅ Successfully imported {$matchup_count} matchup records for Week {$week_num}.</p>";
    return $messages;
}