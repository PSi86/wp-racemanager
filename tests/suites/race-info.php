<?php
/**
 * What a race tells a pilot outside its own pages (1.19.0): includes/race-info.php - its location,
 * its calendar entry, and the tags the registration mail can use.
 *
 * The confirmation mail said "Rennen: 2578", the race field's value. What has to hold:
 *
 *   - the mail names the race it was sent for: its title as typed, its dates as the race-date block
 *     shows them, start and end, where it takes place and that place on a map, its page, its live
 *     area and next-up view - found by its [rm_nextup], whatever the site called it - and its
 *     calendar entry; each empty where the race has nothing to say, where no race was chosen, and
 *     outside a submission; CF7's own tags are left to CF7; in an HTML mail, the text escaped and
 *     line breaks kept;
 *   - the calendar entry is one event, in UTC from the site's wall clock and time zone, summer and
 *     winter; title, location and page escaped, lines folded at 75 octets without splitting a
 *     character, every line ending in CRLF; none for a race not published, one without both dates,
 *     or what is no race;
 *   - the location as typed, trimmed; nothing kept for an empty field;
 *   - a form with a race field lists the race's tags in its Mail tab; another form does not.
 */

require_once __DIR__ . '/../bootstrap.php';

$GLOBALS['rm_meta'] = array();
function get_post_meta( $id, $key = '', $single = false ) {
    return $GLOBALS['rm_meta'][ $id ][ $key ] ?? '';
}
function update_post_meta( $id, $key, $value ) {
    $GLOBALS['rm_meta'][ $id ][ $key ] = $value;
    return true;
}
function delete_post_meta( $id, $key ) {
    unset( $GLOBALS['rm_meta'][ $id ][ $key ] );
    return true;
}
function get_post_field( $field, $id, $context = 'display' ) {
    $post = get_post( $id );
    return $post ? (string) ( $post->$field ?? '' ) : '';
}
function get_permalink( $post ) {
    $post = $post instanceof WP_Post ? $post : get_post( $post );
    return $post ? 'https://example.test/races/' . $post->post_name . '/' : false;
}
// has_shortcode() answers false for a shortcode not registered, which [rm_nextup] is not where a
// mail goes out; so the tags must not ask it, and core's answer is all it would get.
function has_shortcode( $content, $tag ) {
    return false;
}
function sanitize_textarea_field( $text ) {
    return strip_tags( (string) $text ); // core's keeps the line breaks, as this does
}
// date_i18n() on the wall clock read as UTC gives it back; the formats below need no month names.
function date_i18n( $format, $timestamp = false, $gmt = false ) {
    return gmdate( $format, $timestamp );
}
$GLOBALS['rm_timezone'] = 'Europe/Vienna';
function wp_timezone() {
    return new DateTimeZone( $GLOBALS['rm_timezone'] );
}
// The live area (includes/live-routing.php): page 10, its views the children below.
function rm_live_page_id() {
    return 10;
}
function rm_live_url( $race, $view = '' ) {
    $post = $race instanceof WP_Post ? $race : get_post( $race );
    $view = in_array( $view, array( 'bracket', 'next-up' ), true ) ? $view : 'bracket';
    return $post ? 'https://example.test/live/' . $post->post_name . '/' . $view . '/' : '';
}
// Contact Form 7's submission, as far as the tags read it: the posted race.
class WPCF7_Submission {
    public static $posted = null;

    public static function get_instance() {
        return null === self::$posted ? null : new self();
    }

    public function get_posted_data( $name = '' ) {
        return self::$posted[ $name ] ?? null;
    }
}

$GLOBALS['rm_options'] = array( 'date_format' => 'j.n.Y', 'time_format' => 'H:i' );
require_once RM_TEST_DIR . '/stubs/wordpress.php';
require_once RM_PLUGIN_DIR . '/includes/race-dates.php';
require_once RM_PLUGIN_DIR . '/includes/race-info.php';

