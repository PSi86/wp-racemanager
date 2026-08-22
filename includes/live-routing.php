<?php
// includes/live-routing.php
// Routing for the live micro-site: the selected race lives in the URL path, not in a PHP session.
//
//   /live/                          race selection (the live landing page)
//   /live/{race-slug}/{view}/       a race in one of the views
//   /live/{race-slug}/              redirects to the default view
//   /live/{view}/                   still valid; renders "no race selected"
//
// "{view}" is the slug of any child page of the configured live page (bracket, stats, ...),
// so the pages stay ordinary, editable WordPress pages.
//
// Everything here is stateless: the URL alone determines what is rendered, which makes live
// pages shareable, cacheable and independent per browser tab. Legacy "?race_id=123" URLs are
// still accepted and redirected to their canonical form.

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

/**
 * ID of the configured live landing page.
 *
 * @return int 0 if none is configured.
 */
function rm_live_page_id() {
    return absint( get_option( 'rm_live_page_id' ) );
}

/**
 * Cached routing facts: the live page path and the slugs of its child pages.
 *
 * Rebuilt whenever the live page changes or one of its children is saved or deleted.
 * Cached because rm_live_rewrite_rules() runs on every request.
 *
 * @return array{page_id: int, path: string, views: string[]}
 */
function rm_live_routing_cache() {
    $page_id = rm_live_page_id();
    $cache   = get_option( 'rm_live_routing', array() );

    if ( is_array( $cache ) && isset( $cache['page_id'] ) && (int) $cache['page_id'] === $page_id ) {
        return wp_parse_args( $cache, array( 'page_id' => $page_id, 'path' => '', 'views' => array() ) );
    }

    return rm_rebuild_live_routing_cache();
}

/**
 * Recompute and store the routing cache.
 *
 * @return array{page_id: int, path: string, views: string[]}
 */
function rm_rebuild_live_routing_cache() {
    $page_id = rm_live_page_id();
    $cache   = array( 'page_id' => $page_id, 'path' => '', 'views' => array() );

    if ( $page_id && 'page' === get_post_type( $page_id ) ) {
        $cache['path'] = trim( (string) get_page_uri( $page_id ), '/' );

        $children = get_posts( array(
            'post_type'              => 'page',
            'post_parent'            => $page_id,
            'post_status'            => 'publish',
            'numberposts'            => -1,
            'orderby'                => 'menu_order title',
            'order'                  => 'ASC',
            'no_found_rows'          => true,
            'update_post_term_cache' => false,
            'update_post_meta_cache' => false,
        ) );

        foreach ( $children as $child ) {
            if ( '' !== $child->post_name ) {
                $cache['views'][] = $child->post_name;
            }
        }
    }

    update_option( 'rm_live_routing', $cache, false );

    return $cache;
}

/**
 * Path of the live landing page relative to the site root, without slashes.
 *
 * @return string e.g. 'live'. Empty when no live page is configured.
 */
function rm_live_path() {
    $cache = rm_live_routing_cache();
    return $cache['path'];
}

/**
 * Slugs of the available views (the child pages of the live page).
 *
 * @return string[]
 */
function rm_get_live_view_slugs() {
    $cache = rm_live_routing_cache();
    return $cache['views'];
}

/**
 * The view a race URL points at when none is given.
 *
 * @return string Empty string when the live page has no children.
 */
function rm_get_default_live_view() {
    $views = rm_get_live_view_slugs();
    if ( ! $views ) {
        return '';
    }

    /**
     * Filters the view used when a race URL carries no view segment.
     *
     * @param string   $view  Slug of the default view.
     * @param string[] $views All available view slugs.
     */
    $default = apply_filters( 'rm_default_live_view', in_array( 'bracket', $views, true ) ? 'bracket' : $views[0], $views );

    return in_array( $default, $views, true ) ? $default : $views[0];
}

/**
 * Whether a slug is one of the live views.
 *
 * @param string $slug Slug to test.
 * @return bool
 */
function rm_is_live_view( $slug ) {
    return '' !== $slug && in_array( $slug, rm_get_live_view_slugs(), true );
}

