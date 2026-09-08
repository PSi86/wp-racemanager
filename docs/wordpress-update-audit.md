# WordPress update audit

What the WordPress 6.9–7.1 releases broke, and what else the review turned up.

| | |
|---|---|
| Baseline commit | `ba41e7c`, 2025-06-03 |
| WordPress then / now | 6.8.1 → 7.1 |
| Findings | 24 — 24 resolved |
| Both P0 items | resolved |

Status last verified against `main` on 2026-08-23 by reading the code, not from memory.

---

## The core finding — resolved

**WordPress 6.9 added a fifth parameter to `wp_register_script_module()`, and the plugin
passed `true` there.**

```php
// 6.8 and earlier
function wp_register_script_module( string $id, string $src, array $deps = array(), $version = false )

// 6.9 and later
function wp_register_script_module( string $id, string $src, array $deps = array(), $version = false, array $args = array() )
```

Up to 6.8 the function took four parameters and PHP silently discarded the extra argument.
Since 6.9 the parameter exists **and** is typed `array` — `bool` is not coercible to `array`,
so every call threw an uncaught `TypeError`. Block themes render the template body before
`wp_head()` (`wp-includes/template-canvas.php`), so the fatal took the whole page down.

Every live sub-page — `/live/bracket/`, `/live/stats/`, `/live/nextup/`, `/live/pilots/` —
died with "There has been a critical error on this website". The race list on `/live/` still
rendered, which is why the symptom looked like *picking a race* was broken rather than the
pages themselves.

