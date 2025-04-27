<?php
// includes/block-modifiers.php
/**
 * Modify the output of the details block if the current post type is "race".
 * Only the first two <details> tags are modified.
 * If the event end date is in the future, the details block will be open.
 */
function rm_modify_details_block_output( $block_content, $block ) {
    // Check if this is a singular page of the custom post type 'race'
    if ( is_singular( 'race' ) ) {
        // Adjust the block only if it matches your details block.
        // Change 'core/details' to the appropriate block name if your details block has a custom namespace.
        //error_log( 'Block processed: ' . ( isset( $block['blockName'] ) ? $block['blockName'] : 'no blockName' ) ); // Debugging: Log the block name
            
        if ( isset( $block['blockName'] ) && 'core/details' === $block['blockName'] ) {
            // Retrieve the event end date from post meta.
            $event_end = get_post_meta( get_the_ID(), '_race_event_end', true );
            if ( $event_end ) {
                // Convert the stored event end date to a UNIX timestamp.
                $event_timestamp = strtotime( $event_end );
                // Get the current time (adjusted for your WordPress timezone).
                $current_timestamp = current_time( 'timestamp' );
                if ( $event_timestamp < $current_timestamp ) {
                    // // If the event is in the future, ensure the details block is collapsed by removing any open attribute.
                    //$block_content = preg_replace( '/\s*open\b/', '', $block_content );
                } else {
                    // If open attribute is not already added, insert it into the <details> tag.
                    if ( false === strpos( $block_content, 'open>' ) ) {
                        $block_content = preg_replace( '/<details([^>]*)>/', '<details$1 open>', $block_content, 1);
                        // If you want to limit this to the first two <details> tags, you can use the limit parameter in preg_replace.
                        //$block_content = preg_replace( '/<details([^>]*)>/', '<details$1 open>', $block_content, 2 );
                    }
                }
            }
        }
    }
    return $block_content;
}
add_filter( 'render_block', 'rm_modify_details_block_output', 10, 2 );
