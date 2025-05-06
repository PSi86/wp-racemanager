<?php
/**
 * Front-end rendering + conditional Swiper enqueue.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

/**
 * Front-end render callback for race/media-gallery block.
 *
 * @param array $attrs Block attributes; expects 'mediaItems' => [ int, int, … ].
 * @return string HTML for gallery.
 */
function rm_render_media_gallery( $attrs ) {
    // 1) Grab the IDs
    $ids = isset( $attrs['mediaItems'] ) && is_array( $attrs['mediaItems'] )
        ? array_values( $attrs['mediaItems'] )
        : [];

    if ( empty( $ids ) ) {
        return '';
    }

    // 2) Enqueue Swiper assets
    wp_enqueue_style(
        'race-swiper-css',
        plugin_dir_url( __DIR__ ) . 'assets/swiper-11.2.6/swiper-bundle.min.css',
        [],
        '11.2.6'
    );
    wp_enqueue_script(
        'race-swiper-js',
        plugin_dir_url( __DIR__ ) . 'assets/swiper-11.2.6/swiper-bundle.min.js',
        [],
        '11.2.6',
        true
    );

    // 3) Expose the IDs to JS (in the same order)
    $json_ids = wp_json_encode( $ids );
    wp_add_inline_script(
        'race-swiper-js',
        "var raceMediaIds = {$json_ids};",
        'before'
    );

    // 4) Build the gallery HTML + overlay
    ob_start();
    ?>
    <h2>Gallery</h2>
    <div class="rm-gallery-wrapper">
        <?php foreach ( $ids as $index => $attachment_id ) : 
            // Get 150×150 thumbnail
            $alt = get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );
            $thumb_img = wp_get_attachment_image(
                $attachment_id,
                [ 150, 150 ],
                false,
                [ 'alt' => $alt ]
            );
        ?>
            <div class="rm-gallery-thumb" data-index="<?php echo esc_attr( $index ); ?>">
                <?php echo $thumb_img; ?>
            </div>
        <?php endforeach; ?>
    </div>

    <div id="rm-gallery-overlay" class="rm-gallery-overlay" style="display:none;">
        <div class="rm-gallery-overlay-content">
            <span id="rm-gallery-close" class="rm-gallery-close">&times;</span>
            <div id="rm-gallery-overlay-swiper" class="swiper-container rm-gallery-overlay-slider">
                <div class="swiper-wrapper">
                    <?php foreach ( $ids as $attachment_id ) :
                        $full_url = wp_get_attachment_url( $attachment_id );
                        $mime     = get_post_mime_type( $attachment_id );
                        $alt      = get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );
                    ?>
                        <div class="swiper-slide">
                            <?php if ( 0 === strpos( $mime, 'image/' ) ) : ?>
                                <div class="swiper-zoom-container">
                                    <img
                                        src="<?php echo esc_url( $full_url ); ?>"
                                        loading="lazy"
                                        alt="<?php echo esc_attr( $alt ); ?>"
                                        style="width:100%;height:100%;object-fit:contain;"
                                    >
                                </div>
                            <?php else : ?>
                                <video controls style="width:100%;height:100%;object-fit:contain;">
                                    <source src="<?php echo esc_url( $full_url ); ?>" type="<?php echo esc_attr( $mime ); ?>">
                                    <?php esc_html_e( 'Your browser does not support the video tag.', 'race' ); ?>
                                </video>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div class="swiper-button-prev"></div>
                <div class="swiper-button-next"></div>
            </div>
        </div>
    </div>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        var isTouchDevice   = window.matchMedia('(pointer: coarse)').matches;
        var ids             = raceMediaIds;
        var overlay         = document.getElementById('rm-gallery-overlay');
        var closeBtn        = document.getElementById('rm-gallery-close');
        var swiperContainer = document.getElementById('rm-gallery-overlay-swiper');
        var swiperInstance;

        function updateHash(id) {
            history.replaceState(null, '', '#' + id);
        }
        function clearHash() {
            history.replaceState(null, '', location.pathname + location.search);
        }

        function openOverlay(index) {
            overlay.style.display = 'flex';
            // only lock scroll on non-touch devices
            if (!isTouchDevice) {
                document.body.style.overflow = 'hidden';
            }
            if (!swiperInstance) {
                swiperInstance = new Swiper(swiperContainer, {
                    lazy: true,
                    initialSlide: index,
                    navigation: {
                        hideOnClick: true,
                        nextEl:      '.swiper-button-next',
                        prevEl:      '.swiper-button-prev'
                    },
                    loop: false,
                    zoom: { toggle: true, maxRatio: 3 },
                    keyboard: { enabled: true },
                    mousewheel: { enabled: true }
                });
                swiperInstance.on('slideChange', function() {
                    updateHash(ids[swiperInstance.realIndex]);
                });
            } else {
                swiperInstance.slideTo(index, 0);
            }
            updateHash(ids[index]);
        }

        function closeOverlay() {
            // hide overlay first
            overlay.style.display = 'none';
            document.body.style.overflow = '';  // always restore scroll
            clearHash();
        }

        // thumbnail clicks
        document.querySelectorAll('.rm-gallery-thumb').forEach(function(thumb) {
            thumb.addEventListener('click', function() {
                openOverlay(parseInt(thumb.dataset.index, 10));
            });
        });
        // close button
        closeBtn.addEventListener('click', closeOverlay);
        // ESC key fallback
        document.addEventListener('keydown', function(e) {
            if (overlay.style.display === 'flex' && e.key === 'Escape') {
                closeOverlay();
            }
        });
        // open on hash
        var hashId = parseInt(location.hash.replace('#',''), 10),
            idx    = ids.indexOf(hashId);
        if (idx > -1) openOverlay(idx);


    });
    </script>

    <style>
    .rm-gallery-wrapper {
        display: flex;
        flex-wrap: wrap;
        max-width: 100%;
        gap: 10px;
        justify-content: center;
    }
    @media (min-width: 600px) {
        /* align to the left on larger screens */
        .rm-gallery-wrapper {
            justify-content: flex-start;
        }
    }
    .rm-gallery-thumb img {
        cursor: pointer;
        border-radius: 3px;
    }
    .rm-gallery-overlay {
        position: fixed;
        top: 0; 
        left: 0;
        width: 100%; 
        height: 100dvh;
        background: rgba(0,0,0,0.8);
        display: none;
        align-items: center;
        justify-content: center;
        z-index: 100000;
        margin-block-start: 0;
        overflow-y: hidden;
        overflow-x: hidden;
        -webkit-overflow-scrolling: touch;
    }
    .rm-gallery-overlay-content {
        position: relative;
        /* touch-action: none; */
        touch-action: auto;
        width: 100%;
        height: 100%;
    }
    .rm-gallery-close {
        position: absolute;
        top: 0px; 
        right: 10px;
        font-size: 60px;
        color: #fff;
        line-height: 1;
        cursor: pointer;
        z-index: 100001;
    }
    .rm-gallery-overlay-slider {
        width: 100%;
        height: 100%;
    }
    .swiper-slide img,
    .swiper-slide video {
        display: block;
        width: 100%;
        height: 100%;
        object-fit: contain;
    }
    /*     @media (hover: none) and (pointer: coarse) { */
    @media (pointer: coarse) {
        .swiper-button-prev::after,
        .swiper-button-next::after {
            font-size: 25px !important;
        }
    }
    </style>
    <?php
    return ob_get_clean();
}
