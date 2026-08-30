# How race data reaches the viewer

The path from the timer to a phone at the trackside, as it works today. Written down because
every improvement to the live area starts by changing one of these steps, and because the costs
are not obvious from any single file.

```
RotorHazard                WordPress                      uploads/races/            Browser
-----------                ---------                      --------------            -------
POST /rm/v1/upload   -->   rm_handle_upload()
  whole JSON               rm_process_race()         -->  182-timestamp.json   <--  poll every 10 s
  (limit: 10 MB)           rm_write_files()          -->  182-data.json        <--  full download
                           rm_notify_nextup()                                       on any change
```

## The upload

`POST /wp-json/rm/v1/upload`, authenticated as a WordPress user with `edit_posts`
([`includes/rest-handler.php`](../includes/rest-handler.php)).

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

`rm_process_race()` then either updates the existing `race` post (requires `_race_live` to be
`'1'`, otherwise the race is locked) or creates one, and `rm_write_files()` writes **two** files
into `wp-content/uploads/races/`:

- `{race_id}-timestamp.json` — `{"time":"2026-08-30 14:32:10"}`, a few dozen bytes
- `{race_id}-data.json` — the whole payload, re-encoded

Both are plain files served by the web server. Nothing is stored in the database except post meta
(`_race_last_upload`, `_race_live`).

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

1. Every `refreshInterval` ms, fetch the timestamp file with `cache: 'no-store'`.
2. Compare its **text** against the value in `sessionStorage`.
3. If it differs, fetch `{race}-data.json` — again `cache: 'no-store'`, so always the whole file.
4. Store it in `sessionStorage` and hand the parsed object to every subscriber.

Subscribers get the entire object and pick what they need:

| Module | View | Reads |
|---|---|---|
| `rm-m-displayHeats` | bracket | `heat_data`, `pilot_data`, `class_data`, `current_heat`, `result_data` |
| `rm-m-displayStats` | stats | `result_data` |
| `rm-m-displayPilotStats` | pilots | `pilot_data`, `result_data` |
| `rm-m-pilotSelector` | all | `pilot_data` |
| `rm-m-displayLog` | nextup | `notifications` |

## What this costs

- **Every change ships the whole file to every viewer.** The stats view needs `result_data`; the
  race log needs `notifications` and nothing else. Both download everything, every time.
- **`cache: 'no-store'` on both requests.** No `ETag`, no `If-None-Match`, no 304 — the browser
  cache is bypassed on purpose, so a reload always costs a full transfer.
- **`sessionStorage` is per tab.** A second tab, a reopened PWA or a reload after a crash starts
  from nothing and downloads the file again.
- **Polling is unconditional.** A hidden tab, a phone in a pocket and a viewer with no reception
  poll at the same rate as an active one, and they all poll on the same 10-second grid — a hundred
  phones that opened the page when the heat started ask within the same second.
- **The upload is all-or-nothing.** The timer re-sends pilots, heats and classes with every lap,
  from a field, usually over a phone hotspot.
- **The service worker does not cache anything.** [`templates/template-pwa-sw.js`](../templates/template-pwa-sw.js)
  handles `push` and `notificationclick` only — there is no `fetch` handler. The installed PWA
  therefore shows nothing at all when reception drops, even though it displayed the data a minute
  earlier.
- **Nothing tells the viewer any of this.** There is no indication whether what is on screen is
  current, when it was last checked, or that a check is running.

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
