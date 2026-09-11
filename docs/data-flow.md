# How race data reaches the viewer

The path from the timer to a phone at the trackside, as it works today. Written down because
every improvement to the live area starts by changing one of these steps, and because the costs
are not obvious from any single file.

```
RotorHazard                WordPress                      uploads/races/            Browser
-----------                ---------                      --------------            -------
POST /rm/v1/upload   -->   rm_handle_upload()
  ?race_id=182             rm_update_race()          -->  182-timestamp.json   <--  poll every 10 s
  whole JSON
  (limit: 10 MB)           rm_write_files()          -->  182-data.json        <--  full download
                           rm_notify_nextup()                                       on any change
                           (queued; sent after the answer)
```

## The upload

`POST /wp-json/rm/v1/upload?race_id=…`, authenticated as a WordPress user with `edit_posts`
([`includes/rest-handler.php`](../includes/rest-handler.php)). With `race_id` it writes exactly
that race; without — an older connector — it finds the race by title and creates one if there is
none, for one more release.

The body is the **complete** result JSON, every time — there is no partial or incremental form.
`rm_validate_and_decode_json()` rejects anything above 10 MB. The relevant top-level keys:

| Key | Contents | Changes during a race |
|---|---|---|
| `heat_data.heats[]` | Heats with their slots, seeding, display names | rarely — when the schedule changes |
| `pilot_data.pilots[]` | Pilots, callsigns, teams | rarely — registration is done before |
| `class_data` | Race classes, brackets | rarely |
| `result_data` | Every lap, every ranking, per heat and overall | **constantly** |
| `current_heat.current_heat` | Which heat is up | every heat |
| `notifications` | Added server-side by `add_notifications_to_race_json()` | on every push |

`rm_update_race()` then writes into the race the upload names (requires `_race_live` to be `'1'`,
otherwise the race is locked); an upload without `race_id` goes through `rm_find_or_create_race()`,
which updates the race of that title or has `rm_create_race()` make one. Either way
`rm_write_files()` writes **two** files into `wp-content/uploads/races/`:

- `{race_id}-timestamp.json` — `{"time":"2026-08-30 14:32:10"}`, a few dozen bytes
- `{race_id}-data.json` — the whole payload, re-encoded

Both are plain files served by the web server. Nothing is stored in the database except post meta
(`_race_last_upload`, `_race_live`).

**The body may come gzip-compressed** (`Content-Encoding: gzip`, since 1.6.0). A full event
shrinks to about 7 % that way — 1,642,049 bytes went over the wire as 126,911 in the local test.
Core would refuse it: it parses a JSON body while it checks the parameters, before any
callback, and answers compressed bytes with 400 `rest_invalid_json` (measured on production,
whose LiteSpeed passes the body on as it came). So `rm_decode_compressed_body()` decodes it on
`rest_pre_dispatch`, which runs first, for the `rm/v1` routes only, and only for a user the
endpoints' gate lets through. It inflates at most 10 MB, piece by piece: `gzdecode()`'s own
limit did not hold in PHP 8.3. Every answer of the namespace carries `Accept-Encoding: gzip`
(RFC 7694), and the timer compresses only once it has read that, so an older WordPress keeps
getting bodies it can read. What the upload stores is the same either way, byte for byte.

**The next-up pushes go out after the answer** (since 1.6.0). `rm_notify_nextup()` works out
who flies next, queues their followers' pushes and stores each follower's heat and slot inside
the request; `includes/after-response.php` sends them once the timer has its answer. They used
to go out first, one after another, each waiting for its push service. Measured on the local
site with 100 followers and a stand-in push service that answers in 100 ms, the upload's answer
went from 10.19 s to 0.10 s, and all 100 pushes arrived 0.13-0.28 s after it. They go out 50 at
a time, each with 10 s at most; one after another where `php-http/guzzle7-adapter` or cURL is
missing. A timer on a slow uplink gives up on an answer after 60 s, and reported a stored
upload as failed when the pushes took that long. The same holds for `notify-racers`.

## The download

[`js/rm-m-dataLoader.js`](../js/rm-m-dataLoader.js) is a singleton, created on import. Its
configuration comes from `window.RmJsConfig.dataLoader`, filled in by
`rm_print_js_module_config()`:

```php
'refreshInterval' => $race_live ? 10000 : 0,   // ms; archived races never poll
'timestampUrl'    => …/{race}-timestamp.json,
'dataUrl'         => …/{race}-data.json,
'storageKey'      => $race_id,
'timeout'         => 9000,
```

The cycle:

0. On construction, read the cache out of `localStorage`: the payload under `rm_data_{race_id}`
   and, beside it, `rm_data_{race_id}_meta` with the last timestamp, the last `ETag` and the time
   the data carried. Anything found there is shown immediately and marked **unconfirmed** — it is
   on screen before anyone has asked whether it is still current.
1. Every `refreshInterval` ms, fetch the timestamp file with `cache: 'no-store'`. The delay is
   jittered by ±20 %, doubles after each failure up to two minutes, and is not scheduled at all
   while the page is hidden.
2. Compare its **text** against the cached timestamp. Text, not a parsed value: the comparison
   must not become sensitive to key order or whitespace.
3. If it differs, fetch `{race}-data.json`, carrying `If-None-Match` when an `ETag` is known. A
   `304` means there is nothing to download and, on a phone more to the point, nothing to parse.
4. Store the response **text** in `localStorage` — storing what came off the wire rather than
   re-serialising the parsed object — and hand the parsed object to every subscriber.

`storageKey` stays the bare race id because `displayHeats`, `displayStats` and `pilotSelector`
read it and build their own keys and `data-race-id` attributes out of it. The keys above are
derived from it separately, and the `rm_data_` prefix is what eviction matches on — deliberately
distinct from `rm_last_race`, which belongs to [`js/rm-live-resume.js`](../js/rm-live-resume.js)
and must survive it.

One race's payload is around 1.2 MB as text against an origin budget of a few megabytes, so two
of them do not both fit: writing evicts every other race's entry first, and a write that still
fails falls back to running without a cache rather than failing the page.

`onState()` is the second channel out of the loader, alongside `subscribe()`. It reports what the
loader is doing — checking, downloading, idle, how long since a check succeeded, how many have
failed — and [`js/rm-m-updateStatus.js`](../js/rm-m-updateStatus.js) is its only consumer, turning
it into the pill floating at the foot of each view.

### Two orderings that are load-bearing, and why

Both were learned from a failure at a real event, on the pre-2026 code: for some spectators the
app came up empty and **stayed** empty. Reloading did not help. Reloading again did not help. It
came back only when the app was killed outright and reopened.

**The timestamp is committed after the payload, never before.** The old loader wrote the new
timestamp to `sessionStorage` and then downloaded the data it pointed at. When that download
failed — which on a fading mobile link it does — the version was already marked as seen. Every
later check found the timestamp unchanged, concluded there was nothing new, and never asked for
the data again. The note outlived every reload and died only with the tab, which is exactly why
only a hard kill helped.

Reproduced against the old loader, blocking the payload and then restoring the network:

| | payload requests | standing on screen |
|---|---|---|
| first load, payload blocked | 1, fails | — |
| manual reload | **0** | — |
| manual reload again | **0** | — |
| reload with the network healthy again | **0** | **—** |
| brand new tab | 1 | shown |

**The deadline covers the body, not just the headers.** A fading link usually does not refuse the
connection: the headers arrive and the body then stops coming. Clearing the abort timer once the
response object exists — the obvious way to write it — leaves that read running for ever. The
in-flight flag never clears, every later check returns at the guard that reads it, and the page is
wedged in the same way, reached from the other side. `request()` therefore awaits the body read
inside the timeout.

The payload also gets a **longer** deadline than the timestamp check (30 s against 9 s). Thirty
bytes and a hundred kilobytes do not deserve the same patience, and cutting off a download that
was about to succeed, over and over, is its own way of never loading anything.

[`tests/e2e/flaky-network.cjs`](../tests/e2e/flaky-network.cjs) holds all of this, including the
part that matters most to a spectator: with a warm cache, losing the network costs freshness
rather than the page. That is the difference between "the app is broken" and "the app is behind",
and the freshness indicator is what makes it legible.

Subscribers get the entire object and pick what they need:

| Module | View | Reads |
|---|---|---|
| `rm-m-displayHeats` | bracket | `heat_data`, `pilot_data`, `class_data`, `current_heat`, `result_data` |
| `rm-m-displayStats` | stats | `result_data` |
| `rm-m-displayPilotStats` | pilots | `pilot_data`, `result_data` |
| `rm-m-pilotSelector` | bracket, stats, next-up | `pilot_data` |
| `rm-m-displayLog` | next-up | `notifications` |

Each view enqueues **one** module, and everything else arrives through that module's imports —
which is why the table above is not a free choice. The graph, read out of the `import` statements
rather than assumed:

```
bracket   -> displayHeats      -> dataLoader, pilotSelector
pilots    -> displayPilotStats -> dataLoader
stats     -> displayStats      -> dataLoader, pilotSelector
next-up   -> displayNextUp     -> pilotSelector, displayHeats, displayLog, displayRanking
```

