<?php

if (!defined('ABSPATH')) {
    exit;
}

function aa_ad_manager_register_campaigns_taxonomy() {
    $labels = array(
        'name'          => 'Campaigns',
        'singular_name' => 'Campaign',
        'search_items'  => 'Search Campaigns',
        'all_items'     => 'All Campaigns',
        'edit_item'     => 'Edit Campaign',
        'update_item'   => 'Update Campaign',
        'add_new_item'  => 'Add New Campaign',
        'new_item_name' => 'New Campaign Name',
        'menu_name'     => 'Campaigns',
    );

    $args = array(
        'hierarchical'      => false,
        'labels'            => $labels,
        'show_ui'           => true,
        'show_admin_column' => true,
        'query_var'         => true,
        'rewrite'           => array('slug' => 'aa_campaigns'),
    );

    register_taxonomy('aa_campaigns', array('aa_ads'), $args);
}
add_action('init', 'aa_ad_manager_register_campaigns_taxonomy');

function aa_ad_manager_register_clients_taxonomy() {
    $labels = array(
        'name'          => 'Clients',
        'singular_name' => 'Client',
        'search_items'  => 'Search Clients',
        'all_items'     => 'All Clients',
        'edit_item'     => 'Edit Client',
        'update_item'   => 'Update Client',
        'add_new_item'  => 'Add New Client',
        'new_item_name' => 'New Client Name',
        'menu_name'     => 'Clients',
    );

    $args = array(
        'hierarchical'      => false,
        'labels'            => $labels,
        'show_ui'           => true,
        'show_admin_column' => true,
        'query_var'         => true,
        'rewrite'           => array('slug' => 'aa_clients'),
    );

    register_taxonomy('aa_clients', array('aa_ads'), $args);
}
add_action('init', 'aa_ad_manager_register_clients_taxonomy');

