<?php
// includes/meta-admin.php
// Register the custom meta box for cpt "race"
// Handle changes in metadata via admin panel
// Clean up attachments on post deletion

if (!defined('ABSPATH')) exit; // Exit if accessed directly

add_action( 'add_meta_boxes', 'rm_add_meta_box' );
add_action( 'save_post_race', 'rm_save_meta_box_data' ); // this hook is automatically created for cpt's
//add_action( 'save_post_race', 'rm_meta_set_last_race_inactive' ); // currently this is only used on upload of a new race via REST API
//add_action( 'before_delete_race', 'rm_delete_all_attachments' ); // this hook does not exist!
add_action( 'before_delete_post', 'rm_delete_all_attachments' );

// Add custom columns to the admin posts list for the 'race' CPT
add_filter( 'manage_race_posts_columns', 'rm_add_race_columns' );
function rm_add_race_columns( $columns ) {
    // Add new columns
    $columns['race_live'] = __( 'Live Status', 'wp-racemanager' );
    $columns['last_upload'] = __( 'Last Upload', 'wp-racemanager' );

    // Rearrange or modify columns (optional)
    $new_columns = [];
    foreach ( $columns as $key => $title ) {
        $new_columns[$key] = $title;
        if ( $key === 'title' ) {
            $new_columns['race_live'] = __( 'Live Status', 'wp-racemanager' );
            $new_columns['last_upload'] = __( 'Last Upload', 'wp-racemanager' );
        }
    }

    return $new_columns;
}

// Populate custom column content
add_action( 'manage_race_posts_custom_column', 'rm_race_custom_column_content', 10, 2 );
function rm_race_custom_column_content( $column, $post_id ) {
    if ( $column === 'race_live' ) {
        // Display live status
        $race_live = get_post_meta( $post_id, '_race_live', true );
        //echo $race_live ? __( 'Yes (Unlocked)', 'wp-racemanager' ) : __( 'No (Locked)', 'wp-racemanager' );
        echo $race_live ? __( 'Live (Unlocked)', 'wp-racemanager' ) : __( 'Archive (Locked)', 'wp-racemanager' );
        // Add a hidden span to help with Quick Edit prepopulation
        //echo '<span class="hidden rm_live_status">' . esc_html( $race_live ) . '</span>';
        // Add the custom field value to #inline_{postId} container
        echo '<div class="hidden" id="custom_inline_' . $post_id . '">';
        echo '<div class="rm_live_status">' . esc_html( $race_live ) . '</div>';
        echo '</div>';
    } elseif ( $column === 'last_upload' ) {
        // Display last upload date
        $last_upload = get_post_meta( $post_id, '_race_last_upload', true );
        echo $last_upload ? esc_html( $last_upload ) : __( 'Not available', 'wp-racemanager' );
    }
}

// Make the columns sortable (optional)
add_filter( 'manage_edit-race_sortable_columns', 'rm_make_race_columns_sortable' );
function rm_make_race_columns_sortable( $columns ) {
    $columns['race_live'] = 'race_live';
    $columns['last_upload'] = 'last_upload';
    return $columns;
}

// Handle sorting by custom columns (optional)
add_action( 'pre_get_posts', 'rm_sort_race_columns' );
function rm_sort_race_columns( $query ) {
    if ( ! is_admin() || ! $query->is_main_query() ) {
        return;
    }

    if ( $query->get( 'orderby' ) === 'race_live' ) {
        $query->set( 'meta_key', '_race_live' );
        $query->set( 'orderby', 'meta_value_num' ); // Use 'meta_value' for strings
    }

    if ( $query->get( 'orderby' ) === 'last_upload' ) {
        $query->set( 'meta_key', '_race_last_upload' );
        $query->set( 'orderby', 'meta_value' );
    }
}

// Add a field to the Quick Edit interface
add_action( 'quick_edit_custom_box', 'rm_add_quick_edit_live_status', 10, 2 );
function rm_add_quick_edit_live_status( $column_name, $post_type ) {
    if ( $post_type !== 'race' || $column_name !== 'race_live' ) {
        return;
    }
    ?>
    <fieldset class="inline-edit-col-right">
        <div class="inline-edit-col">
            <label>
                <span class="title"><?php esc_html_e( 'Live Status', 'wp-racemanager' ); ?></span>
                <span class="input-text-wrap">
                    <select name="rm_quick_edit_live_status">
                        <option value="1"><?php esc_html_e( 'Live (Unlocked)', 'wp-racemanager' ); ?></option>
                        <option value="0"><?php esc_html_e( 'Archive (Locked)', 'wp-racemanager' ); ?></option>
                    </select>
                </span>
            </label>
        </div>
    </fieldset>
    <?php
}

add_action( 'admin_enqueue_scripts', 'rm_enqueue_quick_edit_script' );
function rm_enqueue_quick_edit_script( $hook ) {
    if ( $hook === 'edit.php' ) { // Only enqueue on the post list screen
        wp_enqueue_script(
            'rm-quick-edit',
            plugin_dir_url( __DIR__ ) . 'js/rm-cpt-quick-edit.js', // Adjust the path as needed
            [ 'jquery', 'inline-edit-post' ],
            '1.0.3',
            true
        );
    }
}

