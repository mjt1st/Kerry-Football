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
 * @param int    $season_id League the destination is about (0 = none). Added to the URL as
 *                          ?season_id=, which the destination page applies.
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
                // The link names the league; the server applies it before the page renders.
                $out .= '<a href="' . esc_url( kf_league_url( $action['url'], $action['season_id'] ) ) . '" class="' . esc_attr( $classes ) . '">'
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
 * Redirect after a GET action, even from inside a shortcode.
 *
 * Shortcodes run while the theme is already sending the page, so wp_safe_redirect() usually cannot
 * send its header — it returns false and the old code's exit then cut the page off mid-render. The
 * action's URL also stayed in the address bar, so a refresh repeated it: publishing a week again
 * re-emails every player. When headers are gone, replace the address from the browser instead.
 *
 * @param string $url Destination on this site.
 * @return string HTML to return from the shortcode (only reached when headers were already sent).
 */
function kf_redirect_after_action( $url ) {
    $url = wp_validate_redirect( $url, site_url( '/' ) );
    if ( ! headers_sent() ) {
        wp_safe_redirect( $url );
        exit;
    }
    // wp_json_encode escapes "/" so the value cannot close the script element.
    return '<script>window.location.replace(' . wp_json_encode( $url ) . ');</script>'
         . '<noscript><p><a href="' . esc_url( $url ) . '">Continue</a></p></noscript>';
}

/**
 * The leagues this viewer could switch to, as actions.
 *
 * "You do not have access to this page" is usually "...in the league you have selected". Being a
 * commissioner of one league and a player in another is normal here, so the way out is almost
 * always a switch rather than a login.
 *
 * Ended leagues are left out by default: a switch is usually a way back to something current.
 * Pass $include_inactive where an ended league is a legitimate destination — the season summary
 * and dashboard, for a player whose leagues have all finished. Those have no active league to
 * default to, so without it they were offered nothing at all.
 *
 * @param string $target_path      Where to land after switching.
 * @param int    $exclude_id       League to leave out (typically the active one).
 * @param int    $limit            Safety cap on how many buttons to render.
 * @param bool   $include_inactive Also offer ended leagues, after the active ones.
 * @return array kf_notice_action() entries.
 */
function kf_league_switch_actions( $target_path = '/season-summary/', $exclude_id = 0, $limit = 4, $include_inactive = false ) {
    if ( ! is_user_logged_in() ) {
        return [];
    }

    global $wpdb;
    $user_id    = get_current_user_id();
    $exclude_id = (int) $exclude_id;

    // The filter is a fixed string chosen here, never user input.
    $active_filter = $include_inactive ? '1 = 1' : 's.is_active = 1';

    if ( current_user_can( 'manage_options' ) ) {
        $seasons = $wpdb->get_results(
            "SELECT s.id, s.name, s.is_active FROM {$wpdb->prefix}seasons s
             WHERE {$active_filter}
             ORDER BY s.name ASC"
        );
    } else {
        $seasons = $wpdb->get_results( $wpdb->prepare(
            "SELECT DISTINCT s.id, s.name, s.is_active FROM {$wpdb->prefix}seasons s
             LEFT JOIN {$wpdb->prefix}season_players sp
                    ON s.id = sp.season_id AND sp.user_id = %d AND sp.status = 'accepted'
             WHERE {$active_filter} AND ( sp.user_id IS NOT NULL OR s.created_by = %d )
             ORDER BY s.name ASC",
            $user_id, $user_id
        ) );
    }

    // Active leagues first by name, then ended ones newest first. Sorted here rather than with an
    // ORDER BY CASE expression: ordering a DISTINCT result by an expression is SQL-mode dependent in
    // MySQL, and a refused query would have quietly returned no switch buttons at all.
    $seasons = (array) $seasons;
    usort( $seasons, function ( $a, $b ) {
        $a_active = ! isset( $a->is_active ) || (int) $a->is_active === 1;
        $b_active = ! isset( $b->is_active ) || (int) $b->is_active === 1;
        if ( $a_active !== $b_active ) {
            return $a_active ? -1 : 1;
        }
        return $a_active
            ? strcasecmp( (string) $a->name, (string) $b->name )
            : ( (int) $b->id <=> (int) $a->id );
    } );

    $actions = [];
    foreach ( (array) $seasons as $season ) {
        if ( (int) $season->id === $exclude_id ) {
            continue;
        }
        if ( count( $actions ) >= $limit ) {
            break;
        }
        $ended     = isset( $season->is_active ) && ! (int) $season->is_active;
        $actions[] = kf_notice_action(
            ( $ended ? 'View ' . $season->name . ' (ended)' : 'Switch to ' . $season->name ),
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
