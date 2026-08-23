# Test suites

Plain PHP, no framework, no WordPress installation required.

```
php tests/run.php            # everything
php tests/run.php live       # only suites matching "live"
php tests/run.php -v         # print each suite's full output
php tests/suites/vapid.php   # a single suite
```

Exit code is 0 when every suite passed or skipped for a documented reason, 1 when something
failed. A single suite exits 0 (passed), 1 (failed) or 2 (skipped).

## What is covered

| Suite | Guards against |
|---|---|
| `live-routing` | The `/live/{race}/{view}/` rewrite rule, above all what it must **not** match — `/live/page/2/` (the race list's own pagination), `/live/{race}/feed/`, `/live/bracket/`. Plus URL building, race resolution, draft visibility, and that the legacy `?race_id` redirect stays inside the live area so `/register/?race_id=` keeps working. |
| `live-links` | Rewriting the live navigation so every item carries the current race, run against the **real** `WP_HTML_Tag_Processor`. Includes the full "visitor on race 66" scenario and the cases that must stay untouched. |
| `live-shortcodes` | The four live-page shortcodes against the **verbatim WordPress 7.1 signatures** of the script module API. This is the regression guard for the 6.9 breakage: `wp_register_script_module()` gained a fifth `array $args` parameter, and anything else there is an uncaught `TypeError` that kills the whole page. |
| `vapid` | Key generation, the refusal to generate while subscriptions exist, key and contact validation, and constants beating the database. Runs against the real `minishlink/web-push`. |
| `settings-vapid` | That a plain settings save can never wipe the stored private key — it is not rendered into the form, so nothing in the request carries it. |
| `race-files` | The per-race JSON directory being created on demand and reporting failure, and the SQL scoping that keeps a bulk delete inside one race. |

## Optional dependencies

Two suites need something extra and **skip themselves cleanly** when it is missing — a skip
is not a failure, but it does mean that area is unverified on this machine.

**`live-links` needs a WordPress checkout** for the HTML API. It is looked for in this order:

1. `$WP_CORE_DIR`
2. the WordPress root three levels above the plugin — so if the plugin sits in
   `wp-content/plugins/wp-racemanager/` of a development install, this needs no setup at all
3. `tests/.wordpress/` (git-ignored), if you drop a checkout there

```bash
WP_CORE_DIR=/path/to/wordpress php tests/run.php
```

**`vapid` needs the push library**:

```bash
composer install
```

That creates a plugin-local `vendor/` (git-ignored). The plugin finds the autoloader there or
in the locations it has historically lived — see `rm_push_library_available()`.

## Writing another suite

Drop a file in `tests/suites/`. `run.php` picks it up automatically.

```php
require_once __DIR__ . '/../bootstrap.php';

// Define any stub you need *differently* before loading the shared ones —
// everything in tests/stubs/wordpress.php is guarded with function_exists().
function get_post_meta( $id, $key, $single = false ) { return 'whatever'; }

require_once RM_TEST_DIR . '/stubs/wordpress.php';
require_once RM_PLUGIN_DIR . '/includes/the-file-under-test.php';

rm_test_section( 'What this group is about' );
rm_test_check( 'the assertion', $actual === $expected, 'shown only on failure' );

rm_test_finish();
```

Helpers from `bootstrap.php`: `rm_test_section()`, `rm_test_check()`, `rm_test_skip()`,
`rm_test_finish()`, `rm_test_wp_core_dir()`, `rm_test_load_html_api()`.
From `stubs/wordpress.php`: `rm_test_post()` for fixtures and `rm_test_redirect_from()` to
observe a redirect without the script exiting.

## A note on the stubs

Where real WordPress behaviour matters, the stubs follow core rather than a convenient
approximation — `home_url()` prepends a slash to its path, `add_query_arg()` accepts both of
its signatures. A test that assumes the wrong thing is worse than no test: during this
work a "failing" assertion turned out to be a wrong stub, not a wrong implementation.
