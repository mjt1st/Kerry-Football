<?php
/**
 * Player Stats Page — cumulative cross-season statistics.
 * Shortcode: [kf_player_stats]
 *
 * Shows career totals, awards, pick confidence accuracy, odds analytics,
 * season-by-season history, and an all-time leaderboard.
 *
 * @package Kerry_Football
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

function kf_player_stats_shortcode() {
    if ( ! is_user_logged_in() ) {
        return '<p>You must be logged in to view this page.</p>';
    }

    global $wpdb;
    $current_user    = wp_get_current_user();
    $current_user_id = $current_user->ID;
    $is_commissioner = current_user_can( 'manage_options' );

    // Commissioners can view any player's stats via ?player_id=X
    $view_user_id = $current_user_id;
    $view_user    = $current_user;
    if ( $is_commissioner && ! empty( $_GET['player_id'] ) ) {
        $req = get_userdata( intval( $_GET['player_id'] ) );
        if ( $req ) {
            $view_user_id = (int) $_GET['player_id'];
            $view_user    = $req;
        }
    }

    // -------------------------------------------------------------------------
    // DATA FETCHING
    // -------------------------------------------------------------------------

    // All seasons this player participated in (any status week)
    $seasons_played = $wpdb->get_results( $wpdb->prepare(
        "SELECT DISTINCT s.id, s.name, s.is_active
         FROM {$wpdb->prefix}seasons s
         JOIN {$wpdb->prefix}season_players sp ON s.id = sp.season_id
         WHERE sp.user_id = %d AND sp.status = 'accepted'
         ORDER BY s.id ASC",
        $view_user_id
    ) );

    // Career totals from finalized weeks
    $career = $wpdb->get_row( $wpdb->prepare(
        "SELECT
            COUNT(sc.id)                                         AS weeks_scored,
            COALESCE(SUM(sc.score), 0)                          AS total_points,
            COALESCE(SUM(sc.wins), 0)                           AS total_wins,
            COALESCE(SUM(sc.mwow_bonus_awarded), 0)             AS total_mwow_bonus,
            MAX(sc.score)                                        AS best_week,
            MIN(sc.score)                                        AS worst_week,
            ROUND(AVG(sc.score), 1)                             AS avg_week
         FROM {$wpdb->prefix}scores sc
         JOIN {$wpdb->prefix}weeks w ON sc.week_id = w.id
         WHERE sc.user_id = %d AND w.status = 'finalized'",
        $view_user_id
    ) );

    // Total non-tiebreaker picks from finalized weeks (win rate denominator)
    $total_non_tb_picks = (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(p.id)
         FROM {$wpdb->prefix}picks p
         JOIN {$wpdb->prefix}weeks w ON p.week_id = w.id
         JOIN {$wpdb->prefix}matchups m ON p.matchup_id = m.id
         WHERE p.user_id = %d AND w.status = 'finalized' AND m.is_tiebreaker = 0",
        $view_user_id
    ) );

    // MWOW & BPOW wins
    $mwow_count = (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->prefix}weeks
         WHERE mwow_winner_user_id = %d AND status = 'finalized'",
        $view_user_id
    ) );
    $bpow_count = (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->prefix}weeks
         WHERE bpow_winner_user_id = %d AND status = 'finalized'",
        $view_user_id
    ) );

    // Double Downs used & success rate
    $dd_count = (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->prefix}double_down_log WHERE user_id = %d",
        $view_user_id
    ) );
    $dd_success = $wpdb->get_row( $wpdb->prepare(
        "SELECT
            COUNT(*) AS total,
            SUM(CASE WHEN sc_new.score > sh.original_score THEN 1 ELSE 0 END) AS improved,
            ROUND(AVG(sc_new.score - sh.original_score), 1) AS avg_gain
         FROM {$wpdb->prefix}double_down_log ddl
         JOIN {$wpdb->prefix}score_history sh
              ON sh.user_id = ddl.user_id AND sh.week_id = ddl.source_week_id
         JOIN {$wpdb->prefix}scores sc_new
              ON sc_new.user_id = ddl.user_id AND sc_new.week_id = ddl.source_week_id
         WHERE ddl.user_id = %d",
        $view_user_id
    ) );

    // Tiebreaker accuracy (avg/best diff)
    $tb_stats = $wpdb->get_row( $wpdb->prepare(
        "SELECT
            ROUND(AVG(sc.tiebreaker_diff), 1) AS avg_diff,
            MIN(sc.tiebreaker_diff)            AS best_diff,
            COUNT(*)                            AS tb_weeks
         FROM {$wpdb->prefix}scores sc
         JOIN {$wpdb->prefix}weeks w ON sc.week_id = w.id
         WHERE sc.user_id = %d AND w.status = 'finalized' AND sc.tiebreaker_diff >= 0",
        $view_user_id
    ) );

    // Pick accuracy by point value (for confidence tier breakdown + chart)
    $point_dist = $wpdb->get_results( $wpdb->prepare(
        "SELECT
            p.point_value,
            COUNT(*)                                                     AS total,
            SUM(CASE WHEN m.result = p.pick THEN 1 ELSE 0 END)          AS correct
         FROM {$wpdb->prefix}picks p
         JOIN {$wpdb->prefix}weeks w  ON p.week_id    = w.id
         JOIN {$wpdb->prefix}matchups m ON p.matchup_id = m.id
         WHERE p.user_id = %d
           AND w.status = 'finalized'
           AND m.is_tiebreaker = 0
           AND m.result IS NOT NULL AND m.result != ''
         GROUP BY p.point_value
         ORDER BY p.point_value DESC",
        $view_user_id
    ) );

    // Build confidence tiers (top third / middle / bottom third by point value)
    $all_pvs = array_column( $point_dist, 'point_value' );
    sort( $all_pvs );
    $n = count( $all_pvs );
    $high_floor = $n > 0 ? $all_pvs[ max( 0, (int) ceil( $n * 0.66 ) - 1 ) ] : PHP_INT_MAX;
    $low_ceil   = $n > 0 ? $all_pvs[ min( $n - 1, (int) floor( $n * 0.33 ) ) ] : 0;

    $tiers = [
        'high' => ['label' => 'High Confidence', 'emoji' => '🔥', 'color' => '#16a34a', 'correct' => 0, 'total' => 0],
        'mid'  => ['label' => 'Mid Confidence',  'emoji' => '🎯', 'color' => '#2563eb', 'correct' => 0, 'total' => 0],
        'low'  => ['label' => 'Low Confidence',  'emoji' => '🤷', 'color' => '#9ca3af', 'correct' => 0, 'total' => 0],
    ];
    foreach ( $point_dist as $row ) {
        $key = $row->point_value >= $high_floor ? 'high' : ( $row->point_value <= $low_ceil ? 'low' : 'mid' );
        $tiers[ $key ]['correct'] += (int) $row->correct;
        $tiers[ $key ]['total']   += (int) $row->total;
    }

    // Odds-based stats (matchups with spread data, finalized)
    $odds_picks = $wpdb->get_results( $wpdb->prepare(
        "SELECT
            p.pick,
            p.point_value,
            m.team_a, m.team_b,
            m.result,
            m.spread_home,
            m.moneyline_home,
            m.moneyline_away
         FROM {$wpdb->prefix}picks p
         JOIN {$wpdb->prefix}weeks w    ON p.week_id    = w.id
         JOIN {$wpdb->prefix}matchups m ON p.matchup_id = m.id
         WHERE p.user_id = %d
           AND w.status = 'finalized'
           AND m.spread_home IS NOT NULL
           AND m.is_tiebreaker = 0
           AND m.result IS NOT NULL AND m.result != ''",
        $view_user_id
    ) );

    // Process odds picks
    $odds = [
        'total'            => 0,
        'fav_total'        => 0, 'fav_correct'        => 0,
        'dog_total'        => 0, 'dog_correct'         => 0,
        'bold_total'       => 0, 'bold_correct'        => 0, // underdog + high confidence
        'biggest_upset_ml' => 0, 'biggest_upset_team'  => '',
        'biggest_upset_opp' => '',
    ];
    foreach ( $odds_picks as $op ) {
        $odds['total']++;
        // team_b = home, spread_home < 0 means home is favourite
        $home_fav   = (float) $op->spread_home < 0;
        $fav_team   = $home_fav ? $op->team_b : $op->team_a;
        $dog_team   = $home_fav ? $op->team_a : $op->team_b;
        $picked_fav = ( trim( $op->pick ) === trim( $fav_team ) );
        $is_correct = ( trim( $op->result ) === trim( $op->pick ) );
        $dog_ml     = $home_fav ? (int) $op->moneyline_away : (int) $op->moneyline_home;

        if ( $picked_fav ) {
            $odds['fav_total']++;
            if ( $is_correct ) $odds['fav_correct']++;
        } else {
            $odds['dog_total']++;
            if ( $is_correct ) $odds['dog_correct']++;
            // Bold = underdog picked with high point value
            $all_pvs_desc = array_reverse( $all_pvs );
            $top_pvs = array_slice( $all_pvs_desc, 0, max( 1, (int) ceil( $n / 3 ) ) );
            if ( in_array( $op->point_value, $top_pvs ) ) {
                $odds['bold_total']++;
                if ( $is_correct ) $odds['bold_correct']++;
            }
            if ( $is_correct && $dog_ml > $odds['biggest_upset_ml'] ) {
                $odds['biggest_upset_ml']   = $dog_ml;
                $odds['biggest_upset_team'] = $op->pick;
                $odds['biggest_upset_opp']  = $picked_fav ? $dog_team : $fav_team;
            }
        }
    }

    // Season-by-season rank
    $season_ranks = [];
    foreach ( $seasons_played as $season ) {
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT sc.user_id, SUM(sc.score) AS total
             FROM {$wpdb->prefix}scores sc
             JOIN {$wpdb->prefix}weeks w ON sc.week_id = w.id
             WHERE w.season_id = %d AND w.status = 'finalized'
             GROUP BY sc.user_id ORDER BY total DESC",
            $season->id
        ) );
        if ( empty( $rows ) ) continue;
        $rank = null; $player_count = count( $rows );
        foreach ( $rows as $i => $row ) {
            if ( (int) $row->user_id === $view_user_id ) {
                $rank = $i + 1; break;
            }
        }
        if ( $rank !== null ) {
            $season_ranks[] = [
                'id'     => $season->id,
                'name'   => $season->name,
                'rank'   => $rank,
                'of'     => $player_count,
                'active' => (bool) $season->is_active,
            ];
        }
    }

    // All-time leaderboard (cross-season totals)
    $leaderboard = $wpdb->get_results(
        "SELECT
            sc.user_id,
            SUM(sc.score)              AS total_pts,
            COUNT(DISTINCT w.season_id) AS seasons,
            COUNT(sc.id)               AS weeks,
            SUM(sc.wins)               AS total_wins,
            ROUND(AVG(sc.score), 1)    AS avg_score,
            MAX(sc.score)              AS best_week
         FROM {$wpdb->prefix}scores sc
         JOIN {$wpdb->prefix}weeks w ON sc.week_id = w.id
         WHERE w.status = 'finalized'
         GROUP BY sc.user_id
         ORDER BY total_pts DESC"
    );

    // -------------------------------------------------------------------------
    // COMPUTED HELPERS
    // -------------------------------------------------------------------------
    $pct     = fn( $c, $t ) => $t > 0 ? round( ( $c / $t ) * 100 ) : 0;
    $ordinal = function( $n ) {
        if ( ! is_numeric( $n ) ) return $n;
        $s = ['th','st','nd','rd'];
        $v = $n % 100;
        return $n . ( isset( $s[ $v - 20 % 10 ] ) ? $s[ $v - 20 % 10 ] : ( isset( $s[ $v ] ) ? $s[ $v ] : $s[0] ) );
    };

    $win_pct       = $pct( $career->total_wins ?? 0, $total_non_tb_picks );
    $avg_rank      = count( $season_ranks ) > 0 ? round( array_sum( array_column( $season_ranks, 'rank' ) ) / count( $season_ranks ), 1 ) : null;
    $best_rank     = count( $season_ranks ) > 0 ? min( array_column( $season_ranks, 'rank' ) ) : null;
    $season_count  = count( $season_ranks );

    ob_start();
    ?>
    <div class="kf-container kf-stats-page">

        <div class="kf-stats-header">
            <div>
                <h1>📊 Player Stats</h1>
                <p class="kf-stats-subheading"><?php echo esc_html( $view_user->display_name ); ?> &mdash; All-Time Career</p>
            </div>
            <?php if ( $is_commissioner && count( $leaderboard ) > 1 ) : ?>
            <div class="kf-stats-player-switcher">
                <label for="kf-player-switcher">View Player</label>
                <select id="kf-player-switcher" onchange="window.location.href='?player_id='+this.value">
                    <?php foreach ( $leaderboard as $lb_row ) :
                        $lb_u = get_userdata( $lb_row->user_id );
                        if ( ! $lb_u ) continue; ?>
                        <option value="<?php echo esc_attr( $lb_row->user_id ); ?>"
                            <?php selected( $lb_row->user_id, $view_user_id ); ?>>
                            <?php echo esc_html( $lb_u->display_name ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
        </div>

        <?php if ( $season_count === 0 ) : ?>
            <p class="kf-text-muted">No finalized season data found for this player yet.</p>
        <?php else : ?>

        <!-- ================================================================ -->
        <!-- CAREER OVERVIEW -->
        <!-- ================================================================ -->
        <h2 class="kf-stats-section-title">🏆 Career Overview</h2>
        <div class="kf-stats-cards">

            <div class="kf-stat-card">
                <div class="kf-stat-card-value"><?php echo $season_count; ?></div>
                <div class="kf-stat-card-label">Seasons Played</div>
            </div>

            <div class="kf-stat-card">
                <div class="kf-stat-card-value"><?php echo number_format( (int) ( $career->total_points ?? 0 ) ); ?></div>
                <div class="kf-stat-card-label">Total Points</div>
            </div>

            <div class="kf-stat-card">
                <div class="kf-stat-card-value"><?php echo $win_pct; ?>%</div>
                <div class="kf-stat-card-label">Overall Win Rate</div>
                <div class="kf-stat-card-sub"><?php echo number_format( (int) ( $career->total_wins ?? 0 ) ); ?> wins / <?php echo number_format( $total_non_tb_picks ); ?> picks</div>
            </div>

            <div class="kf-stat-card">
                <div class="kf-stat-card-value"><?php echo $career->avg_week ?? '—'; ?></div>
                <div class="kf-stat-card-label">Avg Pts / Week</div>
                <div class="kf-stat-card-sub"><?php echo (int) ( $career->weeks_scored ?? 0 ); ?> weeks played</div>
            </div>

            <?php if ( $avg_rank !== null ) : ?>
            <div class="kf-stat-card">
                <div class="kf-stat-card-value"><?php echo $avg_rank; ?></div>
                <div class="kf-stat-card-label">Avg Season Rank</div>
            </div>
            <?php endif; ?>

            <?php if ( $best_rank !== null ) : ?>
            <div class="kf-stat-card <?php echo $best_rank === 1 ? 'kf-stat-card-gold' : ''; ?>">
                <div class="kf-stat-card-value"><?php echo $ordinal( $best_rank ); ?></div>
                <div class="kf-stat-card-label">Best Season Finish</div>
                <?php if ( $best_rank === 1 ) : ?><div class="kf-stat-card-sub">🏆 League Champion</div><?php endif; ?>
            </div>
            <?php endif; ?>

        </div>

        <!-- ================================================================ -->
        <!-- AWARDS -->
        <!-- ================================================================ -->
        <h2 class="kf-stats-section-title">🥇 Awards &amp; Accolades</h2>
        <div class="kf-stats-cards">

            <div class="kf-stat-card <?php echo $mwow_count > 0 ? 'kf-stat-card-highlight' : ''; ?>">
                <div class="kf-stat-card-value"><?php echo $mwow_count; ?></div>
                <div class="kf-stat-card-label">MWOW Wins</div>
                <div class="kf-stat-card-sub">Most Wins of the Week</div>
            </div>

            <div class="kf-stat-card <?php echo $bpow_count > 0 ? 'kf-stat-card-highlight' : ''; ?>">
                <div class="kf-stat-card-value"><?php echo $bpow_count; ?></div>
                <div class="kf-stat-card-label">BPOW Wins</div>
                <div class="kf-stat-card-sub">Best Points of the Week</div>
            </div>

            <?php if ( $career->best_week !== null ) : ?>
            <div class="kf-stat-card kf-stat-card-gold">
                <div class="kf-stat-card-value"><?php echo (int) $career->best_week; ?></div>
                <div class="kf-stat-card-label">Best Single Week</div>
            </div>
            <?php endif; ?>

            <?php if ( $career->worst_week !== null ) : ?>
            <div class="kf-stat-card">
                <div class="kf-stat-card-value"><?php echo (int) $career->worst_week; ?></div>
                <div class="kf-stat-card-label">Worst Single Week</div>
            </div>
            <?php endif; ?>

            <?php if ( $dd_count > 0 ) : ?>
            <div class="kf-stat-card">
                <div class="kf-stat-card-value"><?php echo $dd_count; ?></div>
                <div class="kf-stat-card-label">Double Downs Used</div>
                <?php if ( $dd_success && (int) $dd_success->total > 0 ) :
                    $dd_pct = $pct( $dd_success->improved, $dd_success->total );
                    $gain   = $dd_success->avg_gain;
                ?>
                <div class="kf-stat-card-sub"><?php echo $dd_pct; ?>% improved &middot; avg +<?php echo $gain; ?> pts</div>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <?php if ( $tb_stats && $tb_stats->avg_diff !== null ) : ?>
            <div class="kf-stat-card">
                <div class="kf-stat-card-value"><?php echo $tb_stats->avg_diff; ?></div>
                <div class="kf-stat-card-label">Avg Tiebreaker Diff</div>
                <div class="kf-stat-card-sub">Best ever: <?php echo (int) $tb_stats->best_diff; ?> pts off &middot; <?php echo (int) $tb_stats->tb_weeks; ?> weeks</div>
            </div>
            <?php endif; ?>

        </div>

        <!-- ================================================================ -->
        <!-- PICK CONFIDENCE BREAKDOWN -->
        <!-- ================================================================ -->
        <?php $has_conf_data = array_sum( array_column( $tiers, 'total' ) ) > 0; ?>
        <?php if ( $has_conf_data ) : ?>
        <h2 class="kf-stats-section-title">🎯 Pick Confidence Accuracy</h2>
        <p class="kf-stats-note">Do your high-value picks deliver? Split by the point values you assigned.</p>
        <div class="kf-stats-confidence">
            <?php foreach ( $tiers as $tier ) :
                if ( $tier['total'] === 0 ) continue;
                $p = $pct( $tier['correct'], $tier['total'] );
            ?>
            <div class="kf-confidence-row">
                <div class="kf-confidence-label"><?php echo $tier['emoji']; ?> <?php echo $tier['label']; ?></div>
                <div class="kf-confidence-bar-wrap">
                    <div class="kf-confidence-bar" style="width:<?php echo $p; ?>%;background:<?php echo $tier['color']; ?>;"></div>
                </div>
                <div class="kf-confidence-pct" style="color:<?php echo $tier['color']; ?>"><?php echo $p; ?>%</div>
                <div class="kf-confidence-record"><?php echo $tier['correct']; ?>–<?php echo ( $tier['total'] - $tier['correct'] ); ?></div>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- Point-value breakdown table -->
        <?php if ( ! empty( $point_dist ) ) : ?>
        <details class="kf-stats-details">
            <summary>Point-by-Point Breakdown</summary>
            <div class="kf-table-wrapper" style="margin-top:0.75em;">
                <table class="kf-table kf-stats-pv-table">
                    <thead>
                        <tr>
                            <th>Point Value</th>
                            <th>Picks</th>
                            <th>Correct</th>
                            <th>Win %</th>
                            <th style="width:40%">Accuracy</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $point_dist as $row ) :
                            $p = $pct( $row->correct, $row->total );
                            $bar_color = $p >= 60 ? '#16a34a' : ( $p >= 45 ? '#2563eb' : '#dc2626' );
                        ?>
                        <tr>
                            <td><strong><?php echo (int) $row->point_value; ?> pts</strong></td>
                            <td><?php echo (int) $row->total; ?></td>
                            <td><?php echo (int) $row->correct; ?></td>
                            <td><?php echo $p; ?>%</td>
                            <td>
                                <div class="kf-mini-bar-wrap">
                                    <div class="kf-mini-bar" style="width:<?php echo $p; ?>%;background:<?php echo $bar_color; ?>;"></div>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </details>
        <?php endif; ?>
        <?php endif; ?>

        <!-- ================================================================ -->
        <!-- VS THE ODDSMAKERS -->
        <!-- ================================================================ -->
        <?php if ( $odds['total'] > 0 ) : ?>
        <h2 class="kf-stats-section-title">📈 vs. The Oddsmakers</h2>
        <p class="kf-stats-note">Based on <?php echo $odds['total']; ?> picks where spread data was available.</p>
        <div class="kf-stats-cards">

            <div class="kf-stat-card">
                <div class="kf-stat-card-value"><?php echo $pct( $odds['dog_total'], $odds['total'] ); ?>%</div>
                <div class="kf-stat-card-label">Contrarian Rate</div>
                <div class="kf-stat-card-sub">Picks against the spread favourite</div>
            </div>

            <div class="kf-stat-card">
                <div class="kf-stat-card-value"><?php echo $pct( $odds['fav_correct'], $odds['fav_total'] ); ?>%</div>
                <div class="kf-stat-card-label">Win Rate — Favourites</div>
                <div class="kf-stat-card-sub"><?php echo $odds['fav_correct']; ?>–<?php echo ( $odds['fav_total'] - $odds['fav_correct'] ); ?> picking the spread fav</div>
            </div>

            <div class="kf-stat-card">
                <div class="kf-stat-card-value"><?php echo $pct( $odds['dog_correct'], $odds['dog_total'] ); ?>%</div>
                <div class="kf-stat-card-label">Win Rate — Underdogs</div>
                <div class="kf-stat-card-sub"><?php echo $odds['dog_correct']; ?>–<?php echo ( $odds['dog_total'] - $odds['dog_correct'] ); ?> backing underdogs</div>
            </div>

            <?php if ( $odds['bold_total'] > 0 ) : ?>
            <div class="kf-stat-card <?php echo $pct( $odds['bold_correct'], $odds['bold_total'] ) >= 50 ? 'kf-stat-card-highlight' : ''; ?>">
                <div class="kf-stat-card-value"><?php echo $pct( $odds['bold_correct'], $odds['bold_total'] ); ?>%</div>
                <div class="kf-stat-card-label">Bold Pick Accuracy</div>
                <div class="kf-stat-card-sub">High-value picks on underdogs (<?php echo $odds['bold_total']; ?> picks)</div>
            </div>
            <?php endif; ?>

            <?php if ( $odds['biggest_upset_ml'] > 0 ) : ?>
            <div class="kf-stat-card kf-stat-card-gold">
                <div class="kf-stat-card-value">+<?php echo number_format( $odds['biggest_upset_ml'] ); ?></div>
                <div class="kf-stat-card-label">Biggest Upset Called</div>
                <div class="kf-stat-card-sub"><?php echo esc_html( $odds['biggest_upset_team'] ); ?> (moneyline)</div>
            </div>
            <?php endif; ?>

            <?php
            // Overall "sharper" rating
            $with_pct  = $pct( $odds['fav_correct'],  max( 1, $odds['fav_total'] ) );
            $against_pct = $pct( $odds['dog_correct'], max( 1, $odds['dog_total'] ) );
            $sharp_label = $against_pct > $with_pct ? '🦅 Contrarian Edge' : '📗 Follows the Line';
            $sharp_pct   = $against_pct > $with_pct ? $against_pct : $with_pct;
            ?>
            <div class="kf-stat-card">
                <div class="kf-stat-card-value"><?php echo $sharp_label; ?></div>
                <div class="kf-stat-card-label">Betting Style</div>
                <div class="kf-stat-card-sub"><?php echo $sharp_pct; ?>% win rate on their stronger side</div>
            </div>

        </div>
        <?php endif; ?>

        <!-- ================================================================ -->
        <!-- SEASON-BY-SEASON HISTORY -->
        <!-- ================================================================ -->
        <?php if ( ! empty( $season_ranks ) ) : ?>
        <h2 class="kf-stats-section-title">📅 Season History</h2>
        <div class="kf-table-wrapper">
            <table class="kf-table">
                <thead>
                    <tr>
                        <th>Season</th>
                        <th>Final Rank</th>
                        <th>Field</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $season_ranks as $sr ) :
                        $medal = $sr['rank'] === 1 ? '🥇 ' : ( $sr['rank'] === 2 ? '🥈 ' : ( $sr['rank'] === 3 ? '🥉 ' : '' ) );
                    ?>
                    <tr>
                        <td><?php echo esc_html( $sr['name'] ); ?></td>
                        <td><strong><?php echo $medal . $ordinal( $sr['rank'] ); ?></strong></td>
                        <td><?php echo (int) $sr['of']; ?> players</td>
                        <td>
                            <?php if ( $sr['active'] ) : ?>
                                <span class="kf-status-active">In Progress</span>
                            <?php else : ?>
                                <span class="kf-status-archived">Complete</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

        <?php endif; // end season_count > 0 ?>

        <!-- ================================================================ -->
        <!-- ALL-TIME LEADERBOARD -->
        <!-- ================================================================ -->
        <?php if ( ! empty( $leaderboard ) ) : ?>
        <h2 class="kf-stats-section-title">🏅 All-Time Leaderboard</h2>
        <div class="kf-table-wrapper">
            <table class="kf-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Player</th>
                        <th>Total Pts</th>
                        <th>Seasons</th>
                        <th>Avg / Week</th>
                        <th>Best Week</th>
                        <th>Total Wins</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $rank_i = 0;
                    foreach ( $leaderboard as $lb_row ) :
                        $lb_u = get_userdata( $lb_row->user_id );
                        if ( ! $lb_u ) continue;
                        $rank_i++;
                        $is_me = ( (int) $lb_row->user_id === $view_user_id );
                        $medal = $rank_i === 1 ? '🥇' : ( $rank_i === 2 ? '🥈' : ( $rank_i === 3 ? '🥉' : $rank_i ) );
                    ?>
                    <tr class="<?php echo $is_me ? 'kf-stats-me-row' : ''; ?>">
                        <td><?php echo $medal; ?></td>
                        <td>
                            <?php echo esc_html( $lb_u->display_name ); ?>
                            <?php if ( $is_me ) : ?><span class="kf-stats-you-badge">You</span><?php endif; ?>
                        </td>
                        <td><strong><?php echo number_format( (int) $lb_row->total_pts ); ?></strong></td>
                        <td><?php echo (int) $lb_row->seasons; ?></td>
                        <td><?php echo $lb_row->avg_score; ?></td>
                        <td><?php echo (int) $lb_row->best_week; ?></td>
                        <td><?php echo number_format( (int) $lb_row->total_wins ); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

    </div>
    <?php
    return ob_get_clean();
}
