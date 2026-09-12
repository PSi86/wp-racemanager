<?php
// includes/race-announcement.php
// A race's announcement (1.12.0): the schedule as a table, and lists of what to bring, what is there
// and which video systems fly - the pattern "Race announcement" of core blocks, with four block
// styles for them. Decided on 2026-09-12: none of it goes through RotorHazard or changes how a race
// runs, so it gets no fields of its own; the organiser edits blocks, as the rest of the page.
//
// A new race starts with it: the race post type's block template inserts the pattern
// (cpt-handler.php), and a race the timer creates carries its markup (rm_create_race()). A race
// written before keeps its text.

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

/** The pattern's name, as the block template and rm_create_race() refer to it. */
const RM_RACE_ANNOUNCEMENT_PATTERN = 'wp-racemanager/race-announcement';

add_action( 'init', 'rm_register_race_announcement' );

/**
 * The pattern's blocks, as the block editor of WordPress 7.1 serializes them - copied from it, so
 * that the editor takes them for its own and does not report them as changed.
 *
 * @return string
 */
function rm_race_announcement_markup() {
    return <<<'RM_ANNOUNCEMENT'
<!-- wp:heading {"level":3} -->
<h3 class="wp-block-heading">Schedule</h3>
<!-- /wp:heading -->

<!-- wp:table {"className":"is-style-rm-schedule"} -->
<figure class="wp-block-table is-style-rm-schedule"><table class="has-fixed-layout"><thead><tr><th>Time</th><th>Programme</th></tr></thead><tbody><tr><td>08:30</td><td>Doors open, registration</td></tr><tr><td>09:00</td><td>Pilot briefing, then training</td></tr><tr><td>10:30</td><td>Qualifying</td></tr><tr><td>12:30</td><td>Lunch break</td></tr><tr><td>13:30</td><td>Elimination</td></tr><tr><td>17:00</td><td>Finals and prize-giving</td></tr></tbody></table></figure>
<!-- /wp:table -->

<!-- wp:heading {"level":3} -->
<h3 class="wp-block-heading">What you need</h3>
<!-- /wp:heading -->

<!-- wp:list {"className":"is-style-rm-checklist"} -->
<ul class="wp-block-list is-style-rm-checklist"><!-- wp:list-item -->
<li>A registered drone, with spare props and parts</li>
<!-- /wp:list-item -->

<!-- wp:list-item -->
<li>Enough batteries for every round, and a charger</li>
<!-- /wp:list-item -->

<!-- wp:list-item -->
<li>Video goggles on the channel you are given</li>
<!-- /wp:list-item -->

<!-- wp:list-item -->
<li>Proof of your drone liability insurance</li>
<!-- /wp:list-item --></ul>
<!-- /wp:list -->

<!-- wp:heading {"level":3} -->
<h3 class="wp-block-heading">On site</h3>
<!-- /wp:heading -->

<!-- wp:list {"className":"is-style-rm-checklist"} -->
<ul class="wp-block-list is-style-rm-checklist"><!-- wp:list-item -->
<li>Power at the pit tables</li>
<!-- /wp:list-item -->

<!-- wp:list-item -->
<li>Timing with RotorHazard, results live on this site</li>
<!-- /wp:list-item -->

<!-- wp:list-item -->
<li>Food and drinks</li>
<!-- /wp:list-item --></ul>
<!-- /wp:list -->

<!-- wp:heading {"level":3} -->
<h3 class="wp-block-heading">Video systems</h3>
<!-- /wp:heading -->

<!-- wp:list {"className":"is-style-rm-allowed"} -->
<ul class="wp-block-list is-style-rm-allowed"><!-- wp:list-item -->
<li>Analog</li>
<!-- /wp:list-item -->

<!-- wp:list-item -->
<li>HDZero</li>
<!-- /wp:list-item -->

<!-- wp:list-item -->
<li>Walksnail</li>
<!-- /wp:list-item --></ul>
<!-- /wp:list -->

<!-- wp:list {"className":"is-style-rm-banned"} -->
<ul class="wp-block-list is-style-rm-banned"><!-- wp:list-item -->
<li>Anything that interferes with the other channels</li>
<!-- /wp:list-item --></ul>
<!-- /wp:list -->
RM_ANNOUNCEMENT;
}

/**
 * Register the pattern, its category, and the block styles it uses.
 *
 * @return void
 */
function rm_register_race_announcement() {
    register_block_pattern_category( 'wp-racemanager', array( 'label' => __( 'Races', 'wp-racemanager' ) ) );
    register_block_pattern( RM_RACE_ANNOUNCEMENT_PATTERN, array(
        'title'       => __( 'Race announcement', 'wp-racemanager' ),
        'description' => __( 'Schedule, what to bring, what is on site, and the video systems allowed and not.', 'wp-racemanager' ),
        'categories'  => array( 'wp-racemanager' ),
        'postTypes'   => array( 'race' ),
        'content'     => rm_race_announcement_markup(),
    ) );

    foreach ( rm_race_announcement_styles() as $block => $styles ) {
        foreach ( $styles as $name => $style ) {
            register_block_style( $block, array(
                'name'         => $name,
                'label'        => $style['label'],
                'inline_style' => $style['css'],
            ) );
        }
    }
}

/**
 * The block styles: a schedule for a table; a checklist, and allowed and not allowed, for a list.
 *
 * @return array<string,array<string,array{label:string,css:string}>> block => name => style.
 */
function rm_race_announcement_styles() {
    $list = static function ( $name, $mark, $colour ) {
        return ".wp-block-list.is-style-$name{list-style:none;padding-left:0}"
            . ".wp-block-list.is-style-$name>li{position:relative;padding-left:1.6em}"
            . ".wp-block-list.is-style-$name>li::before{content:'$mark';position:absolute;left:0;font-weight:bold;color:$colour}";
    };
    return array(
        'core/table' => array(
            'rm-schedule' => array(
                'label' => __( 'Schedule', 'wp-racemanager' ),
                'css'   => '.wp-block-table.is-style-rm-schedule table{border-collapse:collapse;width:100%}'
                    . '.wp-block-table.is-style-rm-schedule :is(th,td){border:0;border-bottom:1px solid rgba(128,128,128,.35);padding:.4em .6em;text-align:left;vertical-align:top}'
                    . '.wp-block-table.is-style-rm-schedule thead{border-bottom:2px solid currentColor}'
                    . '.wp-block-table.is-style-rm-schedule td:first-child{width:5.5em;white-space:nowrap;font-variant-numeric:tabular-nums;font-weight:600}',
            ),
        ),
        'core/list'  => array(
            'rm-checklist' => array( 'label' => __( 'Checklist', 'wp-racemanager' ), 'css' => $list( 'rm-checklist', '\\2713', 'currentColor' ) ),
            'rm-allowed'   => array( 'label' => __( 'Allowed', 'wp-racemanager' ), 'css' => $list( 'rm-allowed', '\\2713', '#2e7d32' ) ),
            'rm-banned'    => array( 'label' => __( 'Not allowed', 'wp-racemanager' ), 'css' => $list( 'rm-banned', '\\2717', '#c62828' ) ),
        ),
    );
}
