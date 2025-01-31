<?php

function register_rm_menu_item_block() {
    register_block_type(__DIR__ . '/src/blocks/rm_menu_item/block.json', array(
        'render_callback' => 'render_rm_menu_item_block'
    ));
}
add_action('init', 'register_rm_menu_item_block');

// Include the render function
require_once plugin_dir_path(__FILE__) . 'includes/blocks/rm_menu_item/render.php';