/* -------------------------------------------------------------------------
 * Rewrite rules
 * ---------------------------------------------------------------------- */

/**
 * Register the race segment as a public query var.
 *
 * @param string[] $vars Registered query vars.
 * @return string[]
 */
function rm_live_query_vars( $vars ) {
    $vars[] = 'rm_race';
    return $vars;
}
add_filter( 'query_vars', 'rm_live_query_vars' );

/**
 * Map /live/{race}/{view}/ onto the existing view page plus an rm_race query var.
 *
 * Registered at the top so it wins over the generic page rules. Single-segment URLs such as
 * /live/bracket/ do not match and keep resolving as ordinary pages.
 *
 * @return void
 */
function rm_live_rewrite_rules() {
    $path  = rm_live_path();
    $views = rm_get_live_view_slugs();

    if ( '' === $path || ! $views ) {
        return;
    }

    /*
     * The second segment is an alternation of the actual view slugs rather than a generic
     * [^/]+. A generic pattern also swallows URLs that have nothing to do with a race:
     * /live/page/2/ (the selection page's own pagination) would resolve to
     * pagename=live/2&rm_race=page and 404, and /live/{race}/feed/ would break feeds.
     */
    $view_pattern = implode( '|', array_map( static function ( $view ) {
        return preg_quote( $view, '#' );
    }, $views ) );

    add_rewrite_rule(
        preg_quote( $path, '#' ) . '/([^/]+)/(' . $view_pattern . ')/?$',
        'index.php?pagename=' . $path . '/$matches[2]&rm_race=$matches[1]',
        'top'
    );
}
add_action( 'init', 'rm_live_rewrite_rules' );

/**
 * Rebuild the routing cache and flush the rewrite rules.
 *
 * @return void
 */
function rm_flush_live_rewrite_rules() {
    rm_rebuild_live_routing_cache();
    flush_rewrite_rules( false );
}

/**
 * Flush when the live page or one of its children changes.
 *
 * @param int     $post_id Post ID.
 * @param WP_Post $post    Post object.
 * @return void
 */
function rm_maybe_flush_live_rewrite_rules( $post_id, $post = null ) {
    $post = $post ? $post : get_post( $post_id );
    if ( ! $post || 'page' !== $post->post_type ) {
        return;
    }

    $live_page_id = rm_live_page_id();
    if ( ! $live_page_id ) {
        return;
    }

    if ( (int) $post->ID === $live_page_id || (int) $post->post_parent === $live_page_id ) {
        rm_flush_live_rewrite_rules();
    }
}
add_action( 'save_post_page', 'rm_maybe_flush_live_rewrite_rules', 10, 2 );
add_action( 'deleted_post', 'rm_maybe_flush_live_rewrite_rules', 10, 2 );

/**
 * Flush when the live page setting itself is changed.
 *
 * @return void
 */
function rm_live_page_option_changed() {
    rm_flush_live_rewrite_rules();
}
add_action( 'update_option_rm_live_page_id', 'rm_live_page_option_changed' );
add_action( 'add_option_rm_live_page_id', 'rm_live_page_option_changed' );

/* -------------------------------------------------------------------------
 * Resolving the current race
 * ---------------------------------------------------------------------- */

/**
 * Resolve a race by slug or numeric ID.
 *
 * Unpublished races resolve only for users who may edit them, so a draft race is not
 * exposed by guessing its slug.
 *
 * @param string|int $identifier Race slug or post ID.
 * @return WP_Post|null
 */
function rm_resolve_race( $identifier ) {
    if ( '' === $identifier || null === $identifier ) {
        return null;
    }

    $race = null;

    if ( is_numeric( $identifier ) ) {
        $post = get_post( absint( $identifier ) );
        if ( $post && 'race' === $post->post_type ) {
            $race = $post;
        }
    } else {
        $posts = get_posts( array(
            'name'                   => sanitize_title( $identifier ),
            'post_type'              => 'race',
            'post_status'            => array( 'publish', 'private', 'draft', 'pending', 'future' ),
            'numberposts'            => 1,
            'no_found_rows'          => true,
            'update_post_term_cache' => false,
            'update_post_meta_cache' => false,
        ) );
        $race = $posts ? $posts[0] : null;
    }

    if ( ! $race ) {
        return null;
    }

    if ( 'publish' !== $race->post_status && ! current_user_can( 'edit_post', $race->ID ) ) {
        return null;
    }

    return $race;
}

