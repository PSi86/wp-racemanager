# Test suites

Two of them, and they answer different questions.

**`php tests/run.php`** is the one to reach for: plain PHP, no framework, no WordPress
installation required, so it runs anywhere and it is fast.

**`npm run test:e2e`** and **`npm run test:pilot-selector`** use a real browser, because some
behaviour is what the DOM does rather than what the source says. They are deliberately kept out of
the PHP runner — see [Browser checks](#browser-checks) at the end.

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
| `registration-email` | The address the registration confirmation uses: derived from the site's own domain by default, overridable in the settings, and carried into a form the plugin created — but never over an edit an organiser made by hand. |
| `seo-head` | That `<head>` carries exactly one `<title>` — the SEO handler used to echo its own next to core's — that the per-post override reaches it through `pre_get_document_title`, and that an archive or 404 produces no undefined-variable warnings. |
| `settings-vapid` | That a plain settings save can never wipe the stored private key — it is not rendered into the form, so nothing in the request carries it. |
| `race-files` | The per-race JSON directory being created on demand and reporting failure, and the SQL scoping that keeps a bulk delete inside one race. |
| `activation` | What the activation hook leaves behind, and what a *second* activation must not: the `CREATE TABLE` statement `dbDelta()` can actually parse, the CF7 example form being created once rather than once per activation, and the plugin header — one version number in three places, plus the `Requires` headers. |
| `rest-auth` | That the RotorHazard endpoints ask for a capability instead of just "is logged in", that all three routes are behind that gate, and that the dead API key check stays gone. |

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

## Browser checks

```bash
npm run test:pilot-selector                         # no WordPress needed, only a browser
npm run test:live-resume                            # against https://racemanager.ddev.site
npm run test:e2e                                    # against https://racemanager.ddev.site
RM_E2E_URL=https://other.ddev.site npm run test:e2e
RM_E2E_SHOT=shot.png npm run test:e2e               # also save a screenshot
```

Both run through Playwright, and both skip rather than fail when Playwright or its Chromium is
missing.

### `tests/e2e/pilot-selector.cjs`

`js/rm-m-pilotSelector.js` against the real module in a real DOM. It needs **no** WordPress and no
DDEV: the page is assembled in the test and the `dataLoader` the module imports is served as a
stub, so the subscriber callback can be driven directly. A browser is required all the same,
because what is under test is what a `<select>` does with its options and its `selectedIndex` —
exactly what a hand-written DOM stub tends to get wrong.

It covers both halves of **D1**, which have to hold together: that repeated data updates rebuild
the option list instead of appending another copy of it, and that a pilot leaving the field mid-race
falls back to the placeholder instead of leaving the control blank at `selectedIndex === -1`. Plus
that the server-rendered placeholder survives the rebuild, that the fallback dispatches a `change`
so `displayHeats` and `displayStats` stop filtering by a pilot the list no longer offers, and that a
page without the control does not take the module down on import.

Run against the module as it was before the fix, five of its eight checks fail — which is the
point of it.

### `tests/e2e/live-resume.cjs`

`js/rm-live-resume.js`, and what the selection page does with the race it remembers. Needs a
started site with **at least two races that carry result data**, because the whole question is what
happens when a visitor comes back; it skips when there are fewer.

The behaviour is a state machine split between the server and `localStorage`, and the two halves
disagreeing is what it guards. The selection page resolves a race of its own — the header link back
to it carries `?rm_race=<slug>` — so "the server gave me a race" is a different question from "this
is a race page". It covers: a race page storing itself; the selection page reached without the
marker still offering the stored race *and* marking it in the list; the selection page reached
*with* the marker marking that race and not also offering it; the marker keeping the stored entry
in step; and `?resume=1` going straight through.

### `tests/e2e/editor.cjs`

The block editor, through Playwright. Exit codes match the PHP suites: 0 passed,
1 failed, 2 skipped. It skips — rather than fails — when Playwright is missing, when no Chromium
has been downloaded for the installed Playwright build, when the site is unreachable, or when the
login is refused; each of those prints the command that fixes it. `RM_E2E_USER` and `RM_E2E_PASS`
default to the `admin` / `admin` that `bin/bootstrap-devenv.sh` creates.

What it guards:

| Check | Why it cannot be a PHP suite |
|---|---|
| the editor canvas is an iframe | Since 7.1 this is unconditional — there is no `apiVersion` check left in `editor`, `block-editor` or `edit-post`. A block that is not iframe-safe no longer degrades the editor, it just misbehaves. |
| every block is on `apiVersion: 3` | Read from the live registry rather than from `block.json`, so a block that fails to register is caught too. |
| each block renders inside the iframe | Insertion and rendering are editor behaviour. Blocks that declare a `parent` or `ancestor` are skipped with the reason, because they cannot be inserted at the document root — `nav-latest-races` only lives inside a `core/navigation-submenu`. |
| `race-gallery` thumbnails, its inline `<style>`, and its `wp.media` frame | The block ships its CSS as an inline `<style>` instead of through `block.json`, so what matters is that the element reaches the iframe document *and still applies* — the check measures the rendered thumbnail at 150px. The media modal is the Backbone one: the block's JavaScript runs in the parent realm, so the modal opens over the iframe rather than inside it. None of that is visible from the source. |
| no `apiVersion` deprecation, no console error from this plugin | The browser console is the only place these appear. |

The media checks skip when the library holds no image; `ddev wp media import <file>` gives it one.

This is the check to repeat when a WordPress major changes the editor again. The last time it
earned its keep was the `apiVersion: 3` migration (**A2** in
[`wordpress-update-audit.md`](../docs/wordpress-update-audit.md)), where nothing but a real editor
could answer whether the Backbone media modal survives the iframe.

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
