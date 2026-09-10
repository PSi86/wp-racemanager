<?php
/**
 * Every asset the plugin enqueues carries WP_RACEMANAGER_VERSION -- checked in the source, so
 * that it covers every call site and not only the ones some suite happens to execute.
 *
 * live-shortcodes checks what the four live shortcodes actually register at runtime, and it did
 * its job there. But it can only see what it renders, and three enqueues sat outside it: the
 * service worker registration on every live page ('1.0.3'), the quick-edit script in the race
 * list ('1.0.3'), and the stylesheet of the blinking live dot in the navigation, which passed no
 * version at all -- WordPress then appends its *own* version, so the URL read
 * rm_live_page_link.css?ver=7.1 and would have changed with the next core update rather than
 * with the file. All three were still there after 1.2.0 had declared the rule settled.
 *
 * The scan reads tokens rather than matching text, so a commented-out enqueue does not count and
 * an argument that spans several lines or contains a nested call is split correctly.
 */

require_once __DIR__ . '/../bootstrap.php';

/**
 * The script and style functions whose fourth argument is the version.
 */
const RM_TEST_ENQUEUE_FUNCTIONS = array(
    'wp_enqueue_script',
    'wp_register_script',
    'wp_enqueue_style',
    'wp_register_style',
    'wp_enqueue_script_module',
    'wp_register_script_module',
);

/**
 * Files exempt from the rule, and why. Keep this list short and give every entry a reason.
 */
const RM_TEST_VERSION_EXEMPT = array(
    // [rm_viewer] is the pre-module bracket viewer. No live page uses it any more -- the
    // shortcode is referenced only from a commented-out line in rest-handler.php -- and changes
    // there are recorded rather than made (see the D1 note in docs/wordpress-update-audit.md).
    'includes/sc-rm_viewer.php' => 'legacy [rm_viewer] shortcode',
);

/**
 * The significant tokens of a PHP file: whitespace and comments dropped, everything else kept
 * in order. A commented-out enqueue is therefore invisible, which is what it is to WordPress.
 *
 * @param string $file Absolute path.
 * @return array
 */
function rm_test_significant_tokens( $file ) {
    $skip = array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_INLINE_HTML, T_OPEN_TAG, T_CLOSE_TAG );
    $out  = array();
    foreach ( token_get_all( file_get_contents( $file ) ) as $token ) {
        if ( is_array( $token ) && in_array( $token[0], $skip, true ) ) {
            continue;
        }
        $out[] = $token;
    }
    return $out;
}

/**
 * The text of a token, whether it is a bare character or an array.
 *
 * @param array|string $token
 * @return string
 */
function rm_test_token_text( $token ) {
    return is_array( $token ) ? $token[1] : $token;
}

/**
 * Every call to one of RM_TEST_ENQUEUE_FUNCTIONS in a file, with its arguments split at the
 * top-level commas. Function definitions and method calls of the same name are not calls to
 * the WordPress function and are left out.
 *
 * @param string $file Absolute path.
 * @return array[] Each: function, line, args (a list of token lists).
 */
function rm_test_enqueue_calls( $file ) {
    $tokens = rm_test_significant_tokens( $file );
    $count  = count( $tokens );
    $calls  = array();

    for ( $i = 0; $i < $count; $i++ ) {
        $token = $tokens[ $i ];
        if ( ! is_array( $token ) || ! in_array( $token[0], array( T_STRING, T_NAME_FULLY_QUALIFIED ), true ) ) {
            continue;
        }
        $name = strtolower( ltrim( $token[1], '\\' ) );
        if ( ! in_array( $name, RM_TEST_ENQUEUE_FUNCTIONS, true ) ) {
            continue;
        }
        $prev = $i > 0 ? $tokens[ $i - 1 ] : null;
        if ( is_array( $prev ) && in_array( $prev[0], array( T_FUNCTION, T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_NEW ), true ) ) {
            continue;
        }
        if ( ! isset( $tokens[ $i + 1 ] ) || '(' !== $tokens[ $i + 1 ] ) {
            continue;
        }

        $args    = array();
        $current = array();
        $depth   = 0;
        for ( $k = $i + 2; $k < $count; $k++ ) {
            $t    = $tokens[ $k ];
            $text = rm_test_token_text( $t );
            if ( in_array( $text, array( '(', '[', '{' ), true )
                || ( is_array( $t ) && in_array( $t[0], array( T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES ), true ) ) ) {
                ++$depth;
            } elseif ( in_array( $text, array( ')', ']', '}' ), true ) ) {
                if ( 0 === $depth ) {
                    if ( $current ) {
                        $args[] = $current; // a trailing comma leaves nothing behind
                    }
                    break;
                }
                --$depth;
            } elseif ( ',' === $text && 0 === $depth ) {
                $args[]  = $current;
                $current = array();
                continue;
            }
            $current[] = $t;
        }

        $calls[] = array( 'function' => $name, 'line' => $token[2], 'args' => $args );
    }

    return $calls;
}

