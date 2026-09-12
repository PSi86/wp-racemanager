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
| `includes/live-routing.php` | The live micro-site's URLs. Resolves the selected race from the path, builds canonical URLs, rewrites navigation links and marks the navigation that switches views (`rm-live-nav`), handles legacy redirects. Start here for anything about `/live/`. |
| `includes/livepage-handler.php` | The four live-page shortcodes, the JS module configuration they emit, and the view tabs fixed to the foot of a phone's screen — emitted once per page, like the pill, with a stylesheet that is loaded only where the tabs are. |
| `includes/rest-handler.php` | The REST endpoints RotorHazard talks to. |
| `includes/vapid-handler.php` | Web Push keys — the single source of truth. |
| `includes/cpt-handler.php` | The `race` custom post type and its meta. |
| `includes/pilot-key.php` | The pilot key: one version-5 UUID per registered email address, the same every time, derived in a namespace of this site's own. What RotorHazard is to match a returning pilot by, since `user_id` is 0 for everyone without an account. `rm_registration_rows()` in `admin-registrations.php` puts it into the admin list, the CSV and `get-pilots` alike. |
| `js/rm-m-dataLoader.js` | Singleton that polls the race JSON and notifies subscribers. Every other `rm-m-*` module hangs off it. Two channels out: `subscribe()` for the data, `onState()` for what the loader is doing. |
| `js/rm-m-updateStatus.js` | The freshness pill floating at the foot of every live view — the only consumer of `onState()`. `describe()` is pure and exported so the state machine can be tested without a broken network, and `isRelevant()` beside it decides whether the pill appears at all: on a race that is **not** flagged live it stays hidden unless the data could not be loaded. `rm_update_status_markup()` emits it **once per page**, not once per shortcode. |
| `templates/template-pwa-sw.js` | The service worker: push, and since L6 the kept copies of the live pages and their files for when the network does not answer. Network first for everything, the race JSON never touched. Written to the WordPress root as `pwa-sw.js` by `rm_maybe_refresh_pwa_files()` on `admin_init`, whenever a value or a template changed. |

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
- **Every enqueued asset is versioned with `WP_RACEMANAGER_VERSION`, never a literal.** A
  hand-written version has to be remembered on every edit and never is: `rm-update-status.css` was
  rewritten twice while the `'1.1.0'` beside it stayed put, and two stylesheets carried no version
  at all. A returning visitor then keeps the cached copy and runs the previous release, which
  looks like nothing is wrong because the old file still works. `asset-versions` reads every
  enqueue in the source and fails on a literal or a missing version — three had survived the rule
  because no suite rendered them. A bundled library under `assets/` is versioned by the release in
  its directory name instead. This cannot cover `rm-m-dataLoader.js`, reached through a relative
  `import` that WordPress does not version — see `docs/deployment.md`.
- **`js/rm-m-pilotSelector.js`, `js/rm-m-displayHeats.js` and `js/class_templates_V1.js` run on
  the timer too.** The RotorHazard connector's `/bracketview` takes them over byte for byte
  (decided on 2026-09-12: this repository is their source), next to a `dataLoader` of its own that
  reads RotorHazard's socket and hands a new subscriber `{}` until every section has arrived. A
  change to them has to work there as well; the connector picks it up with its next sync.
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

The `tests/e2e/*.cjs` suites are separate on purpose: they need a started DDEV site,
`node_modules` and a Chromium, and they cover what PHP cannot reach. Each skips rather than fails
when any of that is missing.

| Suite | Covers |
|---|---|
| `npm run test:e2e` | the block editor — that the blocks survive its iframe, that `race-gallery`'s media modal still opens, and that the console stays clean |
| `npm run test:pilot-selector` | the pilot dropdown, rebuilt list and placeholder fallback, and the timer's `dataLoader` handing it `{}` first |
| `npm run test:class-templates` | the bracket templates: every entry a seeding label, no test pilot, every ID once. Node only, no browser |
| `npm run test:live-resume` | remembering the last race, and the selection page presenting it the same way whether it came from the URL or from storage |
| `npm run test:update-status` | the data path and the freshness pill — where the cache goes, that a returning visitor does not download the payload again (measured in bytes off the wire), and that the pill never claims freshness it does not have — and, offline, is there and says so |
| `npm run test:flaky-network` | the live app on a bad mobile link: a payload that never arrives, a body that stalls after the headers, an impatient viewer hammering refresh, a slow-but-working connection, and an outage with a warm cache. This is the regression guard for the field failure described above |
| `npm run test:offline` | the service worker: the first visit kept, a reload or an app launch without a connection still showing the race with the pill there and saying offline — a finished race's too — no race JSON in its cache, the kept page after the deadline on a link that delivers nothing, and neither `no-store` pages nor stale copies of changed files served while the network answers |
| `npm run test:bracket-titles` | the bracket scrolled sideways on a phone: the class titles stay where they are, whole in view and over an opaque background, while the races and their connecting lines move by exactly the distance scrolled |
| `npm run test:view-tabs` | the live views on a phone: the view tabs at the foot of the screen, one tap to another view, the pill above them and room kept below, 44 px targets for the pilot filter and the theme's burger, the current view marked, and no tabs on a wide screen or without a race |
| `npm run test:stats-filter` | the pilot filter on the stats view — marking, filtering, the per-round lap tables going with the leaderboards, pruning of what the filter emptied, and that the bracket view's own filter is unmoved |