/**
 * The race the current request is about.
 *
 * Order of precedence: the rm_race path segment, then a legacy ?race_id parameter (which
 * rm_live_canonical_redirect() turns into a permanent redirect), then the current post when
 * that post is itself a race.
 *
 * @return WP_Post|null
 */
function rm_get_current_race() {
    // Memoised because the link rewriting calls this once per rendered block. Kept in a
    // global rather than a static so it can be invalidated (see rm_reset_current_race()).
    if ( array_key_exists( 'rm_current_race', $GLOBALS ) ) {
        return $GLOBALS['rm_current_race'];
    }

    $race = null;

    $slug = get_query_var( 'rm_race' );
    if ( '' !== $slug ) {
        $race = rm_resolve_race( $slug );
    }

    if ( ! $race && ! empty( $_GET['race_id'] ) ) {
        $race = rm_resolve_race( absint( wp_unslash( $_GET['race_id'] ) ) );
    }

    if ( ! $race && is_singular( 'race' ) ) {
        $race = get_queried_object();
    }

    $GLOBALS['rm_current_race'] = $race;

    return $race;
}

/**
 * Drop the memoised race so the next call resolves again.
 *
 * @return void
 */
function rm_reset_current_race() {
    unset( $GLOBALS['rm_current_race'] );
}

/**
 * ID of the race the current request is about.
 *
 * @return int|false
 */
function rm_get_current_race_id() {
    $race = rm_get_current_race();
    return $race ? (int) $race->ID : false;
}

/* -------------------------------------------------------------------------
 * Building URLs
 * ---------------------------------------------------------------------- */

/**
 * Canonical URL of a race inside the live area.
 *
 * @param WP_Post|int|string $race Race post, ID or slug.
 * @param string             $view Optional view slug; defaults to the default view.
 * @return string Empty string when the race or the live area cannot be resolved.
 */
function rm_live_url( $race, $view = '' ) {
    $path = rm_live_path();
    if ( '' === $path ) {
        return '';
    }

    if ( ! $race instanceof WP_Post ) {
        $race = rm_resolve_race( $race );
    }
    if ( ! $race ) {
        return '';
    }

    if ( ! rm_is_live_view( $view ) ) {
        $view = rm_get_default_live_view();
    }
    if ( '' === $view ) {
        return home_url( user_trailingslashit( '/' . $path ) );
    }

    return home_url( user_trailingslashit( '/' . $path . '/' . $race->post_name . '/' . $view ) );
}

/**
 * URL of the race selection page.
 *
 * @return string
 */
function rm_live_selection_url() {
    $path = rm_live_path();
    return $path ? home_url( user_trailingslashit( '/' . $path ) ) : home_url( '/' );
}

/**
 * If a URL points at a page inside the live area, return the view slug it addresses.
 *
 * Used to rewrite the hrefs of the live navigation so they carry the current race.
 *
 * @param string $url Absolute or root-relative URL.
 * @return string|null View slug, '' for the landing page itself, or null if outside the live area.
 */