/**
 * An argument as source text, for messages.
 *
 * @param array|null $arg Token list.
 * @return string
 */
function rm_test_arg_text( $arg ) {
    return null === $arg ? '' : implode( ' ', array_map( 'rm_test_token_text', $arg ) );
}

/**
 * The string literals inside an argument, unquoted and concatenated -- enough to see which file
 * a src expression like `plugin_dir_url( __DIR__ ) . 'css/x.css'` points at.
 *
 * @param array|null $arg Token list.
 * @return string
 */
function rm_test_arg_literals( $arg ) {
    $out = '';
    foreach ( (array) $arg as $t ) {
        if ( is_array( $t ) && T_CONSTANT_ENCAPSED_STRING === $t[0] ) {
            $out .= substr( $t[1], 1, -1 );
        }
    }
    return $out;
}

/**
 * Whether a version argument is the plugin's constant and nothing else.
 *
 * @param array|null $version Token list, or null when the argument is not passed.
 * @return bool
 */
function rm_test_is_plugin_version( $version ) {
    return null !== $version && 1 === count( $version ) && is_array( $version[0] )
        && 'WP_RACEMANAGER_VERSION' === ltrim( $version[0][1], '\\' );
}

rm_test_section( 'The scanner reads calls, not text' );
// Run against a fixture first, so that a scanner that quietly misses calls cannot report the
// plugin as clean.
$fixture = sys_get_temp_dir() . '/rm-asset-versions-fixture.php';
file_put_contents( $fixture, <<<'PHP'
<?php
// wp_enqueue_script( 'line-comment', 'x.js', array(), '1.0' );
/* wp_enqueue_style( 'block-comment', 'x.css' ); */
function wp_register_style( $handle ) {}
$obj->wp_enqueue_style( 'method-call', 'x.css', array(), '1.0' );
\wp_enqueue_style(
    'multi-line',
    plugin_dir_url( __DIR__ ) . 'css/multi.css',
    array( 'a', 'b' ),
    WP_RACEMANAGER_VERSION,
);
wp_enqueue_script_module( 'by-handle' );
PHP
);
$fixture_calls = rm_test_enqueue_calls( $fixture );
unlink( $fixture );
$fixture_handles = array_map( static fn( $c ) => rm_test_arg_text( $c['args'][0] ?? null ), $fixture_calls );
rm_test_check( 'comments, definitions and method calls are skipped',
    array( "'multi-line'", "'by-handle'" ) === $fixture_handles, implode( ', ', $fixture_handles ) );
$multi = $fixture_calls[0]['args'] ?? array();
rm_test_check( 'a multi-line call splits into its four arguments',
    4 === count( $multi ), count( $multi ) . ' arguments' );
rm_test_check( 'the src is read through the concatenation',
    'css/multi.css' === rm_test_arg_literals( $multi[1] ?? null ), rm_test_arg_text( $multi[1] ?? null ) );
rm_test_check( 'and the version is recognised as the plugin\'s',
    rm_test_is_plugin_version( $multi[3] ?? null ), rm_test_arg_text( $multi[3] ?? null ) );

