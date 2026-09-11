<?php
/**
 * includes/after-response.php
 *
 * Work that waits until the client has its answer.
 *
 * The upload and notify-racers used to answer only once every push had gone out, one after another,
 * each waiting for its push service. Measured on the local site on 2026-09-11 with a stand-in push
 * service that answers in 100 ms: 10 pushes held the upload's answer 1.1 s, 50 pushes 5.1 s, 100
 * pushes 10.2 s. Real push services answered in 0.04-0.44 s from a home line, and Apple's twice
 * not within 20 s. A timer on a slow uplink gives up on an answer after 60 s and then reports a
 * stored upload as failed, so the pushes now go out after the answer.
 *
 * PHP-FPM and LiteSpeed let a script close the connection and go on working. Where neither does,
 * the task still runs at the end of the request, and the client waits for it as it always did.
 */

defined( 'ABSPATH' ) || exit;

/**
 * Run a task once the answer has gone out.
 *
 * Tasks run on `shutdown`, in the order they were added, after WordPress has flushed its output
 * buffers (priority 1) and the connection to the client has been closed where the server allows it.
 *
 * @param callable $task Called without arguments. What it throws is logged, not raised.
 */
function rm_after_response( callable $task ) {
    static $hooked = false;
    if ( ! $hooked ) {
        add_action( 'shutdown', 'rm_run_after_response', 1000 );
        $hooked = true;
    }
    $GLOBALS['rm_after_response_tasks'][] = $task;
}

/**
 * Close the connection to the client, then run the tasks rm_after_response() collected.
 *
 * Each task runs once. One that throws does not keep the others from running.
 */
function rm_run_after_response() {
    $tasks = $GLOBALS['rm_after_response_tasks'] ?? array();
    $GLOBALS['rm_after_response_tasks'] = array();
    if ( ! $tasks ) {
        return;
    }

    // The client may hang up once it has its answer; the tasks still have to run.
    ignore_user_abort( true );
    $closed = rm_finish_response();
    // With WP_DEBUG, debug.log says whether the server let the answer go first.
    if ( class_exists( '\RaceManager\WP_RaceManager' ) ) {
        \RaceManager\WP_RaceManager::write_log( sprintf(
            'rm_after_response: %d task(s) after the answer, connection closed by %s',
            count( $tasks ),
            '' === $closed ? 'nothing - the client waited for them' : $closed
        ) );
    }

    foreach ( $tasks as $task ) {
        try {
            $task();
        } catch ( \Throwable $e ) {
            error_log( 'rm_after_response: ' . $e->getMessage() );
        }
    }
}

/**
 * Hand the client its answer and close the connection, where the server can.
 *
 * @return string 'fastcgi' (PHP-FPM, the local site), 'litespeed' (LiteSpeed, production), or ''
 *                when neither is there and the client waits until the request ends.
 */
function rm_finish_response() {
    if ( function_exists( 'fastcgi_finish_request' ) ) {
        fastcgi_finish_request();
        return 'fastcgi';
    }
    if ( function_exists( 'litespeed_finish_request' ) ) {
        litespeed_finish_request();
        return 'litespeed';
    }
    return '';
}