function rm_match_live_view( $url ) {
    if ( ! is_string( $url ) || '' === $url ) {
        return null;
    }

    // Ignore anchors, mailto:, tel: and anything on another host.
    if ( preg_match( '#^(?:[a-z][a-z0-9+.-]*:)?//#i', $url ) ) {
        $host = wp_parse_url( $url, PHP_URL_HOST );
        if ( $host && $host !== wp_parse_url( home_url(), PHP_URL_HOST ) ) {
            return null;
        }
    } elseif ( '' !== $url && ! str_starts_with( $url, '/' ) ) {
        return null;
    }

    $live_path = rm_live_path();
    if ( '' === $live_path ) {
        return null;
    }

    $path = (string) wp_parse_url( $url, PHP_URL_PATH );
    $home = (string) wp_parse_url( home_url(), PHP_URL_PATH );
    if ( '' !== $home && str_starts_with( $path, $home ) ) {
        $path = substr( $path, strlen( $home ) );
    }
    $path = trim( $path, '/' );

    if ( $path === $live_path ) {
        return '';
    }

    if ( ! str_starts_with( $path, $live_path . '/' ) ) {
        return null;
    }

    $rest = explode( '/', substr( $path, strlen( $live_path ) + 1 ) );

    // /live/{view}/ -- a bare view link, the case the navigation produces.
    if ( 1 === count( $rest ) && rm_is_live_view( $rest[0] ) ) {
        return $rest[0];
    }

    // /live/{race}/{view}/ -- already carries a race; leave it alone.
    return null;
}

/* -------------------------------------------------------------------------
 * Redirects
 * ---------------------------------------------------------------------- */

/**
 * Send legacy and incomplete URLs to their canonical form.
 *
 * Runs on template_redirect, before the live resources are loaded.
 *
 * @return void
 */
function rm_live_canonical_redirect() {
    if ( is_admin() || wp_doing_ajax() || is_robots() || is_feed() ) {
        return;
    }

    $live_path = rm_live_path();
    if ( '' === $live_path ) {
        return;
    }

    // 1. Legacy "?race_id=123", but only inside the live area: /register/?race_id=123
    //    is the registration form's own parameter and must be left alone.
    if ( ! empty( $_GET['race_id'] ) && \RaceManager\WP_RaceManager::is_live_page() ) {
        $race = rm_resolve_race( absint( wp_unslash( $_GET['race_id'] ) ) );
        $view = get_query_var( 'rm_race' ) ? '' : rm_current_view_slug();

        if ( $race ) {
            $target = rm_live_url( $race, $view );
            if ( $target ) {
                // Keep any other query args the visitor arrived with.
                $extra = array_filter( wp_unslash( $_GET ), 'is_scalar' );
                unset( $extra['race_id'] );
                if ( $extra ) {
                    $target = add_query_arg( array_map( 'sanitize_text_field', $extra ), $target );
                }
                wp_safe_redirect( $target, 301 );
                exit;
            }
        }
    }

    // 2. /live/{race}/ without a view, which 404s on its own.
    if ( is_404() ) {
        $path = trim( (string) wp_parse_url( rm_current_request_uri(), PHP_URL_PATH ), '/' );
        $home = trim( (string) wp_parse_url( home_url(), PHP_URL_PATH ), '/' );
        if ( '' !== $home && str_starts_with( $path, $home . '/' ) ) {
            $path = substr( $path, strlen( $home ) + 1 );
        }

        if ( str_starts_with( $path, $live_path . '/' ) ) {
            $rest = explode( '/', substr( $path, strlen( $live_path ) + 1 ) );
            if ( 1 === count( $rest ) ) {
                $race = rm_resolve_race( $rest[0] );
                if ( $race ) {
                    $target = rm_live_url( $race );
                    if ( $target ) {
                        wp_safe_redirect( $target, 301 );
                        exit;
                    }
                }
            }
        }
    }

    // 3. A numeric race segment, e.g. /live/182/bracket/ -- redirect to the slug form.
    $slug = get_query_var( 'rm_race' );
    $view = rm_current_view_slug();
    if ( '' !== $slug && '' !== $view && is_numeric( $slug ) ) {
        $race = rm_resolve_race( $slug );
        if ( $race ) {
            $target = rm_live_url( $race, $view );
            if ( $target ) {
                wp_safe_redirect( $target, 301 );
                exit;
            }
        }
    }
}
add_action( 'template_redirect', 'rm_live_canonical_redirect', 1 );

/**
 * The view slug of the page currently being rendered.
 *
 * @return string Empty string when the current page is not a live view.
 */