$files = array( RM_PLUGIN_DIR . '/wp-racemanager.php' );
$iter  = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( RM_PLUGIN_DIR . '/includes', FilesystemIterator::SKIP_DOTS ) );
foreach ( $iter as $info ) {
    if ( 'php' === $info->getExtension() ) {
        $files[] = $info->getPathname();
    }
}
sort( $files );

$checked      = array(); // "file:line handle" of every plugin asset that was checked
$stale        = array(); // the ones that failed, with what they pass instead
$vendored     = array(); // bundled third-party libraries => whether their path carries a release
$exempt_stale = array(); // file => the calls in it that the exemption is covering for

foreach ( $files as $file ) {
    $rel = str_replace( '\\', '/', substr( $file, strlen( RM_PLUGIN_DIR ) + 1 ) );
    foreach ( rm_test_enqueue_calls( $file ) as $call ) {
        $handle = rm_test_arg_text( $call['args'][0] ?? null );
        $src    = $call['args'][1] ?? null;
        $where  = "$rel:{$call['line']} $handle";

        // Enqueueing a handle registered elsewhere passes no src, and so no version either.
        if ( null === $src || in_array( rm_test_arg_text( $src ), array( "''", '""' ), true ) ) {
            continue;
        }
        // A bundled library is versioned by its own release, and the release is in its path
        // (assets/swiper-11.2.6/) -- so its URL changes with the library whatever the query says.
        $path = rm_test_arg_literals( $src );
        if ( str_contains( $path, 'assets/' ) ) {
            $vendored[ $where ] = (bool) preg_match( '#assets/[^/]+-\d+(\.\d+)+/#', $path );
            continue;
        }

        $version = $call['args'][3] ?? null;
        $ok      = rm_test_is_plugin_version( $version );
        if ( isset( RM_TEST_VERSION_EXEMPT[ $rel ] ) ) {
            if ( ! $ok ) {
                $exempt_stale[ $rel ][] = $where;
            }
            continue;
        }

        $checked[] = $where;
        if ( ! $ok ) {
            $stale[] = $where . ' => '
                . ( null === $version ? 'no version, so WordPress appends its own' : rm_test_arg_text( $version ) );
        }
    }
}

rm_test_section( 'The scan reaches the plugin' );
// A floor, not an exact count: it is there so that a scan which found nothing -- a moved
// directory, say -- cannot pass as "every asset correctly versioned".
rm_test_check( 'it finds the plugin\'s enqueues', count( $checked ) >= 10, count( $checked ) . ' found' );
rm_test_check( 'including one known to be right',
    (bool) preg_grep( "#^includes/livepage-handler\.php:\d+ 'rm-updateStatus'$#", $checked ),
    implode( "\n         ", $checked ) );

rm_test_section( 'Every plugin asset carries WP_RACEMANAGER_VERSION' );
// A literal has to be remembered on every edit of the file beside it, and it never is. A
// returning visitor then keeps the cached copy and runs the previous release -- which looks like
// nothing is wrong, because the old file still works.
rm_test_check( 'no literal and no missing version, in any file', array() === $stale,
    implode( "\n         ", $stale ) );

rm_test_section( 'Bundled libraries are versioned by their path' );
rm_test_check( 'the Swiper bundle is recognised as one', (bool) $vendored, 'no enqueue under assets/ found' );
$unversioned = array_keys( array_filter( $vendored, static fn( $has_release ) => ! $has_release ) );
rm_test_check( 'every one carries its release in the directory name', array() === $unversioned,
    implode( "\n         ", $unversioned ) );

rm_test_section( 'Exemptions are still needed' );
// Once an exempt file is removed or brought into line, this fails, and its entry in
// RM_TEST_VERSION_EXEMPT can go.
foreach ( RM_TEST_VERSION_EXEMPT as $rel => $reason ) {
    rm_test_check( "$rel ($reason)", ! empty( $exempt_stale[ $rel ] ),
        'nothing left to exempt -- remove the entry' );
}

rm_test_finish();
