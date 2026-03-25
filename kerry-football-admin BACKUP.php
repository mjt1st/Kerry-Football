<?php
/*
Plugin Name: Kerry Football Admin
Description: Frontend UI for Commissioner to create seasons.
Version: 1.1
Author: You
*/

function kf_register_shortcodes() {
    add_shortcode('kf_commissioner_dashboard', 'kf_commissioner_dashboard_shortcode');
add_shortcode('kf_season_setup', 'kf_season_setup_shortcode');
}
add_action('init', 'kf_register_shortcodes');

function kf_season_setup_shortcode() {

    if (!is_user_logged_in()) {
        return '<p>You must be logged in to view this page.</p>';
    }

    $user = wp_get_current_user();
    if (!in_array('commissioner', (array) $user->roles)) {
        return '<p>You do not have access to this page.</p>';
    }

    ob_start();

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['kf_season_submit'])) {
    echo '<p>✅ Form submitted</p>';

    if (!isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'kf_create_season')) {
        echo '<div class="notice notice-error"><p>❌ Security check failed.</p></div>';
    } else {
        echo '<p>✅ Nonce verified</p>';

        global $wpdb;

        $name = sanitize_text_field($_POST['season_name']);
        $num_weeks = intval($_POST['num_weeks']);
        $weekly_points = intval($_POST['weekly_point_total']);
        $matchup_count = intval($_POST['default_matchup_count']);
        $point_values = sanitize_text_field($_POST['default_point_values']);
        $mwow_bonus = intval($_POST['mwow_bonus']);
        $dd_max = intval($_POST['dd_max']);
        $dd_week = intval($_POST['dd_start_week']);

        $table = $wpdb->prefix . 'seasons';
        echo "<p>📦 Inserting into table: $table</p>";

        $result = $wpdb->insert($table, [
            'name' => $name,
            'num_weeks' => $num_weeks,
            'weekly_point_total' => $weekly_points,
            'default_matchup_count' => $matchup_count,
            'default_point_values' => $point_values,
            'mwow_bonus_points' => $mwow_bonus,
            'dd_max_uses' => $dd_max,
            'dd_enabled_week' => $dd_week,
            'is_active' => 1
        ]);

        if ($result === false) {
            echo '<div class="notice notice-error"><p>❌ Insert failed: ' . esc_html($wpdb->last_error) . '</p></div>';
        } else {
            echo '<div class="notice notice-success"><p>✅ Season created successfully! Insert ID: ' . $wpdb->insert_id . '</p></div>';
        }
    }
}

    ?>
    <div class="kf-commissioner-dashboard">
        <h2>Season Setup</h2>
        <form method="POST">
            <?php wp_nonce_field('kf_create_season'); ?>
            <p><label>Season Name<br><input type="text" name="season_name" required></label></p>
            <p><label>Number of Weeks<br><input type="number" name="num_weeks" required></label></p>
            <p><label>Weekly Point Total<br><input type="number" name="weekly_point_total" required></label></p>
            <p><label>Default Matchup Count<br><input type="number" name="default_matchup_count" required></label></p>
            <p><label>Default Point Values (comma-separated)<br><input type="text" name="default_point_values" required placeholder="1,2,3,...15"></label></p>
            <p><label>MWOW Bonus Points<br><input type="number" name="mwow_bonus" required></label></p>
            <p><label>Max Double Dare Uses<br><input type="number" name="dd_max" value="4"></label></p>
            <p><label>DD Starts After Week<br><input type="number" name="dd_start_week" value="8"></label></p>
            <p><button type="submit" name="kf_season_submit">Create Season</button></p>
        </form>
    </div>
    <?php
    return ob_get_clean();
}
add_shortcode('kf_week_setup', 'kf_week_setup_form');


