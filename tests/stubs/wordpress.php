<?php
/**
 * tests/stubs/wordpress.php
 *
 * The slice of WordPress the plugin touches, reimplemented just far enough for the suites.
 *
 * Everything is guarded with function_exists(), so a suite that needs different behaviour
 * defines its own version *before* requiring this file. Where the real behaviour matters --
 * home_url() prepending a slash, add_query_arg()'s two signatures -- these follow core
 * rather than a convenient approximation, because tests that assume the wrong thing are
 * worse than no tests.
 */

if ( ! defined( 'RM_TEST_DIR' ) ) {
    exit( 'Load tests/bootstrap.php first.' );
}

/* -------------------------------------------------------------------------
 * Fixture state, shared with the suites through globals
 * ---------------------------------------------------------------------- */

$GLOBALS['rm_options']     = isset( $GLOBALS['rm_options'] ) ? $GLOBALS['rm_options'] : array();
$GLOBALS['rm_posts']       = isset( $GLOBALS['rm_posts'] ) ? $GLOBALS['rm_posts'] : array();
$GLOBALS['rm_query_vars']  = isset( $GLOBALS['rm_query_vars'] ) ? $GLOBALS['rm_query_vars'] : array();
$GLOBALS['rm_rewrite']     = array();
$GLOBALS['rm_filters']     = array();
$GLOBALS['rm_head_actions'] = array();
$GLOBALS['rm_can_edit']    = false;

if ( ! class_exists( 'WP_Post' ) ) {
    class WP_Post {
        public $ID = 0;
        public $post_type = 'post';
        public $post_name = '';
        public $post_status = 'publish';
        public $post_parent = 0;
        public $post_title = '';
        public $menu_order = 0;

        public function __construct( array $props = array() ) {
            foreach ( $props as $key => $value ) {
                $this->$key = $value;
            }
        }
    }
}

/**
 * Register a fixture post.
 *
 * @param int    $id     Post ID.
 * @param string $type   Post type.
 * @param string $name   Post slug.
 * @param string $status Post status.
 * @param int    $parent Parent post ID.
 * @param string $title  Post title.
 * @return WP_Post
 */
function rm_test_post( $id, $type, $name, $status = 'publish', $parent = 0, $title = '' ) {
    $post = new WP_Post( array(
        'ID'          => $id,
        'post_type'   => $type,
        'post_name'   => $name,
        'post_status' => $status,
        'post_parent' => $parent,
        'post_title'  => '' !== $title ? $title : ucfirst( $name ),
    ) );

    $GLOBALS['rm_posts'][ $id ] = $post;

    return $post;
}

/* -------------------------------------------------------------------------
 * Options, posts, queries
 * ---------------------------------------------------------------------- */

if ( ! function_exists( 'get_option' ) ) {
    function get_option( $key, $default = false ) {
        return array_key_exists( $key, $GLOBALS['rm_options'] ) ? $GLOBALS['rm_options'][ $key ] : $default;
    }
}
if ( ! function_exists( 'update_option' ) ) {
    function update_option( $key, $value, $autoload = null ) {
        $GLOBALS['rm_options'][ $key ] = $value;
        return true;
    }
}
if ( ! function_exists( 'get_post' ) ) {
    function get_post( $id ) {
        return isset( $GLOBALS['rm_posts'][ $id ] ) ? $GLOBALS['rm_posts'][ $id ] : null;
    }
}
if ( ! function_exists( 'get_post_type' ) ) {
    function get_post_type( $id ) {
        $post = get_post( $id );
        return $post ? $post->post_type : false;
    }
}
if ( ! function_exists( 'get_the_title' ) ) {
    function get_the_title( $post ) {
        if ( ! is_object( $post ) ) {
            $post = get_post( $post );
        }
        return $post ? $post->post_title : '';
    }
}
if ( ! function_exists( 'get_page_uri' ) ) {
    function get_page_uri( $id ) {
        $post = get_post( $id );
        if ( ! $post ) {
            return '';
        }
        $parts = array( $post->post_name );
        while ( $post && $post->post_parent ) {
            $post = get_post( $post->post_parent );
            if ( $post ) {
                array_unshift( $parts, $post->post_name );
            }
        }
        return implode( '/', $parts );
    }
}
if ( ! function_exists( 'get_posts' ) ) {
    function get_posts( $args = array() ) {
        $out = array();

        foreach ( $GLOBALS['rm_posts'] as $post ) {
            if ( isset( $args['post_type'] ) && $post->post_type !== $args['post_type'] ) {
                continue;
            }
            if ( isset( $args['post_parent'] ) && (int) $post->post_parent !== (int) $args['post_parent'] ) {
                continue;
            }
            if ( isset( $args['name'] ) && $post->post_name !== $args['name'] ) {
                continue;
            }
            if ( isset( $args['post_status'] ) && ! in_array( $post->post_status, (array) $args['post_status'], true ) ) {
                continue;
            }
            $out[] = $post;
        }

        if ( ! empty( $args['numberposts'] ) && $args['numberposts'] > 0 ) {
            $out = array_slice( $out, 0, (int) $args['numberposts'] );
        }

        return $out;
    }
}
if ( ! function_exists( 'get_query_var' ) ) {
    function get_query_var( $key, $default = '' ) {
        return isset( $GLOBALS['rm_query_vars'][ $key ] ) ? $GLOBALS['rm_query_vars'][ $key ] : $default;
    }
}