// Save the Quick Edit data
add_action( 'save_post', 'rm_save_quick_edit_data' );
function rm_save_quick_edit_data( $post_id ) {
    // Ensure this is for the 'race' CPT
    if ( get_post_type( $post_id ) !== 'race' ) {
        return;
    }

    // Check permissions
    if ( ! current_user_can( 'edit_post', $post_id ) ) {
        return;
    }

    // Check and save the live status
    if ( isset( $_POST['rm_quick_edit_live_status'] ) ) {
        update_post_meta( $post_id, '_race_live', intval( $_POST['rm_quick_edit_live_status'] ) );
    }
}

function rm_add_meta_box() {
    add_meta_box(
        'rm_meta_data_box',             // HTML 'id' attribute
        __( 'Race Metadata', 'wp-racemanager' ), // Title
        'rm_render_meta_box',      // Callback function to display the UI
        'race',                         // Post type
        'side',                         // Context ('normal', 'side', 'advanced')
        'default'                       // Priority
    );
}

function rm_render_meta_box( $post ) {
    // Retrieve existing metadata (for example, the last update time)
    $last_upload = get_post_meta( $post->ID, '_race_last_upload', true );
    //$json_attach_id = get_post_meta( $post->ID, '_race_json_attachment_id', true );
    $race_live = get_post_meta( $post->ID, '_race_live', true );
    
    // Security nonce (recommended)
    wp_nonce_field( 'rm_meta_box', 'rm_meta_box_nonce' );
    
    ?>
    <p>
        <label for="rm_live"><?php _e( 'Race Live?', 'wp-racemanager' ); ?></label>
        <input type="checkbox" 
               id="rm_live" 
               name="rm_live" 
               value="1"
               <?php checked( $race_live, 1 ); ?> />
    </p>
    <p>
        <label for="rm_last_upload"><?php _e( 'Last Upload:', 'wp-racemanager' ); ?></label>
        <input type="text" 
               id="rm_last_upload" 
               name="rm_last_upload" 
               value="<?php echo esc_attr( $last_upload ); ?>" 
               readonly />
    </p>
    <?php
}

function rm_save_meta_box_data( $post_id ) {
    // Check if our nonce is set and valid
    if ( ! isset( $_POST['rm_meta_box_nonce'] ) ||
         ! wp_verify_nonce( $_POST['rm_meta_box_nonce'], 'rm_meta_box' ) ) {
        return;
    }
    
    // Check the user’s permissions
    if ( ! current_user_can( 'edit_post', $post_id ) ) {
        return;
    }
    
    // Skip if this is an autosave
    if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
        return;
    }

    // Update the attachment ID meta if posted
/*     if ( isset( $_POST['rm_json_attach_id'] ) ) {
        update_post_meta(
            $post_id,
            '_race_json_attachment_id',
            absint( $_POST['rm_json_attach_id'] )
        );
    } */

    // Update the race status meta if posted
    if ( isset( $_POST['rm_live'] ) ) {
        update_post_meta( $post_id, '_race_live', 1 );
    } else {
        update_post_meta( $post_id, '_race_live', 0 );
    }

    // Potentially update last_upload if you want it editable or
    // automatically set it. For example:
    // if( isset( $_POST['rm_last_upload'] ) ) {
    //     update_post_meta( $post_id, '_race_last_upload', sanitize_text_field( $_POST['rm_last_upload'] ) );
    // }

    // Or you might auto-update:
    // update_post_meta( $post_id, '_race_last_upload', current_time( 'mysql' ) );
}

// Delete the attached JSON files when the post is deleted
function rm_delete_all_attachments( $post_id ) {

    // Ensure this is a 'race' post
    if ( get_post_type( $post_id ) !== 'race' ) {
        return;
    }
    
    // Fetch all attachments related to the race post
    $attachments = get_posts( [
        'post_type'   => 'attachment',
        'post_parent' => $post_id,
        'numberposts' => -1, // Get all attachments
        'fields'      => 'ids', // Only fetch IDs to reduce overhead
    ] );

    // Loop through each attachment
    foreach ( $attachments as $attachment_id ) {
        // Force delete the attachment from database and filesystem
        $deleted = wp_delete_attachment( $attachment_id, true );

        if ( ! $deleted ) {
            error_log( "Failed to delete attachment with ID $attachment_id." );
        }
    }
}

function rm_meta_set_last_race_inactive( $post_id ) {
    // Ensure this logic only runs for the 'race' post type
    if ( get_post_type( $post_id ) !== 'race' ) {
        return;
    }

    // Skip if this is an autosave or a revision
    if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
        return;
    }

    if ( wp_is_post_revision( $post_id ) ) {
        return;
    }

    // Fetch the most recent race post that is NOT the current one
    $last_race = get_posts( [
        'post_type'      => 'race',
        'post_status'    => 'publish', // Adjust based on your needs
        'numberposts'    => 1,
        'orderby'        => 'date',
        'order'          => 'DESC',
        'exclude'        => [ $post_id ], // Exclude the current post being updated
        'fields'         => 'ids',       // Only fetch the ID
    ] );

    if ( ! empty( $last_race ) ) {
        $last_race_id = $last_race[0];
        update_post_meta( $last_race_id, '_race_live', 0 ); // Set `_race_live` to 0
    }
}
