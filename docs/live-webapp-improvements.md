# Improving the live web app

Proposals, ordered so that each one stands on its own. The baseline they change is described in
[`data-flow.md`](data-flow.md).

Three things drive the ordering:

- **The download side multiplies, the upload side does not.** One timer uploads; fifty phones at
  the trackside download. A change that halves the download costs fifty times what the same change
  saves on the upload.
- **Anything that needs a change on the RotorHazard side is slower to land** than something the
  plugin can do alone, because it has to be rolled out to the timer as well.
- **The viewer's problem is usually not bandwidth, it is doubt.** "Is this the current standing or
  is my phone stuck?" is answered by a status line, not by a faster transfer.

---

## The list

IDs are stable and referenced from commits and pull requests, the same way the audit's are.

| ID | Prio | Needs | What |
|---|---|---|---|
| L1 | P1 | ✅ nothing to do — the host already sends `br` | Serve the race JSON compressed |
| L5 | P1 | ✅ done | Freshness indicator: is this current, when was it last checked, is it checking now |
| L4 | P1 | ✅ done | Visibility-aware, jittered, backing-off polling |
| L9 | P1 | the theme's rendered markup | A stylesheet for the mobile navigation |
| L2 | P2 | ✅ done — and worth less than this list claimed | Conditional requests alongside `cache: 'no-store'` |
| L3 | P2 | ✅ done | `localStorage` instead of per-tab `sessionStorage` |
| L6 | P2 | nothing | A service worker that caches, so the installed PWA survives bad reception |
| L7 | P2 | nothing | Split the payload into per-section files with an index |
| L8 | P3 | ~~a change on the RotorHazard side~~ — the uploader is ours | Upload only the sections that changed |
| L10 | P3 | — not needed at this audience size | A CDN in front of the JSON |

