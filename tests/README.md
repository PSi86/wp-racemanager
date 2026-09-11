# Test suites

Two runners, and they answer different questions.

**`php tests/run.php`** is the one to reach for: plain PHP, no framework, no WordPress
installation required, so it runs anywhere and it is fast.

**`npm run test:e2e`**, **`test:pilot-selector`**, **`test:live-resume`**,
**`test:update-status`**, **`test:flaky-network`**, **`test:stats-filter`**, **`test:offline`** and
**`test:view-tabs`**
use a real browser, because some behaviour is what the DOM, the network, the service worker and the
browser's own storage do rather than what the source says. They are deliberately kept out
of the PHP runner — see [Browser checks](#browser-checks) at the end.

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
| `live-links` | Rewriting the live navigation so every item carries the current race, run against the **real** `WP_HTML_Tag_Processor`. Includes the full "visitor on race 66" scenario and the cases that must stay untouched. Plus the `rm-live-nav` marking of a navigation with view links — with its links already rewritten, which is how the navigation block meets them — and that the filter is registered for both of its arguments. |
| `live-shortcodes` | The four live-page shortcodes against the **verbatim WordPress 7.1 signatures** of the script module API. This is the regression guard for the 6.9 breakage: `wp_register_script_module()` gained a fifth `array $args` parameter, and anything else there is an uncaught `TypeError` that kills the whole page. Also the view tabs every view emits: one row per page, a tab per view in page order, the current one marked, none without a race, one on a finished race's next-up view, and a routing cache from before the tabs rebuilt with the page titles. |
| `asset-versions` | That every asset the plugin enqueues carries `WP_RACEMANAGER_VERSION`. Read from the source with the tokenizer rather than from a rendered page, so it reaches the call sites no other suite executes — the admin, the navigation, the service worker registration — and a new file is covered the day it is added. Bundled libraries under `assets/` are versioned by the release in their directory name; the legacy `[rm_viewer]` shortcode is exempt by name, and the suite fails once that exemption has nothing left to cover. |
| `pwa-files` | `manifest.json` and `pwa-sw.js` as the plugin writes them into the WordPress root: every placeholder a template uses has a value, the worker's cache is named for the plugin version and the template, and a template changed **without** a version bump is written out all the same — it used to stay on disk as it was, because the signature that decides about rewriting did not cover the templates. Works on a copy of `templates/` so it can change one. |
| `vapid` | Key generation, the refusal to generate while subscriptions exist, key and contact validation, and constants beating the database. Runs against the real `minishlink/web-push`. |
| `registration-email` | The address the registration confirmation uses: derived from the site's own domain by default, overridable in the settings, and carried into a form the plugin created — but never over an edit an organiser made by hand. |
| `seo-head` | That `<head>` carries exactly one `<title>` — the SEO handler used to echo its own next to core's — that the per-post override reaches it through `pre_get_document_title`, and that an archive or 404 produces no undefined-variable warnings. |
| `settings-vapid` | That a plain settings save can never wipe the stored private key — it is not rendered into the form, so nothing in the request carries it. |
| `race-files` | The per-race JSON directory being created on demand and reporting failure, and the SQL scoping that keeps a bulk delete inside one race. |
| `activation` | What the activation hook leaves behind, and what a *second* activation must not: the `CREATE TABLE` statement `dbDelta()` can actually parse, the CF7 example form being created once rather than once per activation, and the plugin header — one version number in three places, plus the `Requires` headers. |
| `rest-auth` | That the RotorHazard endpoints ask for a capability instead of just "is logged in", that every route and method is behind that gate — a route that answers GET and POST counted twice — that the upload's optional `race_id` is checked per race, and that the dead API key check stays gone. |
| `race-selection` | A timer naming its race: the upload with `race_id` updates exactly that race and never creates one — the lookup by title that D3 in the RotorHazard plugin's roadmap is about does not even run — while an upload without it still goes by title, for older timers. `GET /races` lists the 15 newest races the user may edit — counted after that check, read page by page past the ones they may not, the newer post first among equal starts; `POST /races` creates a race from the event, with its files from the start, and refuses a title another race has — the title as it would be stored, a race in the bin not counting — naming that race and saying whether the user may edit it. And the HTTP status of each refusal: 404 for no race, 403 without the right, 400 for a locked race, 409 for a title that is taken, 500 when the files cannot be written. |

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
npm run test:update-status                          # against https://racemanager.ddev.site
npm run test:flaky-network                          # against https://racemanager.ddev.site
npm run test:stats-filter                           # against https://racemanager.ddev.site
npm run test:offline                                # against https://racemanager.ddev.site
npm run test:view-tabs                              # against https://racemanager.ddev.site
npm run test:e2e                                    # against https://racemanager.ddev.site
RM_E2E_URL=https://other.ddev.site npm run test:e2e
RM_E2E_SHOT=shot.png npm run test:e2e               # also save a screenshot
```

All of them run through Playwright, and all of them skip rather than fail when Playwright or its
Chromium is missing.

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

### `tests/e2e/update-status.cjs`

`js/rm-m-dataLoader.js` and `js/rm-m-updateStatus.js` — where the cache goes, how the polling
loop schedules itself, and what the freshness pill is willing to claim. Needs a started site with
a race that has result data; the polling half additionally needs that race flagged live
(`ddev wp post meta update <id> _race_live 1`), and says so rather than failing when it is not.

Its four groups are different kinds of claim, and the difference is the point:

- **The cache** is checked by looking at `localStorage` directly: the payload under its prefixed
  key, the metadata beside it, nothing left in `sessionStorage`, another race evicted on demand —
  and `rm_last_race`, which belongs to `js/rm-live-resume.js`, still there afterwards. That last
  one guards a prefix that is one careless character away from sweeping up the resume entry.
- **The scheduling** is exercised by calling `scheduleNext()` and `currentDelay()` directly rather
  than by waiting out real intervals: twelve delays all within ±20 % and not all equal, the
  backoff doubling to its cap, and a hidden page scheduling nothing at all.
- **The state machine** runs against the exported, pure `describe()` over a table of states. That
  is why it is exported: offline, a failing check, unconfirmed data and an overdue check are all
  states that need a broken network to happen naturally, and every one of them has to fail to say
  "up to date". The same table covers when the pill appears at all — on a race that is not live it
  stays hidden, including while it loads and including when the browser goes offline *after* a
  successful check, and comes back only when the data could not be loaded.
- **Two things a pure function cannot catch**, both found by looking at a browser rather than at a
  result line, and both now guarded here: that the `hidden` attribute actually hides the element
  (the stylesheet sets `display`, which beats the browser's own `[hidden]` rule), and that a load
  which never succeeds settles on the failure instead of sitting on "Loading race data…" for good.
- **The saving** is measured in bytes off the wire, with a **persistent browser profile**. A fresh
  Playwright context is a first-ever visit and would prove nothing; only a profile that survives a
  browser restart reproduces what a returning viewer does. First visit ~100 KB, coming back ~111
  bytes.

Run against the loader as it was before the change, the returning-visitor checks fail: it
downloaded the full payload every time, 100,839 bytes.

### `tests/e2e/flaky-network.cjs`

The live app on a bad mobile link, and the only suite here written in response to something that
happened at a real event rather than to something found while reading code.

The report: for some spectators the app came up empty and **stayed** empty. Reloading did not
help, reloading again did not help, and it came back only when the app was killed outright.

The cause was an ordering mistake in the loader. It recorded the timestamp it had just fetched
*before* downloading the payload that timestamp pointed at. When the download failed — which on a
fading connection it does — that version was already marked as seen, so every later check found
the timestamp unchanged and never asked for the data again. The note lived in `sessionStorage`,
so it survived every reload and died only with the tab.

Run against the pre-2026 loader this suite reports exactly that: `cachedTimestamp` set although
the payload never arrived, `0` payload requests on each subsequent reload, and no standing on
screen even with the network fully healthy again.

Five things are covered, and the second was found *by writing this suite* rather than from the
report:

1. A payload that never arrives leaves nothing behind that suppresses the next attempt.
2. A response whose body stalls after the headers does not wedge the loader either — a second way
   into the same dead end, and the one a fading link produces most often. The abort deadline has
   to cover the body read, not just the headers.
3. Twelve rapid taps on the refresh control produce at most two requests.
4. A slow but working link still delivers, and the payload gets a longer deadline than the 30-byte
   timestamp check so that a download about to succeed is not cut off every time.
5. With a warm cache, a total outage shows the last known standing rather than an empty page —
   the difference between "the app is broken" and "the app is behind".

Needs a race flagged live, like `update-status.cjs`.

### `tests/e2e/offline.cjs`

The service worker's side of a bad link (L6). `flaky-network.cjs` shows that a warm
`localStorage` turns an outage into old data; this one shows there is still a page to show it on.
Before L6 the worker had no `fetch` handler, and a reload without reception got the browser's error
page — `net::ERR_INTERNET_DISCONNECTED`, which is what 14 of the 25 checks that existed at the
time reported against it.

Offline is `context.setOffline(true)`, and a link that is up but delivering nothing is
`context.route` holding every request: in Chromium that reaches the worker's own fetches as well,
which `page.route` does not. What it covers:

1. **The first visit is kept**, although the worker was installed during it and never saw the page
   load — the visit a spectator at the trackside is most likely to have had.
2. **What is kept**: the page, a versioned view module, the loader (which carries no version) and
   the pill's stylesheet — and **no race JSON**, which the loader keeps itself and must fetch from
   the network for the pill to be honest.
3. **A reload without a connection** shows the page, styled, with the standing from `localStorage`,
   and the pill does not claim it is current. And **launching the installed app** without one —
   `start_url` is the selection page, which this visitor never opened — goes on to the race last
   viewed. The first version of the worker failed exactly that: it kept every race page and not the
   page the app starts on.
4. **A page never opened here** gets a *No connection* page with status 503, not the browser's.
5. **Back online, the page comes from the network**, told apart from the kept copy by its `Date`.
6. **Housekeeping**: a cache named like an older worker's is deleted on activation, one belonging
   to something else on the origin is left alone. Both are planted before the worker first runs.
7. **A link that stops delivering** gets the kept page after the worker's 5 s deadline, and the
   page's files do not each wait out a deadline of their own: 5.0 s to a usable page, where the
   first, cache-first version took 10.05 s.
8. **A page marked `no-store`** — the header WordPress sends a logged-in user, faked on the response
   so no credentials are needed — is shown but not kept.
9. **Online, the network decides**, even for a URL with `?ver=`: a stylesheet changed without a
   version bump reaches the page, and replaces the kept copy.

The order is not free. After a failure the worker answers a page's *files* from its copies for 30 s,
so the checks that need the network to win (5 and 9) run before the one that holds every request
(7).

### `tests/e2e/view-tabs.cjs`

The live views on a phone (L9), at 390 × 844 with touch. Measured on production before the change:
every switch between views went through the theme's burger, the overlay's items were 32 px tall,
the burger 30 px, the pilot dropdown 29 px and the filter checkbox 13 px, and the current view was
marked only in the markup.

1. **The view tabs** are fixed to the foot of the screen across its full width: a tab per view, each
   at least 44 px tall and pointing at this race, exactly one current and marked by weight and a
   bar rather than colour alone, the foot of the page padded by the row's height, and the pill —
   where it shows — above the row rather than on it.
2. **One tap** on another tab opens that view, where that tab is then the current one.
3. **The pilot filter**: dropdown and checkbox label at least 44 px, and a tap on the label's text
   toggles the box.
4. **The theme's navigation**, if it has view links (`bin/bootstrap-devenv.sh` creates one; without
   it the section skips): the burger answers 20 px from its centre, the overlay's items are 44 px
   tall, the current view looks different, and the open overlay covers the tabs.
5. **A wide screen** shows no tabs and pads nothing.
6. **No race, no tabs**; a finished race's next-up view keeps them.
7. **No JavaScript**: the tabs are plain links and still there.

Against the plugin as it was, 16 of the 20 checks that run fail; the navigation section skips,
since nothing marked the navigation then.

### `tests/e2e/stats-filter.cjs`

The pilot filter on the stats view: a dropdown that marks one pilot in every leaderboard they
appear in, and a checkbox that drops everyone else. The same two levels the bracket view has
always offered.

What makes it worth its own suite is how far the filter has to reach. Every leaderboard on the
page comes out of one method — class summaries, per heat, per round, event totals — and underneath
them sit the per-round lap tables, which carry the same `pilot_id`. Filtering the standings while
leaving everyone else's lap times under them is the kind of half-applied filter that makes people
stop trusting the control, so that is checked by counting both. A page of table headers with no
rows beneath them is the other failure it guards against, which is what the pruning pass exists
for; a pilot who appears nowhere gets a stated answer rather than a blank page.

The last group re-checks the **bracket** view. `displayStats` now imports `pilotSelector`, which
the bracket has always imported, and both views share one selection — so the bracket's own dimming
and structural filtering are re-measured here to make sure nothing moved.

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
