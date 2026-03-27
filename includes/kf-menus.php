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
    $is_commissioner = current_user_can('manage_options');

    // CORRECTED: The table name is just 'seasons', not '{$wpdb->prefix}seasons'. $wpdb->prefix is already 'edk_'.
    // Only show active seasons in the switcher (archived seasons are excluded)
    if ($is_commissioner) {
        $seasons_table = $wpdb->prefix . 'seasons';
        $seasons = $wpdb->get_results("SELECT id, name, is_active FROM $seasons_table WHERE is_active = 1 ORDER BY name ASC");
    } else {
        $seasons_table = $wpdb->prefix . 'seasons';
        $season_players_table = $wpdb->prefix . 'season_players';
        $seasons = $wpdb->get_results($wpdb->prepare(
            "SELECT s.id, s.name, s.is_active FROM $seasons_table s
             JOIN $season_players_table sp ON s.id = sp.season_id
             WHERE sp.user_id = %d AND sp.status = 'accepted' AND s.is_active = 1
             ORDER BY s.name ASC", $user_id
        ));
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
    
    // --- FIX #1 --- Store the active season ID on the parent menu item object itself.
    // We will use this in the filter function below to add the data-season-id attribute.
    $items[$parent_item_key]->attr_title = $active_season_id;


    $submenu_items = [];
    foreach ($seasons as $season) {
        if ($season->id == $active_season_id) continue;
        $item = new stdClass();
        $item->ID = $season->id + 10000;
        $item->title = $season->name;
        $item->url = '#'; 
        $item->menu_item_parent = $parent_item_id;
        $item->menu_order = 500 + $season->id; 
        $item->type = 'custom';
        $item->object = 'custom';
        $item->object_id = '';
        $item->classes = ['kf-season-switcher-item', 'menu-item', 'menu-item-type-custom'];
        $item->attr_title = $season->id; // Store the ID for the filter function
        $item->db_id = 0;
        $submenu_items[] = $item;
    }
    
    array_splice($items, $parent_item_key + 1, 0, $submenu_items);
    
    return $items;
}
add_filter('wp_nav_menu_objects', 'kf_render_season_switcher_in_menu', 20, 2);


function kf_add_season_switcher_data_attribute($atts, $item, $args) {
    // For the sub-items (the other seasons in the dropdown)
    if (isset($item->classes) && in_array('kf-season-switcher-item', $item->classes)) {
        $atts['data-season-id'] = $item->attr_title;
    }
    // --- FIX #2 --- For the main parent item (the currently active season)
    if (isset($item->classes) && in_array('kf-season-switcher', $item->classes)) {
        $atts['data-season-id'] = $item->attr_title;
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

function kf_filter_menu_items_by_role( $items, $args ) {
    if ( is_admin() ) { return $items; }
    $is_commissioner = current_user_can('manage_options');
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