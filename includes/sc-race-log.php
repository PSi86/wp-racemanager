<?php
// includes/sc-race-log.php
// Register the REST API routes

// In your plugin file (or functions.php):

// 1) Register the shortcode
add_shortcode( 'rm_race_log', 'race_log_shortcode' );

/**
 * Shortcode callback: [rm_race_log]
 *
 * Expects race_id in the URL (e.g. ?race_id=123).
 * Outputs a scrollable list of notifications stored in _race_notification_log.
 */
function race_log_shortcode( $atts ) {
    // Grab and sanitize race_id from URL
    $race_id = isset( $_GET['race_id'] ) ? absint( $_GET['race_id'] ) : 0;
    if ( ! $race_id ) {
        return '<p><em>No race specified.</em></p>';
    }

    // Fetch the logged notifications
    $notifications = get_post_meta( $race_id, '_race_notification_log', true );
    if ( empty( $notifications ) || ! is_array( $notifications ) ) {
        return '<p><em>The race log is currently empty.</em></p>';
    }

    // Build the HTML
    ob_start();
    ?>
    <h2>Race Log</h2>
    <div class="race-notifications-log" style="
        max-height: 400px;
        overflow-y: auto;
        border: 1px solid #ddd;
        padding: 10px;
        background: #fafafa;
    ">
        <?php foreach ( $notifications as $n ) : ?>
            <div class="race-notification-item" style="margin-bottom: 20px; clear: both;">
                <strong>
                    <?php echo esc_html( $n['msg_time'] ); ?> -
                    <?php echo esc_html( $n['msg_title'] ); ?>
                </strong>
                <div style="margin-top: 5px;">
                    <?php if ( ! empty( $n['msg_icon'] ) ) : ?>
                        <img src="<?php echo esc_url( $n['msg_icon'] ); ?>"
                             alt=""
                             style="max-width: 64px; max-height: 64px; float: left; margin-right: 10px;">
                    <?php endif; ?>

                    <div style="overflow: hidden;">
                        <p><?php echo nl2br( esc_html( $n['msg_body'] ) ); ?></p>
                    </div>
                </div>

                <?php if ( ! empty( $n['msg_url'] ) ) : ?>
                    <p style="clear: both; margin-top: 8px;">
                        <strong>Link:</strong>
                        <a href="<?php echo esc_url( $n['msg_url'] ); ?>"
                           target="_blank"
                           rel="noopener">
                           <?php echo esc_html( $n['msg_url'] ); ?>
                        </a>
                    </p>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
    <?php
    return ob_get_clean();
}