// Race 5 over two days with a location, race 6 on one day without; a draft; one without dates.
rm_test_post( 5, 'race', 'spring-cup', 'publish', 0, 'Spring "Cup" & Co, 2026' );
$GLOBALS['rm_meta'][5] = array( '_race_event_start' => '2026-06-13 09:00:00', '_race_event_end' => '2026-06-14 17:30:00', RM_RACE_LOCATION_META => "Halle Süd\nHauptstraße 1, 1010 Wien" );
rm_test_post( 6, 'race', 'summer-sprint', 'publish', 0, 'Summer Sprint' );
$GLOBALS['rm_meta'][6] = array( '_race_event_start' => '2026-06-20 09:00:00', '_race_event_end' => '2026-06-20 18:00:00' );
rm_test_post( 7, 'race', 'not-yet', 'draft', 0, 'Not Yet' );
$GLOBALS['rm_meta'][7] = $GLOBALS['rm_meta'][6];
rm_test_post( 8, 'race', 'no-dates', 'publish', 0, 'No Dates' );
rm_test_post( 9, 'race', 'winter-cup', 'publish', 0, 'Winter Cup' );
$GLOBALS['rm_meta'][9] = array( '_race_event_start' => '2026-12-05 09:00:00', '_race_event_end' => '2026-12-05 18:00:00' );
rm_test_post( 10, 'page', 'live' );
rm_test_post( 11, 'page', 'bracket', 'publish', 10 )->post_content = '[rm_bracket]';
rm_test_post( 12, 'page', 'next-up', 'publish', 10 )->post_content = '<!-- wp:shortcode -->[rm_nextup]<!-- /wp:shortcode -->';
rm_test_post( 20, 'page', 'about' );

$tag = fn( $name, $html = false ) => rm_race_mail_tag( null, $name, $html );

rm_test_section( 'The mail names the race' );
WPCF7_Submission::$posted = array( 'race_id' => '5' );
rm_test_check( 'its title, as typed', 'Spring "Cup" & Co, 2026' === $tag( '_race_title' ), $tag( '_race_title' ) );
rm_test_check( 'its dates over two days, as the race-date block has them', '13.6.2026 @ 09:00 - 14.6.2026 @ 17:30' === $tag( '_race_dates' ), $tag( '_race_dates' ) );
rm_test_check( 'start and end', '13.6.2026 @ 09:00' === $tag( '_race_start' ) && '14.6.2026 @ 17:30' === $tag( '_race_end' ), $tag( '_race_start' ) . ' / ' . $tag( '_race_end' ) );
rm_test_check( 'where, as typed', "Halle Süd\nHauptstraße 1, 1010 Wien" === $tag( '_race_location' ), $tag( '_race_location' ) );
rm_test_check( 'that place on a map', 'https://www.google.com/maps/search/?api=1&query=Halle%20S%C3%BCd%20Hauptstra%C3%9Fe%201%2C%201010%20Wien' === $tag( '_race_map_url' ), $tag( '_race_map_url' ) );
rm_test_check( 'its page', 'https://example.test/races/spring-cup/' === $tag( '_race_url' ), $tag( '_race_url' ) );
rm_test_check( 'its live area', 'https://example.test/live/spring-cup/bracket/' === $tag( '_race_live_url' ), $tag( '_race_live_url' ) );
rm_test_check( 'its next-up view, found by [rm_nextup]', 'https://example.test/live/spring-cup/next-up/' === $tag( '_race_nextup_url' ), $tag( '_race_nextup_url' ) );
rm_test_check( 'its calendar entry', 'https://example.test/?rm_race_calendar=5' === $tag( '_race_calendar_url' ), $tag( '_race_calendar_url' ) );
WPCF7_Submission::$posted = array( 'race_id' => array( '6' ) );
rm_test_check( 'one day: the date, then the two times', '20.6.2026 @ 09:00 - 18:00' === $tag( '_race_dates' ), $tag( '_race_dates' ) );
rm_test_check( 'no location: neither place nor map', '' === $tag( '_race_location' ) && '' === $tag( '_race_map_url' ) );
WPCF7_Submission::$posted = array( 'race_id' => '7' );
rm_test_check( 'a race not published: no calendar entry', '' === $tag( '_race_calendar_url' ) && 'Not Yet' === $tag( '_race_title' ) );
WPCF7_Submission::$posted = array( 'race_id' => '8' );
rm_test_check( 'a race without dates: no dates, no calendar entry', '' === $tag( '_race_dates' ) && '' === $tag( '_race_start' ) && '' === $tag( '_race_calendar_url' ) );
WPCF7_Submission::$posted = array( 'race_id' => '20' );
rm_test_check( 'a race field naming what is no race: nothing', '' === $tag( '_race_title' ) && '' === $tag( '_race_url' ) );
WPCF7_Submission::$posted = array();
rm_test_check( 'no race chosen: nothing', '' === $tag( '_race_title' ) );
WPCF7_Submission::$posted = null;
rm_test_check( 'outside a submission: nothing', '' === $tag( '_race_title' ) );
WPCF7_Submission::$posted = array( 'race_id' => '5' );
rm_test_check( 'CF7\'s own tags left to CF7', null === rm_race_mail_tag( null, '_site_title', false ) && null === rm_race_mail_tag( null, 'race_id', false ) );
rm_test_check( 'what another filter made of a tag, kept', 'theirs' === rm_race_mail_tag( 'theirs', '_race_title', false ) );
rm_test_check( 'an HTML mail: the title escaped', 'Spring &quot;Cup&quot; &amp; Co, 2026' === $tag( '_race_title', true ), $tag( '_race_title', true ) );
rm_test_check( '  the location\'s line break kept', "Halle Süd<br />\nHauptstraße 1, 1010 Wien" === $tag( '_race_location', true ), $tag( '_race_location', true ) );
rm_test_check( '  a URL as a URL', 'https://www.google.com/maps/search/?api=1&#038;query=Halle%20S%C3%BCd%20Hauptstra%C3%9Fe%201%2C%201010%20Wien' === $tag( '_race_map_url', true ), $tag( '_race_map_url', true ) );

