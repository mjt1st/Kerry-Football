<?php
/**
 * Shortcode handler for the site's main hub/dashboard page.
 * Displays relevant season cards and actions for both players and commissioners.
 *
 * @package Kerry_Football
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Main function to generate the homepage content.
 */
function kf_homepage_shortcode() {
    ob_start();

    // Only show content for logged-in users.
    if ( is_user_logged_in() ) {
        $current_user    = wp_get_current_user();
        global $wpdb;
        $is_commissioner = kf_is_any_commissioner();

        // Get seasons (commissioner sees all; players see theirs).
        if ( $is_commissioner ) {
            $seasons = $wpdb->get_results(
                "SELECT id, name, is_active FROM {$wpdb->prefix}seasons ORDER BY is_active DESC, name ASC"
            );
        } else {
            $seasons = $wpdb->get_results( $wpdb->prepare(
                "SELECT s.id, s.name, s.is_active
                 FROM {$wpdb->prefix}seasons s
                 JOIN {$wpdb->prefix}season_players sp ON s.id = sp.season_id
                 WHERE sp.user_id = %d AND sp.status = 'accepted'
                 ORDER BY s.is_active DESC, s.name ASC",
                $current_user->ID
            ) );
        }
        ?>
        <div class="kf-container">
            <h1>Welcome, <?php echo esc_html( $current_user->display_name ); ?>!</h1>

            <div class="kf-seasons-hub">
                <h2>Active Seasons</h2>
                <div class="kf-season-cards-container">
                <?php
                $active_seasons_found = false;
                if ( $seasons ) {
                    foreach ( $seasons as $season ) {
                        if ( $season->is_active ) {
                            $card_data = kf_get_card_data_for_season( $season->id, $current_user->ID, $is_commissioner );
                            echo kf_render_season_card( $season, $is_commissioner, $card_data );
                            $active_seasons_found = true;
                        }
                    }
                }
                if ( ! $active_seasons_found ) {
                    echo '<p>There are no active seasons to display.</p>';
                }
                ?>
                </div>

                <?php
                $past_seasons_list = $seasons ? array_filter( (array) $seasons, fn($s) => ! $s->is_active ) : [];
                if ( ! empty( $past_seasons_list ) ) : ?>
                <h2 style="margin-top: 2em;">Past Seasons</h2>
                <div class="kf-archived-seasons-list">
                <?php foreach ( $past_seasons_list as $season ) :
                    echo kf_render_archived_season_row( $season, $current_user->ID );
                endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php
    } else {
        // Logged-out view.
        ?>
        <div class="kf-container kf-login-form-container">
            <h1>Kerry Football Login</h1>
            <?php wp_login_form( array( 'redirect' => home_url() ) ); ?>
        </div>
        <?php
    }

    return ob_get_clean();
}

/**
 * Data Fetching for a season card (supports commissioner who is also a player).
 *
 * NOTE:
 * - current_week_published: last week with status='published' (used for player UI).
 * - latest_week_any:       highest week_number of any status (used for admin button logic).
 */
function kf_get_card_data_for_season( $season_id, $user_id, $is_commissioner ) {
    global $wpdb;
    $data = [];

    // Is the current user a player in this season?
    $data['is_also_player'] = $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(id) FROM {$wpdb->prefix}season_players WHERE season_id = %d AND user_id = %d AND status = 'accepted'",
        $season_id, $user_id
    ) ) > 0;

    // Latest PUBLISHED week (the "Current Week" for players).
    $data['current_week_published'] = $wpdb->get_row( $wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}weeks
         WHERE season_id = %d AND status = 'published'
         ORDER BY week_number DESC LIMIT 1",
        $season_id
    ) );

    // Latest week of ANY status (helps commissioner CTAs).
    $data['latest_week_any'] = $wpdb->get_row( $wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}weeks
         WHERE season_id = %d
         ORDER BY week_number DESC LIMIT 1",
        $season_id
    ) );

    // Player count.
    $data['player_count'] = (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(id) FROM {$wpdb->prefix}season_players WHERE season_id = %d AND status = 'accepted'",
        $season_id
    ) );

    // Player-specific info.
    $data['rank']            = 'N/A';
    $data['total_score']     = 0;
    $data['picks_submitted'] = false;

    if ( $data['is_also_player'] ) {
        // Rank & total score.
        $scores = $wpdb->get_results( $wpdb->prepare(
            "SELECT user_id, SUM(score) AS total_score
             FROM {$wpdb->prefix}scores
             WHERE week_id IN (SELECT id FROM {$wpdb->prefix}weeks WHERE season_id = %d)
             GROUP BY user_id
             ORDER BY total_score DESC",
            $season_id
        ) );

        foreach ( (array) $scores as $index => $score_row ) {
            if ( (int) $score_row->user_id === (int) $user_id ) {
                $data['rank']        = $index + 1;
                $data['total_score'] = (int) ( $score_row->total_score ?? 0 );
                break;
            }
        }

        // Picks submitted for the CURRENT PUBLISHED week?
        if ( $data['current_week_published'] ) {
            $data['picks_submitted'] = $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(id) FROM {$wpdb->prefix}picks WHERE user_id = %d AND week_id = %d",
                $user_id, $data['current_week_published']->id
            ) ) > 0;
        }
    }

    return $data;
}

