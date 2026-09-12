<?php
/**
 * Kerry Football — dead-end pages, with a way out.
 *
 * Every refusal in this plugin used to be one sentence on an otherwise empty page: "You do not
 * have access to this page." with nothing to click. Two of them described the way out in words
 * ("Please return to the Manage Weeks page") without linking there. A refusal is a fork in the
 * road, not a wall, so each one renders through here with the routes that actually apply.
 *
 * The most common refusal is not really "no", it is "not in the league you currently have
 * selected" — so kf_league_switch_actions() offers the viewer's other leagues as one-click
 * switches, reusing the same season-switching link the homepage cards use.
 *
 * @package Kerry_Football
 * @since   1.8.15
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * One action for kf_notice_page().
 *
 * @param string $label     Button text.
 * @param string $url       Where it goes.
 * @param int    $season_id Switch to this league first (0 = plain link). Uses the existing
 *                          kf-season-select-and-go behaviour, so the league is set before the
 *                          browser follows the URL.
 * @return array
 */
function kf_notice_action( $label, $url, $season_id = 0 ) {
    return [
        'label'     => (string) $label,
        'url'       => (string) $url,
        'season_id' => (int) $season_id,
    ];
}

/**
 * Renders a refusal or empty state with somewhere to go.
 *
 * @param string $title    Short heading.
 * @param string $message  Plain text; escaped here, so callers pass raw strings.
 * @param array  $actions  kf_notice_action() entries. The first is styled as the primary.
 * @param string $tone     'info' | 'warn' | 'error'.
 * @return string HTML.
 */
function kf_notice_page( $title, $message, $actions = [], $tone = 'info' ) {
    $tone = in_array( $tone, [ 'info', 'warn', 'error' ], true ) ? $tone : 'info';

    $out  = '<div class="kf-container">';
    $out .= '<div class="kf-notice-card kf-notice-' . esc_attr( $tone ) . '">';
    $out .= '<h2 class="kf-notice-title">' . esc_html( $title ) . '</h2>';
    if ( $message !== '' ) {
        $out .= '<p class="kf-notice-message">' . esc_html( $message ) . '</p>';
    }

    if ( ! empty( $actions ) ) {
        $out .= '<div class="kf-notice-actions">';
        $first = true;
        foreach ( $actions as $action ) {
            if ( empty( $action['label'] ) || empty( $action['url'] ) ) {
                continue;
            }
            $classes = 'kf-button' . ( $first ? ' kf-button-primary' : ' kf-button-secondary' );
            if ( ! empty( $action['season_id'] ) ) {
                // Switch league first, then go — same handler as the homepage season cards.
                $out .= '<a href="#" class="' . esc_attr( $classes ) . ' kf-season-select-and-go"'
                     . ' data-season-id="' . esc_attr( (int) $action['season_id'] ) . '"'
                     . ' data-redirect-url="' . esc_url( $action['url'] ) . '">'
                     . esc_html( $action['label'] ) . '</a>';
            } else {
                $out .= '<a href="' . esc_url( $action['url'] ) . '" class="' . esc_attr( $classes ) . '">'
                     . esc_html( $action['label'] ) . '</a>';
            }
            $first = false;
        }
        $out .= '</div>';
    }

    $out .= '</div></div>';

    return $out;
}

/**
 * The leagues this viewer could switch to, as actions.
 *
 * "You do not have access to this page" is usually "...in the league you have selected". Being a
 * commissioner of one league and a player in another is normal here, so the way out is almost
 * always a switch rather than a login.
 *
 * @param string $target_path Where to land after switching.
 * @param int    $exclude_id  League to leave out (typically the active one).
 * @param int    $limit       Safety cap on how many buttons to render.
 * @return array kf_notice_action() entries.
 */
function kf_league_switch_actions( $target_path = '/season-summary/', $exclude_id = 0, $limit = 4 ) {
    if ( ! is_user_logged_in() ) {
        return [];
    }

    global $wpdb;
    $user_id    = get_current_user_id();
    $exclude_id = (int) $exclude_id;

    if ( current_user_can( 'manage_options' ) ) {
        $seasons = $wpdb->get_results(
            "SELECT id, name FROM {$wpdb->prefix}seasons WHERE is_active = 1 ORDER BY name ASC"
        );
    } else {
        $seasons = $wpdb->get_results( $wpdb->prepare(
            "SELECT DISTINCT s.id, s.name FROM {$wpdb->prefix}seasons s
             LEFT JOIN {$wpdb->prefix}season_players sp
                    ON s.id = sp.season_id AND sp.user_id = %d AND sp.status = 'accepted'
             WHERE s.is_active = 1 AND ( sp.user_id IS NOT NULL OR s.created_by = %d )
             ORDER BY s.name ASC",
            $user_id, $user_id
        ) );
    }

    $actions = [];
    foreach ( (array) $seasons as $season ) {
        if ( (int) $season->id === $exclude_id ) {
            continue;
        }
        if ( count( $actions ) >= $limit ) {
            break;
        }
        $actions[] = kf_notice_action(
            'Switch to ' . $season->name,
            site_url( $target_path ),
            (int) $season->id
        );
    }

    return $actions;
}

/**
 * Is there an invitation this player has not answered yet?
 *
 * Being invited but not accepted looks identical to being a stranger, and the accept/decline
 * buttons live on the Player Dashboard where nothing points to them.
 *
 * @param int      $season_id
 * @param int|null $user_id Defaults to current user.
 * @return bool
 */
function kf_has_pending_invite( $season_id, $user_id = null ) {
    $season_id = (int) $season_id;
    if ( $season_id <= 0 ) {
        return false;
    }
    if ( ! $user_id ) {
        $user_id = get_current_user_id();
    }

    global $wpdb;

    return (bool) $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->prefix}season_players
         WHERE season_id = %d AND user_id = %d AND status = 'invited'",
        $season_id, $user_id
    ) );
}

/**
 * The standard "you are not signed in" page.
 *
 * @param string $what Optional description of what they were trying to reach.
 * @return string HTML.
 */
function kf_notice_login_required( $what = 'this page' ) {
    return kf_notice_page(
        'Please log in',
        'You need to be logged in to see ' . $what . '.',
        [
            kf_notice_action( 'Log in', wp_login_url( home_url( add_query_arg( [] ) ) ) ),
            kf_notice_action( 'Home', site_url( '/' ) ),
        ],
        'info'
    );
}

// No closing PHP tag.
