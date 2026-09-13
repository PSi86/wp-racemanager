<?php
/**
 * What a race tells a pilot outside its own pages (1.19.0): where it takes place, an entry for the
 * calendar, and the tags the registration mail can use to say which race it was.
 *
 * The confirmation mail Contact Form 7 sends after a registration read "Rennen: 2578": the race
 * field's value is the race's ID, which is what the plugin stores the registration under. The mail
 * can now name the race and say more about it with these tags - CF7's own [_site_title] is of the
 * same kind:
 *
 *   [_race_title]         the race's title
 *   [_race_dates]         start to end, as the race-date block shows them
 *   [_race_start]         start, date and time
 *   [_race_end]           end, date and time
 *   [_race_location]      where it takes place ('' when the race does not say)
 *   [_race_map_url]       that place on a map ('' without a location)
 *   [_race_url]           the race's page: the announcement, the schedule
 *   [_race_live_url]      the race in the live area
 *   [_race_nextup_url]    its next-up view: the pilot's next heat, the channel, push notifications
 *   [_race_calendar_url]  the race as a calendar entry (.ics)
 *
 * The race is the one the form was sent for - its field race_id. [race_id] itself still gives the
 * ID. Each is '' where there is nothing to say, so a line with it is only as empty as the race.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** Where a race takes place: free text, the venue and its address (1.19.0). */
const RM_RACE_LOCATION_META = '_race_location';

/** The query variable that asks for a race's calendar entry. */
const RM_RACE_CALENDAR_VAR = 'rm_race_calendar';

/** The tags the registration mail can use (CF7 calls them special mail tags). */
const RM_RACE_MAIL_TAGS = array(
    '_race_title',
    '_race_dates',
    '_race_start',
    '_race_end',
    '_race_location',
    '_race_map_url',
    '_race_url',
    '_race_live_url',
    '_race_nextup_url',
    '_race_calendar_url',
);

/* -------------------------------------------------------------------------
 * Where
 * ---------------------------------------------------------------------- */

/**
 * Where a race takes place, as its meta box has it.
 *
 * @param int $race_id
 * @return string '' when it does not say.
 */
function rm_race_location( $race_id ) {
    return trim( (string) get_post_meta( $race_id, RM_RACE_LOCATION_META, true ) );
}

/**
 * Keep where a race takes place: several lines at most - a venue, a street, a town - and nothing
 * when the field is left empty.
 *
 * @param int    $race_id
 * @param string $value   As typed.
 * @return void
 */
function rm_save_race_location( $race_id, $value ) {
    $location = trim( sanitize_textarea_field( (string) $value ) );
    if ( '' === $location ) {
        delete_post_meta( $race_id, RM_RACE_LOCATION_META );
        return;
    }
    update_post_meta( $race_id, RM_RACE_LOCATION_META, $location );
}

/**
 * A place on a map: a search for it on Google Maps, which a phone opens in its map app.
 *
 * @param string $location
 * @return string '' without a location.
 */
function rm_race_map_url( $location ) {
    $location = trim( (string) $location );
    if ( '' === $location ) {
        return '';
    }
    return 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode( preg_replace( '/\s+/', ' ', $location ) );
}

/* -------------------------------------------------------------------------
 * Where to look
 * ---------------------------------------------------------------------- */

/**
 * The race's next-up view in the live area: the view whose page carries [rm_nextup], whatever the
 * site called it. The race's live start where there is none. Read from the page's text rather than
 * with has_shortcode(), which answers only for a shortcode registered - and [rm_nextup] is
 * registered on the live pages alone, not where a mail is sent.
 *
 * @param WP_Post|int $race
 * @return string '' where the site has no live area.
 */
function rm_race_nextup_url( $race ) {
    if ( ! function_exists( 'rm_live_url' ) ) {
        return '';
    }
    $view = '';
    foreach ( ! rm_live_page_id() ? array() : get_posts( array(
        'post_type'   => 'page',
        'post_parent' => rm_live_page_id(),
        'post_status' => 'publish',
        'numberposts' => -1,
    ) ) as $page ) {
        if ( $page instanceof WP_Post && preg_match( '/\[rm_nextup[\s\]\/]/', (string) $page->post_content ) ) {
            $view = $page->post_name;
            break;
        }
    }
    return (string) rm_live_url( $race, $view );
}

/**
 * The address of the race's calendar entry.
 *
 * @param int $race_id
 * @return string
 */
function rm_race_calendar_url( $race_id ) {
    return add_query_arg( RM_RACE_CALENDAR_VAR, (int) $race_id, home_url( '/' ) );
}

