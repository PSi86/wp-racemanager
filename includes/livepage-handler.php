<?php
// includes/livepage-handler.php
// Live Microsite Session & URL Rewrite
// Uses PHP sessions to persist a selected race post ID and rewrites all /live/ page URLs to include the race_id parameter.
// Starts a session (only for /live pages) and appends the race_id from the session to all links in the /live hierarchy.

// Reminder: if you need to check for permissions, you can use a callback like this:
//'permission_callback' => function() {
//    return current_user_can( 'edit_posts' );
//},

if (!defined('ABSPATH')) exit; // Exit if accessed directly

/**
 * Start a PHP session if one isn't already active.
 */
function rm_start_session() {
    if ( rm_is_live_page() && !session_id() ) {
        session_start();
    }
}
add_action( 'init', 'rm_start_session', 1 );

/**
 * Helper function to retrieve the current race post ID.
 * It checks the URL first and then the session.
 *
 * @return int|false The race ID, or false if not set.
 */
function rm_get_current_race_id() {
    if ( ! empty( $_GET['race_id'] ) ) {
        $race_id = absint( $_GET['race_id'] );
        $_SESSION['live_race_id'] = $race_id; // Store in session for future requests
        return absint( $race_id );
    } elseif ( ! empty( $_SESSION['live_race_id'] ) ) {
        return absint( $_SESSION['live_race_id'] );
    }
    return false;
}

/**
 * Rewrite all page accesses inside the /live/ hierarchy by appending the race_id parameter.
 * This ensures that the URL is shareable and always includes the race selection.
 */
function rm_rewrite_live_urls() {
    // Only run on the frontend.
    if ( is_admin() ) {
        return;
    }

    // Check if the current URL is part of the /live/ hierarchy.
    $request_uri = $_SERVER['REQUEST_URI'];
    // We check if the URI starts with "/live" (covers /live, /live/pilots, etc.).
    //if ( preg_match( '#^/live(/|$)#', $request_uri ) ) {
    //if ( is_page( array( 'pilots', 'bracket', 'stats', 'next-up' ) ) ) {
    if ( (strpos($request_uri, '/wp/live/') === 0) ) {
        // If the race_id parameter is missing...
            $race_id = rm_get_current_race_id();
            if ( $race_id ) {
                // Build the current full URL with the race_id parameter appended.
                $redirect_url = add_query_arg( 'race_id', $race_id, $request_uri );
                // Avoid potential redirect loops.
                if ( $request_uri !== $redirect_url ) {
                    wp_redirect( $redirect_url );
                    exit;
                }
            }
            elseif ( ! is_page( array( 'live' ) ) ) {
                // no race_id found in the session and no race_id in the URL and not already on the landing page
                $redirect_url = home_url( '/live/' );
                // Avoid potential redirect loops.
                if ( $request_uri !== $redirect_url ) {
                    // Redirect to the landing page
                    wp_redirect( $redirect_url );
                    exit;
                }
                // Redirect to the landing page
                //wp_redirect( home_url( '/live/' ) );
                //exit;
            }
    }
}
add_action( 'template_redirect', 'rm_rewrite_live_urls', 2 );

/**
 * Shortcode to display pilots data.
 * Usage: [rm_pilots]
 */
function rm_sc_select_race( $atts ) {
    $race_id = rm_get_current_race_id();
    // TODO - this should not be necessary because of the rm_rewrite_live_urls function and its redirect to /live/
    if ( ! $race_id ) {
        return '<p>No race selected. Please go back to the <a href="' . esc_url( home_url( '/live/' ) ) . '">Race Selection</a> page.</p>';
    }
    // TODO - Race selection and optional pilot selection for notifications
    // Retrieve pilots data from race post meta (change 'pilots_data' as needed).
    $pilots = get_post_meta( $race_id, 'pilots_data', true );
    ob_start();
    if ( $pilots ) {
        echo '<div class="rm-pilots-content">' . esc_html( $pilots ) . '</div>';
    } else {
        echo '<p>No pilots data available for this race.</p>';
    }
    return ob_get_clean();
}
add_shortcode( 'rm_select_race', 'rm_sc_select_race' );

/**
 * Shortcode to display pilots data.
 * Usage: [rm_pilots]
 */
function rm_pilots_shortcode( $atts ) {
    $race_id = rm_get_current_race_id();
    if ( ! $race_id ) {
        return '<p>No race selected. Please go back to the <a href="' . esc_url( home_url( '/live/' ) ) . '">Race Selection</a> page.</p>';
    }
    // Retrieve pilots data from race post meta (change 'pilots_data' as needed).
    $pilots = get_post_meta( $race_id, 'pilots_data', true );
    ob_start();
    if ( $pilots ) {
        echo '<div class="rm-pilots-content">' . esc_html( $pilots ) . '</div>';
    } else {
        echo '<p>No pilots data available for this race.</p>';
    }
    return ob_get_clean();
}
add_shortcode( 'rm_pilots', 'rm_pilots_shortcode' );

/**
 * Shortcode to display bracket data.
 * Usage: [rm_bracket]
 */
function rm_bracket_shortcode( $atts ) {
    $race_id = rm_get_current_race_id();
    if ( ! $race_id ) {
        return '<p>No race selected. Please go back to the <a href="' . esc_url( home_url( '/live/' ) ) . '">Race Selection</a> page.</p>';
    }
    $bracket = get_post_meta( $race_id, 'bracket_data', true );
    ob_start();
    if ( $bracket ) {
        echo '<div class="rm-bracket-content">' . esc_html( $bracket ) . '</div>';
    } else {
        echo '<p>No bracket data available for this race.</p>';
    }
    return ob_get_clean();
}
add_shortcode( 'rm_bracket', 'rm_bracket_shortcode' );

/**
 * Shortcode to display stats data.
 * Usage: [rm_stats]
 */
function rm_stats_shortcode( $atts ) {
    $race_id = rm_get_current_race_id();
    if ( ! $race_id ) {
        return '<p>No race selected. Please go back to the <a href="' . esc_url( home_url( '/live/' ) ) . '">Race Selection</a> page.</p>';
    }
    $stats = get_post_meta( $race_id, 'stats_data', true );
    ob_start();
    if ( $stats ) {
        echo '<div class="rm-stats-content">' . esc_html( $stats ) . '</div>';
    } else {
        echo '<p>No stats data available for this race_id:' . esc_html( $race_id ) . '</p>';
    }
    return ob_get_clean();
}
add_shortcode( 'rm_stats', 'rm_stats_shortcode' );

/**
 * Optional: Output a navigation menu for the microsite.
 * Place this in your custom header for /live/ pages.
 */
/* function rm_microsite_navigation() {
    ?>
    <nav class="rm-microsite-nav">
        <ul>
            <li><a href="<?php echo esc_url( home_url( '/live/' ) ); ?>">Race Selection</a></li>
            <li><a href="<?php echo esc_url( rm_get_microsite_url( 'pilots' ) ); ?>">Pilots</a></li>
            <li><a href="<?php echo esc_url( rm_get_microsite_url( 'bracket' ) ); ?>">Bracket</a></li>
            <li><a href="<?php echo esc_url( rm_get_microsite_url( 'stats' ) ); ?>">Stats</a></li>
        </ul>
    </nav>
    <?php
} */