function kf_week_setup_form() {
    if (!is_user_logged_in()) return '<p>You must be logged in.</p>';

    $user = wp_get_current_user();
    if (!in_array('commissioner', (array)$user->roles)) {
        return '<p>You do not have access to this page.</p>';
    }

    global $wpdb;

    // Handle form submission
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['kf_week_submit'])) {
        if (!wp_verify_nonce($_POST['_wpnonce'], 'kf_create_week')) {
            return '<p>Security check failed.</p>';
        }

        $season_id = intval($_POST['season_id']);
        $week_number = intval($_POST['week_number']);
        $point_values = sanitize_text_field($_POST['point_values']);
        $deadline = sanitize_text_field($_POST['deadline']);
        $tiebreaker_index = intval($_POST['tiebreaker']);

        // Insert the week
        $wpdb->insert($wpdb->prefix . 'weeks', [
            'season_id' => $season_id,
            'week_number' => $week_number,
            'point_values' => $point_values,
            'submission_deadline' => $deadline,
            'is_locked' => 0
        ]);

        $week_id = $wpdb->insert_id;

        // Insert matchups
        foreach ($_POST['team_a'] as $i => $teamA) {
            $teamB = sanitize_text_field($_POST['team_b'][$i]);
            $teamA = sanitize_text_field($teamA);
            $wpdb->insert($wpdb->prefix . 'matchups', [
                'week_id' => $week_id,
                'team_a' => $teamA,
                'team_b' => $teamB,
                'result' => 'undecided'
            ]);

            if ($i == $tiebreaker_index) {
                $tiebreaker_id = $wpdb->insert_id;
                $wpdb->update($wpdb->prefix . 'weeks', ['tiebreaker_matchup_id' => $tiebreaker_id], ['id' => $week_id]);
            }
        }

        return '<div class="notice notice-success"><p>Week created successfully!</p></div>';
    }

    // Get active seasons
    $seasons = $wpdb->get_results("SELECT id, name FROM {$wpdb->prefix}seasons WHERE is_active = 1");
// Pre-fill default week number
$default_week_number = 1;
if (!empty($seasons)) {
    $season_id = $seasons[0]->id; // First season by default
    $existing_weeks = $wpdb->get_var(
        $wpdb->prepare("SELECT MAX(week_number) FROM {$wpdb->prefix}weeks WHERE season_id = %d", $season_id)
    );
    if ($existing_weeks) {
        $default_week_number = intval($existing_weeks) + 1;
    }
}

    ob_start(); ?>
    <h2>Week Setup</h2>
    <form method="POST">
        <?php wp_nonce_field('kf_create_week'); ?>
        <p><label>Select Season:<br>
            <select name="season_id" required>
                <?php foreach ($seasons as $s): ?>
                    <option value="<?= $s->id ?>"><?= esc_html($s->name) ?></option>
                <?php endforeach; ?>
            </select>
        </label></p>
        <p><label>Week Number:<br>
    <input type="number" name="week_number" value="<?= esc_attr($default_week_number) ?>" required>
</label></p>

        <p><label>Available Point Values (comma-separated):<br>
            <input type="text" name="point_values" required placeholder="1,2,3,...15">
        </label></p>
        <p><label>Submission Deadline:<br>
            <input type="datetime-local" name="deadline" required>
        </label></p>

        <h3>Matchups</h3>
        <div id="matchups">
            <?php for ($i = 0; $i < 5; $i++): ?>
    <fieldset style="margin-bottom: 16px; padding: 12px; border: 1px solid #ccc; border-radius: 6px;">
        <legend>Matchup <?= $i + 1 ?></legend>
        <label>Home Team:
    <input type="text" name="team_a[]" placeholder="e.g. Georgia (Home)" required>
</label>
<br><br>
<label>Away Team:
    <input type="text" name="team_b[]" placeholder="e.g. Alabama (Away)" required>
</label>

        <br><br>
        <label>
            <input type="radio" name="tiebreaker" value="<?= $i ?>">
            Use this matchup as Tiebreaker
        </label>
    </fieldset>
