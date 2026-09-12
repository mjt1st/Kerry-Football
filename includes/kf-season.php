<?php

/**
 * Aggregate spread profile for a season, built from its saved matchups.
 *
 * The week profile is DERIVED rather than stored. Every figure it reports already lives in
 * the matchups table (spread_home per game), so any week's profile can be recomputed at any
 * time and the season view is just the same query without a week filter. That avoids adding
 * a column — which on this plugin means a kf_install_db() change plus a kf_maybe_upgrade_db()
 * block on a live site — and removes any chance of a stored figure drifting out of sync with
 * the matchups it describes.
 *
 * @param int $season_id      Season to aggregate.
 * @param int $exclude_week_id Optional week to leave out, so a week can be compared against
 *                             the rest of the season rather than against a set including itself.
 * @return object|null Row with games, weeks, closest, biggest, avg_spread, close_games,
 *                     blowouts — or null when the season has no games with odds yet.
 */
function kf_get_season_spread_profile( $season_id, $exclude_week_id = 0 ) {
    global $wpdb;

    $season_id = intval( $season_id );
    if ( $season_id <= 0 ) {
        return null;
    }

    $sql = "SELECT COUNT(*) AS games,
                   COUNT(DISTINCT w.id) AS weeks,
                   MIN(ABS(m.spread_home)) AS closest,
                   MAX(ABS(m.spread_home)) AS biggest,
                   AVG(ABS(m.spread_home)) AS avg_spread,
                   SUM(CASE WHEN ABS(m.spread_home) <= 3.5 THEN 1 ELSE 0 END) AS close_games,
                   SUM(CASE WHEN ABS(m.spread_home) >= 10  THEN 1 ELSE 0 END) AS blowouts
            FROM {$wpdb->prefix}matchups m
            INNER JOIN {$wpdb->prefix}weeks w ON w.id = m.week_id
            WHERE w.season_id = %d
              AND m.is_tiebreaker = 0
              AND m.spread_home IS NOT NULL";

    $params = [ $season_id ];

    if ( intval( $exclude_week_id ) > 0 ) {
        $sql     .= " AND w.id != %d";
        $params[] = intval( $exclude_week_id );
    }

    $row = $wpdb->get_row( $wpdb->prepare( $sql, $params ) );

    if ( ! $row || intval( $row->games ) === 0 ) {
        return null;
    }

    return $row;
}

function kf_season_setup_shortcode() {
    if (!is_user_logged_in()) {
        return kf_notice_login_required( 'this page' );
    }

    $user = wp_get_current_user();
    if (!in_array('commissioner', (array) $user->roles)) {
        return kf_notice_page(
            'Commissioners only',
            'Setting up a season is for people who run a league.',
            [ kf_notice_action( 'Home', site_url( '/' ) ), kf_notice_action( 'Player Dashboard', site_url( '/player-dashboard/' ) ), kf_notice_action( 'Season Summary', site_url( '/season-summary/' ) ) ],
            'warn'
        );
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