`test:update-status` needs a race that is flagged live (`ddev wp post meta update <id> _race_live
1`), or the polling half of it has nothing to watch; it says so and carries on with the rest.

**When changing the live routing, run `php tests/run.php live` and make sure `live-links` does
not skip** — that suite needs a WordPress checkout, and it is the one that would catch a
navigation regression.

## The local site

A DDEV site at `https://racemanager.ddev.site` runs this working copy, and it may be started and
used at any time — for measuring, for the browser suites, for trying a change before it is
committed. `ddev start` has to run from the project directory one level up, because `ddev` finds
its project by walking up from the working directory; `bin/dev-doctor.sh` then says whether the
site still looks like production.

The Windows host has no PHP, so the PHP suites run in the container:

```bash
MSYS_NO_PATHCONV=1 ddev exec -d /var/www/html/wp-app/wp-content/plugins/wp-racemanager php tests/run.php
```

`MSYS_NO_PATHCONV=1` is for Git Bash, which otherwise rewrites the `-d` path into a Windows one;
PowerShell needs nothing. After changing `templates/template-pwa-sw.js`, `ddev wp eval
'rm_maybe_refresh_pwa_files();'` — or opening any admin page — writes the new worker to the
WordPress root; until then the site serves the old one. Three races carry real result data from
production. The theme differs —
Twenty Twenty-Five here, Frost on production — which matters for anything the navigation renders.
The rest of the setup, and what deliberately does not match production, is in
[`docs/development-setup.md`](docs/development-setup.md).

## Building the blocks

```bash
npm ci             # not `npm install` -- the built output is committed
npm run build      # wp-scripts, blocks-src/ -> blocks/
```

Only `race-gallery` is built from source; the other blocks are hand-written `index.js` files
in `blocks/`. The build runs on the host, and `blocks/race-gallery/` is committed, so a source
change has to be committed together with its rebuilt output.

**Every block is dynamic**: `save` returns `null` and the markup comes from a `render_callback` in
`includes/block-render-*.php`. Nothing is stored in post content but the block delimiter, so
changes to `block.json` — `apiVersion`, attributes, supports — cannot invalidate saved content.
That removes the usual risk from block migrations, and it is worth checking before assuming one is
dangerous.

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
  locations; historically it lived outside the plugin. `php-http/guzzle7-adapter` lets a round of
  pushes go out at once; without it they go one after another. Either way they go after the
  answer (`includes/after-response.php`, since 1.6.0).
- Registration data lives in a custom table `{prefix}rm_registrations`, push subscriptions in
  `{prefix}rm_subscriptions`. Race JSON lives in `wp-content/uploads/races/`.

### Options the plugin owns

| Option | What it holds |
|---|---|
| `rm_live_page_id` | The live area's parent page. Everything under `/live/` hangs off it. |
| `rm_live_routing` | Cached view slugs and the live path; rebuilt when the live pages change. |
| `rm_vapid` | Web Push key pair and subject, unless the `RM_VAPID_*` constants are set. |
| `rm_pilot_namespace` | The namespace the pilot keys are derived in: a random UUID, generated once, unless `RM_PILOT_NAMESPACE` is set in `wp-config.php`. **Never change it** — every pilot would get a new key, and RotorHazard would take each for a new pilot. |
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

**Two orderings in `js/rm-m-dataLoader.js` are load-bearing, and both were learned from a failure
at a real event** — the app came up empty and stayed empty through reload after reload, and came
back only when it was killed outright. Do not reorder either without reading the reasoning in
`docs/data-flow.md`:

- **The timestamp is committed only after the payload has arrived.** Recording it first marks a
  version as seen that was never received, and every later check then skips the download.
- **The abort deadline covers the body read, not just the headers.** A fading link delivers
  headers and then stalls; a timer cleared too early leaves an in-flight flag set for ever, and
  every later check returns at the guard that reads it.

`tests/e2e/flaky-network.cjs` covers both, and fails against the pre-2026 loader.

The browser cache lives in `localStorage` under `rm_data_{race_id}` with `rm_data_{race_id}_meta`
beside it, and a write evicts every other race first — one payload is ~1.2 MB against an origin
budget of a few megabytes. The `rm_data_` prefix is what eviction matches; `rm_last_race` belongs
to `js/rm-live-resume.js` and must survive it. `storageKey` in the config stays the **bare race
id** because three view modules build their own keys and `data-race-id` attributes out of it.

## Known open items

Three lists, and they answer different questions:

- [`docs/wordpress-update-audit.md`](docs/wordpress-update-audit.md) — what a year of WordPress
  updates broke or exposed. 24 findings, all resolved. Closed.
- [`docs/live-webapp-improvements.md`](docs/live-webapp-improvements.md) — how the live app itself
  could get better, above all its data path. L1–L6 and L9 are done; L7/L8 (per-section files,
  then per-section uploads) are open; L10 (a CDN) is not needed at this audience size. [`docs/data-flow.md`](docs/data-flow.md) is the baseline it changes.
- [`docs/pilot-identity.md`](docs/pilot-identity.md) — a stable per-pilot identifier in
  `get-pilots`, so RotorHazard can recognise a returning pilot. Decided on 2026-09-11: no account
  needed, none created, one reproducible key per email address. The WordPress half is done
  (`pilot_key`); the connector still has to match on it.

The audit is closed, so the other two are the ones with work left in them. The audit stays worth
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