<?php endfor; ?>

        </div>

        <p><button type="submit" name="kf_week_submit">Create Week</button></p>
    </form>
    <?php
    return ob_get_clean();
}
add_filter('wp_nav_menu_objects', 'kf_filter_menu_items_by_role', 10, 2);

function kf_filter_menu_items_by_role($items, $args) {
    if (is_admin()) return $items; // Don't filter in admin

    $user = wp_get_current_user();
    $user_roles = (array) $user->roles;

    foreach ($items as $key => $item) {
        if (strpos($item->title, '[commissioner]') !== false && !in_array('commissioner', $user_roles)) {
            unset($items[$key]);
        }
        if (strpos($item->title, '[player]') !== false && !in_array('player', $user_roles)) {
            unset($items[$key]);
        }
    }

    return $items;
}
add_filter('wp_nav_menu_objects', 'kf_hide_role_labels_in_menu_titles', 20, 2);

function kf_hide_role_labels_in_menu_titles($items, $args) {
    foreach ($items as $item) {
        // Remove anything in square brackets, e.g. [commissioner]
        $item->title = preg_replace('/\s*\[.*?\]\s*/', '', $item->title);
    }
    return $items;
}
add_shortcode('kf_season_setup', 'kf_season_setup_shortcode');


function kf_commissioner_dashboard_shortcode() {
    if (!is_user_logged_in()) return '<p>You must be logged in.</p>';

    $user = wp_get_current_user();
    if (!in_array('commissioner', (array)$user->roles)) {
        return '<p>You do not have access to this page.</p>';
    }

    global $wpdb;
    $season_table = $wpdb->prefix . 'seasons';
    $week_table = $wpdb->prefix . 'weeks';
    $picks_table = $wpdb->prefix . 'picks';

    // Handle status toggle if submitted
   if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['season_toggle']) && check_admin_referer('kf_toggle_season')) {
    $season_id = intval($_POST['season_id']);
    $new_status = $_POST['new_status'] === '1' ? 1 : 0;
    $wpdb->update($season_table, ['is_active' => $new_status], ['id' => $season_id]);
    echo '<div class="notice notice-success"><p>Season status updated.</p></div>';
}

    // Get all seasons
    $seasons = $wpdb->get_results("SELECT * FROM $season_table ORDER BY id DESC");

    if (!$seasons) return '<p>No seasons found.</p>';

    ob_start(); ?>

    <h2>Commissioner Dashboard</h2>

    <h3>Season Management</h3>
    <table style="width:100%; border-collapse:collapse;">
        <thead>
            <tr>
                <th style="border-bottom:1px solid #ccc;">Name</th>
                <th style="border-bottom:1px solid #ccc;">Status</th>
                <th style="border-bottom:1px solid #ccc;">Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($seasons as $s): ?>
                <tr>
                    <td><?= esc_html($s->name) ?></td>
                    <td><?= $s->is_active ? 'Active' : 'Finished' ?></td>
                    <td>
                        <form method="POST" style="display:inline;">
                            <?php wp_nonce_field('kf_toggle_season'); ?>
                            <input type="hidden" name="season_id" value="<?= $s->id ?>">
                            <input type="hidden" name="season_toggle" value="1">
                            <input type="hidden" name="new_status" value="<?= $s->is_active ? '0' : '1' ?>">
                            <button type="submit">
                                <?= $s->is_active ? 'Mark Finished' : 'Reactivate' ?>
                            </button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <?php
    return ob_get_clean();
}

add_shortcode('kf_player_picks', 'kf_player_picks_shortcode');