`D1` (the pilot dropdown) is **resolved** — [#15](https://github.com/PSi86/wp-racemanager/pull/15),
rebuilt list plus placeholder fallback, with `tests/e2e/pilot-selector.cjs` covering both halves.
It no longer constrains when L4/L5 get built.

---

## Stage 1 — no protocol change, no RotorHazard change

Everything here is inside the plugin and can ship in one release.

### L1 · Serve the JSON compressed

Check first (`curl -sI` with `Accept-Encoding: gzip`, see [`data-flow.md`](data-flow.md)). Plesk
and nginx compress `text/html` and `text/css` by default; `application/json` from an uploads
directory is often *not* in the list. Race JSON is highly repetitive and compresses by 80–90 %.

If the host does not do it, an `.htaccess` in `wp-content/uploads/races/` can:

```apache
<IfModule mod_deflate.c>
  AddOutputFilterByType DEFLATE application/json
</IfModule>
```

**Effort:** minutes. **Effect:** the single largest transfer saving available, on every request,
for every viewer. Do this before anything else, and measure again afterwards.

### L2 · Conditional requests — done, and smaller than it looked

**Done.** The premise held: both files carry `ETag` and `Last-Modified`, and a request with
`If-None-Match` answers `304` with a zero-byte body (measured, `100,614 B` → `0 B`).

**But this entry overstated its own value, and the correction matters more than the change.** It
claimed that reloads, second tabs and the PWA cold start "today always cost a full transfer".
Measured in a real browser, a reload in the same tab cost **56 bytes** — the timestamp gate and
`sessionStorage` already covered it. What actually paid the full ~100 KB were the second tab and
the returning visitor, and both of those are fixed by L3, not by L2. Once the cache survives,
L2 is the fallback for when storage is unavailable or has been evicted.

`cache: 'no-store'` stayed rather than being dropped. Keeping the browser's own cache out of the
way is what makes a `304` visible to the code instead of being turned back into a `200` from
cache, and that distinction is what L5 reports on. The conditional header is sent explicitly.

### L3 · Cache across tabs, not per tab — done

**Done**, and this was the change that carried the saving. Payload under `rm_data_{race_id}`,
metadata (timestamp, `ETag`, data time) beside it, other races evicted on write, every read and
write treated as failable — private mode, blocked site data and a full quota all throw, and a
write that fails twice gives up on storage for the page view rather than failing the page.

Measured against the real payload with a persistent browser profile, because a fresh profile is a
first-ever visit and would have proved nothing:

| | before | after |
|---|---|---|
| first ever visit | 100,834 B | 100,834 B |
| reload, same tab | 56 B | 56 B |
| second tab | 100,768 B | **56 B** |
| browser closed and reopened | 100,839 B | **111 B** |

The first row does not move and should not: a browser that has never seen the race has to
download it. The last row is what a viewer coming back to the trackside actually does.

### L4 · Poll like a phone, not like a server — done

**Done**, all four, and `setInterval` gave way to a self-scheduling `setTimeout` because no two
delays are alike any more:

- **Pause while hidden.** Nothing is scheduled while `document.visibilityState === 'hidden'`; the
  page checks once on the way back.
- **Check on reconnect**, on `online` and on `focus`.
- **Back off on failure.** 10 s → 20 s → 40 s → 80 s, capped at two minutes, reset on the first
  success.
- **Jitter of ±20 %** on every delay.

One thing this list did not foresee: `visibilitychange`, `focus` and `online` all fire within
milliseconds of each other when a tab comes back, so without a floor the fix for "poll less" would
have produced three requests in one instant. There is a two-second minimum gap between checks.

### L5 · The freshness indicator — done

*(Explicitly wanted, and the one improvement every viewer sees.)*

**Done**, as [`js/rm-m-updateStatus.js`](../js/rm-m-updateStatus.js) plus
`css/rm-update-status.css`: a **pill floating at the foot of the viewport**, emitted by all four
live shortcodes through one helper (`rm_update_status_markup()`) so that markup, module and
stylesheet cannot drift apart. It ships `hidden` and the module reveals it — without JavaScript
nothing polls, so there is nothing truthful to say and an empty pill would be worse than none.

The first attempt was a text line in the flow above each view. It worked and looked like an
afterthought, which for the one improvement every viewer sees is not good enough.

What the form is doing:

- **Quiet at rest, loud at the problem.** While things are current the text is grey and says the
  least it can — `Up to date · 17:18`. Every other state takes a tinted surface, its own colour
  and a fuller sentence, and is allowed to wrap. Nobody has to tap it to learn something is wrong.
- **The quiet is in the ink, never in the element's opacity.** Dimming the pill dims its surface
  with it, and a half-transparent panel over a bracket full of pilot names is illegible — it reads
  as a rendering fault rather than as restraint. That was the first version of this stylesheet and
  the screenshot is what caught it.
- **No `backdrop-filter`.** Unevenly supported, a compositing layer on exactly the phones this has
  to be cheap on, and leaning on it for legibility makes the fallback the unreadable case.
- **Bottom centre, not bottom right**, where back-to-top buttons, chat bubbles and cookie banners
  live. Below 26 rem it spans the width instead, which is easier to hit with a thumb.
  `env(safe-area-inset-bottom)` keeps it off the iPhone home indicator.
- **The whole pill is the button.** Tapping forces a check, which is the only honest answer to
  "is it stuck?".
- **Emitted once per page, not once per shortcode.** Two live shortcodes on one page is a real
  configuration — the same one `rm_add_js_module_config()` exists for — and a second element would
  both duplicate the id and stack a second pill on the first.

Three things about the built version differ from the sketch below:

- **The strings are English**, not German. The rest of the live area already is — "The race log is
  currently empty.", "There is no saved race data available to view." — and one German line in it
  would have read as an oversight.
- **The two times are never subtracted from one another.** The data time is site-local wall clock
  with no timezone in it (PHP `current_time('mysql')`); the check time is this browser's clock.
  Each is shown as what it is. The data time is read out of the string and never converted, so a
  timestamp from another day carries its date rather than showing a bare `17:18` that would look
  current.
- **A race that is not flagged live makes no freshness claim at all.** There is no next check to
  promise, so the line says when the data was made and stops there.

The decision table below is what the code implements; the state machine is the pure `describe()`
function, exported so `tests/e2e/update-status.cjs` can walk every branch without needing a broken
network to produce one.

The loader currently exposes only "here is new data". It needs to expose its **state**:

| State | When | What the viewer reads |
|---|---|---|
| `fresh` | last check succeeded, nothing changed | `Aktuell · geprüft vor 8 s` |
| `checking` | timestamp request in flight | `Prüfe …` |
| `updating` | data download in flight | `Lade neue Daten …` |
| `stale` | last successful check older than ~3 intervals | `Daten von 14:32 · seit 2 min nicht erreichbar` |
| `offline` | `navigator.onLine === false` or the fetch failed | `Offline · zuletzt 14:32` |

Two different times matter and both should be visible: **when the data was produced** (the race's
own timestamp) and **when we last asked** (the check). Conflating them is what makes a status line
untrustworthy.

Implementation: give `DataLoader` a small event emitter (`checkstart`, `checkend`, `updatestart`,
`updateend`, `error`) alongside the existing `subscribe()`, plus `lastCheckedAt`, `lastChangedAt`
and `dataTimestamp`. A new module `rm-m-updateStatus.js` renders into a container the shortcodes
emit. Tapping it forces a check — which is also the honest answer to "is it stuck?".

Details worth getting right: `role="status"` and `aria-live="polite"` so a screen reader announces
changes without stealing focus; one shared timer for the relative time rather than one per
component; the absolute time in `title`; never show "aktuell" while a check is failing.

### L6 · A service worker that caches

The service worker handles push and nothing else — there is no `fetch` handler, so the installed
PWA shows an error page when reception drops, even though it had the data a moment ago.

Add: cache-first for the app shell (CSS, JS modules, icons), stale-while-revalidate for the race
JSON. Two consequences worth planning for: a versioned cache name and an eviction step in
`activate`, and a deliberate decision not to cache `-timestamp.json` — that one must always hit the
network or the freshness indicator starts lying.

---

## Stage 2 — split the payload, plugin side only

### L7 · Per-section files with an index

Still no RotorHazard change: the timer keeps uploading the whole file, and `rm_write_files()`
splits it on arrival.

```
182-index.json        {"updated":"…","sections":{"result_data":{"hash":"9f2c…","bytes":48210}, …}}
182-result_data.json
182-heat_data.json
182-pilot_data.json
182-class_data.json
182-current_heat.json
182-notifications.json
182-data.json         kept as it is, for anything that still wants the whole thing
```

The client polls `index.json` instead of the timestamp file, compares hashes per section, and
downloads **only what changed**. It reassembles the object it hands to subscribers, so no display
module changes at all.

Why this pays: during a race, `result_data` and `current_heat` change constantly while
`pilot_data`, `heat_data` and `class_data` do not. Today a lap in heat 12 re-sends the pilot list
to every viewer. And a view can fetch only its own sections — the race log needs `notifications`,
the stats view needs `result_data`.

Falls back cleanly: no `index.json` (an archived race written by the old code) → use `data.json`
exactly as now.

**Effort:** a day, mostly tests. **Effect:** the download shrinks to what actually changed, for
every viewer, on every update.

---

## Stage 3 — the upload side, needs RotorHazard

### L8 · Upload only the sections that changed

Only worth doing after stage 2, because stage 2 defines the sections and the hashes this builds on.

**The simple version, and the one to build:** before uploading, the timer fetches
`GET /rm/v1/manifest?race_id=…` (the same hashes as `index.json`) and posts only the sections whose
hash differs, as `{"sections":{"result_data":{…}}}`. The server replaces those sections whole and
rewrites the index. Idempotent, order-independent, and a failed upload simply repeats.

Explicitly **not** proposed: JSON Patch or an append-only event log. Both are more efficient and
both introduce ordering and reconciliation problems that are miserable to debug at a race with a
flaky hotspot. The failure mode of "replace this section" is a repeat; the failure mode of a
missed patch is silent corruption.

Worth pairing with it: gzip the request body (`Content-Encoding: gzip`), which is a few lines on
each side and cuts the hotspot traffic again.

---

## Stage 4 — if the origin becomes the bottleneck

Not needed at current scale; listed so the option is known.

- **L10 — a CDN in front of the JSON** (Cloudflare's free tier is enough): short TTL plus
  `stale-while-revalidate`, purged on upload. The origin then serves one request per change
  instead of one per viewer per change. This is the largest possible win for viewer load and needs
  no protocol change at all.
- **Not SSE, not WebSockets.** Both hold a PHP worker per connected viewer. On shared hosting with
  a handful of workers, fifty phones at a race exhaust the pool and take the whole site down.
  Polling that is visibility-aware, jittered and conditional is the right answer here, and it
  degrades gracefully instead of catastrophically.
- **Push as a wake-up signal.** The VAPID infrastructure exists. A silent push per upload would
  drop the polling interval — but delivery is neither guaranteed nor prompt, every viewer must
  have subscribed, and iOS delivers only to an installed PWA. Useful as an *accelerator* on top of
  polling, never as a replacement.

---

## Separate from the data path

### L9 · The mobile menu needs a stylesheet

The plugin ships exactly one navigation style: `css/rm_live_page_link.css`, twenty lines for the
blinking dot on the live link. Everything else about the live navigation is the theme's, and on a
phone that is where it falls apart.

Proposed: `css/rm-live-nav.css`, enqueued on live pages only, mobile first —

- the view switcher (bracket / pilots / stats / next up) as a horizontally scrollable row of
  segments with the current view marked, sticky under the header, so switching views never needs
  the burger menu;
- tap targets of at least 44 px, and `padding-bottom: env(safe-area-inset-bottom)` so the
  installed PWA does not put controls under the home indicator;
- ~~the freshness indicator from L5 living in that same bar~~ — **decide this again when L9 is
  built.** L5 shipped as a pill floating at the foot of the viewport, which is where a sticky view
  switcher would also want to be. Two floating elements stacked on the bottom edge is worse than
  either alone, so it is one or the other: either the switcher takes the bottom and the indicator
  moves into it, or the switcher goes under the header and the indicator stays where it is.
- the burger overlay's items sized for a thumb rather than a mouse.

**Measured** on 2026-09-09, `https://copterrace.com/live/bracket/?race_id=2402` in headless
Chromium at 390 × 844 CSS pixels, `isMobile`, `hasTouch`. Production runs the **Frost** theme; the
local environment runs Twenty Twenty-Five, so the local site is not a proving ground for this.

What is actually there:

| | |
|---|---|
| Navigation container | `<nav class="is-responsive items-justified-right no-wrap nav-live-area wp-block-navigation">` — there is already a `nav-live-area` class to hang a rule on |
| The four view links | in the DOM, **0 × 0 px, not visible**. Every view switch goes through the burger |
| Burger overlay | six items — Home, Select Race, Pilots, Bracket, Stats, Next up — right-aligned, **32 px tall**, 18 px font, no marking of the current view, and roughly the lower 60 % of the overlay empty |
| Header | 88 px tall, `position: static` — it scrolls away, so nothing is reachable once you are down in the bracket |
| Page width | 390 px document against a 390 px viewport: no horizontal page scroll, the bracket has its own scroll container |

And the plugin's own controls on that page, which the entry above did not account for:

| Element | Size | |
|---|---|---|
| `.web-controls` | 380 × 73 px | the row holding both controls |
| `#pilotSelector` | 165 × 29 px | below the 44 px minimum |
| `#filterCheckbox` | **13 × 13 px** | far below it, and the label is not wired as a tap target |

So the concrete problems, in the order they hurt:

1. **Switching views costs three taps** — burger, item, close — because the links collapse to
   nothing. This is the one the proposed segment row fixes.
2. **Nothing is sticky.** The bracket is long; once scrolled, there is no way back to the
   navigation or to the pilot filter without scrolling to the top.
3. **Three tap targets are under 44 px**, the checkbox drastically so at 13 px.
4. **The current view is not marked** anywhere, in the overlay or outside it.

A note found on the way: production still serves the **old** URL form. `/live/bracket/?race_id=2402`
answers 200 while `/live/winter-whooprace-2025/bracket/` answers 404, so the path-based router
(finding B1) has never been deployed there. That is direct evidence for step 1 of the next-steps
list below, not an assumption.

### Pilot dropdown (D1) · resolved

`rm-m-pilotSelector.js` now rebuilds its list instead of appending to it, and falls back to the
placeholder when the selected pilot has left the field — both halves together, because clearing
the list alone made the selection go blank. It no longer waits on whichever stage touches the
loader. `tests/e2e/pilot-selector.cjs` covers it; five of its eight checks fail against the
pre-fix module.

Worth keeping in mind while building L4/L5 anyway: the selector subscribes to the same loader, so
a change to how subscribers are notified reaches it too.

---

## What had to be answered first — three of four are answered

Each one changes what gets built. They were measured on 2026-09-09 against the three real races
now in the local environment (see [`development-setup.md`](development-setup.md)).

1. **How big is a real `-data.json`, and is it already compressed?** — **answered.**

   | Race | raw | gzip -9 | served as |
   |---|---|---|---|
   | Galaxy Cup 2025 | 1299 KB | 96 KB (7 %) | `content-encoding: br` |
   | Fall Whooprace 2025 | 1614 KB | 120 KB (7 %) | `br` |
   | Winter Whooprace 2025 | 1177 KB | 89 KB (7 %) | `br` |

   **L1 is therefore already done** — copterrace.com serves Brotli, and the `.htaccess` snippet
   below is moot. The file is 1.2–1.6 MB of highly repetitive JSON that compresses to roughly a
   fourteenth of itself. Against the threshold this question set — "at 30 KB gzipped L7 may never
   be worth building" — 90–120 KB is above it, but not by the margin that would make L7 urgent,
   especially since the big file is fetched only when the timestamp changes.

2. **Is the uploader on the RotorHazard side ours to change?** — **yes.** It is
   `src/server/plugins/teamrace_manager/__init__.py` in the RotorHazard checkout, ~1100 lines, and
   it is Peter's own code. Two things about it matter here:

   - The payload is already assembled as **named sections** — `pilot_data`, `heat_data`,
     `class_data`, `result_data`, `current_heat`, `format_data`, `frequency_data`, plus the
     `msg_*` notification fields. L7's per-section split lines up with keys that already exist,
     and L8 becomes a matter of omitting unchanged ones rather than restructuring anything.
   - The upload fires on **`Evt.RACE_SCHEDULE`** and from a manual *Upload Results* button. Every
     other event hook in the file is commented out, so it is once per heat scheduling, not per
     lap. The per-viewer cost is therefore one ~100 KB transfer per heat, not a stream.

   That plugin is a project of its own and wants its own review pass.

3. **What does the live navigation actually render on a phone?** — **answered**, measured at
   390 × 844 against production. The four view links collapse to 0 × 0 and every view switch goes
   through the burger; the overlay's items are 32 px tall with no current-view marking; the header
   does not stick; and the plugin's own `#filterCheckbox` is a 13 × 13 px tap target. The full
   measurement is in the L9 entry above, including the theme (**Frost**, against Twenty
   Twenty-Five locally) and the class names to write against.

4. **How many people watch a race at once?** — **up to 32 pilots take part**, so viewers are that
   order of magnitude plus spectators: tens, not thousands. At ~100 KB per viewer per heat that is
   single-digit megabytes per upload event, which no origin will notice. **L10 is not needed**,
   and L7's value is about phone data and latency rather than about protecting the server.

---

## Next steps

In order, and each one is a self-contained piece of work:

1. **Deploy what is already merged.** Nothing on this list should be built on top of a production
   site that still runs the June 2025 code. See [`deployment.md`](deployment.md). This is now a
   year of accumulated work — the whole audit, the dependency catch-up and `apiVersion: 3`.
2. ~~**Measure**~~ and ~~**L1**~~ — done, see the answers above.
3. ~~**L2, L3**~~ and ~~**L4, L5**~~ — done, in one pass rather than two releases. They were
   planned as separate steps and turned out to be one: all four touch the same sixty lines of
   `js/rm-m-dataLoader.js`, and building them apart would have meant rewriting that stretch four
   times. L5 also needs what L2 and L3 change — a 304 is the "nothing changed" state, and a cache
   that survives is what produces the "shown but not yet confirmed" state.
4. **L6 · the service worker.** Now the largest thing missing from the data path, and the one the
   others cleared the way for: with the payload in `localStorage` and the loader reporting its own
   state, a `fetch` handler has something coherent to fall back to and something to tell the
   viewer when it does. Two decisions to make deliberately — a versioned cache name with an
   eviction step in `activate`, and **not** caching `-timestamp.json`, which must always hit the
   network or the freshness indicator starts lying.
5. **L9** — still unblocked, and the measurement says it is worth more than its P1 rating
   suggested: on a phone the live area is effectively a single view unless the visitor knows to
   open the burger.
6. **L7**, and then **L8** with it. Both are unblocked, and designing them together is the point:
   the uploader already speaks in the sections L7 would split the file into.

The local environment now carries the three real races from production, so all of this can be
built against real payloads rather than fixtures.

---

## Where to build this

Stages 1 and 2 are worth doing in the local development environment
([`development-setup.md`](development-setup.md)) rather than against production: the whole point is
to change behaviour under bad network conditions, and that is exactly what browser devtools can
simulate and a live race cannot.
