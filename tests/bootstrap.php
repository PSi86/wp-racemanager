<?php
/**
 * tests/bootstrap.php
 *
 * Shared setup for the plugin's test suites.
 *
 * The suites do not need a WordPress installation to run: they load the plugin's own files
 * against a small set of stubs and assert on the result. Two suites additionally exercise
 * real WordPress classes (the HTML API) -- those locate a WordPress checkout and skip
 * themselves cleanly when none is available. See tests/README.md.
 *
 * Each suite runs in its own PHP process (see tests/run.php), so suites are free to define
 * their own version of a stub before requiring this file; everything here is guarded with
 * function_exists().
 */

if ( PHP_SAPI !== 'cli' ) {
    exit( 'The test suites are meant to be run from the command line.' );
}

define( 'RM_TEST_DIR', __DIR__ );
define( 'RM_PLUGIN_DIR', dirname( __DIR__ ) );

if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', RM_TEST_DIR . '/.fake-wp/' );
}
if ( ! defined( 'WP_RACEMANAGER_VERSION' ) ) {
    define( 'WP_RACEMANAGER_VERSION', '1.1.0' );
}
if ( ! defined( 'WP_RACEMANAGER_DIR' ) ) {
    define( 'WP_RACEMANAGER_DIR', RM_PLUGIN_DIR . '/' );
}

/**
 * Locate a WordPress checkout, for the suites that exercise real core classes.
 *
 * Checked in order:
 *   1. The WP_CORE_DIR environment variable.
 *   2. The WordPress root above the plugin -- the normal case when the plugin sits in
 *      wp-content/plugins/ of a development install, so this needs no configuration.
 *   3. tests/.wordpress/, if someone dropped a checkout there (git-ignored).
 *
 * @return string|null Path to the WordPress root, or null if none was found.
 */
function rm_test_wp_core_dir() {
    static $found = false;

    if ( false !== $found ) {
        return $found;
    }

    $candidates = array();

    $env = getenv( 'WP_CORE_DIR' );
    if ( $env ) {
        $candidates[] = rtrim( $env, '/\\' );
    }

    $candidates[] = dirname( RM_PLUGIN_DIR, 3 ); // wp-content/plugins/<plugin> -> WordPress root
    $candidates[] = RM_TEST_DIR . '/.wordpress';

    foreach ( $candidates as $candidate ) {
        if ( is_file( $candidate . '/wp-includes/version.php' ) ) {
            $found = $candidate;
            return $found;
        }
    }

    $found = null;
    return $found;
}

/**
 * Load the WordPress HTML API, which the link-rewriting suite runs against directly.
 *
 * @return bool True if the API is available.
 */
function rm_test_load_html_api() {
    $core = rm_test_wp_core_dir();
    if ( ! $core ) {
        return false;
    }

    $inc   = $core . '/wp-includes';
    $files = array(
        '/html-api/class-wp-html-span.php',
        '/html-api/class-wp-html-text-replacement.php',
        '/html-api/class-wp-html-decoder.php',
        '/html-api/class-wp-html-attribute-token.php',
    );

    foreach ( $files as $file ) {
        if ( ! is_file( $inc . $file ) ) {
            return false;
        }
        require_once $inc . $file;
    }

    // WP 7.1's set_attribute() reaches for these two; pulling in all of kses.php and utf8.php
    // is not worth it, so they are provided verbatim instead.
    if ( ! function_exists( 'wp_has_noncharacters' ) ) {
        function wp_has_noncharacters( string $text ): bool {
            return (bool) preg_match( '/\xEF\xB7[\x90-\xAF]|\xEF\xBF[\xBE\xBF]/', $text );
        }
    }
    if ( ! function_exists( 'wp_kses_uri_attributes' ) ) {
        function wp_kses_uri_attributes() {
            return array(
                'action', 'archive', 'background', 'cite', 'classid', 'codebase', 'data',
                'formaction', 'href', 'icon', 'longdesc', 'manifest', 'poster', 'profile',
                'src', 'usemap', 'xmlns',
            );
        }
    }

    if ( ! is_file( $inc . '/html-api/class-wp-html-tag-processor.php' ) ) {
        return false;
    }
    require_once $inc . '/html-api/class-wp-html-tag-processor.php';

    return class_exists( 'WP_HTML_Tag_Processor' );
}

/* -------------------------------------------------------------------------
 * Tiny assertion helpers
 * ---------------------------------------------------------------------- */

$GLOBALS['rm_test_failures'] = 0;
$GLOBALS['rm_test_total']    = 0;
$GLOBALS['rm_test_skipped']  = false;

/**
 * Print a section heading.
 *
 * @param string $title Heading text.
 * @return void
 */
function rm_test_section( $title ) {
    echo "\n  " . $title . "\n";
}

/**
 * Assert a condition and report it.
 *
 * @param string $label     What is being asserted.
 * @param bool   $condition The result.
 * @param string $detail    Optional detail printed when the assertion fails.
 * @return bool The condition, so callers can branch on it.
 */
function rm_test_check( $label, $condition, $detail = '' ) {
    ++$GLOBALS['rm_test_total'];

    $status = $condition ? 'ok  ' : 'FAIL';
    printf( "    %-58s %s\n", $label, $status );

    if ( ! $condition ) {
        ++$GLOBALS['rm_test_failures'];
        if ( '' !== $detail ) {
            echo '         ' . $detail . "\n";
        }
    }

    return (bool) $condition;
}

/**
 * Skip the current suite, reporting why.
 *
 * @param string $reason Why the suite cannot run.
 * @return void
 */
function rm_test_skip( $reason ) {
    $GLOBALS['rm_test_skipped'] = true;
    echo "\n    SKIPPED: " . $reason . "\n";
    rm_test_finish();
}

/**
 * Print the suite summary and exit with a meaningful status code.
 *
 * Exit codes: 0 passed, 1 failed, 2 skipped.
 *
 * @return void
 */
function rm_test_finish() {
    if ( $GLOBALS['rm_test_skipped'] ) {
        exit( 2 );
    }

    $failures = $GLOBALS['rm_test_failures'];
    $total    = $GLOBALS['rm_test_total'];

    if ( $failures ) {
        printf( "\n    %d of %d checks failed\n", $failures, $total );
        exit( 1 );
    }

    printf( "\n    all %d checks passed\n", $total );
    exit( 0 );
}