/* -------------------------------------------------------------------------
 * The calendar entry
 * ---------------------------------------------------------------------- */

/**
 * The race as an iCalendar file (RFC 5545): one event from its start to its end, its title, where it
 * takes place and its page. The times go out in UTC, from the site's wall clock and time zone, so a
 * calendar anywhere puts it at the right hour.
 *
 * @param int $race_id
 * @return string '' for what is no published race with a start and an end.
 */
function rm_race_calendar_ics( $race_id ) {
    $race = get_post( $race_id );
    if ( ! $race instanceof WP_Post || 'race' !== $race->post_type || 'publish' !== $race->post_status ) {
        return '';
    }
    $start = rm_race_calendar_time( get_post_meta( $race->ID, '_race_event_start', true ) );
    $end   = rm_race_calendar_time( get_post_meta( $race->ID, '_race_event_end', true ) );
    if ( '' === $start || '' === $end ) {
        return '';
    }
    $host  = (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST );
    $lines = array(
        'BEGIN:VCALENDAR',
        'VERSION:2.0',
        'PRODID:-//WP RaceManager//' . $host . '//EN',
        'CALSCALE:GREGORIAN',
        'METHOD:PUBLISH',
        'BEGIN:VEVENT',
        'UID:race-' . $race->ID . '@' . $host,
        'DTSTAMP:' . gmdate( 'Ymd\THis\Z' ),
        'DTSTART:' . $start,
        'DTEND:' . $end,
        'SUMMARY:' . rm_ics_text( $race->post_title ),
    );
    $location = rm_race_location( $race->ID );
    if ( '' !== $location ) {
        $lines[] = 'LOCATION:' . rm_ics_text( $location );
    }
    $url     = (string) get_permalink( $race );
    $lines[] = 'URL:' . $url;
    $lines[] = 'DESCRIPTION:' . rm_ics_text( $url );
    $lines[] = 'END:VEVENT';
    $lines[] = 'END:VCALENDAR';
    return implode( "\r\n", array_map( 'rm_ics_fold', $lines ) ) . "\r\n";
}

/**
 * An event date as the calendar needs it: in UTC, 20260221T073000Z.
 *
 * @param mixed $value Stored meta value, the site's wall clock.
 * @return string '' when it cannot be understood.
 */
function rm_race_calendar_time( $value ) {
    $canonical = rm_normalize_event_datetime( $value );
    if ( '' === $canonical ) {
        return '';
    }
    try {
        $local = new DateTimeImmutable( $canonical, wp_timezone() );
    } catch ( Exception $e ) {
        return '';
    }
    return $local->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Ymd\THis\Z' );
}

/**
 * Text for a calendar property: backslash, semicolon and comma escaped, a line break as \n.
 *
 * @param string $text
 * @return string
 */
function rm_ics_text( $text ) {
    $text = str_replace( array( '\\', ';', ',' ), array( '\\\\', '\\;', '\\,' ), (string) $text );
    return str_replace( array( "\r\n", "\r", "\n" ), '\\n', $text );
}

/**
 * A content line folded as RFC 5545 asks: at most 75 octets, each further line starting with a
 * space, and never inside a character.
 *
 * @param string $line
 * @return string
 */
function rm_ics_fold( $line ) {
    $out   = '';
    $width = 75;
    while ( strlen( $line ) > $width ) {
        $cut = $width;
        // Back off a cut that would split a UTF-8 character (a continuation byte starts 10xxxxxx).
        while ( $cut > 0 && ( ord( $line[ $cut ] ) & 0xC0 ) === 0x80 ) {
            $cut--;
        }
        $out  .= substr( $line, 0, $cut ) . "\r\n ";
        $line  = substr( $line, $cut );
        $width = 74; // the leading space counts
    }
    return $out . $line;
}

/**
 * Answer ?rm_race_calendar=<race ID> with the race's calendar entry, as a file to open; 404 for
 * what has none. Out of page caches, since the race's dates can still change.
 *
 * @return void
 */