function kf_player_picks_shortcode() {
    if (!is_user_logged_in()) return '<p>You must be logged in.</p>';

    $user = wp_get_current_user();
    if (!in_array('player', (array)$user->roles)) {
        return '<p>You do not have access to this page.</p>';
    }

    global $wpdb;
    $weeks_table = $wpdb->prefix . 'weeks';
    $matchups_table = $wpdb->prefix . 'matchups';
    $picks_table = $wpdb->prefix . 'picks';

    $now = current_time('mysql');

    // Get the current open week
    $week = $wpdb->get_row("SELECT * FROM $weeks_table WHERE submission_deadline > '$now' ORDER BY submission_deadline ASC LIMIT 1");
    if (!$week) return '<p>No open week available to submit picks.</p>';

    $matchups = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM $matchups_table WHERE week_id = %d",
        $week->id
    ));

    $available_points = array_map('trim', explode(',', $week->point_values));

    // Check for existing picks
    $existing_picks = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM $picks_table WHERE week_id = %d AND user_id = %d",
        $week->id, get_current_user_id()
    ), OBJECT_K);

    $is_edit = !empty($existing_picks);

    // Handle form submission
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['kf_player_picks_submit'])) {
        if (!wp_verify_nonce($_POST['_wpnonce'], 'kf_submit_picks')) {
            return '<p>Security check failed.</p>';
        }

        $selected_teams = $_POST['winner'];
        $selected_points = $_POST['points'];

        // Validation: ensure all point values are unique
        if (count($selected_points) !== count(array_unique($selected_points))) {
            return '<p style="color:red;">Each point value must be used only once.</p>';
        }

        // Validation: ensure all selected points exist in available list
        foreach ($selected_points as $pt) {
            if (!in_array($pt, $available_points)) {
                return '<p style="color:red;">Invalid point value detected.</p>';
            }
        }

        // Delete existing picks first if editing
        $wpdb->delete($picks_table, ['user_id' => get_current_user_id(), 'week_id' => $week->id]);

        // Insert new picks
        foreach ($matchups as $m) {
            $winner = sanitize_text_field($selected_teams[$m->id]);
            $points = intval($selected_points[$m->id]);

            $wpdb->insert($picks_table, [
                'user_id' => get_current_user_id(),
                'week_id' => $week->id,
                'matchup_id' => $m->id,
                'selected_team' => $winner,
                'assigned_points' => $points
            ]);
        }

        return '<p style="color:green;">Your picks have been saved successfully.</p>';
    }

    ob_start(); ?>
    <h2>Player Picks – Week <?= $week->week_number ?></h2>
    <p><strong>Deadline:</strong> <?= date('M d, Y h:i A', strtotime($week->submission_deadline)) ?></p>

    <form method="POST">
        <?php wp_nonce_field('kf_submit_picks'); ?>

        <?php foreach ($matchups as $m): ?>
            <fieldset style="margin-bottom: 16px; padding: 12px; border: 1px solid #ccc; border-radius: 6px;">
                <legend><?= esc_html($m->team_a) ?> vs <?= esc_html($m->team_b) ?></legend>

                <label><input type="radio" name="winner[<?= $m->id ?>]" value="<?= esc_attr($m->team_a) ?>"
                    <?= isset($existing_picks[$m->id]) && $existing_picks[$m->id]->selected_team === $m->team_a ? 'checked' : '' ?>
                    required> <?= esc_html($m->team_a) ?></label><br>

                <label><input type="radio" name="winner[<?= $m->id ?>]" value="<?= esc_attr($m->team_b) ?>"
                    <?= isset($existing_picks[$m->id]) && $existing_picks[$m->id]->selected_team === $m->team_b ? 'checked' : '' ?>
                    required> <?= esc_html($m->team_b) ?></label><br><br>

                <label>Assign Point Value:<br>
                    <select name="points[<?= $m->id ?>]" required>
                        <option value="">-- Select --</option>
                        <?php foreach ($available_points as $pt): ?>
                            <option value="<?= $pt ?>"
                                <?= isset($existing_picks[$m->id]) && $existing_picks[$m->id]->assigned_points == $pt ? 'selected' : '' ?>>
                                <?= $pt ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
            </fieldset>
        <?php endforeach; ?>

        <button type="submit" name="kf_player_picks_submit"><?= $is_edit ? 'Update' : 'Submit' ?> Picks</button>
    </form>
    <?php
    return ob_get_clean();
}