function rm_current_view_slug() {
    if ( ! is_page() ) {
        return '';
    }

    $post = get_queried_object();
    if ( ! $post instanceof WP_Post ) {
        return '';
    }

    return rm_is_live_view( $post->post_name ) ? $post->post_name : '';
}

/**
 * Request URI of the current request, without the query string.
 *
 * @return string
 */
function rm_current_request_uri() {
    return isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
}

/* -------------------------------------------------------------------------
 * Keeping the race across in-page navigation
 * ---------------------------------------------------------------------- */

/**
 * Rewrite links to live views so they carry the current race.
 *
 * The view navigation is an ordinary navigation block whose links point at /live/stats/ and
 * friends. Rather than requiring a custom block, those hrefs are rewritten while rendering.
 * Uses the HTML API so the markup is parsed rather than pattern-matched.
 *
 * @param string $block_content Rendered block HTML.
 * @return string
 */
function rm_rewrite_live_links( $block_content ) {
    if ( is_admin() || ! is_string( $block_content ) || '' === $block_content ) {
        return $block_content;
    }

    $live_path = rm_live_path();
    if ( '' === $live_path || ! str_contains( $block_content, '/' . $live_path . '/' ) ) {
        return $block_content;
    }

    $race = rm_get_current_race();
    if ( ! $race ) {
        return $block_content;
    }

    $processor = new WP_HTML_Tag_Processor( $block_content );
    $changed   = false;

    while ( $processor->next_tag( 'a' ) ) {
        $href = $processor->get_attribute( 'href' );
        $view = rm_match_live_view( $href );

        if ( null === $view ) {
            continue; // Not a link into the live area.
        }

        if ( '' === $view ) {
            /**
             * Filters whether links back to the race selection carry the current race.
             *
             * The selection page uses it to mark the active race. Note this deliberately uses
             * rm_race and not race_id: the latter would trigger the legacy redirect and bounce
             * the visitor straight back out of the selection page.
             *
             * @param bool    $carry Whether to append the race. Default true.
             * @param WP_Post $race  The current race.
             */
            if ( ! apply_filters( 'rm_selection_link_carries_race', true, $race ) ) {
                continue;
            }
            $target = add_query_arg( 'rm_race', $race->post_name, rm_live_selection_url() );
        } else {
            $target = rm_live_url( $race, $view );
        }

        if ( $target && $target !== $href ) {
            $processor->set_attribute( 'href', $target );
            $changed = true;
        }
    }

    return $changed ? $processor->get_updated_html() : $block_content;
}
add_filter( 'render_block', 'rm_rewrite_live_links' );
// Classic menus never pass through render_block, so cover wp_nav_menu() as well.
add_filter( 'wp_nav_menu', 'rm_rewrite_live_links' );

/**
 * Remember the current race client-side and honour the PWA's ?resume=1 start URL.
 *
 * Deliberately client-side: the server stays stateless, so every live URL remains cacheable.
 *
 * @return void
 */
function rm_enqueue_live_resume_script() {
    if ( '' === rm_live_path() || ! \RaceManager\WP_RaceManager::is_live_page() ) {
        return;
    }

    wp_enqueue_script(
        'rm-live-resume',
        plugin_dir_url( __DIR__ ) . 'js/rm-live-resume.js',
        array(),
        WP_RACEMANAGER_VERSION,
        false // In the head: the resume redirect should happen before the page paints.
    );

    $race = rm_get_current_race();

    wp_add_inline_script(
        'rm-live-resume',
        'window.RmLiveResume = ' . wp_json_encode( array(
            'raceUrl'      => $race ? rm_live_url( $race, rm_current_view_slug() ) : '',
            'raceSlug'     => $race ? $race->post_name : '',
            'raceTitle'    => $race ? get_the_title( $race ) : '',
            'selectionUrl' => rm_live_selection_url(),
            'isSelection'  => rm_live_page_id() === (int) get_queried_object_id(),
        ) ) . ';',
        'before'
    );
}
add_action( 'wp_enqueue_scripts', 'rm_enqueue_live_resume_script' );
