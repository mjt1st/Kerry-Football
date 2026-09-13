<?php
// --- START OF FILE: kf-menus.php ---
/**
 * Handles all dynamic menu modifications for the plugin.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Transforms the placeholder menu item into a dynamic, functional Season Switcher.
 */
function kf_render_season_switcher_in_menu( $items, $args ) {
    if ( !isset($args->theme_location) || $args->theme_location !== 'primary' ) {
        return $items;
    }

    $placeholder_class = 'kf-season-switcher-placeholder';
    $parent_item_key = -1;
    $parent_item_id = 0;
    foreach ( $items as $key => $menu_item ) {
        if ( in_array( $placeholder_class, $menu_item->classes ) ) {
            $parent_item_id = $menu_item->ID;
            $parent_item_key = $key;
            break;
        }
    }

    if ( $parent_item_key === -1 || !is_user_logged_in() ) { return $items; }
    if (session_status() === PHP_SESSION_NONE) { session_start(); }
    
    global $wpdb;
    $user_id = get_current_user_id();

    // Active leagues this viewer may open: every one for a site admin, otherwise those they are an
    // accepted member of or created — the same rule as kf_can_access_season(). Commissioning ANY
    // league used to list EVERY league on the site, and picking one they were not in failed with
    // "You are not a participant in this season".
    if ( current_user_can( 'manage_options' ) ) {
        $seasons = $wpdb->get_results( "SELECT id, name, is_active FROM {$wpdb->prefix}seasons WHERE is_active = 1 ORDER BY name ASC" );
    } else {
        $seasons = $wpdb->get_results( $wpdb->prepare(
            "SELECT DISTINCT s.id, s.name, s.is_active FROM {$wpdb->prefix}seasons s
             LEFT JOIN {$wpdb->prefix}season_players sp
                    ON s.id = sp.season_id AND sp.user_id = %d AND sp.status = 'accepted'
             WHERE s.is_active = 1 AND ( sp.user_id IS NOT NULL OR s.created_by = %d )
             ORDER BY s.name ASC",
            $user_id, $user_id
        ) );
    }


    if (empty($seasons)) {
        unset($items[$parent_item_key]);
        return array_values($items);
    }
    
    $active_season_id = $_SESSION['kf_active_season_id'] ?? 0;
    $active_season_name = 'Select Season';

    if ($active_season_id === 0 && !empty($seasons)) {
        $active_season_id = $seasons[0]->id;
        $_SESSION['kf_active_season_id'] = $active_season_id;
    }
    foreach($seasons as $season) {
        if ($season->id == $active_season_id) { $active_season_name = $season->name; break; }
    }
    
    $items[$parent_item_key]->title = 'Season: ' . esc_html($active_season_name);
    $items[$parent_item_key]->url = '#';
    $items[$parent_item_key]->classes[] = 'menu-item-has-children kf-season-switcher'; // Simplified class name
    
    // The id rides on its own property. It used to be stored in attr_title, which the walker prints as
    // the link's title attribute — a hover tooltip showing a bare number.
    $items[$parent_item_key]->kf_season_id = (int) $active_season_id;


    $submenu_items = [];
    foreach ($seasons as $season) {
        if ($season->id == $active_season_id) continue;
        $item = new stdClass();
        $item->ID = $season->id + 10000;
        $item->title = $season->name;
        // A real link: opens in a new tab, works without JavaScript, and the league is applied from
        // the URL before the page renders.
        $item->url = kf_league_url( site_url( '/season-summary/' ), $season->id );
        $item->menu_item_parent = $parent_item_id;
        $item->menu_order = 500 + $season->id; 
        $item->type = 'custom';
        $item->object = 'custom';
        $item->object_id = '';
        $item->classes = ['kf-season-switcher-item', 'menu-item', 'menu-item-type-custom'];
        $item->attr_title = '';
        $item->kf_season_id = (int) $season->id;
        $item->db_id = 0;
        // Walker_Nav_Menu reads these on every item it renders. Leaving them off logged a
        // PHP warning per item per page load — thousands of "Undefined property:
        // stdClass::$current" lines in the site error log, which buried real errors.
        $item->current = false;
        $item->current_item_ancestor = false;
        $item->current_item_parent = false;
        $item->target = '';
        $item->xfn = '';
        $item->description = '';
        $item->post_parent = 0;
        $item->object_id = 0;
        $item->type_label = 'Custom Link';
        $submenu_items[] = $item;
    }
    
    array_splice($items, $parent_item_key + 1, 0, $submenu_items);
    
    return $items;
}
add_filter('wp_nav_menu_objects', 'kf_render_season_switcher_in_menu', 20, 2);


