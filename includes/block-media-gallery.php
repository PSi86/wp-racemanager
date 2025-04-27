<?php
/**
 * Front-end rendering + conditional Swiper enqueue.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

function rm_render_media_gallery( $attrs ) {
    // Conditionally enqueue Swiper only when this block is rendered
    wp_enqueue_style(
        'race-swiper-css',
        'https://unpkg.com/swiper/swiper-bundle.min.css',
        [],
        '8.0.0'
    );
    wp_enqueue_script(
        'race-swiper-js',
        'https://unpkg.com/swiper/swiper-bundle.min.js',
        [],
        '8.0.0',
        true
    );
    wp_add_inline_script(
        'race-swiper-js',
        "document.addEventListener('DOMContentLoaded',function(){
           new Swiper('.race-media-gallery',{});
         });"
    );

    $ids = $attrs['mediaIds'] ?? [];
    if ( empty( $ids ) ) {
        return '';
    }

    $slides = '';
    foreach ( $ids as $id ) {
        $url  = wp_get_attachment_url( $id );
        $mime = get_post_mime_type( $id );
        if ( strpos( $mime, 'image/' ) === 0 ) {
            $slides .= sprintf(
                '<div class="swiper-slide"><img src="%s" alt=""></div>',
                esc_url( $url )
            );
        } else {
            $slides .= sprintf(
                '<div class="swiper-slide"><video controls src="%s"></video></div>',
                esc_url( $url )
            );
        }
    }

    return sprintf(
        '<div class="race-media-gallery swiper-container"><div class="swiper-wrapper">%s</div></div>',
        $slides
    );
}