**`rm-m-pilotSelector` therefore does not run on the pilots view.** `displayPilotStats` never
imported it, and that shortcode's `<select id="pilotSelector">` markup is commented out in
`livepage-handler.php` to match. The `pilotSelector` entry it still emits into `RmJsConfig` is a
leftover that nothing reads. Anything that reasons about "the pilot dropdown on every live page"
is wrong before it starts — it is on three of the four.

That the pilots view has no filter is a decision rather than an omission: its table is already one
row per pilot, so there is nothing to narrow down.

The stats view **did** belong in that list until the pilot filter was built. Both halves of it —
the import and the `<select>` markup — had been present but commented out, along with a
half-finished change handler whose two guards were crossed over. Anyone reading the old shape
would reasonably have concluded the view was meant to stay unfiltered; it was unfinished, not
decided.

## What this costs

- **Every change ships the whole file to every viewer.** The stats view needs `result_data`; the
  race log needs `notifications` and nothing else. Both download everything, every time. This is
  the one that is left, and it is what L7 and L8 in
  [`live-webapp-improvements.md`](live-webapp-improvements.md) are about.
- **The upload is all-or-nothing.** The timer re-sends pilots, heats and classes with every lap,
  from a field, usually over a phone hotspot.

### What this used to cost, and no longer does

Kept because the reasoning is worth more than the conclusion, and because one of these entries
was wrong for a year before anyone measured it.

- ~~**`sessionStorage` is per tab.**~~ The cache is in `localStorage` now. Measured against the
  real payload on the local site, with a persistent browser profile — a fresh profile is a
  first-ever visit and would prove nothing:

  | | before | after |
  |---|---|---|
  | first ever visit | 100,834 B | 100,834 B |
  | reload, same tab | 56 B | 56 B |
  | second tab | 100,768 B | **56 B** |
  | browser closed and reopened | 100,839 B | **111 B** |

  **The reload row is the correction.** This document used to say a reload "always costs a full
  transfer", and the improvement list repeated it. It never did: the timestamp gate plus
  `sessionStorage` already covered that one case, and only that one. What actually paid were the
  second tab and the returning visitor.

- ~~**`cache: 'no-store'` on both requests.**~~ Still `no-store`, and deliberately so — but the
  data request now carries `If-None-Match` itself. Keeping the browser cache out of the way is
  what makes a `304` arrive as a `304` instead of being turned back into a `200` from cache, and
  that distinction is what the status line reports on. Since the cache survives, this is the
  fallback for when storage is unavailable or evicted rather than the main saving.

- ~~**Polling is unconditional.**~~ It stops while the page is hidden and checks again on return,
  on `focus` and on `online`; the delay carries ±20 % jitter and doubles after each failure up to
  two minutes. `visibilitychange`, `focus` and `online` all fire within milliseconds of each other
  when a tab comes back, so a two-second floor keeps that from becoming three requests at once.

- ~~**The service worker does not cache anything.**~~ It had no `fetch` handler, so a reload or a
  relaunch of the installed PWA without reception showed the browser's error page, although the
  standing sat in `localStorage`. Since L6 [`templates/template-pwa-sw.js`](../templates/template-pwa-sw.js)
  keeps the live pages and their files and answers from those copies when the network does not —
  network first, so it changes nothing while the network answers. It leaves the race JSON alone
  entirely: the payload is the loader's to keep, and the timestamp has to come from the network or
  the freshness pill would be reporting on a copy.

- ~~**Nothing tells the viewer any of this.**~~ [`js/rm-m-updateStatus.js`](../js/rm-m-updateStatus.js)
  renders the loader's state above every live view. Two times are shown and never conflated: when
  the data was produced (site-local wall clock, straight out of the timestamp file, never
  converted) and when we last asked (this browser's clock). They come from different clocks, which
  is exactly why they are displayed separately and never subtracted from one another.

## Measuring it

The numbers that decide how much any of this matters are the file size and the change rate, and
both are properties of a real event rather than of the code:

```bash
curl -sI  https://<site>/wp-content/uploads/races/<id>-data.json | grep -i -E 'content-length|content-encoding|etag|last-modified'
curl -s   https://<site>/wp-content/uploads/races/<id>-data.json | gzip -c | wc -c   # what compression would leave
```

If `content-encoding: gzip` is missing there, that is the cheapest fix in the whole system.

What could replace this pipeline, and in what order, is in
[`live-webapp-improvements.md`](live-webapp-improvements.md).