function kf_add_season_switcher_data_attribute($atts, $item, $args) {
    // For the sub-items (the other seasons in the dropdown)
    if ( isset( $item->kf_season_id ) && is_array( $item->classes ?? null )
         && ( in_array( 'kf-season-switcher-item', $item->classes, true ) || in_array( 'kf-season-switcher', $item->classes, true ) ) ) {
        $atts['data-season-id'] = (int) $item->kf_season_id;
    }
    return $atts;
}
add_filter('nav_menu_link_attributes', 'kf_add_season_switcher_data_attribute', 10, 3);


function kf_add_login_logout_link( $items, $args ) {
    $menu_location = 'primary'; 
    if ( isset($args->theme_location) && $args->theme_location == $menu_location ) {
        if ( is_user_logged_in() ) {
            $items .= '<li class="menu-item kf-logout-link"><a href="' . wp_logout_url( home_url() ) . '">Logout</a></li>';
        } else {
            $items .= '<li class="menu-item kf-login-link"><a href="' . wp_login_url( get_permalink() ) . '">Login</a></li>';
        }
    }
    return $items;
}
add_filter( 'wp_nav_menu_items', 'kf_add_login_logout_link', 20, 2 );

/**
 * Points a placeholder menu item at the active season's current week summary.
 *
 * Add a menu item in Appearance > Menus with the CSS class kf-week-summary-placeholder (a
 * custom link, URL "#"). Its title and link are rewritten per request, because the week id
 * lives in the URL and changes every week — there is no static URL to bookmark.
 *
 * Same shape as the season switcher above: a placeholder the site owner positions, filled in
 * here. The item is removed when the active season has no week players can see yet.
 */
function kf_render_week_summary_in_menu( $items, $args ) {
    if ( ! isset( $args->theme_location ) || $args->theme_location !== 'primary' ) {
        return $items;
    }

    $placeholder_class = 'kf-week-summary-placeholder';
    $item_key = -1;
    foreach ( $items as $key => $menu_item ) {
        if ( ! empty( $menu_item->classes ) && in_array( $placeholder_class, $menu_item->classes, true ) ) {
            $item_key = $key;
            break;
        }
    }
    if ( $item_key === -1 ) { return $items; }

    if ( ! is_user_logged_in() ) {
        unset( $items[ $item_key ] );
        return array_values( $items );
    }
    if ( session_status() === PHP_SESSION_NONE && ! headers_sent() ) { session_start(); }

    $season_id = (int) ( $_SESSION['kf_active_season_id'] ?? 0 );
    $week      = null;
    if ( $season_id > 0 ) {
        global $wpdb;
        $week = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, week_number FROM {$wpdb->prefix}weeks
             WHERE season_id = %d AND status <> 'draft'
             ORDER BY week_number DESC LIMIT 1",
            $season_id
        ) );
    }

    // Nothing to show yet: drop the item rather than leave a link to nowhere.
    if ( ! $week ) {
        unset( $items[ $item_key ] );
        return array_values( $items );
    }

    $items[ $item_key ]->title = 'Week ' . (int) $week->week_number . ' Summary';
    $items[ $item_key ]->url   = site_url( '/week-summary/?week_id=' . (int) $week->id );

    return $items;
}
add_filter( 'wp_nav_menu_objects', 'kf_render_week_summary_in_menu', 20, 2 );

function kf_filter_menu_items_by_role( $items, $args ) {
    if ( is_admin() ) { return $items; }
    $is_commissioner = kf_is_any_commissioner();
    $hidden_parent_ids = [];
    foreach ( $items as $key => $item ) {
        $hide_this_item = ( in_array( 'kf-commissioner-only', $item->classes ) && ! $is_commissioner ) ||
                          ( in_array( 'kf-player-only', $item->classes ) && ! is_user_logged_in() );
        $parent_is_hidden = !empty($item->menu_item_parent) && in_array($item->menu_item_parent, $hidden_parent_ids);

        if ( $hide_this_item || $parent_is_hidden ) {
            $hidden_parent_ids[] = $item->ID;
            unset( $items[$key] );
        }
    }
    return $items;
}
add_filter( 'wp_nav_menu_objects', 'kf_filter_menu_items_by_role', 10, 2 );
// --- END OF FILE: kf-menus.php ---