/**
 * Format a UTC datetime string as Eastern Time and label ET.
 */
function kf_format_deadline_et( $utc_datetime ) {
    if ( empty( $utc_datetime ) || $utc_datetime === '0000-00-00 00:00:00' ) {
        return 'N/A';
    }
    try {
        $dt = new DateTime( $utc_datetime, new DateTimeZone( 'UTC' ) );         // DB stores UTC
        $dt->setTimezone( new DateTimeZone( 'America/New_York' ) );             // Show in US/Eastern
        return $dt->format( 'D, M j g:i A' ) . ' ET';
    } catch ( Exception $e ) {
        return 'N/A';
    }
}

/**
 * Render a compact single-row card for an archived (past) season.
 * Only fetches final rank + total score — no weekly data needed.
 */
function kf_render_archived_season_row( $season, $user_id ) {
    global $wpdb;

    // Final rank & total score for this player
    $scores = $wpdb->get_results( $wpdb->prepare(
        "SELECT user_id, SUM(score) AS total_score
         FROM {$wpdb->prefix}scores
         WHERE week_id IN (SELECT id FROM {$wpdb->prefix}weeks WHERE season_id = %d)
         GROUP BY user_id ORDER BY total_score DESC",
        $season->id
    ) );

    $rank        = null;
    $total_score = null;
    $player_ct   = count( $scores );
    foreach ( $scores as $i => $row ) {
        if ( (int) $row->user_id === (int) $user_id ) {
            $rank        = $i + 1;
            $total_score = (int) $row->total_score;
            break;
        }
    }

    $suffix = function( $n ) {
        if ( ! is_numeric( $n ) ) return $n;
        $ends = ['th','st','nd','rd','th','th','th','th','th','th'];
        return ( ( $n % 100 ) >= 11 && ( $n % 100 ) <= 13 ) ? $n . 'th' : $n . $ends[ $n % 10 ];
    };

    ob_start(); ?>
    <div class="kf-archived-row">
        <div class="kf-archived-row-info">
            <span class="kf-archived-name"><?php echo esc_html( $season->name ); ?></span>
            <?php if ( $rank !== null ) : ?>
                <span class="kf-archived-rank">
                    Final Rank: <strong><?php echo $suffix( $rank ); ?></strong> of <?php echo $player_ct; ?>
                    &nbsp;&middot;&nbsp; <?php echo $total_score; ?> pts
                </span>
            <?php endif; ?>
        </div>
        <a href="#"
           class="kf-button kf-button-secondary kf-season-select-and-go"
           data-season-id="<?php echo esc_attr( $season->id ); ?>"
           data-redirect-url="<?php echo esc_url( site_url( '/season-summary/' ) ); ?>">
            View Season
        </a>
    </div>
    <?php
    return ob_get_clean();
}

/**
 * Get a UNIX timestamp (UTC) from the DB UTC datetime string.
 */
function kf_deadline_ts_utc( $utc_datetime ) {
    if ( empty( $utc_datetime ) || $utc_datetime === '0000-00-00 00:00:00' ) {
        return null;
    }
    try {
        $dt = new DateTime( $utc_datetime, new DateTimeZone( 'UTC' ) );
        return $dt->getTimestamp();
    } catch ( Exception $e ) {
        return null;
    }
}

/**
 * Render a season card (hybrid: player + commissioner).
 */
