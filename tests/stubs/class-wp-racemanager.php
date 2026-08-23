<?php
/**
 * tests/stubs/class-wp-racemanager.php
 *
 * Stand-in for the plugin's main class. Only is_live_page() is consulted by the code under
 * test -- the routing module uses it to keep the legacy ?race_id redirect inside the live
 * area. Suites flip RaceManager\WP_RaceManager::$live to move in and out of that area.
 */

namespace RaceManager;

if ( ! class_exists( __NAMESPACE__ . '\WP_RaceManager' ) ) {
    class WP_RaceManager {
        /** @var bool Whether the current request counts as a live page. */
        public static $live = true;

        public static function is_live_page() {
            return self::$live;
        }

        public static function write_log( $message ) {}
    }
}
