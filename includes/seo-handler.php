<?php
// includes/seo-handler.php
// Output SEO meta tags in the head section of the page.
// This includes the title, description, keywords, canonical link, and Open Graph tags.

if (!defined('ABSPATH')) exit; // Exit if accessed directly

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
    if ( is_singular() ) {
        global $post;
        $post_title = get_post_meta($post->ID, '_seo_title', true);
        $post_desc  = get_post_meta($post->ID, '_seo_description', true);
        $post_keys  = get_post_meta($post->ID, '_seo_keywords', true);
    }

    // Title
    $title = $post_title ?: ( is_home() ? $seo['default_title'] : wp_get_document_title() );
    if ( ! empty( $title ) ) {
        echo "<title>" . esc_html( $title ) . "</title>";
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
        global $post;
        $url = get_permalink( $post->ID );
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
