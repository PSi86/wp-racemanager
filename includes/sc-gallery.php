<?php
// includes/sc-gallery.php
// Register the custom shortcode [rm_gallery]

/**
 * Shortcode to display a gallery.
 * By default it will display all images and videos attached to the current post.
 * Alternatively you can pass an anchor (e.g. #123) to start the gallery at that media item.
 */
function rm_gallery_shortcode($atts) {
    global $post;
    add_action('wp_enqueue_scripts', 'rm_enqueue_swiper_assets');

    // Query video attachments.
    $video_args = array(
        'post_parent'    => $post->ID,
        'post_type'      => 'attachment',
        'post_mime_type' => 'video',
        'orderby'        => 'menu_order',
        'order'          => 'ASC',
        'numberposts'    => -1,
    );
    $videos = get_children($video_args);

    // Query image attachments.
    $image_args = array(
        'post_parent'    => $post->ID,
        'post_type'      => 'attachment',
        'post_mime_type' => 'image',
        'orderby'        => 'menu_order',
        'order'          => 'ASC',
        'numberposts'    => -1,
    );
    $images = get_children($image_args);

    // Build arrays of attachment IDs (to support the anchor).
    $video_ids = array();
    if ( ! empty( $videos ) ) {
        foreach ( $videos as $video ) {
            $video_ids[] = $video->ID;
        }
    }
    $image_ids = array();
    if ( ! empty( $images ) ) {
        foreach ( $images as $image ) {
            $image_ids[] = $image->ID;
        }
    }

    // Determine the category and index based on the hash value.
    $initialCategory = '';
    $initialIndex = 0;
    if (!empty($_SERVER['REQUEST_URI'])) {
        // Check the URL hash (anchor) instead of GET parameters.
        // For PHP we can check $_SERVER['REQUEST_URI'] for a '#' if needed,
        // but since anchors are not sent to the server, we leave the initial index as 0.
        // The JavaScript below will handle opening via hash.
    }

    ob_start();
    ?>
    <div class="rm-gallery-wrapper">
        <?php if ( ! empty( $videos ) ) : ?>
            <div class="rm-gallery-section rm-gallery-videos">
                <h3>Videos</h3>
                <div class="rm-gallery-thumbnails" id="rm-gallery-thumbs-videos">
                    <?php 
                    $i = 0;
                    foreach ( $videos as $video ) : ?>
                        <div class="rm-gallery-thumb" data-index="<?php echo $i; ?>" data-category="videos">
                            <?php echo wp_get_attachment_image( $video->ID, 'thumbnail' ); ?>
                        </div>
                    <?php 
                    $i++;
                    endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <?php if ( ! empty( $images ) ) : ?>
            <div class="rm-gallery-section rm-gallery-images">
                <h3>Pictures</h3>
                <div class="rm-gallery-thumbnails" id="rm-gallery-thumbs-images">
                    <?php 
                    $i = 0;
                    foreach ( $images as $image ) : ?>
                        <div class="rm-gallery-thumb" data-index="<?php echo $i; ?>" data-category="images">
                            <?php echo wp_get_attachment_image( $image->ID, 'thumbnail' ); ?>
                        </div>
                    <?php 
                    $i++;
                    endforeach; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <!-- Overlay Modal -->
    <div id="rm-gallery-overlay" class="rm-gallery-overlay" style="display:none;">
        <div class="rm-gallery-overlay-content">
            <span id="rm-gallery-close" class="rm-gallery-close">&times;</span>
            <!-- Overlay slider for Videos -->
            <div id="rm-gallery-overlay-videos" class="swiper-container rm-gallery-overlay-slider" style="display:none;">
                <div class="swiper-wrapper">
                    <?php foreach ( $videos as $video ) : ?>
                        <div class="swiper-slide">
                            <video controls style="width:100%; height:100%; object-fit:contain;">
                                <source src="<?php echo esc_url( wp_get_attachment_url( $video->ID ) ); ?>" type="<?php echo esc_attr( $video->post_mime_type ); ?>">
                                Your browser does not support the video tag.
                            </video>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div class="swiper-button-prev" id="rm-overlay-videos-prev"></div>
                <div class="swiper-button-next" id="rm-overlay-videos-next"></div>
            </div>
            <!-- Overlay slider for Images -->
            <div id="rm-gallery-overlay-images" class="swiper-container rm-gallery-overlay-slider" style="display:none;">
                <div class="swiper-wrapper">
                    <?php foreach ( $images as $image ) : ?>
                        <div class="swiper-slide">
                            <div class="swiper-zoom-container">
                                <img src="<?php echo esc_url( wp_get_attachment_url( $image->ID ) ); ?>" alt="<?php echo esc_attr( get_the_title( $image->ID ) ); ?>" style="width:100%; height:100%; object-fit:contain;">
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div class="swiper-button-prev" id="rm-overlay-images-prev"></div>
                <div class="swiper-button-next" id="rm-overlay-images-next"></div>
            </div>
        </div>
    </div>

    <script>
    document.addEventListener('DOMContentLoaded', function(){
        var overlay = document.getElementById('rm-gallery-overlay');
        var closeBtn = document.getElementById('rm-gallery-close');
        var overlayVideos = document.getElementById('rm-gallery-overlay-videos');
        var overlayImages = document.getElementById('rm-gallery-overlay-images');
        var swiperVideos, swiperImages;
        var currentCategory = '';

        // Update the URL hash instead of a query parameter.
        function updateURLHash(category, index) {
            var mediaIds = {
                'videos': <?php echo json_encode($video_ids); ?>,
                'images': <?php echo json_encode($image_ids); ?>
            };
            var mediaId = mediaIds[category][index];
            window.history.replaceState(null, '', '#' + mediaId);
        }

        // Remove the hash from the URL.
        function removeURLHash() {
            window.history.replaceState(null, '', window.location.pathname + window.location.search);
        }

        // Initialize the Swiper instance if not already done.
        function initSwiper(category, initialIndex) {
            if (category === 'videos') {
                if (!swiperVideos) {
                    swiperVideos = new Swiper('#rm-gallery-overlay-videos', {
                        initialSlide: initialIndex,
                        navigation: {
                            hideOnClick: true,
                            nextEl: '#rm-overlay-videos-next',
                            prevEl: '#rm-overlay-videos-prev'
                        },
                        loop: false,
                    });
                    swiperVideos.on('slideChange', function() {
                        updateURLHash('videos', swiperVideos.realIndex);
                    });
                } else {
                    swiperVideos.slideTo(initialIndex, 0);
                }
            } else if (category === 'images') {
                if (!swiperImages) {
                    swiperImages = new Swiper('#rm-gallery-overlay-images', {
                        initialSlide: initialIndex,
                        navigation: {
                            hideOnClick: true,
                            nextEl: '#rm-overlay-images-next',
                            prevEl: '#rm-overlay-images-prev'
                        },
                        loop: false,
                        zoom: {
                            maxRatio: 3, // Max zoom ratio
                            toggle: true, // Toggle zooming
                        },
                        keyboard: {
                            enabled: true,
                            onlyInViewport: true,
                        },
                        mousewheel: {
                            enabled: true,
                        },
                    });
                    swiperImages.on('slideChange', function() {
                        updateURLHash('images', swiperImages.realIndex);
                    });
                } else {
                    swiperImages.slideTo(initialIndex, 0);
                }
            }
        }

        // Open the overlay for the chosen category at the given index.
        function openOverlay(category, index) {
            currentCategory = category;
            overlay.style.display = 'flex';
            // Prevent background scroll when overlay is active.
            document.body.style.overflow = 'hidden';
            if (category === 'videos') {
                overlayVideos.style.display = 'block';
                overlayImages.style.display = 'none';
                initSwiper('videos', index);
            } else if (category === 'images') {
                overlayImages.style.display = 'block';
                overlayVideos.style.display = 'none';
                initSwiper('images', index);
            }
            updateURLHash(category, index);
        }

        // Close the overlay, restore scrolling and remove the hash.
        function closeOverlay() {
            overlay.style.display = 'none';
            document.body.style.overflow = '';
            removeURLHash();
        }

        // Add click listeners to all thumbnails.
        var thumbnails = document.querySelectorAll('.rm-gallery-thumb');
        thumbnails.forEach(function(thumb){
            thumb.addEventListener('click', function(){
                var category = thumb.getAttribute('data-category');
                var index = parseInt(thumb.getAttribute('data-index'));
                openOverlay(category, index);
            });
        });

        // Close overlay on close button click.
        closeBtn.addEventListener('click', function(){
            closeOverlay();
        });

        // Keyboard navigation: left/right arrows for navigation, ESC for closing.
        document.addEventListener('keydown', function(e) {
            if (overlay.style.display === 'flex') {
                if (e.key === 'Escape') {
                    closeOverlay();
                }
            }
        });

        // On page load, check the URL hash and open the overlay if applicable.
        var mediaParam = window.location.hash.substring(1);
        if (mediaParam) {
            mediaParam = parseInt(mediaParam);
            var videoIds = <?php echo json_encode($video_ids); ?>;
            var imageIds = <?php echo json_encode($image_ids); ?>;
            if (videoIds.includes(mediaParam)) {
                var index = videoIds.indexOf(mediaParam);
                openOverlay('videos', index);
            } else if (imageIds.includes(mediaParam)) {
                var index = imageIds.indexOf(mediaParam);
                openOverlay('images', index);
            }
        }
    });
    </script>

    <style>
    /* Container for the thumbnails. */
    .rm-gallery-wrapper {
        max-width: 100%;
        margin: 0 auto;
    }
    .rm-gallery-section {
        margin-bottom: 30px;
    }
    .rm-gallery-thumbnails {
        display: flex;
        flex-wrap: wrap;
        gap: 10px;
    }
    .rm-gallery-thumb {
        cursor: pointer;
    }
    /* Overlay styles */
    .rm-gallery-overlay {
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(0,0,0,0.8);
        z-index: 1000000; /* Increased z-index to appear above WP admin bar */
        display: none;
        align-items: center;
        justify-content: center;
        margin-block-start: 0px;
    }
    .rm-gallery-overlay-content {
        position: relative;
        width: 100vw;
        height: 100vh;
        touch-action: none; /* Prevent touch events from propagating to the background */
    }
    .rm-gallery-close {
        position: absolute;
        top: 10px;
        right: 20px;
        font-size: 60px; /* Larger close button */
        color: #fff;
        cursor: pointer;
        z-index: 1000001; /* Ensures the close button appears above the overlay and admin bar */
    }
    .swiper-container {
        width: 100%;
        height: 100%;
        /* touch-action: none; */
    }
    .swiper-slide img,
    .swiper-slide video {
        display: block;
        width: 100%;
        height: 100%;
        object-fit: contain;
    }
    @media (hover: none) and (pointer: coarse) {
        .swiper-button-prev::after,
        .swiper-button-next::after {
            font-size: 12px !important;
        }
    }
    </style>
    <?php
    return ob_get_clean();
}
add_shortcode('rm_gallery', 'rm_gallery_shortcode');

function rm_enqueue_swiper_assets() {
    wp_enqueue_style('swiper-css', plugin_dir_url( __DIR__ ) . 'assets/swiper-11.2.6/swiper-bundle.min.css');
    wp_enqueue_script('swiper-js', plugin_dir_url( __DIR__ ) . 'assets/swiper-11.2.6/swiper-bundle.min.js', array(), null, true);
}
?>
