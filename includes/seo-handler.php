<?php
// includes/seo-handler.php
// Output SEO meta tags in the head section of the page.
// This includes the title, description, keywords, canonical link, and Open Graph tags.

if (!defined('ABSPATH')) exit; // Exit if accessed directly

/**
 * Read the SEO overrides stored on the current post.
 *
 * Returns empty strings off a singular view, so callers never touch an undefined variable --
 * which is what happened before on archives, the 404 page and the search results, where PHP 8
 * turns every read into a warning in the log.
 *
 * @return array{title:string, description:string, keywords:string}
 */
function rm_seo_post_overrides() {
    $empty = array( 'title' => '', 'description' => '', 'keywords' => '' );

    if ( ! is_singular() ) {
        return $empty;
    }

    $post_id = get_queried_object_id();
    if ( ! $post_id ) {
        return $empty;
    }

    return array(
        'title'       => (string) get_post_meta( $post_id, '_seo_title', true ),
        'description' => (string) get_post_meta( $post_id, '_seo_description', true ),
        'keywords'    => (string) get_post_meta( $post_id, '_seo_keywords', true ),
    );
}

/**
 * Feed the per-post title override into the one title tag WordPress prints.
 *
 * This used to be echoed from wp_head at priority 1 -- the same hook and priority core uses for
 * _wp_render_title_tag(), so every singular page carried two <title> elements. Which one a
 * search engine or a browser tab honours is undefined. Filtering the document title instead
 * means there is exactly one, and the override also reaches everything else that asks for the
 * document title.
 *
 * @param string $title Title WordPress would use.
 * @return string
 */
function rm_seo_document_title( $title ) {
    $overrides = rm_seo_post_overrides();

    return '' !== $overrides['title'] ? $overrides['title'] : $title;
}
add_filter( 'pre_get_document_title', 'rm_seo_document_title' );

// Hook early to override theme defaults if needed
add_action('wp_head', 'rm_seo_output_meta', 1);
function rm_seo_output_meta() {
    // Merge defaults with stored settings
    $seo = array_merge(
        [
            'default_title'       => get_bloginfo('name'),
            'default_description' => get_bloginfo('description'),
            'default_keywords'    => '',
        ],
        (array) get_option('rm_seo', [])
    );

    // Per-post overrides
    $overrides  = rm_seo_post_overrides();
    $post_title = $overrides['title'];
    $post_desc  = $overrides['description'];
    $post_keys  = $overrides['keywords'];

    // Title. The <title> element itself belongs to core -- rm_seo_document_title() already put
    // the override into it -- so only the Open Graph twin is printed here.
    $title = is_home() ? $seo['default_title'] : wp_get_document_title();
    if ( ! current_theme_supports( 'title-tag' ) && ! empty( $title ) ) {
        // A theme that opts out of the title tag leaves the document without one; then, and
        // only then, print it ourselves.
        echo "<title>" . esc_html( $title ) . "</title>";
    }
    if ( ! empty( $title ) ) {
        echo '<meta property="og:title" content="' . esc_attr( $title ) . '">';
    }

    // Description
    $desc = $post_desc ?: $seo['default_description'];
    if ( ! empty( $desc ) ) {
        echo '<meta name="description" content="' . esc_attr( $desc ) . '">';
        echo '<meta property="og:description" content="' . esc_attr( $desc ) . '">';
    }

    // Keywords
    $keys = $post_keys ?: $seo['default_keywords'];
    if ( ! empty( $keys ) ) {
        echo '<meta name="keywords" content="' . esc_attr( $keys ) . '">';
    }

    // Canonical and og:url
    if ( is_front_page() || is_home() ) {
        $url = home_url( '/' );
    } elseif ( is_singular() ) {
        $url = get_permalink( get_queried_object_id() );
    } else {
        $url = '';
    }

    if ( ! empty( $url ) ) {
        echo '<link rel="canonical" href="' . esc_url( $url ) . '">';
        echo '<meta property="og:url" content="' . esc_url( $url ) . '">';
    }

    // Open Graph image
    if ( is_singular() && has_post_thumbnail() ) {
        $img = wp_get_attachment_image_src( get_post_thumbnail_id(), 'full' );
        if ( ! empty( $img[0] ) ) {
            echo '<meta property="og:image" content="' . esc_url( $img[0] ) . '">';
        }
    }
}
