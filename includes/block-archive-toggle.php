<?php
// includes/block-archive-toggle.php
// Query registered pilots from the registrations_table
if (!defined('ABSPATH')) exit; // Exit if accessed directly

 function rm_render_archive_toggle_block( $attributes ) {
    // Determine the current view from the URL parameter, default to 'upcoming'
    $current_view = isset( $_GET['race_view'] ) ? sanitize_text_field( $_GET['race_view'] ) : 'past';

    // Build the archive links for upcoming and past races
    $archive_url   = get_post_type_archive_link( 'race' );
    $upcoming_url  = esc_url( add_query_arg( 'race_view', 'upcoming', $archive_url ) );
    $past_url      = esc_url( add_query_arg( 'race_view', 'past', $archive_url ) );
    // The toggle switch itself should link to the opposite view.
    $toggle_url    = ( 'past' === $current_view ) ? $upcoming_url : $past_url;
    
    ob_start();
    ?>
    <div class="race-toggle-container">
        <a href="<?php echo $upcoming_url; ?>" class="race-toggle-link <?php echo ( 'past' !== $current_view ? 'active' : '' ); ?>">
            Upcoming
        </a>
        <a href="<?php echo $toggle_url; ?>" class="switch-link">
            <label class="switch">
              <input type="checkbox" <?php checked( $current_view, 'past' ); ?>>
              <span class="slider"></span>
            </label>
        </a>
        <a href="<?php echo $past_url; ?>" class="race-toggle-link <?php echo ( 'past' === $current_view ? 'active' : '' ); ?>">
            Archive
        </a>
    </div>
    <style>
        /* Container styles for the toggle links */
        .race-toggle-container {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 1rem;
            margin-bottom: 1rem;
        }
        .race-toggle-link {
            text-decoration: none;
            font-weight: bold;
            color: #a7aaad;
        }
        .race-toggle-link.active {
            color: #000;
        }
        /* CSS switch styling based on W3Schools example */
        .switch {
          position: relative;
          display: inline-block;
          width: 60px;
          height: 34px;
          pointer-events: none;
        }
        .switch input { 
          opacity: 0;
          width: 0;
          height: 0;
          pointer-events: none;
        }
        .slider {
          position: absolute;
          cursor: pointer;
          top: 0;
          left: 0;
          right: 0;
          bottom: 0;
          background-color: black;
          transition: .4s;
          border-radius: 34px;
        }
        .slider:before {
          position: absolute;
          content: "";
          height: 26px;
          width: 26px;
          left: 4px;
          bottom: 4px;
          background-color: white;
          transition: .4s;
          border-radius: 50%;
        }
        input:checked + .slider {
          background-color: #000000;
        }
        input:checked + .slider:before {
          transform: translateX(26px);
        }
    </style>
    <?php
    return ob_get_clean();
}