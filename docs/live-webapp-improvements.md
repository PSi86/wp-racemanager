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

## Stage 1 — no protocol change, no RotorHazard change

Everything here is inside the plugin and can ship in one release.

### 1.1 Serve the JSON compressed

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

### 1.2 Let the browser cache, and use conditional requests

Drop `cache: 'no-store'` and send `If-None-Match` instead. The web server already produces `ETag`
and `Last-Modified` for static files, so an unchanged file answers **304 with no body**. Combined
with the timestamp gate this mostly protects reloads, second tabs and the PWA cold start — the
cases that today always cost a full transfer.

### 1.3 Cache across tabs, not per tab

`sessionStorage` → `localStorage`, keyed by race and by the data timestamp. A reopened PWA then
renders the last known standing **immediately**, before the network is even asked, and the first
request is a cheap timestamp check rather than a full download.

Storage is capacity-limited, so evict other races' entries on write, and treat every read and
write as failable (private mode, full quota).

### 1.4 Poll like a phone, not like a server

Four changes to the same loop:

- **Pause while hidden.** `document.visibilityState === 'hidden'` → stop the interval; check once
  immediately on `visibilitychange` back to visible. A phone in a pocket currently polls all day.
- **Check on reconnect.** Listen for `online`, and on `focus`.
- **Back off on failure.** 10 s → 20 s → 40 s → capped at ~2 min, reset on the first success.
  Reception at a race site is bad in bursts; hammering it does not help.
- **Add jitter.** Everyone opens the page when the heat starts, so everyone polls on the same
  second. ±20 % random offset spreads that across the interval — this is the difference between a
  spike of a hundred simultaneous requests and a smooth trickle.

### 1.5 The freshness indicator

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

### 1.6 A service worker that caches

The service worker handles push and nothing else — there is no `fetch` handler, so the installed
PWA shows an error page when reception drops, even though it had the data a moment ago.

Add: cache-first for the app shell (CSS, JS modules, icons), stale-while-revalidate for the race
JSON. Two consequences worth planning for: a versioned cache name and an eviction step in
`activate`, and a deliberate decision not to cache `-timestamp.json` — that one must always hit the
network or the freshness indicator starts lying.

---

## Stage 2 — split the payload, plugin side only

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

- **A CDN in front of the JSON** (Cloudflare's free tier is enough): short TTL plus
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

### The mobile menu needs a stylesheet

The plugin ships exactly one navigation style: `css/rm_live_page_link.css`, twenty lines for the
blinking dot on the live link. Everything else about the live navigation is the theme's, and on a
phone that is where it falls apart.

Proposed: `css/rm-live-nav.css`, enqueued on live pages only, mobile first —

- the view switcher (bracket / pilots / stats / next up) as a horizontally scrollable row of
  segments with the current view marked, sticky under the header, so switching views never needs
  the burger menu;
- tap targets of at least 44 px, and `padding-bottom: env(safe-area-inset-bottom)` so the
  installed PWA does not put controls under the home indicator;
- the freshness indicator from 1.5 living in that same bar;
- the burger overlay's items sized for a thumb rather than a mouse.

**To do this properly I need the rendered markup**, since the classes come from the theme: the
navigation block's HTML on a live page at phone width, or a screenshot. I could not fetch
copterrace.com from this session — the sandbox blocks it.

### Pilot dropdown (D1, still open)

`rm-m-pilotSelector.js` rebuilding its list is part of the same event flow and belongs to whichever
stage touches the loader. Both halves have to land together: clearing the list without preserving
the selection makes the selection go blank when a pilot leaves the field.

---

## Suggested order

1. **1.1 compression** — measure, then fix. Minutes, and it changes what everything else is worth.
2. **1.5 freshness indicator + 1.4 polling** — one piece of work, since both live in the loader's
   state machine. This is what the viewer actually notices.
3. **1.2 / 1.3 caching** — small, and they make reloads and PWA restarts cheap.
4. **1.6 service worker caching** — the installed app stops being useless without reception.
5. **Stage 2 sectioning** — the real transfer win, still plugin-only.
6. **Stage 3 upload deltas** — once the timer side can be changed.
7. **Stage 4** — only when the numbers say so.

Stages 1 and 2 are worth doing in the local development environment
([`development-setup.md`](development-setup.md)) rather than against production: the whole point is
to change behaviour under bad network conditions, and that is exactly what browser devtools can
simulate and a live race cannot.