Resolved in [#2](https://github.com/PSi86/wp-racemanager/pull/2). The fifth argument was
dropped rather than replaced with `array( 'in_footer' => true )`, which would have moved the
script tags from head to footer — a real behaviour change, wrong for a hotfix.

---

## Findings

Sorted by priority. IDs are stable and referenced from commit messages and pull requests.

| ID | Prio | Status | Area | Finding | From a WP update? |
|---|---|---|---|---|---|
| A1 | P0 | ✅ [#2](https://github.com/PSi86/wp-racemanager/pull/2) | Live / PWA | Fatal `TypeError` from the new `wp_register_script_module()` signature | yes — WP 6.9 |
| E1 | P0 | ✅ [#3](https://github.com/PSi86/wp-racemanager/pull/3) | Security | `keygen.php` was publicly reachable and printed the private VAPID key | no |
| B1 | P1 | ✅ [#5](https://github.com/PSi86/wp-racemanager/pull/5) | Live / PWA | Race selection lived in the PHP session — not cache-, tab- or PWA-safe | worsened by 6.8+ |
| C1 | P1 | ✅ [#8](https://github.com/PSi86/wp-racemanager/pull/8) | Data model | `_race_event_start` / `_race_event_end` hold Unix integers *and* `datetime-local` strings | no |
| E2 | P1 | ✅ [#4](https://github.com/PSi86/wp-racemanager/pull/4) | Security | Registrations admin had no nonces; bulk delete was not scoped to the race | no |
| E5 | P1 | ✅ [#4](https://github.com/PSi86/wp-racemanager/pull/4) | REST upload | Upload directory was never created; the `WP_Error` was discarded | no |
| A2 | P2 | ✅ | Blocks | All 7 blocks on `apiVersion: 2` — deprecated since 6.9, and 7.1 iframes them anyway | yes — WP 6.9/7.0 |
| B2 | P2 | ✅ [#5](https://github.com/PSi86/wp-racemanager/pull/5) | Performance | `session_start()` on every live page load disabled page caching | no |
| B3 | P2 | ✅ [#11](https://github.com/PSi86/wp-racemanager/pull/11) | Live / PWA | Two live shortcodes on one page overwrote each other's JS config. The block-theme dependency itself is accepted — see below | no |
| B4 | P2 | ✅ [#5](https://github.com/PSi86/wp-racemanager/pull/5) | Live / PWA | Session redirect without no-cache headers; `/live/*` not excluded from speculative loading | yes — WP 6.8/7.1 |
| D1 | P2 | ✅ | Frontend JS | Pilot dropdown accumulates duplicates on every data refresh — in two places, not one | no |
| D3 | P2 | ✅ [#5](https://github.com/PSi86/wp-racemanager/pull/5) | PWA | Manifest and service worker contained `https://domain.com/` and were only written on activation | no |
| D5 | P2 | ✅ [#3](https://github.com/PSi86/wp-racemanager/pull/3) | Frontend JS | `rm-m-pwa-subscribe.js` touched missing DOM nodes unguarded, taking the whole nextup page down | no |
| E3 | P2 | ✅ [#3](https://github.com/PSi86/wp-racemanager/pull/3) | Robustness | Composer autoload via `../../../../../vendor/`, unguarded and inconsistent | no |
| E4 | P2 | ✅ [#10](https://github.com/PSi86/wp-racemanager/pull/10) | Database | `dbDelta()` called with `IF NOT EXISTS` — schema upgrades never apply | no |
| E8 | P2 | ✅ [#5](https://github.com/PSi86/wp-racemanager/pull/5), [#12](https://github.com/PSi86/wp-racemanager/pull/12) | Portability | Hard-coded `copterrace.com` — the URLs went in #5, the CF7 mail addresses in #12 | no |
| E9 | P2 | ✅ [#11](https://github.com/PSi86/wp-racemanager/pull/11) | SEO | Duplicate `<title>`, PHP warnings on non-singular pages | no |
| C2 | P3 | ✅ [#8](https://github.com/PSi86/wp-racemanager/pull/8) | Data model | `register_post_meta()` with the invalid type `datetime` | no |
| D2 | P3 | ✅ [#11](https://github.com/PSi86/wp-racemanager/pull/11) | Frontend JS | Gallery block binds an inline script to `DOMContentLoaded` | no |
| D4 | P3 | ✅ [#3](https://github.com/PSi86/wp-racemanager/pull/3) | Push | VAPID keys empty and not configurable anywhere | no |
| E6 | P3 | ✅ [#10](https://github.com/PSi86/wp-racemanager/pull/10) | REST | Upload endpoint guarded only by `is_user_logged_in()`; the API key check is dead code | no |
| E10 | P3 | ✅ [#10](https://github.com/PSi86/wp-racemanager/pull/10) | Activation | `create_event_registration_cf7_form()` creates another CF7 form on every activation | no |
| E7 | P3 | ✅ [#10](https://github.com/PSi86/wp-racemanager/pull/10) | Cleanup | `ABSPATH` guard commented out, dead code, three different version numbers | no |
| F1 | P3 | ✅ | Toolchain | npm and Composer dependencies one to two majors behind | indirectly |

---

## What is still open, in detail

### C1 — two date formats in the same meta field · resolved

Was the highest-value item left, because it silently hid races.

- **Write path 1** — `includes/rest-handler.php` stores an **integer** for races created by
  RotorHazard: `update_post_meta( $race_id, '_race_event_start', strtotime('today 8:00') )`
- **Write path 2** — `includes/cpt-meta-handler.php` stores the raw value of a
  `<input type="datetime-local">`, i.e. `2026-08-22T10:00` — with a `T` and no seconds
- **Read paths** all cast with `'type' => 'DATE'` / `'DATETIME'`:
  `block-render-race-select.php`, `block-render-nav-latest-races.php`, `race-archive.php`,
  `sc-cf7-event-dropdown.php`

`CAST('1750000000' AS DATETIME)` yields **NULL**. Every race auto-created by RotorHazard
therefore drops out of the navigation submenu, the archive filter and the CF7 registration
dropdown, and sorts as NULL in the race list. Not sporadic — deterministic, depending on
whether a race was ever edited in the backend.

**Resolved** in [#8](https://github.com/PSi86/wp-racemanager/pull/8): one canonical format
(`Y-m-d H:i:s`, site-local wall clock — the same thing `current_time('mysql')` produces),
normalised on save in *both* write paths, plus a migration behind a permanent dry run on
**Settings → RaceManager**. The report always shows the current state and a sample of what
would change; the write needs a confirmation checkbox. Values that cannot be understood are
left untouched and counted rather than guessed at.

The one decision worth recording: integers are read back with `gmdate()`, not `wp_date()`.
WordPress runs PHP in UTC, so the integers `strtotime('today 8:00')` produced reverse exactly
and give back the wall clock the caller meant. Going through the site timezone would turn an
intended 8am start into 10am on a UTC+2 site.

**C2** was fixed with it: `register_post_meta()` used `'type' => 'datetime'`, which is not a
valid meta type (allowed: `string`, `boolean`, `integer`, `number`, `array`, `object`).

### B3 — JS config hooked from inside a shortcode · P2

`rm_print_js_module_config()` is registered with `add_action( 'wp_head', … )` from inside each
of the four shortcodes in `includes/livepage-handler.php`. That works only because block
themes render the template *before* `wp_head()` (`wp-includes/template-canvas.php`). In a
classic theme `window.RmJsConfig` would simply be absent and every JS module would throw.

There was a second problem, and it bit in block themes too: each shortcode assigned
`$rm_js_config` wholesale, so with two of them on one page the last one won and the earlier
module came up unconfigured. (Registering the `wp_head` action four times was never part of it —
core stores a string callback under its own name, so it fires once regardless.)

**Project decision:** the site runs a block theme and will keep doing so, so the theme
dependency is accepted and B3 is *not* a reason to rewrite the configuration mechanism. What
remained in scope was the collision, and [#11](https://github.com/PSi86/wp-racemanager/pull/11) fixed it: `rm_add_js_module_config()` merges
per module instead of overwriting, so two shortcodes that configure different modules both get
what they asked for. Moving to the script module data API (`script_module_data_{$id}`) stays the
cleaner long-term option, not a prerequisite.

### D1 — pilot dropdown accumulates options · resolved

`js/rm-m-pilotSelector.js` appended a full set of `<option>` elements on every data update
without clearing first. Verified against the real module in a real DOM.

**It was in two places.** `js/bracketV25.js` carries the same construction for the older
`[rm_viewer]` shortcode, where `processRHData()` runs again on every fetch while the mode is
`live`. That shortcode is legacy — the only reference to it outside its own file is a
commented-out line in `rest-handler.php`, so new race posts no longer get it — but posts created
before the live area existed still can, and the accumulation is identical there. Both are fixed
the same way; the module is the one with test coverage, since `bracketV25.js` is a jQuery script
built around globals and cannot be exercised in isolation without stubbing most of it.

It only bites on a **live** race: `refreshInterval` is `0` unless `_race_live` is set, so an
archived race populates exactly once. And the callback fires per *upload*, not per poll —
plus once per failed poll, because `checkForUpdates()` notifies subscribers in its `catch`
block (the comment next to it claims the opposite).

| Scenario | Options in the dropdown |
|---|---|
| Archived race | 31 — built once, never duplicated |
| Live, 30 min open, upload per heat | 301 |
| Live, 4 h open, upload per heat | 2401 |
| Live, 30 min open, timestamp fetch failing | 5401 |

**The two fixes belong together.** Rebuilding the list alone introduces a visible regression:
today the selection survives a pilot leaving the field *because* the stale option is still
there. After a naive rebuild, `select.value = <gone>` sets `selectedIndex` to `-1` and the
control renders blank — not even the placeholder.

```
today (append):        selectedIndex=1,  value="5"    selection survives
naive rebuild:         selectedIndex=-1, value=""     blank control
rebuild + fallback:    selectedIndex=0,  value="0"    placeholder
```

Also worth doing while in there: guard against a missing `#pilotSelector` element. The module
throws `TypeError: Cannot read properties of null` at construction, which would take down the
importing page — same failure mode as D5. Currently latent, because both pages that load the
module do have the element. The type inconsistency (`number` from the constructor, `string`
after a selection) is cosmetic — no strict comparison anywhere depends on it.

### A2 — all seven blocks on `apiVersion: 2` · resolved

**The consequence recorded here first was 6.9 behaviour and did not describe 7.1.** It read "the
post editor drops out of iframe mode for any post containing one". In 7.1 there is no such
fallback: `editor.min.js` passes `shouldIframe: true` unconditionally, `BlockCanvas` in
`block-editor.min.js` defaults it to true, and `editor`, `block-editor` and `edit-post` contain no
`apiVersion` check at all. The version-2 blocks were therefore not degrading the editor into an
older mode — they were already running inside the iframe without having declared that they can,
which is the more awkward of the two readings. Core's own wording is hedged the same way: the
block "*may* work as a non-iframe editor". Confirmed from the other side by driving the editor:
the canvas is an iframe, with every block on version 2.

The six hand-written blocks were safe to move together. Every block in this plugin is dynamic —
`save` returns `null` and the output comes from a `render_callback` — so no stored markup existed
that a version bump could invalidate, which is the usual risk in this migration. All six already
called `useBlockProps`, none reference `document`, their only `window` use is the `window.wp`
passed into the IIFE, and none declare styles that would have to reach into the iframe.

`race-gallery` was the one filed as risky, because it drives the Backbone media modal
(`wp.shortcode`, `wp.media.gallery.attachments`, `wp.media.model.Selection`,
`wp.media({ frame: 'post' })`) which opens in the parent document while the block renders in the
iframe. That turned out to work, and it was checked rather than reasoned about — a Playwright run
against the real editor confirmed all of:

- the block renders inside the iframe, both empty and with media,
- the inline `<style>` lands in the iframe document and applies (thumbnails measure 150px),
- the block's own `wp.media` frame opens over the iframe, in `gallery-edit` state, with the
  existing selection loaded through the `wp.shortcode` path,
- no `API version 2 or lower` deprecation and no console error from this plugin.

The one warning left in that run comes from core, not from here:
`global-styles-css-custom-properties-inline-css was added to the iframe incorrectly`.

Worth knowing for the next round: that check is not part of `php tests/run.php`, which needs
neither Docker nor a browser. It was a one-off script. If WordPress 7.2 changes the iframe again,
this is the kind of verification to repeat.

### F1 — dependencies one to two majors behind · resolved

Both of the following were silent — nothing failed, and the test suite stayed green:

- **web-push 11 dropped its own Guzzle dependency.** It resolves a PSR-18 client through
  `php-http/discovery` at construction time instead, so upgrading without adding a client leaves
  `class_exists()` reporting the library as present while every notification throws
  `Http\Discovery\Exception\NotFoundException`. `composer.json` now requires `guzzlehttp/guzzle`
  explicitly, and the `vapid` suite constructs a `WebPush` instance so the gap cannot reopen
  unnoticed.
- **`@wordpress/scripts` 34 replaced `CleanWebpackPlugin` with webpack's own `output.clean`,**
  which empties the entire output directory before emit. `blocks/` holds the built `race-gallery`
  *and* six hand-written blocks, and the old `webpack.config.js` protected them by swapping the
  plugin instance — a hook that no longer exists. Rebuilt as an `output.clean.keep` rule, derived
  from the directories under `blocks-src/` rather than naming `race-gallery`. Verified by building
  once without it: six of the seven block directories were deleted.

---

## Suggested order

The working filter for this round: **only changes that improve general code quality and
robustness.** No redesigns, no feature work, and nothing that exists purely to lift a constraint
the project is happy to live with (see B3).

1. ~~**E7, E4, E6, E10**~~ — done in [#10](https://github.com/PSi86/wp-racemanager/pull/10).
2. ~~**E9, D2**~~ and ~~**B3 (reduced)**~~ — done in [#11](https://github.com/PSi86/wp-racemanager/pull/11);
   ~~**E8 remainder**~~ in [#12](https://github.com/PSi86/wp-racemanager/pull/12), where the address became a setting that defaults to the
   site's own domain.
3. ~~**F1**~~, ~~**A2**~~ — both done. All seven blocks are on `apiVersion: 3`.
4. ~~**D1**~~ — done, both halves at once as the entry warned, and in `bracketV25.js` as well as
   in the module. That closes the audit.

---

## Beyond the catch-up

This document tracks what a year of WordPress updates broke or exposed. Improvements to the live
app itself — the data path, the freshness indicator, the mobile navigation — are a separate list in
[`live-webapp-improvements.md`](live-webapp-improvements.md), with
[`data-flow.md`](data-flow.md) as the baseline they change.

---

## Method

WordPress core was cloned from `github.com/WordPress/WordPress` and tag 6.8.1 compared against
tag 7.1. For the signature comparison, every function declaration in both versions was parsed
(3,376 and 3,630 respectively) and checked against every function call in the plugin — that is
where A1 came from, as the only relevant signature change. The `TypeError` was reproduced
locally on PHP 8.4.19.

Findings marked resolved were re-verified against `main` by reading the code. **B3 and the E8
remainder were found to be still open during that re-verification**, having previously been
assumed fixed; both are closed now.

Regression coverage for the resolved items lives in [`tests/`](../tests/README.md) — 263 checks
across eleven suites.
