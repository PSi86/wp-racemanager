# How race data reaches the viewer

The path from the timer to a phone at the trackside, as it works today. Written down because
every improvement to the live area starts by changing one of these steps, and because the costs
are not obvious from any single file.

```
RotorHazard                WordPress                      uploads/races/            Browser
-----------                ---------                      --------------            -------
POST /rm/v1/upload   -->   rm_handle_upload()
  ?race_id=182             rm_update_race()
  whole JSON               rm_write_files()          -->  182-part-*.json      <--  the parts that changed
  (limit: 10 MB)                                     -->  182-data.json        <--  first visit, fallback
                                                     -->  182-index.json       <--  on a change
                                                     -->  182-timestamp.json   <--  poll every 10 s
                           rm_notify_nextup()
                           (queued; sent after the answer)
```

In the order they are written: the parts, the whole file, the index, the timestamp last.

## The upload

`POST /wp-json/rm/v1/upload?race_id=…`, authenticated as a WordPress user with `edit_posts`
([`includes/rest-handler.php`](../includes/rest-handler.php)). With `race_id` it writes exactly
that race; without — an older connector — it finds the race by title and creates one if there is
none, for one more release.

The body is the **complete** result JSON, every time — there is no partial or incremental form.
`rm_validate_and_decode_json()` rejects anything above 10 MB. The relevant top-level keys:

| Key | Contents | Changes during a race |
|---|---|---|
| `heat_data.heats[]` | Heats with their slots, seeding, display names | every heat: each heat's `next_round` counts the rounds flown (read from the connector's `payload.py`; this table said "rarely — when the schedule changes" until 1.8.0) |
| `pilot_data.pilots[]` | Pilots, callsigns, teams | rarely — registration is done before |
| `class_data` | Race classes, brackets | rarely |
| `result_data` | Every lap, every ranking, per heat and overall | **constantly** |
| `current_heat.current_heat` | Which heat is up | every heat |
| `notifications` | Added server-side by `add_notifications_to_race_json()`; empty for an archived race (1.8.1) | on every push |

`rm_update_race()` then writes into the race the upload names (requires `_race_live` to be `'1'`,
otherwise the race is locked); an upload without `race_id` goes through `rm_find_or_create_race()`,
which updates the race of that title or has `rm_create_race()` make one. Either way
`rm_write_files()` ([`includes/race-files.php`](../includes/race-files.php)) writes into
`wp-content/uploads/races/`, in this order:

- `{race_id}-part-{path}.json` — one part of the payload each (since 1.8.0), as
  `{"hash":"…","data":…}`