/* -------------------------------------------------------------------------
 * URLs -- these follow core exactly, the tests depend on it
 * ---------------------------------------------------------------------- */

if ( ! function_exists( 'home_url' ) ) {
    function home_url( $path = '' ) {
        // get_home_url(): $url .= '/' . ltrim( $path, '/' )
        $url = 'https://example.test';
        if ( $path && is_string( $path ) ) {
            $url .= '/' . ltrim( $path, '/' );
        }
        return $url;
    }
}
if ( ! function_exists( 'site_url' ) ) {
    function site_url( $path = '' ) {
        return home_url( $path );
    }
}
if ( ! function_exists( 'admin_url' ) ) {
    function admin_url( $path = '' ) {
        return 'https://example.test/wp-admin/' . $path;
    }
}
if ( ! function_exists( 'add_query_arg' ) ) {
    function add_query_arg( ...$args ) {
        // Core accepts add_query_arg( array $args, $url ) and add_query_arg( $key, $value, $url ).
        if ( is_array( $args[0] ) ) {
            $pairs = $args[0];
            $url   = isset( $args[1] ) ? $args[1] : '';
        } else {
            $pairs = array( $args[0] => $args[1] );
            $url   = isset( $args[2] ) ? $args[2] : '';
        }
        $separator = str_contains( $url, '?' ) ? '&' : '?';
        return $url . $separator . http_build_query( $pairs );
    }
}
if ( ! function_exists( 'user_trailingslashit' ) ) {
    function user_trailingslashit( $string, $type = '' ) {
        return rtrim( $string, '/' ) . '/';
    }
}
if ( ! function_exists( 'trailingslashit' ) ) {
    function trailingslashit( $string ) {
        return rtrim( $string, '/\\' ) . '/';
    }
}
if ( ! function_exists( 'wp_parse_url' ) ) {
    function wp_parse_url( $url, $component = -1 ) {
        return -1 === $component ? parse_url( $url ) : parse_url( $url, $component );
    }
}

/* -------------------------------------------------------------------------
 * Escaping, sanitising, encoding
 * ---------------------------------------------------------------------- */

