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
Nothing here is started yet.

| ID | Prio | Needs | What |
|---|---|---|---|
| L1 | P1 | nothing — measure first | Serve the race JSON compressed |
| L5 | P1 | nothing | Freshness indicator: is this current, when was it last checked, is it checking now |
| L4 | P1 | nothing | Visibility-aware, jittered, backing-off polling |
| L9 | P1 | the theme's rendered markup | A stylesheet for the mobile navigation |
| L2 | P2 | nothing | Conditional requests instead of `cache: 'no-store'` |
| L3 | P2 | nothing | `localStorage` instead of per-tab `sessionStorage` |
| L6 | P2 | nothing | A service worker that caches, so the installed PWA survives bad reception |
| L7 | P2 | nothing | Split the payload into per-section files with an index |
| L8 | P3 | a change on the RotorHazard side | Upload only the sections that changed |
| L10 | P3 | numbers from a real event | A CDN in front of the JSON |

`D1` (the pilot dropdown) stays in [`wordpress-update-audit.md`](wordpress-update-audit.md) but
belongs to whichever of L4/L5 touches the loader first — see below.

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

### L2 · Let the browser cache, and use conditional requests

Drop `cache: 'no-store'` and send `If-None-Match` instead. The web server already produces `ETag`
and `Last-Modified` for static files, so an unchanged file answers **304 with no body**. Combined
with the timestamp gate this mostly protects reloads, second tabs and the PWA cold start — the
cases that today always cost a full transfer.

### L3 · Cache across tabs, not per tab

`sessionStorage` → `localStorage`, keyed by race and by the data timestamp. A reopened PWA then
renders the last known standing **immediately**, before the network is even asked, and the first
request is a cheap timestamp check rather than a full download.

Storage is capacity-limited, so evict other races' entries on write, and treat every read and
write as failable (private mode, full quota).

### L4 · Poll like a phone, not like a server

Four changes to the same loop:

- **Pause while hidden.** `document.visibilityState === 'hidden'` → stop the interval; check once
  immediately on `visibilitychange` back to visible. A phone in a pocket currently polls all day.
- **Check on reconnect.** Listen for `online`, and on `focus`.
- **Back off on failure.** 10 s → 20 s → 40 s → capped at ~2 min, reset on the first success.
  Reception at a race site is bad in bursts; hammering it does not help.
- **Add jitter.** Everyone opens the page when the heat starts, so everyone polls on the same
  second. ±20 % random offset spreads that across the interval — this is the difference between a
  spike of a hundred simultaneous requests and a smooth trickle.

### L5 · The freshness indicator

*(Explicitly wanted, and the one improvement every viewer sees.)*

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
- the freshness indicator from L5 living in that same bar;
- the burger overlay's items sized for a thumb rather than a mouse.

**To do this properly I need the rendered markup**, since the classes come from the theme: the
navigation block's HTML on a live page at phone width, or a screenshot. I could not fetch
copterrace.com from this session — the sandbox blocks it.

### Pilot dropdown (D1, still open)

`rm-m-pilotSelector.js` rebuilding its list is part of the same event flow and belongs to whichever
stage touches the loader. Both halves have to land together: clearing the list without preserving
the selection makes the selection go blank when a pilot leaves the field.

---

## What has to be answered before some of this can start

These are not rhetorical — each one changes what gets built, and none of them can be answered
from inside the repository.

1. **How big is a real `-data.json`, and is it already compressed?**
   ```bash
   curl -sI  https://<site>/wp-content/uploads/races/<id>-data.json | grep -i -E 'content-length|content-encoding'
   curl -s   https://<site>/wp-content/uploads/races/<id>-data.json | gzip -c | wc -c
   ```
   At 30 KB already gzipped, L7 may never be worth building. At 300 KB uncompressed it is the most
   valuable item on the list. **Everything below L5 waits on this number.**

2. **Is the uploader on the RotorHazard side yours to change?** If yes, L8 becomes realistic and
   L7 should be designed with it in mind. If no, the plan ends at L7 and the upload stays
   all-or-nothing.

3. **What does the live navigation actually render on a phone?** The classes come from the theme,
   so L9 needs the markup or a screenshot at phone width. (This session cannot fetch the
   production site — the sandbox blocks outbound access to it.)

4. **How many people watch a race at once?** Ten and fifty are different systems. It decides
   whether L10 is ever needed and how much L7 is worth.

---

## Next steps

In order, and each one is a self-contained piece of work:

1. **Deploy what is already merged.** Nothing on this list should be built on top of a production
   site that still runs the June 2025 code. See [`deployment.md`](deployment.md).
2. **Measure** — question 1 above, plus a look at how often the timer uploads during a heat.
3. **L1**, if the measurement says compression is missing. Minutes of work, and it changes what
   every other item is worth.
4. **L5 + L4 together**, in the local environment. They are one state machine, and browser
   devtools can simulate the bad network they exist for; a live race cannot be paused to test.
   Take D1 along, since it subscribes to the same loader.
5. **L9**, once the markup is available.
6. **L2, L3, L6** as one release — all three are about not re-fetching what is already known.
7. **L7**, if the numbers justify it.
8. **L8**, if question 2 is a yes.

---

## Where to build this

Stages 1 and 2 are worth doing in the local development environment
([`development-setup.md`](development-setup.md)) rather than against production: the whole point is
to change behaviour under bad network conditions, and that is exactly what browser devtools can
simulate and a live race cannot.