rm_test_section( 'The calendar entry' );
$ics   = rm_race_calendar_ics( 5 );
$lines = explode( "\r\n", rtrim( $ics, "\r\n" ) );
rm_test_check( 'one event in one calendar', str_starts_with( $ics, "BEGIN:VCALENDAR\r\nVERSION:2.0\r\n" ) && str_ends_with( $ics, "END:VEVENT\r\nEND:VCALENDAR\r\n" ) && 1 === substr_count( $ics, 'BEGIN:VEVENT' ), $ics );
rm_test_check( 'every line ends in CRLF', ! preg_match( "/(?<!\r)\n/", $ics ), $ics );
rm_test_check( 'in UTC: 09:00 in Vienna in June is 07:00Z', in_array( 'DTSTART:20260613T070000Z', $lines, true ) && in_array( 'DTEND:20260614T153000Z', $lines, true ), $ics );
$winter = rm_race_calendar_ics( 9 );
rm_test_check( '  and in December 08:00Z', str_contains( $winter, "DTSTART:20261205T080000Z\r\n" ) && str_contains( $winter, "DTEND:20261205T170000Z\r\n" ), $winter );
rm_test_check( 'its title and place escaped', in_array( 'SUMMARY:Spring "Cup" & Co\\, 2026', $lines, true ) && in_array( 'LOCATION:Halle Süd\\nHauptstraße 1\\, 1010 Wien', $lines, true ), $ics );
rm_test_check( 'its page, and one UID per race', in_array( 'URL:https://example.test/races/spring-cup/', $lines, true ) && in_array( 'UID:race-5@example.test', $lines, true ) && (bool) preg_grep( '/^DTSTAMP:\d{8}T\d{6}Z$/', $lines ), $ics );
rm_test_check( 'a race without a location: no LOCATION', ! str_contains( rm_race_calendar_ics( 6 ), 'LOCATION' ) );
$GLOBALS['rm_posts'][9]->post_title = str_repeat( 'Großer Preis von Österreich, ', 5 );
$long = rm_race_calendar_ics( 9 );
$ok   = true;
foreach ( explode( "\r\n", rtrim( $long, "\r\n" ) ) as $line ) {
    $ok = $ok && strlen( $line ) <= 75 && mb_check_encoding( $line, 'UTF-8' );
}
$unfolded = str_replace( "\r\n ", '', $long );
rm_test_check( 'a long title folded: no line over 75 octets, no character split',
    $ok && str_contains( $long, "\r\n " ) && str_contains( $unfolded, 'SUMMARY:' . str_repeat( 'Großer Preis von Österreich\\, ', 5 ) ), $long );
rm_test_check( 'none for a race not published, one without dates, or a page',
    '' === rm_race_calendar_ics( 7 ) && '' === rm_race_calendar_ics( 8 ) && '' === rm_race_calendar_ics( 20 ) && '' === rm_race_calendar_ics( 999 ) );

rm_test_section( 'Where' );
rm_save_race_location( 6, "  Vereinsheim\nAm Platz 3  " );
rm_test_check( 'as typed, trimmed, its lines kept', "Vereinsheim\nAm Platz 3" === rm_race_location( 6 ), var_export( rm_race_location( 6 ), true ) );
rm_save_race_location( 6, '   ' );
rm_test_check( 'an empty field keeps nothing', ! isset( $GLOBALS['rm_meta'][6][ RM_RACE_LOCATION_META ] ) && '' === rm_race_location( 6 ) );
rm_test_check( 'no map without a place', '' === rm_race_map_url( '  ' ) );

rm_test_section( 'The form\'s Mail tab' );
$listed = rm_race_mail_tags_listed( array( 'race_id', 'pilot_name_1' ) );
rm_test_check( 'a form with a race field lists the race\'s tags', array() === array_diff( RM_RACE_MAIL_TAGS, $listed ) && in_array( 'pilot_name_1', $listed, true ), implode( ' ', $listed ) );
rm_test_check( 'another form does not', array( 'your-name' ) === rm_race_mail_tags_listed( array( 'your-name' ) ) );

rm_test_finish();
