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
php tests/run.php      # plain PHP, runs anywhere
npm run test:e2e       # the block editor, in a real browser
```

`tests/run.php` is plain PHP, no framework, no WordPress needed. Two suites need optional
dependencies and skip themselves cleanly — see `tests/README.md`. Add a suite by dropping a file
in `tests/suites/`.

`tests/e2e/editor.cjs` is separate on purpose: it needs a started DDEV site, `node_modules` and a
Chromium, and it covers what PHP cannot reach — that the blocks survive the editor's iframe, that
`race-gallery`'s media modal still opens, and that the console stays clean. It skips rather than
fails when any of that is missing.

**When changing the live routing, run `php tests/run.php live` and make sure `live-links` does
not skip** — that suite needs a WordPress checkout, and it is the one that would catch a
navigation regression.

## Building the blocks

```bash
npm ci             # not `npm install` -- the built output is committed
npm run build      # wp-scripts, blocks-src/ -> blocks/
```

Only `race-gallery` is built from source; the other blocks are hand-written `index.js` files
in `blocks/`. The build runs on the host, and `blocks/race-gallery/` is committed, so a source
change has to be committed together with its rebuilt output.

`webpack.config.js` is load-bearing: wp-scripts empties its output directory before every emit,
and the output directory is `blocks/`, so the config narrows the clean to the folders that have a
source under `blocks-src/`. Without it a build deletes the six hand-written blocks silently. After
any `@wordpress/scripts` bump, run `npm run build && git status --short blocks/` — nothing may
appear as deleted.

`node_modules/` sits inside the DDEV project and the container never reads it, so
`.ddev/mutagen/mutagen.yml` ignores `/wp-racemanager/node_modules`. That file has to have its
`#ddev-generated` marker removed to survive, and the marker must not appear anywhere else in it
either — DDEV greps the whole file. See "Building the blocks" in
[`docs/development-setup.md`](docs/development-setup.md).

## Environment

- Requires Contact Form 7 (checked on activation).
- Requires **PHP 8.2** and **WordPress 6.5** — declared in the plugin header. The PHP floor comes
  from `web-token/jwt-library` behind `minishlink/web-push`, the WordPress one from the Script
  Modules API.
- Push needs `minishlink/web-push` via Composer. The autoloader is looked for in several
  locations; historically it lived outside the plugin.
- Registration data lives in a custom table `{prefix}rm_registrations`, push subscriptions in
  `{prefix}rm_subscriptions`. Race JSON lives in `wp-content/uploads/races/`.

### Options the plugin owns

| Option | What it holds |
|---|---|
| `rm_live_page_id` | The live area's parent page. Everything under `/live/` hangs off it. |
| `rm_live_routing` | Cached view slugs and the live path; rebuilt when the live pages change. |
| `rm_vapid` | Web Push key pair and subject, unless the `RM_VAPID_*` constants are set. |
| `rm_registration_email` | Sender/Reply-To/Bcc of the registration mail. Empty = derive from the site domain. |
| `rm_cf7_form_id` | The CF7 example form the activation hook created, so it is created only once. |
| `rm_seo` | Default title, description and keywords. |
| `rm_last_races_count` | How many races the navigation submenu lists. |
| `rm_callsign_field` | Name of the CF7 field holding the pilot callsign. |
| `rm_pwa_files_signature` | Hash of the values baked into `manifest.json` / `pwa-sw.js`; a mismatch regenerates them. |
| `rm_event_dates_migrated` | Timestamp of the last event-date migration run. |

## How the data reaches the viewer

RotorHazard uploads the **whole** result JSON; the plugin writes it to two files per race and the
browser polls the small one to decide whether to download the big one. The contract, its costs and
what could replace it are in [`docs/data-flow.md`](docs/data-flow.md) — read that before changing
`js/rm-m-dataLoader.js`, `rm_write_files()` or the upload endpoint.

## Known open items

Two lists, and they answer different questions:

- [`docs/wordpress-update-audit.md`](docs/wordpress-update-audit.md) — what a year of WordPress
  updates broke or exposed. 24 findings, all resolved. **The maintenance to-do list.**
- [`docs/live-webapp-improvements.md`](docs/live-webapp-improvements.md) — how the live app itself
  could get better, above all its data path. L1–L10, none started, four questions to answer first.
  [`docs/data-flow.md`](docs/data-flow.md) is the baseline it changes.

The audit is closed, so the second list is the one with work left in it. The audit stays worth
reading for *why* things are the way they are — several entries record a wrong first diagnosis
next to the corrected one.

## Documentation

- [`docs/`](docs/) — the audit and to-do list, the deployment test protocol, and the reasoning
  behind the live URLs and the VAPID handling.
- [`docs/development-setup.md`](docs/development-setup.md) — setting up a local WordPress with
  DDEV. The repository sits **beside** the site (`<project>/wp-racemanager`) and a relative
  symlink at `wp-app/wp-content/plugins/wp-racemanager` points back at it, so the container sees
  the path WordPress insists on while the working copy stays one level below the project root.
  The target must stay relative — Mutagen carries symlinks in `portable` mode and refuses an
  absolute one. `bin/bootstrap-devenv.sh` builds the site from scratch.
- [`docs/deployment.md`](docs/deployment.md) — building the artifact and installing it on a host
  without WP-CLI. Note that a ZIP replace does **not** re-run the activation hook, and that
  reactivating to force it duplicates the CF7 registration form (E10).
- [`tests/README.md`](tests/README.md) — how to run and extend the suites.