if ( ! function_exists( 'kf_render_season_card' ) ) {
    function kf_render_season_card( $season, $is_commissioner, $card_data ) {
        ob_start();

        $current_week    = $card_data['current_week_published'] ?? null; // last published week
        $latest_week_any = $card_data['latest_week_any'] ?? null;        // newest week of any status
        $is_also_player  = $card_data['is_also_player'] ?? false;

        // Player-facing variables.
        $picks_submitted = (bool) ( $card_data['picks_submitted'] ?? false );
        $picks_link      = $current_week ? site_url( '/my-picks/?week_id=' . $current_week->id ) : '#';
        $is_published    = (bool) $current_week; // by definition, yes if set.

        ?>
        <div class="kf-season-card">
            <div class="kf-card-header">
                <h3><?php echo esc_html( $season->name ); ?></h3>
                <?php if ( $season->is_active ) : ?>
                    <span class="kf-card-badge">Active</span>
                <?php else : ?>
                    <span class="kf-card-badge kf-card-badge-archived">Archived</span>
                <?php endif; ?>
            </div>

            <div class="kf-card-body">
                <?php // ----- PLAYER VIEW ----- ?>
                <?php if ( $is_also_player ) : ?>
                    <h4>🏆 Your Player View</h4>
                    <ul class="kf-card-stats">
                        <li><strong>Overall Rank:</strong> <?php echo esc_html( $card_data['rank'] ); ?> of <?php echo esc_html( $card_data['player_count'] ); ?></li>
                        <li><strong>Total Points:</strong> <?php echo esc_html( $card_data['total_score'] ); ?></li>
                    </ul>
                    <hr>
                    <ul class="kf-card-stats">
                        <li><strong>Current Week:</strong> <?php echo $current_week ? esc_html( $current_week->week_number ) : 'N/A'; ?></li>

                        <li><strong>Your Picks:</strong>
                            <?php
                            if ( ! $current_week ) {
                                echo '<span class="kf-text-muted">Not Open Yet</span>';
                            } else {
                                // Requirement: show Action Required on published week even after deadline (late submissions allowed).
                                if ( $picks_submitted ) {
                                    echo '<span class="kf-text-success">✅ Submitted</span>';
                                } else {
                                    echo '<a href="' . esc_url( $picks_link ) . '" class="kf-text-danger">⚠️ Action Required</a>';
                                }
                            }
                            ?>
                        </li>

                        <li><strong>Deadline:</strong>
                            <?php
                            echo $current_week
                                ? esc_html( kf_format_deadline_et( $current_week->submission_deadline ) )
                                : 'N/A';
                            ?>
                        </li>
                    </ul>

                    <?php
                    // Player "Smart Button"
                    $btn_text = 'View Summary';
                    $btn_link = site_url( '/season-summary/' );
                    if ( $is_published ) {
                        $btn_text = $picks_submitted ? 'View/Edit Your Picks' : 'Make Your Picks';
                        $btn_link = $picks_link;
                    }
                    ?>
                    <a href="#"
                       class="kf-button kf-button-primary kf-season-select-and-go"
                       data-season-id="<?php echo esc_attr( $season->id ); ?>"
                       data-redirect-url="<?php echo esc_url( $btn_link ); ?>">
                        <?php echo esc_html( $btn_text ); ?>
                    </a>
                <?php endif; ?>

                <?php // ----- COMMISSIONER VIEW ----- ?>
                <?php if ( $is_commissioner ) : ?>
                    <?php if ( $is_also_player ) { echo '<hr style="margin: 20px 0;"><h4>👑 Admin View</h4>'; } ?>
                    <ul class="kf-card-stats">
                        <li><strong>Current Week:</strong> <?php echo $current_week ? esc_html( $current_week->week_number ) : 'N/A'; ?></li>
                        <li><strong>Week Status:</strong> <?php echo $current_week ? 'Published' : ( $latest_week_any ? ucfirst( esc_html( $latest_week_any->status ) ) : 'Not Started' ); ?></li>
                        <li><strong>Players:</strong> <?php echo esc_html( $card_data['player_count'] ?? 0 ); ?></li>
                    </ul>
                    <?php
                    // Commissioner "Smart Button" (driven by latest ANY week so they can act on drafts/results)
                    $admin_btn_text = 'Manage Weeks';
                    $admin_btn_link = site_url( '/manage-weeks/' );
                    if ( $latest_week_any ) {
                        if ( $latest_week_any->status === 'draft' ) {
                            $admin_btn_text = 'Setup Week ' . esc_html( $latest_week_any->week_number );
                            $admin_btn_link = site_url( '/week-setup/?week_id=' . $latest_week_any->id );
                        } elseif ( $latest_week_any->status === 'published' ) {
                            $admin_btn_text = 'Enter Week ' . esc_html( $latest_week_any->week_number ) . ' Results';
                            $admin_btn_link = site_url( '/enter-results/?week_id=' . $latest_week_any->id );
                        } elseif ( $latest_week_any->status === 'tie_resolution_needed' ) {
                            $admin_btn_text = 'Resolve Ties (Week ' . esc_html( $latest_week_any->week_number ) . ')';
                            $admin_btn_link = site_url( '/week-summary/?week_id=' . $latest_week_any->id );
                        } elseif ( $latest_week_any->status === 'finalized' ) {
                            // Latest is finalized; likely next is a draft or none exists.
                            $admin_btn_text = 'Manage Weeks';
                            $admin_btn_link = site_url( '/manage-weeks/' );
                        }
                    }
                    ?>
                    <a href="#"
                       class="kf-button kf-button-primary kf-season-select-and-go"
                       data-season-id="<?php echo esc_attr( $season->id ); ?>"
                       data-redirect-url="<?php echo esc_url( $admin_btn_link ); ?>">
                        <?php echo esc_html( $admin_btn_text ); ?>
                    </a>

                    <div class="kf-card-quick-links">
                        <a href="#"
                           class="kf-season-select-and-go"
                           data-season-id="<?php echo esc_attr( $season->id ); ?>"
                           data-redirect-url="<?php echo esc_url( site_url( '/manage-players/' ) ); ?>">Manage Players</a> |
                        <a href="#"
                           class="kf-season-select-and-go"
                           data-season-id="<?php echo esc_attr( $season->id ); ?>"
                           data-redirect-url="<?php echo esc_url( site_url( '/edit-season/' ) ); ?>">Edit Season</a>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }
}
