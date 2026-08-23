# WordPress update audit

What the WordPress 6.9–7.1 releases broke, and what else the review turned up.

| | |
|---|---|
| Baseline commit | `ba41e7c`, 2025-06-03 |
| WordPress then / now | 6.8.1 → 7.1 |
| Findings | 23 — 13 resolved, 1 partially, 9 open |
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
| A2 | P2 | **open** | Blocks | All 7 blocks on `apiVersion: 2` — deprecated since 6.9, editor drops out of the iframe | yes — WP 6.9/7.0 |
| B2 | P2 | ✅ [#5](https://github.com/PSi86/wp-racemanager/pull/5) | Performance | `session_start()` on every live page load disabled page caching | no |
| B3 | P2 | **open** | Live / PWA | JS config is hooked to `wp_head` from inside a shortcode — only works in block themes | no |
| B4 | P2 | ✅ [#5](https://github.com/PSi86/wp-racemanager/pull/5) | Live / PWA | Session redirect without no-cache headers; `/live/*` not excluded from speculative loading | yes — WP 6.8/7.1 |
| D1 | P2 | **open** | Frontend JS | Pilot dropdown accumulates duplicates on every data refresh | no |
| D3 | P2 | ✅ [#5](https://github.com/PSi86/wp-racemanager/pull/5) | PWA | Manifest and service worker contained `https://domain.com/` and were only written on activation | no |
| D5 | P2 | ✅ [#3](https://github.com/PSi86/wp-racemanager/pull/3) | Frontend JS | `rm-m-pwa-subscribe.js` touched missing DOM nodes unguarded, taking the whole nextup page down | no |
| E3 | P2 | ✅ [#3](https://github.com/PSi86/wp-racemanager/pull/3) | Robustness | Composer autoload via `../../../../../vendor/`, unguarded and inconsistent | no |
| E4 | P2 | **open** | Database | `dbDelta()` called with `IF NOT EXISTS` — schema upgrades never apply | no |
| E8 | P2 | ⚠️ partly [#5](https://github.com/PSi86/wp-racemanager/pull/5) | Portability | Hard-coded `copterrace.com` — the URLs are gone, the email addresses in the CF7 form template remain | no |
| E9 | P2 | **open** | SEO | Duplicate `<title>`, PHP warnings on non-singular pages | no |
| C2 | P3 | ✅ [#8](https://github.com/PSi86/wp-racemanager/pull/8) | Data model | `register_post_meta()` with the invalid type `datetime` | no |
| D2 | P3 | **open** | Frontend JS | Gallery block binds an inline script to `DOMContentLoaded` | no |
| D4 | P3 | ✅ [#3](https://github.com/PSi86/wp-racemanager/pull/3) | Push | VAPID keys empty and not configurable anywhere | no |
| E6 | P3 | **open** | REST | Upload endpoint guarded only by `is_user_logged_in()`; the API key check is dead code | no |
| E7 | P3 | **open** | Cleanup | `ABSPATH` guard commented out, dead code, three different version numbers | no |
| F1 | P3 | **open** | Toolchain | npm and Composer dependencies one to two majors behind | indirectly |

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

There is a second problem: with two such shortcodes on one page, the global `$rm_js_config` is
overwritten by the last one and the config script is printed twice.

**Fix:** attach the configuration to the module itself via the script module data API
(`script_module_data_{$id}`) instead of a global `wp_head` detour.

### D1 — pilot dropdown accumulates options · P2

`js/rm-m-pilotSelector.js` appends a full set of `<option>` elements on every data update
without clearing first. Verified against the real module in a real DOM.

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

### The rest

- **A2** — all blocks on `apiVersion: 2`. Since 6.9 `registerBlockType` logs a deprecation and
  the post editor drops out of iframe mode for any post containing one. `race-gallery` is the
  risky one to migrate: it uses the old Backbone media library (`wp.media`, `wp.shortcode`).
- **E4** — `create_db_table()` passes `CREATE TABLE IF NOT EXISTS` to `dbDelta()`. Core parses
  the table name with `preg_match('|CREATE TABLE ([^ ]*)|')` and so reads it as "IF". The table
  is created the first time (MySQL handles it), but **schema changes are never detected**.
- **E6** — `/rm/v1/upload` is guarded only by `is_user_logged_in()`; per-post capability is
  checked later. `rm_validate_api_key()` is dead code and uses a non-timing-safe comparison.
- **E7** — the `ABSPATH` guard in `wp-racemanager.php` is commented out; `is_racemanager_rest_api_request()`
  starts with `return true`; the version appears as `1.0` (header), `1.0.0` (constant) and
  `1.0.1` (`package.json`); `Requires at least` and `Requires PHP` headers are missing entirely,
  so WordPress cannot warn about an incompatible update.
- **E8 remainder** — `includes/admin-registrations.php` still hard-codes
  `registration@copterrace.com` in the CF7 form template it creates on activation.
- **E9** — `includes/seo-handler.php` prints its own `<title>` at `wp_head` priority 1, where
  core also registers `_wp_render_title_tag()`, giving two title tags. `$post_title`,
  `$post_desc` and `$post_keys` are read on non-singular pages without being defined.
- **F1** — `@wordpress/components` is ten majors behind, `@wordpress/scripts` four;
  `minishlink/web-push` is on 9.x with 11.x current. The devcontainer pins Node 18 (EOL).
  Needed before A2 can be tackled.

---

## Suggested order

1. **E4, E6, E7, E9** — small, self-contained, no migration risk. Good to batch.
2. **B3** — script module data API. Touches the same file as any future live-page work.
3. **F1 → A2** — dependencies first, then `apiVersion: 3`. `race-gallery` needs the most care.
4. **D1 + D2** — frontend polish. Low risk, low urgency.

---

## Method

WordPress core was cloned from `github.com/WordPress/WordPress` and tag 6.8.1 compared against
tag 7.1. For the signature comparison, every function declaration in both versions was parsed
(3,376 and 3,630 respectively) and checked against every function call in the plugin — that is
where A1 came from, as the only relevant signature change. The `TypeError` was reproduced
locally on PHP 8.4.19.

Findings marked resolved were re-verified against `main` by reading the code. **B3 and the E8
remainder were found to be still open during that re-verification**, having previously been
assumed fixed.

Regression coverage for the resolved items lives in [`tests/`](../tests/README.md) — 181 checks
across seven suites.