- `{race_id}-data.json` — the whole payload, re-encoded, with the index added as `rm_index`
- `{race_id}-index.json` — the index (since 1.8.0): `format`, `time` (the timestamp's) and for
  every part its `path`, `hash` (xxh64 of its JSON) and `bytes`
- `{race_id}-timestamp.json` — `{"time":"2026-08-30 14:32:10"}`, a few dozen bytes

All of them are plain files served by the web server, each written beside itself and renamed over
the old one, so a browser reading it meanwhile gets the old file or the new one, whole. Parts the
payload no longer has — a heat deleted on the timer — are removed once the new index is out.
Nothing is stored in the database except post meta (`_race_last_upload`, `_race_live`).

**The timestamp goes last** (since 1.8.0). It is the promise that what it announces is there, and it
used to be written first: a browser polling between the two writes took the new timestamp with the
old data — a 304 for the unchanged file confirmed it — and so did every browser after a data write
that failed. Each then held the old standing under the new timestamp until the next upload. The
same mistake the loader made until 2026 (see the two orderings below), made on the server. A file
that cannot be written now leaves the timestamp as it was.

**The parts** (L7 in [`live-webapp-improvements.md`](live-webapp-improvements.md), since 1.8.0).
Every top-level key of the payload is a part, except `result_data`, whose keys are parts in turn —
and `result_data.heats` and `result_data.classes` are split once more, a part per heat and per
class (`RM_RACE_SPLIT`). Only a JSON object is split, and only when every key can go into a file
name (`[A-Za-z0-9_]`): a list, an empty object and anything else stay one part, so the parts put
back together are the payload whatever shape it came in. A payload whose top level cannot be split
gets no index, and an old index is removed; so does a race that is not live (since 1.8.1, see
below). Race 34, the Winter Whooprace, is 57 parts.

Why that deep, measured on the three races from production (Brotli, as production serves them):

| Race | whole file | `result_data` | split per section: an update | split per heat and class: an update |
|---|---|---|---|---|
| Galaxy Cup 2025 | 60.1 KB | 55.8 KB | 58.8 KB (98 %) | 11.0 KB on average (18 %) |
| Fall Whooprace 2025 | 77.9 KB | 75.1 KB | 76.6 KB (98 %) | 10.2 KB (13 %) |
| Winter Whooprace 2025 | 57.8 KB | 55.4 KB | 56.8 KB (98 %) | 8.4 KB (14 %) |

`result_data` changes with every upload, since every upload comes after a heat was flown, and the
sections that do not change are together only 3–7 KB. Most of `result_data` is its heats, one entry
each, and a heat not flown since the last upload does not change. An update after a heat changes
that heat's part, its class's, the event leaderboard, `current_heat`, and `heat_data`, whose
`next_round` counts the heat's rounds. The last column counts all of these, and class and event
leaderboard as changed every time, so it errs on the high side. Measured locally with the browser
suite, gzip: 11,365 bytes for an update that changed a heat, its class, the event leaderboard and
`current_heat` — `heat_data` stayed as it was, which a real upload's would not — against 102,039
for the whole file.

Writing the parts costs the upload's answer about 20 ms. Measured in the local container with the
largest of the three races, 1.65 MB and 64 files, seven runs: `rm_write_files()` 34 ms at the
median, against 12 ms for the whole file and the timestamp alone.

**A notification writes the files again** (`handle_notification_request()` reads the whole file back
and hands it to `rm_write_files()`): the index it carries is taken out and made anew, and only the
race log's hash changes. A viewer downloads that one part rather than the whole file. Only for a
live race: since 1.8.1 `notify-racers` refuses an archived race with 400, *Race is locked and takes
no messages.*, before anything is stored or pushed, and an ID that is no race with 404.

**An archived race keeps its results, whole, and nothing else** (since 1.8.1, decided on
2026-09-12). The parts serve the updates of a live race, and an archived race takes none; the race
log belongs to the event while it runs — the file of Fall Whooprace 2025 copied from production
still carried *Mittagsbestellung abgeben!* with the link to the order. So `rm_write_files()` stores
parts and the race log for a live race only, and setting a race to archive — the meta box, Quick
Edit, WP-CLI, anything that stores `_race_live` — runs `rm_archive_race()`:

1. the files are written again, as a race that is not live gets them: the whole file with an empty
   race log, and the timestamp, with a new time since the data changed; index and parts go;
2. then the race log goes from the database, for good.

In that order, so that a write that fails leaves the log for the next time. A race whose files
carry nothing to clear is left as it is — Quick Edit stores the flag as a number, and WordPress
takes that for a change on every save. Set live again, a race starts with an empty log, and its
next upload brings the parts back. The races archived before 1.8.1 are cleared the same way,
once, on the first admin page after the update (`rm_archived_races_cleared`, see
[`deployment.md`](deployment.md)).

**The body may come gzip-compressed** (`Content-Encoding: gzip`, since 1.6.0). A full event
shrinks to about 7 % that way — 1,642,049 bytes went over the wire as 126,911 in the local test.
Core would refuse it: it parses a JSON body while it checks the parameters, before any
callback, and answers compressed bytes with 400 `rest_invalid_json` (measured on production,
whose LiteSpeed passes the body on as it came). So `rm_decode_compressed_body()` decodes it on
`rest_pre_dispatch`, which runs first, for the `rm/v1` routes only, and only for a user the
endpoints' gate lets through. It inflates at most 10 MB, piece by piece: `gzdecode()`'s own
limit did not hold in PHP 8.3. Every answer of the namespace carries `Accept-Encoding: gzip`
(RFC 7694), and the timer compresses only once it has read that, so an older WordPress keeps
getting bodies it can read. A PHP without zlib, which is optional, does not say so, and answers
a compressed body 415 rather than dying of the missing `inflate_init()`. On a 415 the timer sends
the event again as it is, and on core's 400 `rest_invalid_json` too — what it meets when the site
goes back to an older WP RaceManager while it runs. What the upload stores is the same either
way, byte for byte.

**The next-up pushes go out after the answer** (since 1.6.0). `rm_notify_nextup()` works out
who flies next, queues their followers' pushes and stores each follower's heat and slot inside
the request; `includes/after-response.php` sends them once the timer has its answer. They used
to go out first, one after another, each waiting for its push service. Measured on the local
site with 100 followers and a stand-in push service that answers in 100 ms, the upload's answer
went from 10.19 s to 0.10 s, and all 100 pushes arrived 0.13-0.28 s after it. They go out 50 at
a time, each with 10 s at most; one after another where `php-http/guzzle7-adapter` or cURL is
missing. A timer on a slow uplink gives up on an answer after 60 s, and reported a stored
upload as failed when the pushes took that long. The same holds for `notify-racers`.

**A follower follows the pilot key** (since 1.7.0). The RotorHazard connector sends each pilot's
`pilot_key` in `pilot_data`, and a subscription keeps the key of the pilot it was made for (column
`pilot_key` of `wp_rm_subscriptions`). The timer gives its pilots new IDs when it re-creates them
(*Clear pilots before download*); by ID alone a subscription then followed whoever had the number,
with pushes about someone else's heat. Where the upload has keys, a subscription with a key is
matched by it and takes the pilot's new ID; one from before keys is matched by ID and takes the
key. An upload without keys, from an older connector, is matched by ID as before. The live pages'
pilot selection follows the key the same way. A ZIP replace does not run the activation hook, so
`rm_maybe_upgrade_subscriptions_table()` adds the column on the first request after the update.

## The download

[`js/rm-m-dataLoader.js`](../js/rm-m-dataLoader.js) is a singleton, created on import. Its
configuration comes from `window.RmJsConfig.dataLoader`, filled in by
`rm_print_js_module_config()`:

```php
'refreshInterval' => $race_live ? 10000 : 0,   // ms; archived races never poll
'timestampUrl'    => …/{race}-timestamp.json,
'dataUrl'         => …/{race}-data.json,
'indexUrl'        => …/{race}-index.json,       // since 1.8.0
'partUrl'         => …/{race}-part-%s.json,     // since 1.8.0; %s is the part's name
'storageKey'      => $race_id,
'timeout'         => 9000,
```

The cycle:

0. On construction, read the cache out of `localStorage`, and beside it `rm_data_{race_id}_meta`
   with the last timestamp, the last `ETag` and the time the data carried. A race with an index is
   stored in parts, `rm_data_{race_id}_part_{name}` each, and the meta entry carries the index that
   names them; every one of them has to be there, or none of it counts. A race without one is
   stored whole under `rm_data_{race_id}`. Anything found is shown immediately and marked
   **unconfirmed** — it is on screen before anyone has asked whether it is still current.
1. Every `refreshInterval` ms, fetch the timestamp file with `cache: 'no-store'`. The delay is
   jittered by ±20 %, doubles after each failure up to two minutes, and is not scheduled at all
   while the page is hidden.
2. Compare its **text** against the cached timestamp. Text, not a parsed value: the comparison
   must not become sensitive to key order or whitespace.
3. If it differs, and the loader knows which parts it holds: fetch the index, and then, all at
   once, every part whose hash differs from the one it holds (**since 1.8.0**). Otherwise fetch
   `{race}-data.json`, carrying `If-None-Match` when an `ETag` is known; a `304` means there is
   nothing to download and, on a phone more to the point, nothing to parse. The whole file carries
   the index as `rm_index`, which the loader takes out before any subscriber sees it — that is how
   a first visit learns which parts it holds, without a second request that could belong to
   another upload.
4. Store what arrived — the whole file's **text** as it came off the wire, or the changed parts —
   and hand the parsed object to every subscriber. Put together from parts, it is parsed anew from
   their text on every update, as the whole file always was: nothing a subscriber changes in what it
   is handed can reach the next update.

Where the parts will not do, the loader downloads the whole file instead: the index is gone or in a
format it does not read, more than half of the payload changed (after a day offline, say), a part
turns out to be no part, or the index was overtaken three times running. Each part carries its own
hash, so a part a newer upload replaced after the index was read shows it, and the index is read
again. An index older than the timestamp counts as overtaken too: the server writes it first, so
only a cache between browser and server could hand out such a pair.

A race stored before 1.8.0 has no `rm_index` in its whole file, and the loader never asks for an
index there: the parts cost an archived race nothing. Loader and files of either version work
together — an old loader downloads the whole file as it always did and hands on the extra key,
which no module reads; a new loader meeting old files downloads the whole file. That matters
because the loader carries no version (see [`deployment.md`](deployment.md)).

`storageKey` stays the bare race id because `displayHeats`, `displayStats` and `pilotSelector`
read it and build their own keys and `data-race-id` attributes out of it. The keys above are
derived from it separately, and the `rm_data_` prefix is what eviction matches on — deliberately
distinct from `rm_last_race`, which belongs to [`js/rm-live-resume.js`](../js/rm-live-resume.js)
and must survive it. A race whose ID begins with this one's is another race: `rm_data_340_part_…`
is not a part of race 34.

One race's payload is around 1.2 MB as text against an origin budget of a few megabytes, so two
of them do not both fit: writing evicts every other race's entries first, and a write that still
fails falls back to running without a cache rather than failing the page. Parts are written before
the meta entry whose index names them, and parts that are gone are removed after it.

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

**Both hold for the parts** (since 1.8.0), and both had a counterpart on the server:

- Nothing is committed until every part the index names has arrived: not the timestamp, not the
  index, not a byte of storage. A part that fails fails the update; the parts that did arrive are
  kept in memory, never shown and never stored, so that on a fading link each attempt gets further
  than the last. Every part is read inside its own deadline, body included.
- On the server the timestamp is written last, after the parts, the whole file and the index, and
  every file is replaced whole — see [The upload](#the-upload). It used to be written first.

[`tests/e2e/race-parts.cjs`](../tests/e2e/race-parts.cjs) holds the parts' side: a part aborted,
one whose body stalls after the headers, an upload landing while the parts come in. Against the
loader from before 1.8.0, 33 of its 45 checks fail.

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

- **The upload is all-or-nothing.** The timer sends pilots, heats and classes with every upload,
  from a field, usually over a phone hotspot. Once per heat, not with every lap as this entry said
  until 1.8.0 — the upload fires on `Evt.RACE_SCHEDULE` and from a button. Compressed (1.6.0) a full
  event is 93–125 KB; the parts that changed after a heat would be 11–15 KB, estimated the same way
  as the table in [The upload](#the-upload) but with gzip. At 0.25 Mbit/s that is about 4 s against
  0.5 s, on an upload that runs in the background and is retried. That is L8 in
  [`live-webapp-improvements.md`](live-webapp-improvements.md), put off on 2026-09-12 until L7 has
  been measured at an event.

### What this used to cost, and no longer does

Kept because the reasoning is worth more than the conclusion, and because one of these entries
was wrong for a year before anyone measured it.

- ~~**Every change ships the whole file to every viewer.**~~ Since 1.8.0 a viewer downloads the
  parts that changed (L7): 8–15 KB after a heat instead of 58–78 KB, and a race-log message costs
  one small part instead of the whole file. The first visit still downloads the whole file, now
  with its index, which adds about 1.3 KB (102,144 B against 100,833 B locally); a returning
  visitor still pays 110 B for the timestamp.

  What this entry also said — "the stats view needs `result_data`; the race log needs
  `notifications` and nothing else" — suggested letting each view fetch only its own sections.
  That would have saved nothing: every view reads `result_data`, the race log's included, since
  the next-up view that shows it also shows the heats. Not built.

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
curl -s   https://<site>/wp-content/uploads/races/<id>-index.json                    # the parts and their sizes (1.8.0)
```

If `content-encoding: gzip` is missing there, that is the cheapest fix in the whole system.

What could replace this pipeline, and in what order, is in
[`live-webapp-improvements.md`](live-webapp-improvements.md).
