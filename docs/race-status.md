# Live and archived

A race has one flag, `_race_live`: `'1'` is live, anything else is archived. The admin calls the two
*Live (Unlocked)* and *Archive (Locked)*. The flag is set in the meta box and in Quick Edit, by
`rm_create_race()` — a race an upload or the timer's **New race** creates is live — by WP-CLI, and
since 1.9.0 by the plugin itself, a day after a race's end. The code is
[`includes/race-status.php`](../includes/race-status.php); `rm_race_is_live()` is the one reading of
the flag every part of the plugin shares.

## What follows from the state

A dash: older than the work that built the rest of this table.

| | Live | Archived | Since |
|---|---|---|---|
| An upload from the timer | stored | refused, 400 `race_locked` | — |
| A race-log message from the timer | stored and pushed | refused, 400 *Race is locked and takes no messages.* | 1.8.1 |
| The race's files | whole file, parts and index, race log in them | whole file and timestamp only, empty race log | 1.8.0, 1.8.1 |
| The race log in the database | kept | deleted when the race is archived | 1.8.1 |
| Push subscriptions | kept | deleted when the race is archived, and when it is deleted | 1.9.0 |
| The live pages' polling | every 10 s | none; one check when a page loads | — |
| The freshness pill | shown | hidden unless the data could not be loaded | — (L5) |
| The next-up view | heats, subscription form, race log | *This race is over.* | — |
| `[rm_race_log]` | the race log | nothing | 1.8.1 |
| *Live:* in the race list, the dot on the live link | shown, once the race has results | not shown | 1.9.0 |
| The installed app, started on `/live/?resume=1` | goes straight on to the race last viewed | stays on the selection page and offers it | 1.9.0 |
| `GET /races` for the timer | `"live": true` | `"live": false` | 1.3 |

*Live:* and the dot said "a race had an upload in the last two hours" until 1.9.0. They stayed for
up to two hours after a race was archived, went out in a long break of a race that was live, and a
cached page kept whatever they said when it was cached. They follow the flag now; that a forgotten
flag does not keep a race marked for ever is archiving by itself's to see to.

## What a change sets off

`rm_on_race_live_changed()`, on `added_post_meta` and `updated_post_meta` — so whatever stores the
flag, archiving follows:

1. **Set to archive:** `rm_archive_race()` writes the files again as an archived race gets them — a
   new timestamp, since the data changed — and removes index and parts; then the race log goes
   from the database, then the push subscriptions. Files first, so that a write that fails leaves
   the rest for the next time. A race with nothing to clear is left as it is.
2. **Any change of state empties the page cache**, and so do a race's first results, which put it
   on the race list.

Only a change: `update_post_meta`, which fires before a value is stored, remembers the state. Quick
Edit passes the flag as a number where the database holds a string, and WordPress compares the two
strictly — to WordPress every save there is a change.

### The page cache

The live area is cacheable on purpose, and the state is in its markup: whether a view polls, the
next-up view's form or *This race is over.*, *Live:* in the list, the live races the app may resume
into, and the dot on the live link, which the navigation of every page carries. **Measured on
production on 2026-09-12:** the home page, `/live/` and a race's bracket all came from LiteSpeed's
page cache (`X-LiteSpeed-Cache: hit`), which keeps a page for 7 days unless configured otherwise.

So a change empties all of it, with LiteSpeed Cache's `do_action( 'litespeed_purge', '*' )` — its
page cache only, not its CSS, JS and object caches. That is a few times per event, and it cannot
miss a page that carries the state. The purge travels in a response's headers; from WP-CLI and
WP-Cron, where no response carries it, LiteSpeed Cache keeps it and sends it with the next request.
Both read in its source, 7.9.1. Without LiteSpeed Cache nothing happens; another page cache would
need its own call.

## Archiving by itself

`rm_auto_archive_races()` runs by the hour through WP-Cron and sets a live race to archive when its
**end and its last upload are both more than a day ago**. Both, because the end can be wrong: a race
an upload creates gets *today, 19:00* as its end, and an event running into a second day would be
locked in the middle of it if the end alone counted. A race without an end stays live. An end
stored in another form is read through `rm_normalize_event_datetime()` first.

- Scheduled on `init`, since a ZIP replace runs no activation hook; the deactivation hook takes the
  schedule away.
- **`define( 'RM_AUTO_ARCHIVE', false );` in `wp-config.php` switches it off** and takes an existing
  schedule away — for the development site, whose races are from 2025 (see
  [`development-setup.md`](development-setup.md)), and for an organiser who archives by hand.
- The first run is scheduled for now, and WordPress spawns WP-Cron on the same request. **The first
  request after the update archives every live race whose end and last upload lie more than a day
  back**, race log and subscriptions included. Found on the local site, where switching it on for a
  moment archived race 34 there and then.

## Once, after the update

`rm_maybe_clear_archived_races()` archives the races that were archived before: their race logs, the
parts 1.8.0 may have left, and their push subscriptions. On the first admin page after the update,
not in an AJAX request, which the live pages make. Recorded as `rm_archive_schema` = 2; 1.8.1 ran a
first version of it without the subscriptions and recorded that as `rm_archived_races_cleared`, and
a site that ran it runs it again.

## Not tied to the state, on purpose

- **The registrations** and what they hold — names, addresses, phone numbers. How long they are
  kept is a question of its own, not one of whether the race is over; an organiser often needs them
  after the event.
- **`get-pilots`**: it only reads.
- **The race's own page**, its gallery, its dates, and the details block that is open until the
  event's end: they go by the date.

## Known gaps

- **There are two states, and a race that has not begun is the second.** A race created in the admin
  for its registrations is *not live*: the meta box shows it as *Archive (Locked)*, and its next-up
  view says *This race is over.* The race list shows only races with results, so that view is
  reached by its URL alone. *Upcoming* could be derived — not live, never uploaded to — where the
  words matter; nothing needs a new flag.
- **A race created live before its event** — the timer's **New race** the evening before — carries
  *Live:* and the dot from then on.
- **Registration goes by its own flag and the end date.** A race archived before its end, with
  registration open, keeps *Join now!* and its place in the CF7 dropdown until the end. Closing
  registration when a race is archived would have to happen on a real change from live only — the
  meta box saves a new race as archived, and would close its registration at once.