if ( ! function_exists( 'esc_url' ) ) {
    function esc_url( $url, $protocols = null, $context = 'display' ) {
        return str_replace( '&', '&#038;', (string) $url );
    }
}
if ( ! function_exists( 'esc_url_raw' ) ) {
    function esc_url_raw( $url, $protocols = null ) {
        return (string) $url;
    }
}
if ( ! function_exists( 'esc_html' ) ) {
    function esc_html( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES ); }
}
if ( ! function_exists( 'esc_attr' ) ) {
    function esc_attr( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES ); }
}
if ( ! function_exists( 'esc_textarea' ) ) {
    function esc_textarea( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES ); }
}
if ( ! function_exists( 'esc_html__' ) ) {
    function esc_html__( $text, $domain = null ) { return esc_html( $text ); }
}
if ( ! function_exists( 'esc_html_e' ) ) {
    function esc_html_e( $text, $domain = null ) { echo esc_html( $text ); }
}
if ( ! function_exists( '__' ) ) {
    function __( $text, $domain = null ) { return $text; }
}
if ( ! function_exists( '_e' ) ) {
    function _e( $text, $domain = null ) { echo $text; }
}
if ( ! function_exists( '_n' ) ) {
    function _n( $single, $plural, $number, $domain = null ) { return 1 === (int) $number ? $single : $plural; }
}
if ( ! function_exists( '_x' ) ) {
    function _x( $text, $context, $domain = null ) { return $text; }
}
if ( ! function_exists( 'sanitize_text_field' ) ) {
    function sanitize_text_field( $str ) { return trim( strip_tags( (string) $str ) ); }
}
if ( ! function_exists( 'sanitize_title' ) ) {
    function sanitize_title( $str ) { return strtolower( preg_replace( '/[^A-Za-z0-9_-]/', '-', (string) $str ) ); }
}
if ( ! function_exists( 'sanitize_key' ) ) {
    function sanitize_key( $str ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $str ) ); }
}
if ( ! function_exists( 'sanitize_email' ) ) {
    function sanitize_email( $mail ) { return filter_var( trim( (string) $mail ), FILTER_VALIDATE_EMAIL ) ?: ''; }
}
if ( ! function_exists( 'absint' ) ) {
    function absint( $value ) { return abs( (int) $value ); }
}
if ( ! function_exists( 'wp_unslash' ) ) {
    function wp_unslash( $value ) { return $value; }
}
if ( ! function_exists( 'wp_parse_args' ) ) {
    function wp_parse_args( $args, $defaults = array() ) { return array_merge( $defaults, (array) $args ); }
}
if ( ! function_exists( 'wp_json_encode' ) ) {
    function wp_json_encode( $data, $flags = 0 ) { return json_encode( $data, $flags ); }
}
if ( ! function_exists( 'wp_kses' ) ) {
    function wp_kses( $string, $allowed ) { return $string; }
}

/* -------------------------------------------------------------------------
 * Errors
 * ---------------------------------------------------------------------- */

if ( ! class_exists( 'WP_Error' ) ) {
    class WP_Error {
        private $code;
        private $message;
        private $data;

        public function __construct( $code = '', $message = '', $data = '' ) {
            $this->code    = $code;
            $this->message = $message;
            $this->data    = $data;
        }

        public function get_error_code() { return $this->code; }
        public function get_error_message() { return $this->message; }
        public function get_error_data() { return $this->data; }
    }
}
if ( ! function_exists( 'is_wp_error' ) ) {
    function is_wp_error( $thing ) { return $thing instanceof WP_Error; }
}

/* -------------------------------------------------------------------------
 * Hooks -- recorded rather than executed
 * ---------------------------------------------------------------------- */

if ( ! function_exists( 'add_filter' ) ) {
    function add_filter( $hook, $callback, $priority = 10, $args = 1 ) {
        $GLOBALS['rm_filters'][] = $hook;
    }
}
if ( ! function_exists( 'add_action' ) ) {
    function add_action( $hook, $callback, $priority = 10, $args = 1 ) {
        $GLOBALS['rm_filters'][] = $hook;
        if ( 'wp_head' === $hook ) {
            $GLOBALS['rm_head_actions'][] = $callback;
        }
    }
}
if ( ! function_exists( 'apply_filters' ) ) {
    function apply_filters( $hook, $value ) {
        if ( isset( $GLOBALS['rm_filter_overrides'][ $hook ] ) ) {
            return $GLOBALS['rm_filter_overrides'][ $hook ];
        }
        return $value;
    }
}
if ( ! function_exists( 'add_rewrite_rule' ) ) {
    function add_rewrite_rule( $regex, $query, $after = 'bottom' ) {
        $GLOBALS['rm_rewrite'][] = array( $regex, $query, $after );
    }
}
if ( ! function_exists( 'flush_rewrite_rules' ) ) {
    function flush_rewrite_rules( $hard = true ) {}
}
if ( ! function_exists( 'add_shortcode' ) ) {
    function add_shortcode( $tag, $callback ) {}
}
if ( ! function_exists( 'shortcode_atts' ) ) {
    function shortcode_atts( $pairs, $atts, $shortcode = '' ) { return array_merge( $pairs, (array) $atts ); }
}

