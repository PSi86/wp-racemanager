# WP RaceManager

WordPress plugin that connects a [RotorHazard](https://github.com/RotorHazard/RotorHazard) FPV
race timer to a WordPress site. RotorHazard uploads race data over REST; the plugin stores it,
renders live results, and pushes notifications to pilots. The live area is installable as a PWA.

## Layout

```
wp-racemanager.php        Plugin bootstrap, singleton, activation hook
includes/                 All PHP. One concern per file, loaded from the bootstrap
blocks/                   Built Gutenberg blocks (block.json + index.js per block)
blocks-src/               Source for the blocks built with wp-scripts (only race-gallery so far)
js/                       Frontend. rm-m-*.js are ES modules for the live pages
css/, img/, assets/       Styles, PWA icons, bundled Swiper
templates/                Templates for the generated manifest.json and pwa-sw.js
tests/                    Plain-PHP test suites — see tests/README.md
bin/                      build-plugin-zip.sh (deployable artifact), dev-doctor.sh (local site check)
```

`.gitattributes` decides what ships: everything development-only is `export-ignore`d, so
`git archive` (and with it `bin/build-plugin-zip.sh`) leaves it out. See
[`docs/deployment.md`](docs/deployment.md).

### The pieces that matter most

| File | What it owns |
|---|---|
| `includes/live-routing.php` | The live micro-site's URLs. Resolves the selected race from the path, builds canonical URLs, rewrites navigation links, handles legacy redirects. Start here for anything about `/live/`. |
| `includes/livepage-handler.php` | The four live-page shortcodes and the JS module configuration they emit. |
| `includes/rest-handler.php` | The REST endpoints RotorHazard talks to. |
| `includes/vapid-handler.php` | Web Push keys — the single source of truth. |
| `includes/cpt-handler.php` | The `race` custom post type and its meta. |
| `js/rm-m-dataLoader.js` | Singleton that polls the race JSON and notifies subscribers. Every other `rm-m-*` module hangs off it. |

## How the live area works

The selected race lives **in the URL path**, never in server state:

```
/live/                        race selection
/live/{race-slug}/{view}/     a race in one of the views
/live/{race-slug}/            301 to the default view
/live/{view}/                 valid; renders "no race selected"
```

`{view}` is the slug of any child page of the configured live page, so the view pages stay
ordinary editable WordPress pages. One rewrite rule maps the two-segment form onto the view
page plus an `rm_race` query var.

Consequences worth keeping in mind when changing this:

- **The rewrite rule's second segment is an alternation of the actual view slugs**, not a
  generic `[^/]+`. A generic pattern also swallows `/live/page/2/` and `/live/{race}/feed/`.
- **Navigation links are rewritten at render time** (`rm_rewrite_live_links`, on both
  `render_block` and `wp_nav_menu`) so every item carries the current race. The link back to
  the selection page carries `?rm_race={slug}` as a marker — never `race_id`, which would
  trigger the legacy redirect and bounce the visitor straight out again.
- **Nothing is stored server-side**, so every live URL is cacheable and tabs are independent.
  "Continue where I was" is client-side in `js/rm-live-resume.js`.
- Rewrite rules are cached in the `rm_live_routing` option and flushed when the live page or
  one of its children changes. After changing the rule itself, re-save Permalinks.

## Conventions

- No namespace in `includes/*.php` except `pwa-subscription-handler.php`; functions are
  prefixed `rm_`. The main plugin file uses the `RaceManager` namespace.
- Frontend ES modules are named `rm-m-<thing>.js` and are loaded with
  `wp_enqueue_script_module()`. They read their configuration from `window.RmJsConfig`, which
  `rm_print_js_module_config()` prints on `wp_head`.
- `wp_register_script_module()` takes **five** parameters since WordPress 6.9, the fifth being
  `array $args`. Passing anything else there is an uncaught `TypeError`.
- Race JSON files go through `rm_get_race_data_dir()` / `rm_get_race_data_url()`, never a
  hand-built path — reader and writer must not disagree about where the files live.
- Event dates go through `rm_normalize_event_datetime()` on every write. Canonical format is
  `Y-m-d H:i:s` in site-local wall clock; the admin inputs need `rm_event_datetime_for_input()`
  because `datetime-local` rejects a space instead of the `T`.

## Tests

```bash
php tests/run.php
```

Plain PHP, no framework, no WordPress needed. Two suites need optional dependencies and skip
themselves cleanly — see `tests/README.md`. Add a suite by dropping a file in `tests/suites/`.

**When changing the live routing, run `php tests/run.php live` and make sure `live-links` does
not skip** — that suite needs a WordPress checkout, and it is the one that would catch a
navigation regression.

## Building the blocks

```bash
npm install
npm run build      # wp-scripts, blocks-src/ -> blocks/
```

Only `race-gallery` is built from source; the other blocks are hand-written `index.js` files
in `blocks/`.

## Environment

- Requires Contact Form 7 (checked on activation).
- Push needs `minishlink/web-push` via Composer. The autoloader is looked for in several
  locations; historically it lived outside the plugin.
- Registration data lives in a custom table `{prefix}rm_registrations`, push subscriptions in
  `{prefix}rm_subscriptions`.

## Known open items

The full list with status per item is in [`docs/wordpress-update-audit.md`](docs/wordpress-update-audit.md);
24 findings, 20 resolved. The ones most likely to bite while working here:

- **A2** All blocks are on `apiVersion: 2`. Deprecated since WordPress 6.9; the editor falls
  out of iframe mode for any post containing one. Needs F1 first.
- **D1** `js/rm-m-pilotSelector.js` appends options on every data update without clearing.
  Only bites during a live race on a long-open page. The two fixes belong together: rebuilding
  the list alone makes the selection go blank when a pilot leaves the field, because today the
  stale option is what keeps it selected.
- **F1** npm and Composer dependencies are one to two majors behind.

## Documentation

- [`docs/`](docs/) — the audit and to-do list, the deployment test protocol, and the reasoning
  behind the live URLs and the VAPID handling.
- [`docs/development-setup.md`](docs/development-setup.md) — setting up a local WordPress with
  DDEV. The plugin belongs in `wp-content/plugins/wp-racemanager/`, which is also the layout the
  `live-links` suite needs to find a WordPress checkout. **Still pending:** development is meant
  to move to VS Code against this local site; the guide is written, the site is not built yet.
  Postponed, not dropped.
- [`docs/deployment.md`](docs/deployment.md) — building the artifact and installing it on a host
  without WP-CLI. Note that a ZIP replace does **not** re-run the activation hook, and that
  reactivating to force it duplicates the CF7 registration form (E10).
- [`tests/README.md`](tests/README.md) — how to run and extend the suites.
