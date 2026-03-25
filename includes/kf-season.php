<?php

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
        if (!isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'kf_create_season')) {
            echo '<div class="notice notice-error"><p>❌ Security check failed.</p></div>';
        } else {
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

            // Prevent duplicates for same commissioner (user ID)
            $existing = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM $table WHERE name = %s",
                $name
            ));

            if ($existing > 0) {
                echo '<div class="notice notice-error"><p>❌ A season with that name already exists.</p></div>';
            } else {
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