/* -------------------------------------------------------------------------
 * Conditionals and capabilities
 * ---------------------------------------------------------------------- */

if ( ! function_exists( 'is_admin' ) )      { function is_admin() { return false; } }
if ( ! function_exists( 'is_page' ) )       { function is_page( $page = '' ) { return true; } }
if ( ! function_exists( 'is_404' ) )        { function is_404() { return false; } }
if ( ! function_exists( 'is_feed' ) )       { function is_feed() { return false; } }
if ( ! function_exists( 'is_robots' ) )     { function is_robots() { return false; } }
if ( ! function_exists( 'is_singular' ) )   { function is_singular( $type = '' ) { return false; } }
if ( ! function_exists( 'wp_doing_ajax' ) ) { function wp_doing_ajax() { return false; } }
if ( ! function_exists( 'get_queried_object' ) )    { function get_queried_object() { return null; } }
if ( ! function_exists( 'get_queried_object_id' ) ) { function get_queried_object_id() { return 0; } }
if ( ! function_exists( 'current_user_can' ) ) {
    function current_user_can( $cap = '', ...$args ) { return (bool) $GLOBALS['rm_can_edit']; }
}

/* -------------------------------------------------------------------------
 * Assets and filesystem
 * ---------------------------------------------------------------------- */

if ( ! function_exists( 'plugin_dir_url' ) ) {
    function plugin_dir_url( $file ) { return 'https://example.test/wp-content/plugins/wp-racemanager/'; }
}
if ( ! function_exists( 'plugin_dir_path' ) ) {
    function plugin_dir_path( $file ) { return RM_PLUGIN_DIR . '/'; }
}
if ( ! function_exists( 'wp_enqueue_script' ) )     { function wp_enqueue_script( ...$a ) {} }
if ( ! function_exists( 'wp_enqueue_style' ) )      { function wp_enqueue_style( ...$a ) {} }
if ( ! function_exists( 'wp_add_inline_script' ) )  { function wp_add_inline_script( ...$a ) {} }
if ( ! function_exists( 'wp_localize_script' ) )    { function wp_localize_script( ...$a ) {} }
if ( ! function_exists( 'wp_create_nonce' ) )       { function wp_create_nonce( $action = -1 ) { return 'test-nonce'; } }
if ( ! function_exists( 'wp_mkdir_p' ) ) {
    function wp_mkdir_p( $dir ) { return is_dir( $dir ) || @mkdir( $dir, 0777, true ); }
}

/**
 * Redirects throw, so suites can observe the target without the script exiting.
 */
if ( ! class_exists( 'RM_Test_Redirect' ) ) {
    class RM_Test_Redirect extends Exception {
        public $target;
        public $status;

        public function __construct( $target, $status ) {
            $this->target = $target;
            $this->status = $status;
            parent::__construct( $target );
        }
    }
}
if ( ! function_exists( 'wp_safe_redirect' ) ) {
    function wp_safe_redirect( $target, $status = 302 ) { throw new RM_Test_Redirect( $target, $status ); }
}
if ( ! function_exists( 'wp_redirect' ) ) {
    function wp_redirect( $target, $status = 302 ) { throw new RM_Test_Redirect( $target, $status ); }
}

/**
 * Run a callable and return the redirect it triggered, if any.
 *
 * @param callable $fn Callable to run.
 * @return array{0:string,1:int}|null [ target, status ] or null when nothing redirected.
 */
function rm_test_redirect_from( callable $fn ) {
    try {
        $fn();
        return null;
    } catch ( RM_Test_Redirect $redirect ) {
        return array( $redirect->target, $redirect->status );
    }
}

require_once RM_TEST_DIR . '/stubs/class-wp-racemanager.php';