function rm_serve_race_calendar() {
    if ( ! isset( $_GET[ RM_RACE_CALENDAR_VAR ] ) ) {
        return;
    }
    $race_id = absint( $_GET[ RM_RACE_CALENDAR_VAR ] );
    $ics     = $race_id ? rm_race_calendar_ics( $race_id ) : '';
    if ( ! defined( 'DONOTCACHEPAGE' ) ) {
        define( 'DONOTCACHEPAGE', true );
    }
    do_action( 'litespeed_control_set_nocache', 'WP RaceManager: a race\'s calendar entry follows its dates' );
    nocache_headers();
    if ( '' === $ics ) {
        status_header( 404 );
        header( 'Content-Type: text/plain; charset=utf-8' );
        echo 'No such race.';
        exit;
    }
    $name = sanitize_file_name( (string) get_post_field( 'post_name', $race_id ) ) ?: 'race-' . $race_id;
    header( 'Content-Type: text/calendar; charset=utf-8' );
    header( 'Content-Disposition: attachment; filename="' . $name . '.ics"' );
    echo $ics; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- iCalendar text, escaped by rm_ics_text()
    exit;
}
add_action( 'template_redirect', 'rm_serve_race_calendar', 1 );

/* -------------------------------------------------------------------------
 * The registration mail
 * ---------------------------------------------------------------------- */

/**
 * The race a registration was sent for: its form's field race_id.
 *
 * @return int 0 outside a submission, or when it names no race.
 */
function rm_race_mail_race_id() {
    if ( ! class_exists( 'WPCF7_Submission' ) ) {
        return 0;
    }
    $submission = WPCF7_Submission::get_instance();
    if ( ! $submission ) {
        return 0;
    }
    $value   = $submission->get_posted_data( 'race_id' );
    $race_id = absint( is_array( $value ) ? reset( $value ) : $value );
    return ( $race_id && 'race' === get_post_type( $race_id ) ) ? $race_id : 0;
}

/**
 * What a tag says about a race.
 *
 * @param string $tag     One of RM_RACE_MAIL_TAGS.
 * @param int    $race_id
 * @return string
 */
function rm_race_mail_value( $tag, $race_id ) {
    switch ( $tag ) {
        case '_race_title':
            // As typed: get_the_title() would curl the quotes into HTML entities, which a mail in
            // plain text shows as they are.
            return (string) get_post_field( 'post_title', $race_id, 'raw' );
        case '_race_dates':
            return rm_format_event_dates( get_post_meta( $race_id, '_race_event_start', true ), get_post_meta( $race_id, '_race_event_end', true ) );
        case '_race_start':
            return rm_format_event_datetime( get_post_meta( $race_id, '_race_event_start', true ) );
        case '_race_end':
            return rm_format_event_datetime( get_post_meta( $race_id, '_race_event_end', true ) );
        case '_race_location':
            return rm_race_location( $race_id );
        case '_race_map_url':
            return rm_race_map_url( rm_race_location( $race_id ) );
        case '_race_url':
            return (string) get_permalink( $race_id );
        case '_race_live_url':
            return function_exists( 'rm_live_url' ) ? (string) rm_live_url( $race_id ) : '';
        case '_race_nextup_url':
            return rm_race_nextup_url( $race_id );
        case '_race_calendar_url':
            return '' !== rm_race_calendar_ics( $race_id ) ? rm_race_calendar_url( $race_id ) : '';
    }
    return '';
}

/**
 * Contact Form 7's hook for the tags that are no form field (wpcf7_special_mail_tags): the race's.
 *
 * @param string|null $output What another filter made of the tag; null for nothing yet.
 * @param string      $name   The tag, without brackets.
 * @param bool        $html   Whether the mail is HTML.
 * @return string|null
 */
function rm_race_mail_tag( $output, $name, $html = false ) {
    if ( null !== $output || ! in_array( $name, RM_RACE_MAIL_TAGS, true ) ) {
        return $output;
    }
    $race_id = rm_race_mail_race_id();
    $value   = $race_id ? rm_race_mail_value( $name, $race_id ) : '';
    if ( ! $html ) {
        return $value;
    }
    return str_ends_with( $name, '_url' ) ? esc_url( $value ) : nl2br( esc_html( $value ) );
}
add_filter( 'wpcf7_special_mail_tags', 'rm_race_mail_tag', 10, 3 );

/**
 * List the race's tags where the form's Mail tab lists the tags it can use - for a form with a race
 * field, the plugin's [race] (wpcf7_collect_mail_tags).
 *
 * @param string[]               $tags         The form's tags.
 * @param array                  $options      What was asked for.
 * @param WPCF7_ContactForm|null $contact_form The form.
 * @return string[]
 */
function rm_race_mail_tags_listed( $tags, $options = array(), $contact_form = null ) {
    if ( ! is_array( $tags ) || ! in_array( 'race_id', $tags, true ) ) {
        return $tags;
    }
    return array_values( array_unique( array_merge( $tags, RM_RACE_MAIL_TAGS ) ) );
}
add_filter( 'wpcf7_collect_mail_tags', 'rm_race_mail_tags_listed', 10, 3 );
